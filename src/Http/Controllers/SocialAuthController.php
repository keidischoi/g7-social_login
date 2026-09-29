<?php

namespace Plugins\G7\SocialLogin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Plugins\G7\SocialLogin\Models\SocialAccount;
use Plugins\G7\SocialLogin\Services\SocialAuthService;
use Plugins\G7\SocialLogin\Support\Providers;
use Plugins\G7\SocialLogin\Support\RedirectPath;

class SocialAuthController extends Controller
{
    private const SESSION_REDIRECT = 'g7sl.redirect';

    private const SESSION_LINK_NONCE = 'g7sl.link_nonce';

    public function __construct(private readonly SocialAuthService $service) {}

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        try {
            $driver = $this->service->driverFor($provider);
        } catch (\RuntimeException $e) {
            return $this->frontendLoginError('provider_unavailable');
        } catch (\Throwable $e) {
            // provider 조립 자체의 실패(클래스/바인딩 누락 등)도 500 대신 로그인 화면 오류로 돌려보낸다.
            Log::error('g7-social_login: OAuth provider 조립 실패', ['provider' => $provider, 'exception' => get_class($e), 'error' => $e->getMessage()]);

            return $this->frontendLoginError('provider_unavailable');
        }

        $request->session()->put(self::SESSION_REDIRECT, RedirectPath::sanitize($request->query('redirect')));

        // 연동 대상 회원은 쿼리에서 읽지 않는다. `linkPrepare`(auth:sanctum)가 이미 세션에
        // 심어둔 값만 쓴다 — 쿼리로 받으면 남이 만든 링크로 대상을 지정할 수 있게 된다.
        // 세션에 값이 없으면 그대로 로그인 흐름이다.

        return $driver->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        // 진입 시 이미 검증했지만 세션 값을 그대로 믿지 않고 콜백에서도 다시 검증한다.
        $redirectAfter = RedirectPath::sanitize($request->session()->pull(self::SESSION_REDIRECT));
        $linkNonce = $request->session()->pull(self::SESSION_LINK_NONCE);

        // 설정에서 끈(또는 키가 비어 있는) 제공자의 콜백은 제공자에게 아무 요청도 보내지 않고 거절한다.
        if (! $this->isAvailable($provider)) {
            return $linkNonce
                ? $this->frontendProfileError('provider_unavailable')
                : $this->frontendLoginError('provider_unavailable');
        }

        try {
            $driver = $this->service->driverFor($provider);
            $socialUser = $driver->user();
        } catch (\Throwable $e) {
            Log::warning('g7-social_login: OAuth 콜백 실패', ['provider' => $provider, 'error' => $e->getMessage()]);

            return $linkNonce
                ? $this->frontendProfileError('oauth_failed')
                : $this->frontendLoginError('oauth_failed');
        }

        if (is_string($linkNonce) && $linkNonce !== '') {
            return $this->handleLinkCallback($provider, $linkNonce, (string) $socialUser->getId(), $socialUser->getEmail());
        }

        try {
            $result = $this->service->handleLogin($provider, $socialUser);
        } catch (\RuntimeException $e) {
            $key = $e->getMessage() === 'email_exists_unverified' ? 'email_exists_unverified' : 'login_failed';

            return $this->frontendLoginError($key);
        }

        // 돌아갈 경로는 URL 에 싣지 않고 교환코드 캐시에 담는다 — 프론트가 쿼리스트링을
        // 읽어 이동 경로를 정하면 검증을 우회한 오픈 리다이렉트 표면이 생긴다.
        $code = $this->service->issueExchangeCode($result['user'], $redirectAfter);

        return redirect('/login?'.http_build_query([
            'social_exchange' => $code,
        ]));
    }

    /**
     * Apple 의 form_post 콜백(POST)을 같은 주소의 GET 콜백으로 넘긴다.
     *
     * Apple 은 이름·이메일 scope 를 요청하면 콜백을 다른 사이트(appleid.apple.com)에서 보내는
     * POST 로만 준다. 이 교차 사이트 POST 에는 SameSite=Lax 세션 쿠키가 실리지 않아 state·PKCE·
     * 연동 대상 같은 세션 값을 읽을 수 없다. 303 으로 GET 콜백에 넘기면 브라우저의 최상위 GET
     * 이동이 되어 세션 쿠키가 실리고, 이후 처리는 다른 제공자와 똑같은 `callback()` 이 맡는다.
     * 여기서는 아무 것도 검증·저장하지 않는다(state 검증은 GET 콜백에서 세션과 대조).
     */
    public function appleFormPost(Request $request): RedirectResponse
    {
        if (! $this->isAvailable('apple')) {
            return $this->frontendLoginError('provider_unavailable');
        }

        $params = array_filter([
            'code' => $request->input('code'),
            'state' => $request->input('state'),
            'user' => $request->input('user'),
            'error' => $request->input('error'),
        ], fn ($v) => is_string($v) && $v !== '');

        return redirect()->to(
            url('/api/plugins/'.SocialAuthService::IDENTIFIER.'/apple/callback').'?'.http_build_query($params),
            303
        );
    }

    private function isAvailable(string $provider): bool
    {
        return $this->service->isEnabled($provider) && $this->service->isConfigured($provider);
    }

    private function handleLinkCallback(string $provider, string $nonce, string $providerUserId, ?string $email): RedirectResponse
    {
        $userId = $this->service->consumeLinkNonce($nonce, $provider);

        if ($userId === null) {
            return $this->frontendProfileError('link_expired');
        }

        if ($this->service->isProviderIdTakenByAnotherUser($provider, $providerUserId, $userId)) {
            return $this->frontendProfileError('link_taken');
        }

        $user = User::find($userId);

        if (! $user) {
            return $this->frontendProfileError('link_expired');
        }

        $this->service->linkAccount($user, $provider, $providerUserId, $email);

        return redirect('/mypage/profile?social_link=success');
    }

    public function exchange(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string']]);

        $result = $this->service->consumeExchangeCode($validated['code']);

        if (! $result) {
            return response()->json(['message' => __('auth.login_failed')], 422);
        }

        $user = $result['user'];
        $user->load(['roles.permissions']);

        return response()->json([
            'message' => __('auth.login_success'),
            'data' => (new UserResource($user))->toAuthArray($request),
            'token' => $result['token'],
            'redirect_path' => RedirectPath::sanitize($result['redirect_path']),
        ]);
    }

    public function accounts(Request $request): JsonResponse
    {
        $linked = SocialAccount::where('user_id', $request->user()->id)
            ->pluck('provider')
            ->all();

        return response()->json([
            'data' => [
                'linked_providers' => $linked,
            ],
        ]);
    }

    /**
     * 설정 화면에 보여 줄 제공자별 Callback URL(Redirect URI).
     *
     * 실제 OAuth 요청에 쓰는 것과 같은 `url()` 로 만든다 — 관리자가 콘솔에 등록할 값과
     * 인가 요청의 redirect_uri 가 한 글자라도 다르면 제공자가 거부하기 때문이다.
     */
    public function callbackUrls(): JsonResponse
    {
        $urls = [];
        foreach (Providers::ORDER as $provider) {
            $urls[$provider] = $this->service->callbackUrl($provider);
        }

        return response()->json(['data' => ['urls' => $urls, 'consoles' => Providers::CONSOLES]]);
    }

    public function linkPrepare(Request $request, string $provider): JsonResponse
    {
        if (! $this->service->isEnabled($provider)) {
            return response()->json(['message' => __('common.not_found')], 404);
        }

        $nonce = $this->service->createLinkNonce($request->user(), $provider);

        // nonce 는 URL 이 아니라 세션에 싣는다. 이 라우트는 auth:sanctum 을 통과한 요청만
        // 닿을 수 있으므로, 연동 대상 회원을 정하는 값은 본인 브라우저의 세션에만 남는다.
        $request->session()->put(self::SESSION_LINK_NONCE, $nonce);

        return response()->json([
            'data' => [
                'redirect_url' => '/api/plugins/'.SocialAuthService::IDENTIFIER."/{$provider}/redirect",
            ],
        ]);
    }

    public function unlink(Request $request, string $provider): JsonResponse
    {
        $user = $request->user();

        if (! $this->service->canUnlink($user, $provider)) {
            return response()->json(['message' => __('g7-social_login::messages.profile.unlink_blocked_no_password')], 422);
        }

        SocialAccount::where('user_id', $user->id)
            ->where('provider', $provider)
            ->delete();

        return response()->json(['message' => __('g7-social_login::messages.profile.unlink_success')]);
    }

    private function frontendLoginError(string $key): RedirectResponse
    {
        return redirect('/login?'.http_build_query(['social_error' => $key]));
    }

    private function frontendProfileError(string $key): RedirectResponse
    {
        return redirect('/mypage/profile?'.http_build_query(['social_link' => 'error', 'social_error' => $key]));
    }
}
