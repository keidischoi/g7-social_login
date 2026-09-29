# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.1] - 2026-09-29

### 수정

- 관리자 설정 화면의 제공자별 "Callback URL (Redirect URI) — 콘솔에 등록" 칸이 다크 테마에서 흰 배경에
  옅은 회색 글자로 보여 거의 읽을 수 없던 문제를 고쳤습니다.
  - 원인: 다크 배경용 클래스(`dark:bg-gray-900/40`)가 관리자 템플릿 CSS에 없어 무시되고, 밝은 배경
    (`bg-gray-50`)과 다크용 밝은 글자(`dark:text-gray-200`)만 적용되었습니다.
  - 이제 콜백 URL을 Client ID 칸과 같은 코어 입력칸 스타일(`.input`)의 **읽기 전용 입력칸**으로 표시합니다.
    다크 테마에서는 어두운 배경에 밝은 글자, 라이트 테마에서는 밝은 배경에 어두운 글자로 테마를 그대로
    따릅니다. 고정폭 글꼴이며, 칸을 클릭한 뒤 전체 선택(Ctrl+A)·복사(Ctrl+C)하기 쉽습니다.
  - 라벨은 입력칸과 연결(`label for`)했고, 입력칸에는 `name`이 없어 설정값으로 저장되지 않습니다.
- 설정 화면의 나머지 요소에서 관리자 CSS에 없는 클래스나 다크 짝이 없는 밝은 배경이 없는지 점검했습니다
  (추가로 고칠 곳 없음). 설정 레이아웃 버전은 1.2.1입니다.

### 참고

- 업데이트하면 설정 화면 레이아웃이 자동으로 갱신됩니다. 업데이트 후 `php artisan cache:clear`,
  `php artisan template:cache-clear`를 실행하고 브라우저를 새로고침하세요. 그래도 예전 모양이면
  `php artisan plugin:refresh-layout g7-social_login`을 실행하세요.
- 로그인 화면·설정값·동작은 바뀌지 않았습니다.

## [1.2.0] - 2026-09-29

### 추가

- 애플·페이스북·X·라인·마이크로소프트·깃허브 로그인을 추가해 지원 제공자가 9개가 되었습니다
  (표시 순서: 네이버, 카카오, 구글, 애플, 페이스북, X, 라인, 마이크로소프트, 깃허브).
  - 애플: Services ID·Team ID·Key ID·개인키(.p8)로 client secret(ES256 JWT, 1시간)을 로그인할 때마다
    만들고, `id_token`을 애플 공개키(JWKS)로 검증합니다(서명·`iss`·`aud`·`exp`). 애플의 form_post(POST)
    콜백은 같은 주소의 GET으로 303 전달해 SameSite=Lax 세션에서도 state 검증이 됩니다.
  - X: OAuth 2.0 + PKCE(S256), scope `users.read users.email tweet.read`.
  - 라인: LINE Login v2.1. 이메일은 `id_token`을 LINE verify API로 검증한 뒤 읽습니다.
  - 마이크로소프트: Entra ID `common` 엔드포인트(개인·회사/학교 계정), Graph `/v1.0/me`.
  - 페이스북(Graph API v23.0)·깃허브(기본·인증된 이메일만 사용)는 Socialite 기본 드라이버를 씁니다.
- 제공자마다 사용 여부 스위치와 키 입력칸을 따로 둡니다. 켠 제공자는 키 항목을 모두 채워야 저장됩니다.
- 관리자 설정 화면의 제공자별 칸에 **등록할 콜백 URL**(내 사이트 기준)과 **개발자 콘솔 링크**를 표시합니다
  (`GET /api/plugins/g7-social_login/callback-urls`, 로그인 필요).
- `plugin.json`에 `github_url`을 넣어, 다음 버전부터 관리자 화면에서 GitHub 릴리즈로 업데이트할 수 있습니다.
- Composer가 없는 서버용 `vendor-bundle.zip` / `vendor-bundle.json`(코어 표준 번들 형식)을 함께 배포합니다.

### 변경

- 로그인 버튼을 새로 디자인했습니다. "SNS 간편 로그인" 제목 아래 48px 원형 아이콘 버튼과 그 아래 작은
  이름을 가운데 정렬로 보여 주고, 아래에 얇은 구분선을 둡니다.
  - 켠 제공자 중 앞의 3개만 첫 줄에 보이고, 나머지는 **"그 외 로그인"** 토글(화살표 포함,
    `aria-expanded`·`aria-controls` 지원)로 펼치는 접힌 영역에 둡니다. 기본은 접힘이며, 켠 제공자가 3개
    이하면 토글이 나오지 않습니다. 좁은 화면에서는 줄바꿈됩니다.
  - **설정에서 켠 제공자만** 첫 줄과 접힌 영역 어디에든 표시됩니다.
- 브랜드 아이콘을 PNG에서 각 사의 공식 색상을 쓴 인라인 SVG로 바꿨습니다(마이페이지 연동 목록 포함).
  `resources/images/*.png`는 삭제했습니다.
- 네이버 드라이버를 `vendor/socialiteproviders/naver`(수동 배치)에서 플러그인 `src/Socialite/`로 옮기고,
  `socialiteproviders/naver` 의존성을 제거했습니다. `composer.lock`을 Composer로 다시 생성했습니다.
- 제공자 HTTP 요청에 제한 시간(연결 5초, 전체 15초)을 둡니다.

### 수정

- 끈 제공자나 키가 빈 제공자로 들어온 콜백(애플 POST 콜백 포함)은 제공자에게 요청을 보내지 않고
  `social_error=provider_unavailable`로 거절합니다.

### 보안

- 페이스북·라인·마이크로소프트는 API가 이메일 인증 여부를 주지 않거나 테넌트가 임의로 넣을 수 있는 값이라
  (마이크로소프트 nOAuth 문제) 이메일 자동 연동을 하지 않습니다. 이 경우 임시 이메일로 가입되며, 기존 회원은
  마이페이지에서 직접 연동할 수 있습니다.

### 참고

- 업데이트 후 `php artisan cache:clear`와 `php artisan template:cache-clear`를 실행하세요(관리자 화면만 쓸 수
  있다면 브라우저 새로고침). 기존 네이버·카카오·구글 설정은 그대로 유지되고 새 제공자는 꺼진 상태로 추가됩니다.
- 각 제공자 콘솔에 `https://도메인/api/plugins/g7-social_login/{naver|kakao|google|apple|facebook|x|line|microsoft|github}/callback`
  을 등록해야 합니다. 마이크로소프트 클라이언트 비밀은 만료되므로(최대 24개월) 만료 전에 새로 넣어야 합니다.
- 1.1.0 릴리즈에는 네이버가 이미 포함되어 있었습니다. 이번 버전에서 네이버는 새 디자인과 순서(첫 번째)만 바뀌었습니다.

## [1.1.0] - 2026-09-25

### Added

- Naver login/signup, following the same pattern as Kakao and Google: a login-page button, a
  mypage link/unlink row, admin settings (`naver_enabled`/`naver_client_id`/`naver_client_secret`),
  and `/api/plugins/g7-social_login/naver/{redirect,callback,link/prepare,unlink}` routes.
- Vendored a `socialiteproviders/naver`-compatible `NaverProvider` under `vendor/socialiteproviders/naver`
  (Naver's OAuth2 endpoints, the `state` field Naver requires on token exchange, and its
  `{ "response": {...} }`-wrapped profile payload).

### Notes

- Naver's profile API has no explicit "email verified" flag (unlike Kakao/Google). Auto-link-by-email
  treats a present `response.email` as verified; see the README's "Naver" section for how to disable
  this if it doesn't fit your policy.
- `resources/images/naver-icon.png` is a placeholder brand-color icon, not Naver's official button
  asset — replace it before shipping to production.

## [1.0.4] - 2026-09-24

### Fixed

- Sign-in could fail with `429 Too Many Requests` right after a visitor had browsed the site. The
  plugin routes used an unnamed `throttle:30,1`, whose counter is shared with every other unnamed
  throttle on the site (board lists, menus, widgets, …), so ordinary page views used up the sign-in
  allowance. The routes now use their own named rate limiters:
  `g7-social_login.oauth` (redirect/callback, 20 per minute per IP),
  `g7-social_login.exchange` (10 per minute per IP) and
  `g7-social_login.account` (link/unlink, 10 per minute per member).

### Changed

- When the limit is hit, the sign-in redirect/callback now sends the visitor back to the login page
  with a translated message (`social_error=too_many_attempts`) instead of a raw 429 page. API calls
  still return `429`, now with a translated message.

### Documentation

- README: rate limits table, and why `TRUSTED_PROXIES` must be set behind a reverse proxy
  (check with `php artisan trusted-proxy:status`).

## [1.0.3] - 2026-09-19

### Security

- Hardened the account-linking flow. The member a social account gets linked to is now carried in
  the server-side session, established by the authenticated "link" request itself, instead of being
  passed through the URL. A link can therefore only ever target the member who started it.
- The one-time link code is now consumed with a single conditional update, so it is guaranteed to be
  redeemable exactly once even if two callbacks arrive at the same time.

Sign-in, auto-linking and existing linked accounts are unaffected; no migration is required.

## [1.0.2] - 2026-09-17

### Security

- Social email verification check. The email from Kakao or Google is now used only when the
  provider reports it as verified. Auto-linking to an existing member and marking a new member's
  email as verified both require a provider-verified email; otherwise the account is handled as
  having no email (placeholder address, no auto-link). Existing links are unaffected.
- Improved login exchange-code handling. Each code can be redeemed only once, and the sign-in token
  is issued when the code is redeemed instead of being stored in the cache beforehand. The response
  format and token lifetime are unchanged.

## [1.0.1] - 2026-09-16

### Changed

- `composer.lock` is now committed. Without it, installing this plugin resolved the
  dependency tree afresh on every site, so two installs of the same tag could end up
  with different package versions. Installs now reproduce the exact versions this
  release was verified against (`laravel/socialite` v5.31.0, `socialiteproviders/manager`
  4.9.2, `socialiteproviders/kakao` 4.3.0, and their five transitive dependencies).
  The `replace` block that keeps the 47 packages already shipped by the Gnuboard7 core
  out of this plugin's `vendor/` is unchanged.

## [1.0.0] - 2026-09-15

First public release. Verified on live Gnuboard7 7.0.11 sites (Kakao and Google sign-in end to end).

### Added

- **Kakao and Google login.** Sign in or sign up from the login page with a Kakao or Google account.
- **Auto-link to existing members.** When the social account's email matches a member whose email is
  verified, the account is linked to that member and the member is notified (mail + in-site
  notification). Matches against an unverified email are refused to prevent account takeover.
- **Link / unlink from the profile page.** Members manage their linked social accounts under
  `/mypage/profile`. Unlinking is blocked when it would leave the member with no way to sign in.
- **Admin settings per provider.** Enable Kakao / Google and manage client ID and client secret
  (secrets stored encrypted, masked in the admin screen).
- **Profile editing for social-only members.** Members who signed up with a social account (and have
  no password they know) can open the profile edit form without the password check. Members with a
  real password still get the check.
- **Default role for new sign-ups.** New social members receive the same default role (`user`) as the
  core registration, so they have regular member permissions (reading and writing posts, etc.).
- **Open-redirect protection.** The post-login return path accepts only same-site relative paths;
  anything else falls back to `/`.

### Notes

- Packages already provided by the Gnuboard7 core are declared in `composer.json` → `replace`, so the
  plugin's `vendor/` holds only the 8 plugin-specific packages. When the core moves to a new Laravel
  major version, upgrade `laravel/socialite` as well.
- Servers that cannot run Composer should install from the release zip, which includes `vendor/`.
- Known limitation: social-only members cannot set a password; their recovery path is the linked
  social account.

[1.0.4]: https://github.com/William1607cho/g7-social_login/releases/tag/v1.0.4
[1.0.3]: https://github.com/William1607cho/g7-social_login/releases/tag/v1.0.3
[1.0.2]: https://github.com/William1607cho/g7-social_login/releases/tag/v1.0.2
[1.0.1]: https://github.com/William1607cho/g7-social_login/releases/tag/v1.0.1
[1.0.0]: https://github.com/William1607cho/g7-social_login/releases/tag/v1.0.0
