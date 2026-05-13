<?php

namespace PortalSistemas\SSOClient\Tests;

use Illuminate\Support\Facades\Route;

class LaravelLoginRouteTest extends TestCase
{
    public function test_library_registers_default_laravel_login_route(): void
    {
        $this->assertTrue(Route::has('login'));
        $this->assertSame('/login', route('login', [], false));
    }

    public function test_library_registers_default_laravel_logout_route(): void
    {
        $this->assertTrue(Route::has('logout'));
        $this->assertSame('/logout', route('logout', [], false));
    }

    public function test_default_login_route_redirects_to_sso_authorization(): void
    {
        $response = $this->get('/login');

        $response->assertRedirect();
        $this->assertStringStartsWith(
            'https://sso.example.test/oauth/authorize?',
            $response->headers->get('Location', '')
        );
    }

    public function test_default_logout_route_uses_library_logout_flow(): void
    {
        $response = $this->get('/logout');

        $response->assertRedirect('/');
    }
}
