<?php

use Illuminate\Support\Facades\Route;
use Plugins\G7\SocialLogin\Http\Controllers\SocialAuthController;
use Plugins\G7\SocialLogin\Support\SocialLoginRateLimiters;

/*
 * g7-social_login 플러그인 API 라우트
 *
 * URL prefix: /api/plugins/g7-social_login  (PluginRouteServiceProvider 자동 적용)
 *
 * 제한은 이름 있는 제한기로 건다(SocialLoginRateLimiters). 이름 없는 `throttle:N,1` 은
 * 다른 공개 API 와 카운터를 같이 써서, 둘러보기만 한 방문자도 로그인에서 막혔다.
 */

Route::get('{provider}/redirect', [SocialAuthController::class, 'redirect'])
    ->whereIn('provider', ['kakao', 'google', 'naver'])
    ->middleware(['start.api.session', 'throttle:'.SocialLoginRateLimiters::OAUTH])
    ->name('redirect');

Route::get('{provider}/callback', [SocialAuthController::class, 'callback'])
    ->whereIn('provider', ['kakao', 'google', 'naver'])
    ->middleware(['start.api.session', 'throttle:'.SocialLoginRateLimiters::OAUTH])
    ->name('callback');

Route::post('exchange', [SocialAuthController::class, 'exchange'])
    ->middleware(['throttle:'.SocialLoginRateLimiters::EXCHANGE])
    ->name('exchange');

Route::middleware(['auth:sanctum', 'check.user_status'])->group(function () {
    Route::get('accounts', [SocialAuthController::class, 'accounts'])->name('accounts');

    // start.api.session — 연동 대상 회원을 URL 이 아니라 세션에 싣기 위해 세션을 시작한다.
    // 인증을 통과한 이 요청만 세션에 값을 쓸 수 있으므로, 남이 만든 링크로는 대상을 지정할 수 없다.
    Route::post('{provider}/link/prepare', [SocialAuthController::class, 'linkPrepare'])
        ->whereIn('provider', ['kakao', 'google', 'naver'])
        ->middleware(['start.api.session', 'throttle:'.SocialLoginRateLimiters::ACCOUNT])
        ->name('link.prepare');

    Route::delete('{provider}/unlink', [SocialAuthController::class, 'unlink'])
        ->whereIn('provider', ['kakao', 'google', 'naver'])
        ->middleware(['throttle:'.SocialLoginRateLimiters::ACCOUNT])
        ->name('unlink');
});
