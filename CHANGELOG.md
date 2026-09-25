# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
