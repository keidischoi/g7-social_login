<?php

namespace Plugins\G7\SocialLogin\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Plugins\G7\SocialLogin\Support\BrandIcons;
use Plugins\G7\SocialLogin\Support\Providers;
use Plugins\G7\SocialLogin\Support\SettingsValidation;
use Plugins\G7\SocialLogin\Support\SocialLoginRateLimiters;

/**
 * 로그인 화면(`auth/login`)에 "SNS 간편 로그인" 원형 아이콘 버튼 줄 + 교환코드 처리 init_action 을
 * `core.layout_extension.after_apply` 필터로 주입한다. 로그인 화면에는
 * extension_point 가 없어(조사 결과) 코어/템플릿 파일을 건드리지 않는 이 방식이
 * 유일한 무변경 주입 경로다 (g7-forum-addon 의 board/show 위젯 주입과 동일 패턴).
 *
 * 버튼은 `_global.plugins['g7-social_login'].{provider}_enabled` 로 켜져 있을
 * 때만 보인다(플러그인 설정의 frontend_schema 노출값, 별도 API 호출 불필요).
 * 순서는 Providers::ORDER, 켜진 것 중 앞의 3개는 첫 줄에, 나머지는 "그 외 로그인"
 * 토글로 여닫는 두 번째 줄에 놓인다(토글은 켜진 제공자가 3개를 넘을 때만 보인다).
 */
class LoginPageWidgetListener implements HookListenerInterface
{
    private const WIDGET_ID = 'g7_social_login_widget';

    private const INIT_ACTION_MARKER = 'g7_social_login_exchange';

    /** "로그인 폼" 카드 Div 를 찾기 위한 구조적 시그니처(sirsoft-basic 코어, 원본 그대로) */
    private const FORM_CARD_CLASSNAME = 'w-full max-w-md p-8 bg-white dark:bg-gray-800 rounded-lg shadow';

    /** 그 안에서 "하단 링크 영역" Div 바로 앞에 버튼을 끼워 넣는다(코어 구조 앵커) */
    private const BOTTOM_LINKS_CLASSNAME = 'mt-4 flex items-center justify-between text-sm';

    public static function getSubscribedHooks(): array
    {
        return [
            'core.layout_extension.after_apply' => [
                'method' => 'injectWidget',
                'type' => 'filter',
                'priority' => 20,
            ],
            // 설정 저장 검증(켠 제공자는 키 필수)도 이 리스너가 구독한다.
            // 별도 리스너 클래스를 새로 추가하면, 1.1.x 에서 제자리 업데이트할 때 코어가 훅 캐시를
            // "업데이트 전 plugin.php"(이미 메모리에 올라간 클래스)의 리스너 목록으로 다시 굽기 때문에
            // 새 클래스가 빠진 채로 남는다(plugin:activate 로도 재생성되지 않음). 이미 등록돼 있는
            // 리스너에 붙이면 캐시 재생성 때 새 파일의 getSubscribedHooks() 가 읽혀 바로 반영된다.
            'core.plugin_settings.update_validation_rules' => [
                'method' => 'addSettingsValidationRules',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    public function handle(...$args): void {}

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function addSettingsValidationRules(array $rules, string $identifier = '', array $input = []): array
    {
        return (new SettingsValidation)->addEnabledProviderRules($rules, $identifier, $input);
    }

    public function injectWidget(array $layout, int $templateId = 0): array
    {
        if (($layout['layout_name'] ?? null) !== 'auth/login') {
            return $layout;
        }

        if (! isset($layout['components']) || ! is_array($layout['components'])) {
            return $layout;
        }

        if (! $this->treeHasNodeId($layout['components'], self::WIDGET_ID)) {
            $injected = 0;
            $layout['components'] = $this->spliceIntoFormCard($layout['components'], $injected);

            if ($injected === 0) {
                Log::error('[g7-social_login] 로그인 폼 카드 앵커를 찾지 못해 소셜 로그인 버튼을 주입하지 못했습니다. sirsoft-basic auth/login 레이아웃 구조 변경 여부 확인 필요.', [
                    'template_id' => $templateId,
                ]);
            }
        }

        $layout['initActions'] = $this->ensureExchangeInitAction($layout['initActions'] ?? []);

        return $layout;
    }

    /**
     * @param  array<int, array>  $nodes
     */
    private function spliceIntoFormCard(array $nodes, int &$injected): array
    {
        foreach ($nodes as &$node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['props']['className'] ?? null) === self::FORM_CARD_CLASSNAME && isset($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->insertBeforeBottomLinks($node['children'], $injected);

                if ($injected > 0) {
                    continue;
                }
            }

            if (isset($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->spliceIntoFormCard($node['children'], $injected);
            }
        }

        return $nodes;
    }

    private function insertBeforeBottomLinks(array $children, int &$injected): array
    {
        $anchorIndex = null;

        foreach ($children as $index => $child) {
            if (is_array($child) && ($child['props']['className'] ?? null) === self::BOTTOM_LINKS_CLASSNAME) {
                $anchorIndex = $index;
                break;
            }
        }

        if ($anchorIndex === null) {
            return $children;
        }

        array_splice($children, $anchorIndex, 0, [$this->buildWidgetNode()]);
        $injected++;

        return $children;
    }

    /** "그 외 로그인" 접이식 영역의 DOM id(aria-controls 대상) */
    private const MORE_REGION_ID = 'g7sl-more-providers';

    /** 접이식 영역 열림 상태(_local) 키 — 로그인 폼의 다른 _local 값과 겹치지 않게 접두사를 붙인다 */
    private const MORE_STATE_KEY = 'g7slMoreOpen';

    /**
     * 켜진 제공자만 순서대로 남긴 배열을 만드는 표현식(중괄호 없음).
     *
     * 켜짐 여부는 `_global.plugins['g7-social_login'].{p}_enabled`(frontend_schema 노출값)만으로
     * 판단한다 — 자격증명은 노출하지 않는다. 표시 위치(첫 줄/접이식)는 이 배열 안의 순번으로 정한다.
     */
    private function enabledListExpr(): string
    {
        $order = "['".implode("', '", Providers::ORDER)."']";

        return "{$order}.filter(p => _global.plugins?.['g7-social_login']?.[p + '_enabled'] === true)";
    }

    private function buildWidgetNode(): array
    {
        $list = $this->enabledListExpr();
        $limit = Providers::MAIN_ROW_LIMIT;
        $open = '_local?.'.self::MORE_STATE_KEY.' === true';

        $mainButtons = [];
        $moreButtons = [];
        foreach (Providers::ORDER as $provider) {
            $mainButtons[] = $this->buildProviderButton($provider, "{{{$list}.indexOf('{$provider}') > -1 && {$list}.indexOf('{$provider}') < {$limit}}}");
            $moreButtons[] = $this->buildProviderButton($provider, "{{{$list}.indexOf('{$provider}') >= {$limit}}}");
        }

        $rowClass = 'flex flex-wrap items-start justify-center gap-x-5 gap-y-4';

        return [
            'id' => self::WIDGET_ID,
            'comment' => 'g7-social_login: SNS 간편 로그인(원형 아이콘 버튼)',
            'type' => 'basic',
            'name' => 'Div',
            // 켜진 제공자가 하나도 없으면 제목·구분선까지 통째로 숨긴다.
            'if' => "{{{$list}.length > 0}}",
            'props' => ['className' => 'mt-6'],
            'children' => [
                [
                    'type' => 'basic',
                    'name' => 'P',
                    'props' => ['className' => 'mb-3 text-center text-sm text-gray-500 dark:text-gray-400'],
                    'text' => '$t:g7-social_login.login.heading',
                ],
                [
                    'comment' => '첫 줄: 켜진 제공자 중 앞의 '.$limit.'개',
                    'type' => 'basic',
                    'name' => 'Div',
                    'props' => ['className' => $rowClass],
                    'children' => $mainButtons,
                ],
                [
                    'comment' => "'그 외 로그인' 토글 — 켜진 제공자가 {$limit}개를 넘을 때만",
                    'type' => 'basic',
                    'name' => 'Div',
                    'if' => "{{{$list}.length > {$limit}}}",
                    'props' => ['className' => 'mt-3 flex justify-center'],
                    'children' => [
                        [
                            'type' => 'basic',
                            'name' => 'Button',
                            'props' => [
                                'type' => 'button',
                                'id' => 'g7sl-more-toggle',
                                'aria-controls' => self::MORE_REGION_ID,
                                'aria-expanded' => "{{{$open} ? 'true' : 'false'}}",
                                'className' => 'inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:underline cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500',
                            ],
                            'actions' => [[
                                'type' => 'click',
                                'handler' => 'setState',
                                'params' => [
                                    'target' => 'local',
                                    self::MORE_STATE_KEY => "{{!({$open})}}",
                                ],
                            ]],
                            'children' => [
                                [
                                    'type' => 'basic',
                                    'name' => 'Span',
                                    'text' => '$t:g7-social_login.login.more_toggle',
                                ],
                                [
                                    'type' => 'basic',
                                    'name' => 'Img',
                                    'props' => [
                                        'src' => BrandIcons::chevronDataUri(),
                                        'alt' => '',
                                        'aria-hidden' => 'true',
                                        'width' => 12,
                                        'height' => 12,
                                        'className' => "{{'w-3 h-3 transition-transform duration-200 ' + ({$open} ? 'rotate-180' : '')}}",
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'comment' => "'그 외 로그인' 접이식 영역 — 닫혀 있으면 invisible 이라 탭 이동·보조기기에서도 빠진다",
                    'type' => 'basic',
                    'name' => 'Div',
                    'if' => "{{{$list}.length > {$limit}}}",
                    'props' => [
                        'id' => self::MORE_REGION_ID,
                        'role' => 'region',
                        'aria-labelledby' => 'g7sl-more-toggle',
                        'className' => "{{'overflow-hidden transition-all duration-300 ease-out ' + ({$open} ? 'max-h-96 opacity-100 visible mt-4' : 'max-h-0 opacity-0 invisible')}}",
                    ],
                    'children' => [
                        [
                            'type' => 'basic',
                            'name' => 'Div',
                            'props' => ['className' => $rowClass.' pb-1'],
                            'children' => $moreButtons,
                        ],
                    ],
                ],
                [
                    'comment' => '구분선',
                    'type' => 'basic',
                    'name' => 'Div',
                    'props' => ['className' => 'mt-6 border-t border-gray-200 dark:border-gray-700'],
                ],
            ],
        ];
    }

    /**
     * 원형 아이콘 + 아래 라벨 버튼 하나.
     *
     * href 는 고정 경로다 — g7 표현식 평가기(SafeExpressionEvaluator)는 encodeURIComponent 등
     * 화이트리스트 밖 전역 함수를 호출하지 못하고, 실패 시 {{ }} 원문을 그대로 흘린다.
     */
    private function buildProviderButton(string $provider, string $if): array
    {
        return [
            'type' => 'basic',
            'name' => 'A',
            'if' => $if,
            'props' => [
                'href' => "/api/plugins/g7-social_login/{$provider}/redirect",
                'aria-label' => "\$t:g7-social_login.login.{$provider}_button",
                'title' => "\$t:g7-social_login.login.{$provider}_button",
                'data-provider' => $provider,
                'className' => 'group flex flex-col items-center gap-1.5 rounded-lg p-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 dark:ring-offset-gray-800',
            ],
            'children' => [
                [
                    'type' => 'basic',
                    'name' => 'Img',
                    'props' => [
                        'src' => BrandIcons::dataUri($provider),
                        'alt' => '',
                        'width' => 48,
                        'height' => 48,
                        'className' => 'w-12 h-12 rounded-full shadow-sm transition-transform duration-150 group-hover:scale-105',
                    ],
                ],
                [
                    'type' => 'basic',
                    'name' => 'Span',
                    'props' => ['className' => 'whitespace-nowrap text-xs text-gray-600 dark:text-gray-400 group-hover:text-gray-900'],
                    'text' => "\$t:g7-social_login.login.labels.{$provider}",
                ],
            ],
        ];
    }

    private function ensureExchangeInitAction(array $initActions): array
    {
        foreach ($initActions as $action) {
            if (($action['_marker'] ?? null) === self::INIT_ACTION_MARKER) {
                return $initActions;
            }
        }

        // sequence 로 감싸지 않고 apiCall 을 직접 둔다 — 코어 TemplateApp::executeInitActions 는
        // init action 을 ActionDefinition 으로 옮길 때 `actions` 필드를 복사하지 않아, sequence 가
        // 빈 배열로 조용히 건너뛰어져 교환 API 가 한 번도 호출되지 않았다(실측).
        $initActions[] = [
            '_marker' => self::INIT_ACTION_MARKER,
            'if' => '{{query?.social_exchange}}',
            'handler' => 'apiCall',
            'target' => '/api/plugins/g7-social_login/exchange',
            'params' => [
                'method' => 'POST',
                'body' => ['code' => '{{query.social_exchange}}'],
            ],
            'onSuccess' => [
                [
                    'handler' => 'saveToLocalStorage',
                    'params' => ['key' => 'auth_token', 'value' => '{{response.token}}'],
                ],
                [
                    // SPA navigate 가 아니라 전체 이동(window.location.assign)이어야 한다.
                    // 코어 AuthManager 의 인증 상태(isAuthenticated)는 `login` 핸들러 내부의 private
                    // establishSession 또는 부팅 시 preloadAuth 로만 켜진다. navigate 로 이동하면
                    // 상태가 false 로 남아 DataSourceManager 가 auth_required 데이터소스(current_user)를
                    // 건너뛰고 fallback 으로 _global.currentUser 를 덮어써, 새로고침 전까지 비로그인처럼
                    // 보였다(실측). 전체 이동은 부팅 preloadAuth 를 거쳐 저장된 토큰으로 상태를 복원한다.
                    'handler' => 'openWindow',
                    // 서버가 검증한 값만 쓴다(쿼리스트링의 redirect 는 읽지 않음).
                    'target' => '{{response.redirect_path ?? \'/\'}}',
                    'params' => ['target' => '_self'],
                ],
            ],
            'onError' => [
                [
                    'handler' => 'toast',
                    'params' => ['type' => 'error', 'message' => '{{error.message}}'],
                ],
            ],
        ];

        return [...$initActions, ...$this->socialErrorToasts()];
    }

    /**
     * `social_error` 쿼리 안내 토스트.
     *
     * 기존 오류 코드는 지금처럼 코드 그대로 보여 준다. 제한 초과만 번역 문구로 따로 띄운다
     * (같은 코드로 토스트가 두 번 뜨지 않게 기존 조건에서 그 코드를 뺀다).
     *
     * @return array<int, array>
     */
    private function socialErrorToasts(): array
    {
        $code = SocialLoginRateLimiters::ERROR_CODE;

        return [
            [
                'if' => "{{query?.social_error && query.social_error !== '{$code}'}}",
                'handler' => 'toast',
                'params' => [
                    'type' => 'error',
                    'message' => '{{query.social_error}}',
                ],
            ],
            [
                'if' => "{{query?.social_error === '{$code}'}}",
                'handler' => 'toast',
                'params' => [
                    'type' => 'error',
                    'message' => '$t:g7-social_login.login.too_many_attempts',
                ],
            ],
        ];
    }

    /**
     * @param  array<int, array>  $nodes
     */
    private function treeHasNodeId(array $nodes, string $id): bool
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['id'] ?? null) === $id) {
                return true;
            }

            if (isset($node['children']) && is_array($node['children']) && $this->treeHasNodeId($node['children'], $id)) {
                return true;
            }
        }

        return false;
    }
}
