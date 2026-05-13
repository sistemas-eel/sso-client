<?php

namespace PortalSistemas\SSOClient\Tests;

use PortalSistemas\SSOClient\Core\SSOClient;
use PHPUnit\Framework\TestCase;

class SSOClientWebhookSignatureTest extends TestCase
{
    public function test_it_validates_webhook_signature_over_timestamp_nonce_and_raw_body(): void
    {
        $client = new SSOClient('https://sso.example.test', 'client-id', 'secret', 'https://app.example.test/callback');
        $rawBody = '{"codpes":"123456","reason":"logout","timestamp":1710000000,"nonce":"nonce-1"}';
        $timestamp = '1710000000';
        $nonce = 'nonce-1';
        $webhookSecret = 'webhook-secret';
        $signature = hash_hmac('sha256', $timestamp . $nonce . $rawBody, $webhookSecret);

        $this->assertTrue($client->validateWebhookSignature($rawBody, $timestamp, $nonce, $signature, $webhookSecret));
        $this->assertFalse($client->validateWebhookSignature('{"codpes":"123456"}', $timestamp, $nonce, $signature, $webhookSecret));
    }

    public function test_legacy_codpes_only_signature_is_rejected(): void
    {
        $client = new SSOClient('https://sso.example.test', 'client-id', 'secret', 'https://app.example.test/callback');
        $rawBody = '{"codpes":"123456"}';
        $legacySignature = hash_hmac('sha256', '123456', 'webhook-secret');

        $this->assertFalse($client->validateWebhookSignature($rawBody, '1710000000', 'nonce-1', $legacySignature, 'webhook-secret'));
    }
}
