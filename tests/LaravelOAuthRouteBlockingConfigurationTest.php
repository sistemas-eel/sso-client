<?php

namespace SistemasEel\SSOClient\Tests;

use Illuminate\Support\Facades\Route;

class LaravelOAuthRouteBlockingConfigurationTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set(
            'sso-client.oauth_state.route_lock_seconds',
            45,
        );

        $app['config']->set(
            'sso-client.oauth_state.route_lock_wait_seconds',
            12,
        );
    }

    public function test_oauth_route_blocking_uses_configured_durations(): void
    {
        $routes = Route::getRoutes();

        $login = $routes->getByName('login');
        $callback = $routes->getByName('sso.callback');

        $this->assertNotNull($login);
        $this->assertNotNull($callback);

        $this->assertSame(45, $login->locksFor());
        $this->assertSame(12, $login->waitsFor());

        $this->assertSame(45, $callback->locksFor());
        $this->assertSame(12, $callback->waitsFor());
    }
}