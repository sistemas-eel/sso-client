<?php

namespace PortalSistemas\SSOClient\Tests;

use Illuminate\Support\Facades\Route;

class LaravelLoginRouteDisabledTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('sso-client.login_route.enabled', false);
        $app['config']->set('sso-client.logout_route.enabled', false);
    }

    public function test_login_and_logout_routes_can_be_disabled(): void
    {
        $this->assertFalse(Route::has('login'));
        $this->assertFalse(Route::has('logout'));
        $this->assertTrue(Route::has('sso.callback'));
        $this->assertFalse(Route::has('sso.logout'));
    }
}
