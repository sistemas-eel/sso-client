<?php

use SistemasEel\SSOClient\Laravel\Http\Controllers\SSOController;
use Illuminate\Support\Facades\Route;

$oauthRouteLockSeconds = max(
    1,
    (int) config(
        'sso-client.oauth_state.route_lock_seconds',
        30,
    ),
);

$oauthRouteLockWaitSeconds = max(
    1,
    (int) config(
        'sso-client.oauth_state.route_lock_wait_seconds',
        30,
    ),
);

Route::middleware(config('sso-client.middleware_group', 'web'))
    ->group(function () use (
        $oauthRouteLockSeconds,
        $oauthRouteLockWaitSeconds,
    ) {
        if (config('sso-client.login_route.enabled', true)) {
            $loginPath = '/' . ltrim((string) config('sso-client.login_route.path', '/login'), '/');
            $loginName = (string) config('sso-client.login_route.name', 'login');

            Route::get($loginPath, [SSOController::class, 'login'])
                ->block(
                    $oauthRouteLockSeconds,
                    $oauthRouteLockWaitSeconds,
                )
                ->name($loginName);
        }

        if (config('sso-client.logout_route.enabled', true)) {
            $logoutPath = '/' . ltrim((string) config('sso-client.logout_route.path', '/logout'), '/');
            $logoutName = (string) config('sso-client.logout_route.name', 'logout');

            Route::get($logoutPath, [SSOController::class, 'logout'])->name($logoutName);
        }

        Route::get('/sso/callback', [SSOController::class, 'callback'])
            ->block(
                $oauthRouteLockSeconds,
                $oauthRouteLockWaitSeconds,
            )
            ->name('sso.callback');
});

// Endpoint de API para o Webhook de Logout Global
Route::post('/api/sso/webhook-logout', [SSOController::class, 'webhookLogout'])
    ->name('sso.webhook-logout');
