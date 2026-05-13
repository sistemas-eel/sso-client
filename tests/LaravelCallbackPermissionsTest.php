<?php

namespace PortalSistemas\SSOClient\Tests;

use PortalSistemas\SSOClient\Core\SSOClient;
use PortalSistemas\SSOClient\Tests\Fakes\FakeUser;
use Mockery;

class LaravelCallbackPermissionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeUser::resetStore();
        config()->set('sso-client.user_model', FakeUser::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_callback_syncs_permissions_from_authorization(): void
    {
        session(['oauth_state' => 'abc-state']);

        $mock = Mockery::mock(SSOClient::class);
        $mock->shouldReceive('exchangeCodeForToken')->once()->andReturn([
            'access_token' => 'token-1',
            'refresh_token' => 'refresh-1',
            'expires_in' => 3600,
        ]);
        $mock->shouldReceive('getUserInfo')->once()->andReturn([
            'codpes' => '123456',
            'name' => 'User One',
            'email' => 'user1@example.test',
            'authorization' => [
                'permissions' => ['user', 'admin'],
            ],
        ]);
        $this->app->instance(SSOClient::class, $mock);

        $response = $this->get('/sso/callback?state=abc-state&code=ok');

        $response->assertRedirect('/');
        $this->assertSame(['user', 'admin'], session('client_permissions'));
        $this->assertSame(['user', 'admin'], session('sso_managed_permissions'));

        $user = FakeUser::$usersByCodpes['123456'];
        $this->assertContains('local:keep', $user->permissions);
        $this->assertContains('user', $user->permissions);
        $this->assertContains('admin', $user->permissions);
    }

    public function test_callback_without_authorization_clears_managed_permissions_only(): void
    {
        session(['oauth_state' => 'abc-state']);

        $existing = FakeUser::updateOrCreate(['codpes' => '123456'], ['name' => 'Existing', 'email' => 'existing@example.test']);
        $existing->permissions = ['local:keep', 'user'];
        session(['sso_managed_permissions' => ['user'], 'client_permissions' => ['user']]);

        $mock = Mockery::mock(SSOClient::class);
        $mock->shouldReceive('exchangeCodeForToken')->once()->andReturn([
            'access_token' => 'token-2',
            'refresh_token' => 'refresh-2',
            'expires_in' => 3600,
        ]);
        $mock->shouldReceive('getUserInfo')->once()->andReturn([
            'codpes' => '123456',
            'name' => 'Existing',
            'email' => 'existing@example.test',
        ]);
        $this->app->instance(SSOClient::class, $mock);

        $response = $this->get('/sso/callback?state=abc-state&code=ok');

        $response->assertRedirect('/');
        $this->assertSame([], session('client_permissions'));
        $this->assertNull(session('client_authorization'));
        $this->assertSame([], session('sso_managed_permissions'));
        $this->assertSame(['local:keep'], $existing->permissions);
    }

    public function test_callback_next_login_removes_missing_managed_permission(): void
    {
        $existing = FakeUser::updateOrCreate(['codpes' => '123456'], ['name' => 'Existing', 'email' => 'existing@example.test']);
        $existing->permissions = ['local:keep', 'user', 'admin'];

        session([
            'sso_managed_permissions' => ['user', 'admin'],
            'client_permissions' => ['user', 'admin'],
        ]);

        session(['oauth_state' => 'abc-state-2']);

        $mock = Mockery::mock(SSOClient::class);
        $mock->shouldReceive('exchangeCodeForToken')->once()->andReturn([
            'access_token' => 'token-3',
            'refresh_token' => 'refresh-3',
            'expires_in' => 3600,
        ]);
        $mock->shouldReceive('getUserInfo')->once()->andReturn([
            'codpes' => '123456',
            'name' => 'Existing',
            'email' => 'existing@example.test',
            'authorization' => [
                'permissions' => ['user'],
            ],
        ]);
        $this->app->instance(SSOClient::class, $mock);

        $response = $this->get('/sso/callback?state=abc-state-2&code=ok');

        $response->assertRedirect('/');
        $this->assertSame(['user'], session('client_permissions'));
        $this->assertSame(['user'], session('sso_managed_permissions'));
        $this->assertSame(['local:keep', 'user'], $existing->permissions);
    }

    public function test_callback_keeps_session_permissions_but_does_not_sync_model_permissions_when_disabled(): void
    {
        config()->set('sso-client.authorization.sync_permissions', false);

        $existing = FakeUser::updateOrCreate(['codpes' => '123456'], ['name' => 'Existing', 'email' => 'existing@example.test']);
        $existing->permissions = ['local:keep'];

        session([
            'oauth_state' => 'abc-state-3',
            'sso_managed_permissions' => ['old-sso-permission'],
        ]);

        $mock = Mockery::mock(SSOClient::class);
        $mock->shouldReceive('exchangeCodeForToken')->once()->andReturn([
            'access_token' => 'token-4',
            'refresh_token' => 'refresh-4',
            'expires_in' => 3600,
        ]);
        $mock->shouldReceive('getUserInfo')->once()->andReturn([
            'codpes' => '123456',
            'name' => 'Existing',
            'email' => 'existing@example.test',
            'authorization' => [
                'permissions' => ['admin'],
            ],
        ]);
        $this->app->instance(SSOClient::class, $mock);

        $response = $this->get('/sso/callback?state=abc-state-3&code=ok');

        $response->assertRedirect('/');
        $this->assertSame(['admin'], session('client_permissions'));
        $this->assertNull(session('sso_managed_permissions'));
        $this->assertSame(['local:keep'], $existing->permissions);
    }
}
