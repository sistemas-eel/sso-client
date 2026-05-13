<?php

namespace PortalSistemas\SSOClient\Tests;

use Illuminate\Support\Facades\Cache;

class LaravelWebhookLogoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sso-client.webhook_secret', 'webhook-secret');
        Cache::flush();
    }

    public function test_webhook_logout_requires_signature_over_timestamp_nonce_and_raw_body(): void
    {
        $rawBody = '{"codpes":"123456","reason":"logout"}';
        $timestamp = (string) time();
        $nonce = 'nonce-1';
        $signature = hash_hmac('sha256', $timestamp . $nonce . $rawBody, 'webhook-secret');

        $response = $this->call('POST', '/api/sso/webhook-logout', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
            'HTTP_X_WEBHOOK_NONCE' => $nonce,
        ], $rawBody);

        $response->assertOk();
        $this->assertNotNull(Cache::get('sso_global_logout_123456'));
    }

    public function test_webhook_logout_rejects_legacy_codpes_only_signature(): void
    {
        $rawBody = '{"codpes":"123456","reason":"logout"}';
        $timestamp = (string) time();
        $legacySignature = hash_hmac('sha256', '123456', 'webhook-secret');

        $response = $this->call('POST', '/api/sso/webhook-logout', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => $legacySignature,
            'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
            'HTTP_X_WEBHOOK_NONCE' => 'nonce-legacy',
        ], $rawBody);

        $response->assertStatus(401);
        $this->assertNull(Cache::get('sso_global_logout_123456'));
    }
}
