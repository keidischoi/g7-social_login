# g7-social_login

Social login for [Gnuboard7](https://github.com/gnuboard/g7) with **Naver, Kakao, Google, Apple,
Facebook, X, LINE, Microsoft and GitHub**.

- **Sign in / sign up** with any enabled provider from the login page.
- **Only enabled providers are shown.** Each provider has its own on/off switch. The login page shows
  the first three *enabled* providers (in the order above) as round icon buttons under a small
  "SNS 간편 로그인" heading; any further enabled providers sit in a collapsed **"그 외 로그인"** section
  that opens with a small toggle (hidden when three or fewer are enabled). Disabled providers never
  render and their routes are rejected.
- **Auto-link** — if the social account's email is verified by the provider and matches an existing
  member whose email is verified, the social account is linked to that member instead of creating a
  new one (the member is notified). See [email policy](#email-verification-policy) for which providers
  qualify.
- **Link / unlink** social accounts from the member profile page (`/mypage/profile`).
- **Admin settings** — enable each provider and manage its keys; each provider's block shows the exact
  callback URL to register and a link to its developer console. Secrets are stored encrypted and
  masked in the admin screen. A provider can only be switched on when all of its keys are filled in.
- **Social-only members can edit their profile** — the core profile-edit screen asks for the current
  password first; members who signed up with a social account have no password they know, so the
  plugin lets them skip that step. Members with a real password keep the password check.
- **Default role** — new social sign-ups get the same default role (`user`) as the core registration.
- **Open-redirect protection** — the post-login return path only accepts same-site relative paths.

The core and templates are not modified. The login / profile UI is injected through the
`core.layout_extension.after_apply` hook. Brand icons are inline SVGs in each provider's brand colours.

한국어 안내는 [아래](#사용법-한국어)에 있습니다.

## Requirements

- Gnuboard7 `>= 7.0.10` (tested on **7.0.11**)
- PHP `^8.2` with the `openssl` extension (Apple's client secret is an ES256-signed JWT)
- A template whose login and profile layouts follow the `sirsoft-basic` structure
  (tested with `sirsoft-basic` and `wc-community`). If the anchor nodes cannot be found, the plugin
  logs an error and leaves the page unchanged.
- HTTPS site URL. Behind a reverse proxy, configure `TRUSTED_PROXIES` so the generated callback URL is
  `https://…` — providers reject a redirect URI that does not match exactly, and Apple does not accept
  `http://`, `localhost` or IP-address return URLs at all.

## Installation

The plugin depends on `laravel/socialite` (Google, Facebook, X, GitHub drivers) and
`socialiteproviders/kakao`; the Naver, Apple, LINE and Microsoft drivers ship inside the plugin
(`src/Socialite/`). Packages that the Gnuboard7 core already ships (`illuminate/*`, `symfony/*`,
`guzzlehttp/*`, `psr/*`, `nesbot/carbon`, …) are declared in `composer.json` → `replace`, so only
8 plugin-specific packages end up in the plugin's `vendor/` (about 5 MB) and the core's framework
classes are always used at runtime.

**Where does `vendor/` come from?** The repository and the release zip both contain a prebuilt
`vendor/`, plus `vendor-bundle.zip` / `vendor-bundle.json` (the core's standard "bundled vendor"
format for servers without Composer). The core installer picks one of these:

- Composer can run on the server → `composer install --no-dev` from the committed `composer.lock`;
- otherwise → it extracts `vendor-bundle.zip` after checking its SHA-256 against `vendor-bundle.json`.

Once a plugin has been installed in bundled mode, later updates also require `vendor-bundle.zip`;
every release of this plugin ships it.

### Option A — release zip (works with or without Composer) — recommended

Download `g7-social_login-v1.2.1.zip` from the
[Releases](https://github.com/keidischoi/g7-social_login/releases) page.

**New install**

```bash
# extract so that the folder becomes plugins/_pending/g7-social_login
unzip g7-social_login-v1.2.1.zip -d plugins/_pending/
php artisan plugin:install g7-social_login
php artisan plugin:activate g7-social_login
php artisan template:cache-clear
```

**Update from 1.1.x** — either upload the zip in **Admin → Plugins → g7-social_login → Update**
(external zip), or on the command line:

```bash
php artisan plugin:update g7-social_login --zip=/path/to/g7-social_login-v1.2.1.zip
php artisan cache:clear
php artisan template:cache-clear
```

Existing settings (Naver / Kakao / Google switches, IDs and encrypted secrets) are kept; the new
providers start switched off.

From 1.2.0 on, `plugin.json` carries `github_url`, so **Admin → Plugins** can check for and install
later releases of this repository directly (update source: GitHub).

### Option B — git clone

```bash
git clone https://github.com/keidischoi/g7-social_login.git plugins/_pending/g7-social_login
php artisan plugin:install g7-social_login
php artisan plugin:activate g7-social_login
php artisan template:cache-clear
```

Verify after installing: `plugins/g7-social_login/vendor/laravel/socialite` must exist.

## Configuration

Open **Admin → Plugins → Social Login → Settings** (`/admin/plugins/g7-social_login/settings`),
turn a provider on and enter its keys. Every key of an enabled provider is required (the save is
rejected otherwise). Each provider block in the settings screen shows the exact callback URL for your
site and a link to the provider's console.

Callback (redirect) URIs to register — replace the domain with yours:

| Provider | Callback URL to register | Developer console |
|---|---|---|
| Naver | `https://your-domain.com/api/plugins/g7-social_login/naver/callback` | [developers.naver.com/apps](https://developers.naver.com/apps/) |
| Kakao | `https://your-domain.com/api/plugins/g7-social_login/kakao/callback` | [developers.kakao.com/console/app](https://developers.kakao.com/console/app) |
| Google | `https://your-domain.com/api/plugins/g7-social_login/google/callback` | [console.cloud.google.com/apis/credentials](https://console.cloud.google.com/apis/credentials) |
| Apple | `https://your-domain.com/api/plugins/g7-social_login/apple/callback` | [developer.apple.com → Identifiers → Services IDs](https://developer.apple.com/account/resources/identifiers/list/serviceId) |
| Facebook | `https://your-domain.com/api/plugins/g7-social_login/facebook/callback` | [developers.facebook.com/apps](https://developers.facebook.com/apps/) |
| X | `https://your-domain.com/api/plugins/g7-social_login/x/callback` | [developer.x.com portal](https://developer.x.com/en/portal/dashboard) |
| LINE | `https://your-domain.com/api/plugins/g7-social_login/line/callback` | [developers.line.biz/console](https://developers.line.biz/console/) |
| Microsoft | `https://your-domain.com/api/plugins/g7-social_login/microsoft/callback` | [Entra admin center → App registrations](https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade) |
| GitHub | `https://your-domain.com/api/plugins/g7-social_login/github/callback` | [github.com/settings/developers](https://github.com/settings/developers) |

### Kakao

1. Create an app at [Kakao Developers](https://developers.kakao.com/console/app).
2. Switch the app to a **Biz app** — required to make the Kakao account email a *required* consent item.
3. **[앱] > [플랫폼 키] > [REST API 키]**
   - REST API key → plugin **Client ID**
   - **[클라이언트 시크릿]** code → plugin **Client Secret** (enabled by default for new keys)
   - **[리다이렉트 URI]** → register the Kakao redirect URI above
4. **[카카오 로그인] > [사용 설정]** → set the status to **ON**.
5. **[카카오 로그인] > [동의항목]** → set **닉네임** and **카카오계정(이메일)** to *required*.

### Google

1. Create a project in [Google Cloud Console](https://console.cloud.google.com/).
2. Configure the **OAuth consent screen**. Under authorized domains, registering the top-level domain
   (e.g. `example.com`) also covers its subdomains.
3. **Credentials → Create credentials → OAuth client ID** → application type **Web application**.
4. Add the Google redirect URI above to **Authorized redirect URIs**.
5. Scopes: `openid`, `email`, `profile` (the plugin requests exactly these).

### Naver

1. Create an application at [Naver Developers](https://developers.naver.com/apps/#/register).
2. **사용 API** → add **네이버 로그인**.
3. **제공 정보 선택** → set **이름** and **이메일** to *필수* (required).
4. **API 설정 → Client ID / Client Secret** → copy into the plugin's **Client ID** / **Client Secret**.
5. **API 설정 → 서비스 URL / Callback URL** → register your site URL and the Naver redirect URI above.
6. Apply for review (검수 신청) before opening login to the general public; until then only registered
   testers can sign in.

### Apple

1. **Certificates, Identifiers & Profiles → Identifiers → App IDs**: make sure an App ID has
   **Sign in with Apple** enabled (it becomes the *primary App ID*).
2. **Identifiers → Services IDs → +**: create a Services ID (e.g. `com.example.shop.web`). This value is
   the plugin's **Services ID (client_id)**. Enable **Sign in with Apple → Configure**: choose the primary
   App ID, add your domain under *Domains and Subdomains*, and the Apple callback URL above under
   *Return URLs*.
3. **Keys → +**: create a key with **Sign in with Apple** enabled, download the `.p8` file (only once)
   and note its **Key ID**. Paste the whole `.p8` contents (including the `BEGIN/END PRIVATE KEY` lines)
   into **Private key**.
4. Your **Team ID** is shown at the top right of the developer portal (Membership details).

Notes:
- Apple sends the callback as a cross-site `POST` (`response_mode=form_post`). The plugin forwards it
  to the same URL as a `GET` (303) so the SameSite=Lax session cookie is present; register only the
  one URL.
- The plugin creates the client secret (an ES256 JWT, valid for 1 hour) on each sign-in from the key,
  so there is nothing to renew. The `id_token` is verified against Apple's public keys (`iss`, `aud`,
  `exp`, signature).
- Apple sends the user's **name only on the very first authorization**, and the email may be a
  private relay address (`…@privaterelay.appleid.com`). To send mail to relay addresses, register your
  sending domain/address under **Services → Sign in with Apple for Email Communication**.

### Facebook

1. Create an app at [Meta for Developers](https://developers.facebook.com/apps/) with the
   *Authenticate and request data from users with Facebook Login* use case.
2. **App settings → Basic**: App ID → **App ID**, App secret → **App secret**. Fill in the privacy
   policy URL and switch the app to **Live** before real users sign in.
3. **Facebook Login → Settings → Valid OAuth Redirect URIs**: add the Facebook callback URL above.
4. The plugin requests the `email` permission (Graph API v23.0).

### X (Twitter)

1. In the [X developer portal](https://developer.x.com/en/portal/dashboard) open your Project → App →
   **User authentication settings → Set up**.
2. App permissions **Read**; type of app **Web App, Automated App or Bot** (confidential client).
   Turn on **Request email from users** (requires privacy-policy and terms URLs).
3. **Callback URI / Redirect URL**: the X callback URL above; **Website URL**: your site.
4. **Keys and tokens → OAuth 2.0 Client ID and Client Secret** → plugin **Client ID / Client Secret**
   (not the API key / API secret of OAuth 1.0a).
5. The plugin uses OAuth 2.0 with PKCE (S256) and scopes `users.read users.email tweet.read`.

### LINE

1. In the [LINE Developers console](https://developers.line.biz/console/) create a provider and a
   **LINE Login** channel (app type *Web app*).
2. **Basic settings**: Channel ID → **Channel ID**, Channel secret → **Channel secret**.
3. **LINE Login → Callback URL**: the LINE callback URL above.
4. To receive the email address, apply for **OpenID Connect → Email address permission** in Basic
   settings. The email is read from the `id_token`, which the plugin verifies with LINE's verify API.
5. Publish the channel (status *Published*) before real users sign in.

### Microsoft

1. In the [Microsoft Entra admin center](https://entra.microsoft.com/) → **App registrations → New
   registration**. Supported account types: **Accounts in any organizational directory and personal
   Microsoft accounts** (the plugin uses the `common` endpoint).
2. Redirect URI: platform **Web**, the Microsoft callback URL above.
3. **Application (client) ID** → plugin **Application (client) ID**.
4. **Certificates & secrets → New client secret** → copy the secret **Value** (not the Secret ID) into
   **Client secret**. Client secrets expire (at most 24 months) — renew them before they do.
5. API permissions: the default `User.Read` is enough (the plugin requests
   `openid profile email User.Read`).

### GitHub

1. **Settings → Developer settings → OAuth Apps → New OAuth App**
   ([github.com/settings/developers](https://github.com/settings/developers)).
2. Homepage URL: your site; **Authorization callback URL**: the GitHub callback URL above (an OAuth App
   has exactly one callback URL).
3. Client ID → **Client ID**; **Generate a new client secret** → **Client secret**.
4. The plugin requests `user:email` and uses the account's **primary, verified** email.

## Email verification policy

A provider-supplied email is used (and can auto-link to an existing member) only when the provider
states that it is verified. Otherwise the member is created with a placeholder address
(`social-…@no-email.invalid`) and is never auto-linked by email.

| Provider | Email trusted when | Auto-link by email |
|---|---|---|
| Naver | `response.email` is present (Naver has no separate flag) | yes |
| Kakao | `is_email_valid` and `is_email_verified` are true | yes |
| Google | `email_verified` is true | yes |
| Apple | `email_verified` is true in the verified `id_token` | yes |
| X | `confirmed_email` is returned | yes |
| GitHub | primary + verified address from `/user/emails` | yes |
| Facebook | no verification flag in the API | **no** |
| LINE | no verification flag in the API | **no** |
| Microsoft | tenant-controlled, unverified `email` claim (nOAuth risk) | **no** |

To change this, edit `providerVerifiedEmail()` in `src/Services/SocialAuthService.php`.

## How accounts are matched

On callback the plugin looks for, in order (the email is used only when the provider reports it as
verified — an unverified email is treated as no email):

1. an existing link for this provider account → sign in as that member;
2. a member with the same **verified** email → link and sign in (a notification is sent);
3. a member with the same **unverified** email → refuse (prevents account takeover; sign in with the
   password and link manually from the profile page);
4. otherwise → create a new member with a random password, assign the default `user` role and link
   the account. The email is marked verified only when the provider verified it; without a verified
   email the member gets a placeholder address.

Unlinking is blocked when it would remove the member's last way to sign in (no real password and no
other linked provider).

## Rate limits and reverse proxies

Each step has its own named rate limiter, so ordinary browsing (board lists, menus, widgets) no longer
uses up the sign-in allowance:

| Limiter | Routes | Counted per | Limit |
|---|---|---|---|
| `g7-social_login.oauth` | `{provider}/redirect`, `{provider}/callback` | client IP | 20 per minute |
| `g7-social_login.exchange` | `exchange` | client IP | 10 per minute |
| `g7-social_login.account` | `{provider}/link/prepare`, `{provider}/unlink` | member (IP if none) | 10 per minute |

When the limit is hit, a sign-in page visit is sent back to the login page with a translated message
(`social_error=too_many_attempts`); API calls get a `429` JSON response with a translated message.

**Behind a reverse proxy** (Cloudflare, nginx, a load balancer, a tunnel, …) set `TRUSTED_PROXIES` in
the core `.env`. Without it every visitor is seen as the proxy's IP address, so all visitors share one
sign-in allowance and one busy minute blocks everyone. Check the setting with the core command:

```bash
php artisan trusted-proxy:status
```

## Known limitations

- **Social-only members cannot set a password.** The core password-change API requires the current
  password (`current_password`), which these members do not know, so their only recovery path is
  the linked social account. Removing that rule would let anyone holding a stolen login token set a
  password and take over the account, so it is intentionally left in place.
- The login / profile UI is injected into the template's layout tree; a template whose layouts differ
  from `sirsoft-basic` will not show the buttons (an error is logged).
- Apple, Facebook, X, LINE and Microsoft sign-in were verified with automated tests (mocked provider
  responses) and against a real Gnuboard7 7.0.11 install up to the provider redirect; a first real
  sign-in with your own keys is still recommended for each provider you enable.

## Maintenance notes

- **When the Gnuboard7 core moves to a new Laravel major version, upgrade `laravel/socialite` (and
  `socialiteproviders/manager`) as well.** `replace` only stops duplicate installation; it does not
  guarantee compatibility. The plugin runs against the core's framework classes, so a core upgrade
  outside socialite's constraints (currently `illuminate/*` `^6`–`^13`, manager `^11`–`^13`,
  guzzle `^6`–`^8`) breaks login. Also re-check the `replace` list against the core's `vendor/` for
  newly overlapping packages.
- After changing `composer.json` / `composer.lock`, rebuild `vendor/` **and** `vendor-bundle.zip`
  (`php artisan plugin:vendor-bundle g7-social_login` with the plugin under `plugins/_bundled/`) — sites
  installed in bundled mode cannot update without a matching bundle.
- After regenerating `vendor/` on a running site, restart long-running PHP workers
  (`queue:work`, `reverb:start`) — they keep the old autoloader class map in memory.

## Uninstalling

```bash
php artisan plugin:deactivate g7-social_login
php artisan plugin:uninstall g7-social_login
```

The plugin tables (`g7_social_login_accounts`, `g7_social_login_link_nonces`,
`g7_social_login_user_flags`) are dropped only when you choose to delete plugin data.
Members created through social sign-up remain as regular members.

## 사용법 (한국어)

### 무엇을 하나요
그누보드7에 **네이버·카카오·구글·애플·페이스북·X·라인·마이크로소프트·깃허브** 소셜 로그인을 추가합니다.
코어와 템플릿은 수정하지 않습니다.
- 제공자마다 사용 여부를 따로 켜고 끕니다. **켠 제공자만** 로그인 화면에 나오고, 끈 제공자는 어디에도
  나오지 않으며 해당 주소로 들어와도 거절됩니다.
- 로그인 화면: "SNS 간편 로그인" 제목 아래 켠 제공자 중 **앞의 3개**(위 순서)를 원형 아이콘 버튼으로 보여
  주고, 나머지는 **"그 외 로그인"** 토글을 누르면 펼쳐지는 접힌 영역에 둡니다(기본은 접힘, 3개 이하면
  토글 자체가 나오지 않음).
- 제공자가 인증했다고 알려 준 이메일일 때만, 인증된 이메일이 같은 기존 회원과 자동 연동(회원에게 알림).
  사이트 쪽 미인증 이메일은 거부
- 마이페이지에서 소셜 계정 연동·해제
- 관리자 화면에서 제공자별 사용 여부와 키 관리(시크릿·개인 키는 암호화 저장). 제공자마다 등록할
  콜백 URL과 개발자 콘솔 링크가 표시되고, 키를 다 넣어야 켤 수 있습니다.
- 소셜 가입자는 비밀번호 확인 없이 프로필 편집 가능, 신규 가입자에게 기본 역할(`user`) 부여
- 로그인 후 이동 경로는 사이트 내부 상대경로만 허용(오픈 리다이렉트 방지)

### 설치 / 업데이트
릴리즈 zip(`g7-social_login-v1.2.1.zip`)에는 `vendor/`와 `vendor-bundle.zip`이 들어 있어 Composer가
없는 서버(공유 호스팅 등)에서도 설치·업데이트됩니다.

새로 설치:
```bash
unzip g7-social_login-v1.2.1.zip -d plugins/_pending/
php artisan plugin:install g7-social_login
php artisan plugin:activate g7-social_login
php artisan template:cache-clear
```

1.1.x에서 업데이트: 관리자 → 플러그인 → g7-social_login → 업데이트(외부 zip)로 올리거나
```bash
php artisan plugin:update g7-social_login --zip=/경로/g7-social_login-v1.2.1.zip
php artisan cache:clear
php artisan template:cache-clear
```
기존 네이버·카카오·구글 설정(사용 여부, ID, 암호화된 시크릿)은 그대로 유지되고, 새 제공자는 꺼진 상태로
추가됩니다. 로그인 화면이 바로 바뀌지 않으면 브라우저 새로고침(캐시 비우기)을 하세요.

1.2.0부터 `plugin.json`에 `github_url`이 들어가, 이후 버전은 관리자 → 플러그인 관리에서 GitHub 릴리즈로
바로 업데이트를 확인·설치할 수 있습니다.

### 콜백 URL과 콘솔
관리자 → 플러그인 → 소셜 로그인 설정(`/admin/plugins/g7-social_login/settings`)의 각 제공자 칸에 내
사이트 기준 콜백 URL이 그대로 표시됩니다. 형식은 `https://도메인/api/plugins/g7-social_login/{제공자}/callback`
입니다.

| 제공자 | 콜백 URL 경로 | 콘솔 |
|---|---|---|
| 네이버 | `/api/plugins/g7-social_login/naver/callback` | [네이버 개발자센터](https://developers.naver.com/apps/) |
| 카카오 | `/api/plugins/g7-social_login/kakao/callback` | [카카오디벨로퍼스](https://developers.kakao.com/console/app) |
| 구글 | `/api/plugins/g7-social_login/google/callback` | [Google Cloud Console](https://console.cloud.google.com/apis/credentials) |
| 애플 | `/api/plugins/g7-social_login/apple/callback` | [Apple Developer → Services IDs](https://developer.apple.com/account/resources/identifiers/list/serviceId) |
| 페이스북 | `/api/plugins/g7-social_login/facebook/callback` | [Meta for Developers](https://developers.facebook.com/apps/) |
| X | `/api/plugins/g7-social_login/x/callback` | [X Developer Portal](https://developer.x.com/en/portal/dashboard) |
| 라인 | `/api/plugins/g7-social_login/line/callback` | [LINE Developers](https://developers.line.biz/console/) |
| 마이크로소프트 | `/api/plugins/g7-social_login/microsoft/callback` | [Microsoft Entra](https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade) |
| 깃허브 | `/api/plugins/g7-social_login/github/callback` | [GitHub Developer settings](https://github.com/settings/developers) |

리버스 프록시 뒤라면 `TRUSTED_PROXIES`를 설정해 콜백 URL이 `https://`로 만들어지게 하세요(글자 하나라도
다르면 거부됩니다). 애플은 `http://`·localhost·IP 주소를 받지 않습니다.

**카카오** — 앱 생성 → 비즈 앱 전환 → [앱] > [플랫폼 키] > [REST API 키]에서 REST API 키(= Client ID),
클라이언트 시크릿(= Client Secret), 리다이렉트 URI 등록 → [카카오 로그인] 사용 ON → 동의항목에서
닉네임·카카오계정(이메일) 필수.

**구글** — OAuth 동의 화면 구성 → 사용자 인증 정보 → OAuth 클라이언트 ID(웹 애플리케이션) → 승인된
리디렉션 URI 등록. 범위 `openid email profile`.

**네이버** — 애플리케이션 등록 → 사용 API에 네이버 로그인 → 제공 정보에서 이름·이메일 필수 → Client ID /
Client Secret 입력 → 서비스 URL·Callback URL 등록. 일반 공개 전 검수 신청 필요(검수 전에는 테스터만 로그인).

**애플** — ① App ID에 Sign in with Apple 켜기 ② Services ID 생성(이 값이 **Services ID (client_id)**),
Sign in with Apple → Configure에서 도메인과 Return URL(애플 콜백 URL) 등록 ③ Keys에서 Sign in with Apple
키 생성 → `.p8` 파일(한 번만 받을 수 있음) 내용 전체를 **개인 키**에, **Key ID** 입력 ④ 개발자 포털
오른쪽 위의 **Team ID** 입력. 애플은 콜백을 POST(form_post)로 보내며 플러그인이 같은 주소의 GET으로
넘겨 처리하므로 URL은 하나만 등록하면 됩니다. client secret(JWT)은 로그인할 때마다 자동 생성되어 갱신할
필요가 없습니다. **이름은 처음 동의할 때 한 번만** 오고, 이메일은 `@privaterelay.appleid.com` 중계
주소일 수 있습니다(중계 주소로 메일을 보내려면 "Sign in with Apple for Email Communication"에 발신
도메인 등록).

**페이스북** — 앱 생성(Facebook 로그인 사용 사례) → 기본 설정의 앱 ID·앱 시크릿 입력 → Facebook 로그인 →
설정 → 유효한 OAuth 리디렉션 URI에 콜백 등록 → 개인정보처리방침 URL 입력 후 앱을 **라이브**로 전환.

**X** — 개발자 포털 → 프로젝트 → 앱 → User authentication settings: 권한 Read, 앱 유형 *Web App,
Automated App or Bot*, **Request email from users** 켜기(개인정보처리방침·약관 URL 필요), Callback URI
등록 → Keys and tokens의 **OAuth 2.0 Client ID / Client Secret** 입력(OAuth 1.0a API Key 아님).
PKCE(S256) 사용.

**라인** — LINE Developers에서 Provider와 **LINE Login** 채널 생성 → Channel ID / Channel secret 입력 →
LINE Login 탭 Callback URL 등록 → 이메일을 받으려면 OpenID Connect → Email address permission 신청 →
채널 공개(Published).

**마이크로소프트** — Entra 관리 센터 → 앱 등록 → 새 등록, 지원 계정 유형 "모든 조직 디렉터리의 계정 및
개인 Microsoft 계정" → 리디렉션 URI(웹) 등록 → 애플리케이션(클라이언트) ID 입력 → 인증서 및 비밀 → 새
클라이언트 비밀의 **값**(비밀 ID 아님) 입력. **클라이언트 비밀은 만료**(최대 24개월)되므로 만료 전에
새로 발급해 바꿔 넣으세요.

**깃허브** — Settings → Developer settings → OAuth Apps → New OAuth App → Authorization callback URL
등록(앱당 1개) → Client ID, Generate a new client secret 입력. 기본(primary)·인증된 이메일만 사용.

### 이메일 인증 정책
제공자가 "인증됨"이라고 알려 준 이메일만 쓰고, 그때만 기존 회원과 자동 연동합니다. 그렇지 않으면
임시 주소(`social-…@no-email.invalid`)로 가입되고 이메일 자동 연동은 하지 않습니다.
- 자동 연동함: 네이버(이메일이 있으면 인증으로 간주), 카카오, 구글, 애플, X, 깃허브
- 자동 연동 안 함: 페이스북·라인(인증 여부를 API가 주지 않음), 마이크로소프트(테넌트가 임의로 넣을 수
  있는 미인증 email 클레임 — nOAuth 계정 탈취 방지)

바꾸려면 `src/Services/SocialAuthService.php`의 `providerVerifiedEmail()`을 수정하세요.

### 요청 제한과 리버스 프록시
단계마다 이름 있는 제한기를 따로 둡니다. 게시판 목록·메뉴·위젯 같은 일반 탐색 요청이 로그인 허용량을
깎지 않습니다.

| 제한기 | 라우트 | 세는 기준 | 한도 |
|---|---|---|---|
| `g7-social_login.oauth` | `{provider}/redirect`, `{provider}/callback` | 방문자 IP | 분당 20 |
| `g7-social_login.exchange` | `exchange` | 방문자 IP | 분당 10 |
| `g7-social_login.account` | `{provider}/link/prepare`, `{provider}/unlink` | 회원(없으면 IP) | 분당 10 |

한도를 넘으면 로그인 페이지 이동은 로그인 화면으로 돌아가 안내 문구를 보여 주고
(`social_error=too_many_attempts`), API 호출은 번역된 메시지와 함께 `429` JSON을 받습니다.

**리버스 프록시 뒤에서 운영한다면**(Cloudflare, nginx, 로드밸런서, 터널 등) 코어 `.env`에
`TRUSTED_PROXIES`를 설정하세요. 설정하지 않으면 방문자 전원이 프록시 IP 하나로 인식되어 로그인 제한을
나눠 쓰게 되고, 요청이 몰린 1분 동안 모두가 막힙니다. 설정 상태는 코어 명령으로 확인할 수 있습니다.

```bash
php artisan trusted-proxy:status
```

### 알려진 제약
- **소셜 가입자는 비밀번호를 설정할 수 없고, 계정 복구 수단이 연동된 소셜 계정뿐입니다.** 코어 비밀번호
  변경 API가 현재 비밀번호를 서버에서 요구하기 때문이며, 이 규칙을 풀면 로그인 토큰 탈취자가 비밀번호를
  설정해 계정을 장악할 수 있어 의도적으로 열지 않았습니다.
- 로그인·마이페이지 화면 주입은 `sirsoft-basic` 계열 레이아웃 구조에 의존합니다.
- 애플·페이스북·X·라인·마이크로소프트는 자동 테스트(제공자 응답 모의)와 실제 그누보드7 7.0.11 설치본에서
  제공자 이동 단계까지 검증했습니다. 켜는 제공자마다 실제 키로 첫 로그인을 한 번 확인하세요.

### 유지보수 주의사항
- **코어가 Laravel 메이저 버전을 올리면 socialite도 함께 올려야 합니다.** `replace`는 중복 설치만 막을 뿐
  호환성을 보장하지 않습니다. 코어 업그레이드 시 `replace` 목록도 코어 `vendor/`와 다시 대조하세요.
- `composer.json`/`composer.lock`을 바꾸면 `vendor/`와 함께 `vendor-bundle.zip`도 다시 만드세요
  (`plugins/_bundled/`에 두고 `php artisan plugin:vendor-bundle g7-social_login`). 번들 모드로 설치된
  사이트는 맞는 번들이 없으면 업데이트가 실패합니다.
- 운영 중 `vendor/`를 다시 만들었다면 `queue:work`·`reverb:start` 같은 장기 실행 워커를 재시작하세요.

## Development log

The detailed development history (investigations, root causes, and verification of each fix) is kept
in [docs/DEVELOPMENT_LOG.md](docs/DEVELOPMENT_LOG.md) (Korean). Release history is in
[CHANGELOG.md](CHANGELOG.md).

## License

[MIT](./LICENSE) © 2026 William Cho
