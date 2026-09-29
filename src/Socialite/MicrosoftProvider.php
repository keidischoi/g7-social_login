<?php

namespace Plugins\G7\SocialLogin\Socialite;

use GuzzleHttp\RequestOptions;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User;

/**
 * Microsoft 계정 / Microsoft Entra ID (v2.0 엔드포인트, `common` 테넌트).
 *
 * `common` 이라 개인 Microsoft 계정(outlook.com 등)과 회사·학교(Entra ID) 계정을 모두 받는다.
 * 앱 등록 시 "지원되는 계정 유형"을 "모든 조직 디렉터리의 계정 및 개인 Microsoft 계정"으로
 * 골라야 한다.
 *
 * 프로필은 Microsoft Graph `/v1.0/me` 에서 읽는다. `mail`/`userPrincipalName` 은 테넌트
 * 관리자가 임의로 정할 수 있는 값이라(소유 확인 없음, "nOAuth" 계정 탈취 사례) 이 플러그인은
 * Microsoft 이메일을 "인증된 이메일"로 보지 않는다 — 기존 회원 자동 연동에 쓰지 않는다.
 */
class MicrosoftProvider extends AbstractProvider
{
    public const TENANT = 'common';

    protected $scopes = ['openid', 'profile', 'email', 'User.Read'];

    protected $scopeSeparator = ' ';

    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase('https://login.microsoftonline.com/'.self::TENANT.'/oauth2/v2.0/authorize', $state);
    }

    protected function getTokenUrl()
    {
        return 'https://login.microsoftonline.com/'.self::TENANT.'/oauth2/v2.0/token';
    }

    protected function getUserByToken($token)
    {
        $response = $this->getHttpClient()->get('https://graph.microsoft.com/v1.0/me', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer '.$token,
                'Accept' => 'application/json',
            ],
            RequestOptions::QUERY => ['$select' => 'id,displayName,givenName,surname,mail,userPrincipalName'],
        ]);

        return json_decode((string) $response->getBody(), true) ?: [];
    }

    protected function mapUserToObject(array $user)
    {
        $email = $user['mail'] ?? null;
        if (! is_string($email) || $email === '') {
            $upn = $user['userPrincipalName'] ?? null;
            $email = is_string($upn) && str_contains($upn, '@') && ! str_contains($upn, '#EXT#') ? $upn : null;
        }

        return (new User)->setRaw($user)->map([
            'id' => $user['id'] ?? null,
            'nickname' => null,
            'name' => $user['displayName'] ?? null,
            'email' => $email,
            'avatar' => null,
        ]);
    }
}
