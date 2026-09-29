<?php

namespace Plugins\G7\SocialLogin\Support;

use Illuminate\Support\Facades\Lang;

/**
 * 설정 저장 시 "켠 제공자는 키 항목이 모두 채워져 있어야 한다"를 강제한다.
 *
 * 로그인 화면은 `{p}_enabled` 만 보고 버튼을 그린다(자격증명은 프론트에 노출하지 않는다).
 * 그래서 키 없이 켜 두면 방문자에게 누르면 실패하는 버튼이 보인다 — 저장 단계에서 막는다.
 *
 * 민감 필드(client_secret, private_key)는 설정 화면이 마스크(••••••••)로 되돌려 보내므로
 * required 를 통과하고, 코어가 저장 시 기존 값을 유지한다.
 *
 * 훅 구독은 LoginPageWidgetListener 가 맡는다(이유는 그 클래스의 getSubscribedHooks 주석 참고).
 */
class SettingsValidation
{
    private const IDENTIFIER = 'g7-social_login';

    private const FIELD_LABELS = [
        'client_id' => 'Client ID',
        'client_secret' => 'Client Secret',
        'team_id' => 'Team ID',
        'key_id' => 'Key ID',
        'private_key' => 'Private Key (.p8)',
    ];

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function addEnabledProviderRules(array $rules, string $identifier = '', array $input = []): array
    {
        if ($identifier !== self::IDENTIFIER) {
            return $rules;
        }

        $labels = [];

        foreach (Providers::ORDER as $provider) {
            $enabled = filter_var($input["{$provider}_enabled"] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($enabled !== true) {
                continue;
            }

            foreach (Providers::credentialFields($provider) as $suffix) {
                $field = "{$provider}_{$suffix}";
                $fieldRules = array_values(array_filter((array) ($rules[$field] ?? ['string']), static fn ($r) => $r !== 'nullable'));
                if (! in_array('required', $fieldRules, true)) {
                    array_unshift($fieldRules, 'required');
                }
                $rules[$field] = $fieldRules;
                $labels[$field] = "g7-social_login.settings.{$field}.label";
            }
        }

        if ($labels !== []) {
            $this->registerAttributeLabels(array_keys($labels));
        }

        return $rules;
    }

    /**
     * 검증 오류 문구의 항목 이름을 "apple team id" 같은 원시 키 대신 제공자 이름이 붙은 표시명으로.
     *
     * @param  list<string>  $fields
     */
    private function registerAttributeLabels(array $fields): void
    {
        $locale = app()->getLocale();
        // addLines 전에 validation 그룹을 먼저 로드해 둔다(빈 그룹 조기 캐시 방지 — 코어 KCP 플러그인과 같은 이유).
        Lang::get('validation.required', [], $locale);

        $lines = [];
        foreach ($fields as $field) {
            [$provider, $suffix] = explode('_', $field, 2);
            $lines["validation.attributes.{$field}"] = Providers::name($provider, $locale).' '.(self::FIELD_LABELS[$suffix] ?? $suffix);
        }

        Lang::addLines($lines, $locale);
    }
}
