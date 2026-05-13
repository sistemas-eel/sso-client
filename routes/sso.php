<?php

use PortalSistemas\SSOClient\Laravel\Http\Controllers\SSOController;
use Illuminate\Support\Facades\Route;

Route::middleware(config('sso-client.middleware_group', 'web'))->group(function () {
    if (config('sso-client.login_route.enabled', true)) {
        $loginPath = '/' . ltrim((string) config('sso-client.login_route.path', '/login'), '/');
        $loginName = (string) config('sso-client.login_route.name', 'login');

        Route::get($loginPath, [SSOController::class, 'login'])->name($loginName);
    }

    if (config('sso-client.logout_route.enabled', true)) {
        $logoutPath = '/' . ltrim((string) config('sso-client.logout_route.path', '/logout'), '/');
        $logoutName = (string) config('sso-client.logout_route.name', 'logout');

        Route::get($logoutPath, [SSOController::class, 'logout'])->name($logoutName);
    }

    Route::get('/sso/callback', [SSOController::class, 'callback'])->name('sso.callback');
});

// Endpoint de API para o Webhook de Logout Global
Route::post('/api/sso/webhook-logout', [SSOController::class, 'webhookLogout'])
    ->name('sso.webhook-logout');
