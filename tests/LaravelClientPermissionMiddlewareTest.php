<?php

namespace PortalSistemas\SSOClient\Tests;

use Illuminate\Support\Facades\Route;

class LaravelClientPermissionMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'client_permission:admin'])
            ->get('/test-client-admin', function () {
                return response('ok', 200);
            });
    }

    public function test_blocks_when_permission_not_in_session(): void
    {
        session(['client_permissions' => ['user']]);

        $this->get('/test-client-admin')->assertStatus(403);
    }

    public function test_allows_when_permission_exists_in_session(): void
    {
        session(['client_permissions' => ['user', 'admin']]);

        $this->get('/test-client-admin')->assertStatus(200);
    }
}
