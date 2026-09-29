<?php

namespace Plugins\G7\SocialLogin\Support;

/**
 * 지원하는 소셜 로그인 제공자 목록(단일 기준).
 *
 * 순서가 곧 로그인 화면 버튼 순서다. 라우트 제약, 설정 스키마, 로그인/마이페이지 위젯,
 * 알림 제공자명이 모두 이 목록을 읽는다 — 제공자를 추가할 때 여기 한 곳만 늘리면 되도록
 * 흩어져 있던 `['kakao', 'google', 'naver']` 리터럴을 모았다.
 */
final class Providers
{
    /** 로그인 화면 버튼 순서 */
    public const ORDER = ['naver', 'kakao', 'google', 'apple', 'facebook', 'x', 'line', 'microsoft', 'github'];

    /** 로그인 화면 첫 줄에 보이는 최대 개수 — 나머지는 "그 외 로그인" 접이식 영역으로 간다 */
    public const MAIN_ROW_LIMIT = 3;

    /**
     * 제공자별 자격증명 설정 키(접미사). 모두 채워져 있어야 "설정됨"으로 본다.
     *
     * @var array<string, list<string>>
     */
    private const CREDENTIAL_FIELDS = [
        'apple' => ['client_id', 'team_id', 'key_id', 'private_key'],
    ];

    /** 저장 시 암호화·화면 마스킹 대상 필드(접미사) */
    private const SENSITIVE_FIELDS = ['client_secret', 'private_key'];

    /** @var array<string, array{ko: string, en: string}> */
    private const NAMES = [
        'naver' => ['ko' => '네이버', 'en' => 'Naver'],
        'kakao' => ['ko' => '카카오', 'en' => 'Kakao'],
        'google' => ['ko' => '구글', 'en' => 'Google'],
        'apple' => ['ko' => '애플', 'en' => 'Apple'],
        'facebook' => ['ko' => '페이스북', 'en' => 'Facebook'],
        'x' => ['ko' => 'X', 'en' => 'X'],
        'line' => ['ko' => '라인', 'en' => 'LINE'],
        'microsoft' => ['ko' => '마이크로소프트', 'en' => 'Microsoft'],
        'github' => ['ko' => '깃허브', 'en' => 'GitHub'],
    ];

    /** 개발자 콘솔 주소(설정 화면·README 안내용) */
    public const CONSOLES = [
        'naver' => 'https://developers.naver.com/apps/',
        'kakao' => 'https://developers.kakao.com/console/app',
        'google' => 'https://console.cloud.google.com/apis/credentials',
        'apple' => 'https://developer.apple.com/account/resources/identifiers/list/serviceId',
        'facebook' => 'https://developers.facebook.com/apps/',
        'x' => 'https://developer.x.com/en/portal/dashboard',
        'line' => 'https://developers.line.biz/console/',
        'microsoft' => 'https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade',
        'github' => 'https://github.com/settings/developers',
    ];

    public static function isSupported(string $provider): bool
    {
        return in_array($provider, self::ORDER, true);
    }

    /**
     * @return list<string>
     */
    public static function credentialFields(string $provider): array
    {
        return self::CREDENTIAL_FIELDS[$provider] ?? ['client_id', 'client_secret'];
    }

    public static function isSensitiveField(string $suffix): bool
    {
        return in_array($suffix, self::SENSITIVE_FIELDS, true);
    }

    public static function name(string $provider, string $locale = 'ko'): string
    {
        return self::NAMES[$provider][$locale === 'ko' ? 'ko' : 'en'] ?? $provider;
    }

    /**
     * 설정 스키마(저장 검증·마스킹용). 제공자마다 `{p}_enabled` 토글 + 자격증명 필드.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function settingsSchema(): array
    {
        $schema = [];

        foreach (self::ORDER as $provider) {
            $schema["{$provider}_enabled"] = ['type' => 'boolean'];

            foreach (self::credentialFields($provider) as $suffix) {
                $schema["{$provider}_{$suffix}"] = self::isSensitiveField($suffix)
                    ? ['type' => 'string', 'sensitive' => true]
                    : ['type' => 'string'];
            }
        }

        return $schema;
    }

    /**
     * 기본값 — 모든 제공자는 꺼진 상태로 시작한다.
     *
     * @return array<string, mixed>
     */
    public static function defaultValues(): array
    {
        $defaults = [];

        foreach (self::settingsSchema() as $key => $config) {
            $defaults[$key] = $config['type'] === 'boolean' ? false : '';
        }

        return $defaults;
    }

    /** OAuth 콜백 경로(사이트 주소 뒤에 붙는다) */
    public static function callbackPath(string $provider): string
    {
        return "/api/plugins/g7-social_login/{$provider}/callback";
    }
}
