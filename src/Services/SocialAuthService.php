<?php

namespace Plugins\G7\SocialLogin\Services;

use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Extension\HookManager;
use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\SocialiteManager;
use Laravel\Socialite\Two\GoogleProvider;
use Plugins\G7\SocialLogin\Models\SocialAccount;
use Plugins\G7\SocialLogin\Models\SocialLoginLinkNonce;
use Plugins\G7\SocialLogin\Models\SocialLoginUserFlag;
use Plugins\G7\SocialLogin\Support\RedirectPath;
use SocialiteProviders\Kakao\KakaoProvider;
use SocialiteProviders\Naver\NaverProvider;

/**
 * 카카오/구글/네이버 OAuth 로직 전담 서비스.
 *
 * `SocialiteManager::buildProvider()` 로 provider 인스턴스를 직접 조립한다 — 플러그인
 * 설정(DB)에 저장된 client_id/secret 을 코어 `config/services.php` 에 쓰지 않고
 * 그대로 넘길 수 있어, 코어 설정 파일을 건드리지 않고도(플러그인 격리 원칙) 동적
 * 앱 키 교체가 즉시 반영된다.
 *
 * `Socialite` 파사드는 쓰지 않는다 — socialite 는 플러그인 자체 vendor 에 있어 코어의
 * 패키지 auto-discovery 대상이 아니므로 `SocialiteServiceProvider` 가 등록되지 않고,
 * 파사드 해석 시 `Contracts\Factory is not instantiable` 로 500 이 난다(실측).
 */
class SocialAuthService
{
    public const IDENTIFIER = 'g7-social_login';

    public const PROVIDERS = ['kakao', 'google', 'naver'];

    /** 로그인 교환코드 캐시 TTL(초) — 프론트가 즉시 교환하므로 짧게 잡는다 */
    private const EXCHANGE_TTL = 60;

    /** 교환코드 소비 락 유지시간(초) — 소비 도중 프로세스가 죽어도 이 시간 뒤 풀린다 */
    private const EXCHANGE_LOCK_SECONDS = 10;

    /** 연동 nonce 유효시간(분) — 인증된 XHR 발급 → 곧바로 브라우저 이동에 쓰인다 */
    private const LINK_NONCE_TTL_MINUTES = 5;

    /**
     * 신규 가입자 기본 역할 식별자.
     *
     * 코어에는 이 값을 담은 설정·상수가 없고, 회원가입(`AuthService::register`)과 관리자 회원 생성
     * (`UserService::createUser`) 두 경로 모두 `RoleRepositoryInterface::findByIdentifier('user')`
     * 리터럴로 조회한다 — 같은 저장소·같은 식별자를 그대로 따른다.
     */
    private const DEFAULT_ROLE_IDENTIFIER = 'user';

    public function __construct(
        private readonly PluginSettingsService $settings,
        private readonly RoleRepositoryInterface $roleRepository,
    ) {}

    public function isEnabled(string $provider): bool
    {
        return (bool) $this->settings->get(self::IDENTIFIER, "{$provider}_enabled", false);
    }

    /**
     * @throws \RuntimeException 설정이 비어있거나 비활성화된 경우
     */
    public function driverFor(string $provider): \Laravel\Socialite\Two\AbstractProvider
    {
        if (! in_array($provider, self::PROVIDERS, true) || ! $this->isEnabled($provider)) {
            throw new \RuntimeException("provider_disabled:{$provider}");
        }

        $clientId = (string) $this->settings->get(self::IDENTIFIER, "{$provider}_client_id", '');
        $clientSecret = (string) $this->settings->get(self::IDENTIFIER, "{$provider}_client_secret", '');

        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException("provider_not_configured:{$provider}");
        }

        $redirectUrl = url("/api/plugins/".self::IDENTIFIER."/{$provider}/callback");

        $config = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect' => $redirectUrl,
        ];

        $providerClass = match ($provider) {
            'kakao' => KakaoProvider::class,
            'naver' => NaverProvider::class,
            default => GoogleProvider::class,
        };

        /** @var \Laravel\Socialite\Two\AbstractProvider $driver */
        $driver = (new SocialiteManager(app()))->buildProvider($providerClass, $config);

        return $driver;
    }

    /**
     * 1회용 로그인 교환코드를 발급한다.
     *
     * 브라우저 전체이동(OAuth 콜백 리다이렉트)에는 Authorization 헤더를 실을 수
     * 없으므로, 토큰 자체를 URL 에 노출하는 대신 짧은 1회용 코드만 넘긴다.
     * 캐시에는 대상 회원과 이동 경로만 담고, 토큰은 교환 시점에 발급한다 —
     * 평문 토큰이 캐시 저장소에 남지 않게 하기 위해서다.
     */
    public function issueExchangeCode(User $user, string $redirectPath = RedirectPath::FALLBACK): string
    {
        $code = Str::random(48);

        Cache::put("g7sl:exchange:{$code}", [
            'user_id' => $user->id,
            'redirect_path' => RedirectPath::sanitize($redirectPath),
        ], now()->addSeconds(self::EXCHANGE_TTL));

        return $code;
    }

    /**
     * 교환코드를 1회만 소비하고, 성공했을 때에만 Sanctum 토큰을 발급한다.
     *
     * 같은 코드로 동시에 들어온 요청이 둘 다 get 에 성공하지 않도록 코드별 락을
     * 대기 없이 잡는다 — 락을 못 잡은 요청은 이미 다른 요청이 소비 중이므로 실패로 본다.
     *
     * @return array{token: string, user: User, redirect_path: string}|null
     */
    public function consumeExchangeCode(string $code): ?array
    {
        $lock = Cache::lock("g7sl:exchange-lock:{$code}", self::EXCHANGE_LOCK_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        try {
            $key = "g7sl:exchange:{$code}";
            $payload = Cache::get($key);

            if (! is_array($payload)) {
                return null;
            }

            Cache::forget($key);

            $user = User::find($payload['user_id'] ?? null);

            if (! $user) {
                return null;
            }

            return [
                'token' => $this->createAuthToken($user),
                'user' => $user,
                'redirect_path' => RedirectPath::sanitize($payload['redirect_path'] ?? null),
            ];
        } finally {
            $lock->release();
        }
    }

    private function createAuthToken(User $user): string
    {
        // 코어 로그인(AuthService::login)과 동일한 만료 정책을 따른다.
        $lifetime = (int) g7_core_settings('security.auth_token_lifetime', 30);
        $expiresAt = $lifetime === 0 ? null : now()->addMinutes($lifetime);

        return $user->createToken('auth-token', ['*'], $expiresAt)->plainTextToken;
    }

    public function createLinkNonce(User $user, string $provider): string
    {
        $nonce = Str::random(48);

        SocialLoginLinkNonce::create([
            'nonce' => $nonce,
            'user_id' => $user->id,
            'provider' => $provider,
            'expires_at' => now()->addMinutes(self::LINK_NONCE_TTL_MINUTES),
        ]);

        return $nonce;
    }

    /**
     * nonce 를 1회성으로 소비하고 대상 user_id 를 반환한다. 만료/이미사용/불일치 시 null.
     *
     * 소비는 조건부 UPDATE 한 번으로 한다 — 조회 후 저장하는 방식은 같은 nonce 로 콜백이
     * 동시에 두 번 들어오면 양쪽 다 통과할 수 있다. `used_at IS NULL` 을 UPDATE 의 조건에
     * 두면 영향 행 수가 1인 쪽만 소비에 성공하고 나머지는 0 을 받는다.
     */
    public function consumeLinkNonce(string $nonce, string $provider): ?int
    {
        /** @var SocialLoginLinkNonce|null $record */
        $record = SocialLoginLinkNonce::where('nonce', $nonce)
            ->where('provider', $provider)
            ->whereNull('used_at')
            ->where('expires_at', '>=', now())
            ->first();

        if (! $record) {
            return null;
        }

        $affected = SocialLoginLinkNonce::where('id', $record->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        return $affected === 1 ? $record->user_id : null;
    }

    /**
     * 제공자가 인증했다고 명시한 이메일만 돌려준다. 그 외(미인증·키 없음·알 수 없는 제공자)는 null.
     *
     * - kakao: `kakao_account.is_email_valid` 와 `is_email_verified` 가 둘 다 true
     * - google: `email_verified` 가 true(bool) 또는 "true"(string)
     * - naver: 별도의 "이메일 인증됨" 플래그를 API가 주지 않는다. 다만 네이버는 가입/이메일
     *   변경 시 자체적으로 이메일 소유를 확인시키며, 프로필 조회 응답의 `response.email`은
     *   그 검증된 주소 그대로다(카카오의 "부가 이메일"처럼 별도 미인증 상태가 없다). 그래서
     *   `response.email`이 비어있지 않으면 인증된 것으로 간주한다. 이 판단이 서비스 정책과
     *   맞지 않으면 아래 'naver' 분기를 `default => false`로 바꿔 자동연동을 끄면 된다.
     *
     * socialiteproviders/kakao 는 매핑 단계에서 같은 판정을 이미 하지만, 그 동작에 기대지 않고
     * raw 응답을 직접 엄격하게 확인한다.
     */
    public function providerVerifiedEmail(string $provider, SocialiteUser $socialUser): ?string
    {
        $email = $socialUser->getEmail();

        if (! is_string($email) || $email === '') {
            return null;
        }

        $raw = method_exists($socialUser, 'getRaw') ? $socialUser->getRaw() : [];

        if (! is_array($raw)) {
            return null;
        }

        $verified = match ($provider) {
            'kakao' => Arr::get($raw, 'kakao_account.is_email_valid') === true
                && Arr::get($raw, 'kakao_account.is_email_verified') === true,
            'google' => in_array(Arr::get($raw, 'email_verified'), [true, 'true'], true),
            'naver' => is_string(Arr::get($raw, 'response.email')) && Arr::get($raw, 'response.email') !== '',
            default => false,
        };

        return $verified ? $email : null;
    }

    /**
     * 로그인 콜백 처리 — 기존 연동 매칭 → 이메일 자동 연동 → 신규가입 순으로 판정한다.
     *
     * 이메일은 제공자가 인증한 것만 쓴다(`providerVerifiedEmail`). 미인증 이메일은 이메일이
     * 없는 것으로 취급하므로 기존 회원과 매칭하지 않고, 신규 가입은 대체 이메일로 만든다.
     *
     * @return array{user: User, auto_linked: bool}
     */
    public function handleLogin(string $provider, SocialiteUser $socialUser): array
    {
        $providerUserId = (string) $socialUser->getId();
        $email = $this->providerVerifiedEmail($provider, $socialUser);

        $existing = SocialAccount::where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($existing) {
            return ['user' => $existing->user, 'auto_linked' => false];
        }

        if ($email) {
            /** @var User|null $matchedUser */
            $matchedUser = User::where('email', $email)->first();

            if ($matchedUser && $matchedUser->email_verified_at !== null) {
                $account = $this->linkAccount($matchedUser, $provider, $providerUserId, $email);

                HookManager::doAction(self::IDENTIFIER.'.account.auto_linked', $account);

                return ['user' => $matchedUser, 'auto_linked' => true];
            }

            if ($matchedUser) {
                // 이메일은 같지만 인증되지 않은 기존 계정 — 계정 탈취 벡터가 될 수 있어
                // 자동 연동하지 않는다. 본인이면 이메일/비밀번호로 로그인 후 수동 연동한다.
                throw new \RuntimeException('email_exists_unverified');
            }
        }

        $newUser = DB::transaction(function () use ($socialUser, $email) {
            $user = User::create([
                'name' => $socialUser->getName() ?: $socialUser->getNickname() ?: 'User',
                'email' => $email ?: $this->placeholderEmail(),
                'password' => Hash::make(Str::random(40)),
                'language' => app()->getLocale() ?: 'ko',
                'status' => 'active',
            ]);

            // email_verified_at 은 User::$fillable 에 없어 mass assignment 로 설정되지
            // 않는다 — 직접 대입 후 저장해야 실제로 반영된다. $email 은 제공자가 인증했다고
            // 명시한 경우에만 값이 있으므로 즉시 인증 처리한다(추후 다른 제공자로
            // 같은 이메일 자동 연동을 받으려면 이 값이 실제로 NULL 이 아니어야 한다).
            if ($email) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            SocialLoginUserFlag::create([
                'user_id' => $user->id,
                'has_real_password' => false,
            ]);

            // 코어 회원가입과 동일한 기본 역할 부여 — 없으면 로그인 사용자는 guest 권한으로도
            // 폴백되지 않아(AuthServiceProvider::checkPermission → Gate) 게시판 읽기조차 막힌다.
            // 자동연동(기존 계정) 경로는 이미 역할이 있으므로 여기(신규 생성)에서만 부여한다.
            $defaultRole = $this->roleRepository->findByIdentifier(self::DEFAULT_ROLE_IDENTIFIER);
            if ($defaultRole) {
                $user->roles()->sync([$defaultRole->id]);
                $user->flushPermissionCaches();
            }

            return $user;
        });

        $this->linkAccount($newUser, $provider, $providerUserId, $email);

        return ['user' => $newUser, 'auto_linked' => false];
    }

    public function linkAccount(User $user, string $provider, string $providerUserId, ?string $email): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
            'provider_email' => $email,
            'linked_at' => now(),
        ]);
    }

    public function isProviderIdTakenByAnotherUser(string $provider, string $providerUserId, int $exceptUserId): bool
    {
        return SocialAccount::where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->where('user_id', '!=', $exceptUserId)
            ->exists();
    }

    /**
     * 해제 가능 여부 — "실사용자가 아는 비밀번호"가 없고 다른 연동도 없으면
     * 로그인 수단이 완전히 사라지므로 차단한다.
     *
     * `g7_social_login_user_flags` 에 행이 없으면(=이 플러그인이 만든 소셜 전용
     * 가입자가 아니면) 항상 실비밀번호가 있다고 간주한다 — 코어 회원가입은
     * 항상 비밀번호를 받기 때문이다. 행이 있고 `has_real_password=false` 인
     * 상태에서만 "다른 연동 존재 여부"를 추가로 확인한다. 비밀번호 변경/재설정
     * 시 `PasswordChangedFlagListener` 가 이 행을 지워 true 상태로 전환시킨다.
     */
    public function canUnlink(User $user, string $provider): bool
    {
        $flag = SocialLoginUserFlag::where('user_id', $user->id)->first();
        $hasRealPassword = $flag === null || $flag->has_real_password;

        if ($hasRealPassword) {
            return true;
        }

        return SocialAccount::where('user_id', $user->id)
            ->where('provider', '!=', $provider)
            ->exists();
    }

    private function placeholderEmail(): string
    {
        return 'social-'.Str::random(20).'@no-email.invalid';
    }
}
