<?php

namespace PortalSistemas\SSOClient\Laravel;

use PortalSistemas\SSOClient\Core\SSOClient;
use PortalSistemas\SSOClient\Laravel\Http\Middleware\ClientPermissionMiddleware;
use Illuminate\Support\ServiceProvider;

class SSOClientServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/sso-client.php', 'sso-client');

        $this->app->singleton(SSOClient::class, function ($app) {
            $config = $app['config']['sso-client'];

            return new SSOClient(
                $config['server_url'] ?? '',
                $config['client_id'] ?? '',
                $config['client_secret'] ?? '',
                $config['redirect_uri'] ?? '',
                $config['verify_ssl'] ?? true,
                $config['ca_bundle'] ?? null
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/sso-client.php' => config_path('sso-client.php'),
            ], 'sso-client-config');
        }

        $this->loadRoutesFrom(__DIR__ . '/../../routes/sso.php');

        $router = $this->app['router'];
        $router->aliasMiddleware('client_permission', ClientPermissionMiddleware::class);
    }
}
