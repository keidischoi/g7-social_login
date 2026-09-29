<?php

namespace Plugins\G7\SocialLogin\Socialite;

use GuzzleHttp\RequestOptions;
use Illuminate\Support\Arr;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User;

/**
 * LINE Login v2.1 (OAuth2 + OpenID Connect).
 *
 * - 인가: `https://access.line.me/oauth2/v2.1/authorize` (scope `profile openid email`)
 * - 토큰: `https://api.line.me/oauth2/v2.1/token`
 * - 프로필: `https://api.line.me/v2/profile` (userId, displayName, pictureUrl)
 * - 이메일: 프로필 API 에는 없고 id_token 에만 있다. id_token 은 LINE 의 검증 API
 *   (`/oauth2/v2.1/verify`)로 서명·수신자(client_id)를 확인한 뒤 email 클레임만 쓴다.
 *   이메일 권한은 LINE Developers 콘솔에서 별도 신청·승인이 필요하다(없으면 email 이 비어 온다).
 */
class LineProvider extends AbstractProvider
{
    protected $scopes = ['profile', 'openid', 'email'];

    protected $scopeSeparator = ' ';

    private ?array $tokenResponse = null;

    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase('https://access.line.me/oauth2/v2.1/authorize', $state);
    }

    protected function getTokenUrl()
    {
        return 'https://api.line.me/oauth2/v2.1/token';
    }

    public function getAccessTokenResponse($code)
    {
        $this->tokenResponse = parent::getAccessTokenResponse($code);

        return $this->tokenResponse;
    }

    protected function getUserByToken($token)
    {
        $response = $this->getHttpClient()->get('https://api.line.me/v2/profile', [
            RequestOptions::HEADERS => ['Authorization' => 'Bearer '.$token],
        ]);

        $profile = json_decode((string) $response->getBody(), true) ?: [];

        $idToken = Arr::get($this->tokenResponse ?? [], 'id_token');

        if (is_string($idToken) && $idToken !== '') {
            $verify = $this->getHttpClient()->post('https://api.line.me/oauth2/v2.1/verify', [
                RequestOptions::FORM_PARAMS => ['id_token' => $idToken, 'client_id' => $this->clientId],
            ]);

            $claims = json_decode((string) $verify->getBody(), true);

            // verify API 가 aud 불일치·만료·서명 오류면 400 을 돌려 Guzzle 이 예외를 던진다.
            // 그래도 sub 가 프로필 userId 와 같을 때만 이메일을 받아들인다.
            if (is_array($claims) && ($claims['sub'] ?? null) === ($profile['userId'] ?? null)) {
                $profile['id_token_claims'] = $claims;
                $profile['email'] = $claims['email'] ?? null;
            }
        }

        return $profile;
    }

    protected function mapUserToObject(array $user)
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['userId'] ?? null,
            'nickname' => $user['displayName'] ?? null,
            'name' => $user['displayName'] ?? null,
            'email' => $user['email'] ?? null,
            'avatar' => $user['pictureUrl'] ?? null,
        ]);
    }
}
