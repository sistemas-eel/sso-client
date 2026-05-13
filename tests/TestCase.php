<?php

namespace PortalSistemas\SSOClient\Tests;

use PortalSistemas\SSOClient\Laravel\SSOClientServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            SSOClientServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('sso-client.server_url', 'https://sso.example.test');
        $app['config']->set('sso-client.client_id', 'test-client');
        $app['config']->set('sso-client.client_secret', 'test-secret');
        $app['config']->set('sso-client.redirect_uri', 'https://app.example.test/sso/callback');
    }
}
