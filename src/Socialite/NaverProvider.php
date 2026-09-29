<?php

namespace Plugins\G7\SocialLogin\Socialite;

use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User;

/**
 * 네이버 로그인(OAuth2).
 *
 * 1.1.0 에서는 vendor/socialiteproviders/naver 아래에 손으로 넣어 두었던 클래스다. vendor 는
 * composer.lock 으로 재현돼야 하는데(사이트에서 composer install 이 돌면 Packagist 의
 * socialiteproviders/naver 로 덮여 아래 state 처리 등이 사라진다) 그 보장이 없어서,
 * 1.2.0 에서 다른 자체 제공자(Apple/LINE/Microsoft)와 함께 플러그인 소스로 옮겼다.
 */
class NaverProvider extends AbstractProvider
{

    protected $scopeSeparator = ' ';

    /**
     * Get the authentication URL for the provider.
     *
     * @param  string  $state
     * @return string
     */
    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase('https://nid.naver.com/oauth2.0/authorize', $state);
    }

    /**
     * Get the token URL for the provider.
     *
     * @return string
     */
    protected function getTokenUrl()
    {
        return 'https://nid.naver.com/oauth2.0/token';
    }

    /**
     * 네이버는 인가 코드 발급 때 쓴 state 값을 토큰 교환 요청에도 그대로 되돌려줄 것을
     * 요구한다(문서 명시 필수 파라미터).
     *
     * 부모 AbstractProvider::getState() 는 "저장된 state 를 읽어오는" 게 아니라 매번
     * 새로운 난수를 생성하는 메서드라서(리다이렉트 URL 생성용) 여기서 쓰면 인가 시
     * 네이버로 보낸 값과 다른 값이 되어 네이버가 거부한다(실측: 콜백에서 500).
     * 콜백 요청은 네이버가 되돌려준 그 state 를 그대로 쿼리 파라미터로 갖고 있으므로
     * 그걸 그대로 되돌려 보낸다.
     *
     * @param  string  $code
     * @return array
     */
    protected function getTokenFields($code)
    {
        return array_merge(parent::getTokenFields($code), [
            'state' => $this->request->input('state'),
        ]);
    }

    /**
     * Get the raw user for the given access token.
     *
     * @param  string  $token
     * @return array
     */
    protected function getUserByToken($token)
    {
        $response = $this->getHttpClient()->get('https://openapi.naver.com/v1/nid/me', [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * Map the raw user array to a Socialite User instance.
     *
     * 네이버 응답은 실제 프로필이 `response` 서브키에 감싸여 온다:
     * { "resultcode": "00", "message": "success", "response": { "id": ..., "email": ..., ... } }
     *
     * @param  array  $user
     * @return \Laravel\Socialite\Two\User
     */
    protected function mapUserToObject(array $user)
    {
        $profile = $user['response'] ?? [];

        return (new User())->setRaw($user)->map([
            'id' => $profile['id'] ?? null,
            'nickname' => $profile['nickname'] ?? null,
            'name' => $profile['name'] ?? ($profile['nickname'] ?? null),
            'email' => $profile['email'] ?? null,
            'avatar' => $profile['profile_image'] ?? null,
        ]);
    }
}
