<?php

namespace Plugins\G7\SocialLogin\Socialite;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Arr;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User;

/**
 * Sign in with Apple (OAuth2 + OpenID Connect).
 *
 * - 인가 요청: `https://appleid.apple.com/auth/authorize` (scope `name email` 을 요청하면
 *   Apple 이 `response_mode=form_post` 를 강제한다 — 콜백이 POST 로 온다).
 * - client_secret: 고정 문자열이 아니라 Apple 개발자 계정의 .p8 개인키로 매 요청 서명하는
 *   ES256 JWT 다({@see self::makeClientSecret()}).
 * - 사용자 정보: 별도 프로필 API 가 없다. 토큰 응답의 `id_token`(JWT)을 Apple 공개키(JWKS)로
 *   검증해 `sub`(고유 ID)·`email`·`email_verified` 를 얻는다.
 * - 이름: 최초 1회 인가 때만 콜백 폼의 `user` 필드(JSON)로 온다. 이후 로그인에는 없다.
 *   이메일은 `id_token` 에 매번 들어오지만, 사용자가 "이메일 가리기"를 고르면
 *   `@privaterelay.appleid.com` 릴레이 주소다.
 *
 * 외부 패키지(socialiteproviders/apple, lcobucci/jwt)를 추가하지 않고 이미 vendor 에 있는
 * firebase/php-jwt(laravel/socialite 의 의존성)만 쓴다.
 */
class AppleProvider extends AbstractProvider
{
    public const ISSUER = 'https://appleid.apple.com';

    public const JWKS_URL = 'https://appleid.apple.com/auth/keys';

    protected $scopes = ['name', 'email'];

    protected $scopeSeparator = ' ';

    /** 직전 토큰 응답 — 프로필 API 가 없어 id_token 을 여기서 꺼낸다 */
    private ?array $tokenResponse = null;

    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase(self::ISSUER.'/auth/authorize', $state);
    }

    protected function getCodeFields($state = null)
    {
        return array_merge(parent::getCodeFields($state), [
            'response_mode' => 'form_post',
        ]);
    }

    protected function getTokenUrl()
    {
        return self::ISSUER.'/auth/token';
    }

    public function getAccessTokenResponse($code)
    {
        $this->tokenResponse = parent::getAccessTokenResponse($code);

        return $this->tokenResponse;
    }

    /**
     * Apple 은 프로필 API 가 없다 — 토큰 응답의 id_token 을 검증해 클레임을 돌려준다.
     *
     * @param  string  $token  (사용하지 않음)
     */
    protected function getUserByToken($token)
    {
        $idToken = Arr::get($this->tokenResponse ?? [], 'id_token');

        if (! is_string($idToken) || $idToken === '') {
            throw new \RuntimeException('apple_id_token_missing');
        }

        $claims = $this->verifyIdToken($idToken);

        // 최초 인가 때만 오는 이름(JSON) — 위조돼도 이름 표시에만 쓰이고 ID·이메일은 서명된 id_token 에서만 읽는다.
        $userField = $this->request->input('user');
        if (is_string($userField) && $userField !== '') {
            $decoded = json_decode($userField, true);
            if (is_array($decoded)) {
                $claims['_user'] = $decoded;
            }
        }

        return $claims;
    }

    /**
     * id_token 서명·발급자·수신자·만료를 검증하고 클레임을 배열로 돌려준다.
     *
     * @return array<string, mixed>
     */
    public function verifyIdToken(string $idToken): array
    {
        $response = $this->getHttpClient()->get(self::JWKS_URL, [
            RequestOptions::HEADERS => ['Accept' => 'application/json'],
        ]);

        $jwks = json_decode((string) $response->getBody(), true);

        if (! is_array($jwks) || empty($jwks['keys'])) {
            throw new \RuntimeException('apple_jwks_unavailable');
        }

        $claims = (array) JWT::decode($idToken, JWK::parseKeySet($jwks, 'RS256'));

        if (($claims['iss'] ?? null) !== self::ISSUER) {
            throw new \RuntimeException('apple_id_token_invalid_issuer');
        }

        $aud = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if (! in_array($this->clientId, $audiences, true)) {
            throw new \RuntimeException('apple_id_token_invalid_audience');
        }

        if (empty($claims['sub'])) {
            throw new \RuntimeException('apple_id_token_missing_sub');
        }

        return $claims;
    }

    protected function mapUserToObject(array $user)
    {
        $first = Arr::get($user, '_user.name.firstName');
        $last = Arr::get($user, '_user.name.lastName');
        $name = trim(implode(' ', array_filter([$first, $last], fn ($v) => is_string($v) && $v !== '')));

        return (new User)->setRaw($user)->map([
            'id' => (string) $user['sub'],
            'nickname' => null,
            'name' => $name !== '' ? $name : null,
            'email' => $user['email'] ?? null,
            'avatar' => null,
        ]);
    }

    /**
     * Apple client_secret(ES256 JWT)을 만든다.
     *
     * @param  string  $clientId  Services ID (예: com.example.web)
     * @param  string  $teamId  Apple Developer Team ID (10자리)
     * @param  string  $keyId  Sign in with Apple 키의 Key ID (10자리)
     * @param  string  $privateKey  .p8 파일 내용(PEM). 머리/꼬리줄이 없거나 줄바꿈이 `\n` 문자열이어도 받는다.
     * @param  int  $ttl  유효시간(초). Apple 상한은 6개월이다.
     */
    public static function makeClientSecret(string $clientId, string $teamId, string $keyId, string $privateKey, int $ttl = 3600): string
    {
        $now = time();

        return JWT::encode([
            'iss' => $teamId,
            'iat' => $now,
            'exp' => $now + $ttl,
            'aud' => self::ISSUER,
            'sub' => $clientId,
        ], self::normalizePrivateKey($privateKey), 'ES256', $keyId);
    }

    public static function normalizePrivateKey(string $key): string
    {
        $key = trim(str_replace(['\\r\\n', '\\n', "\r\n", "\r"], "\n", $key));

        if (! str_contains($key, '-----BEGIN')) {
            $body = preg_replace('/\s+/', '', $key);
            $key = "-----BEGIN PRIVATE KEY-----\n".chunk_split($body, 64, "\n")."-----END PRIVATE KEY-----";
        }

        return $key."\n";
    }
}
