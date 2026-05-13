<?php

namespace PortalSistemas\SSOClient\Tests\Agente;

use PortalSistemas\SSOClient\Agente\AgenteResponse;
use PHPUnit\Framework\TestCase;

class AgenteResponseLegacyTest extends TestCase
{
    public function test_success_can_be_serialized_for_legacy_php_without_laravel_bootstrap(): void
    {
        $response = AgenteResponse::success('OS-456', 'Ordem criada.');

        if (is_array($response)) {
            $this->assertSame(201, $response['status']);
            $this->assertSame('OS-456', $response['body']['protocolo']);
            return;
        }

        $this->assertSame(201, $response->getStatusCode());
    }

    public function test_legacy_helpers_mask_sensitive_payload_fields(): void
    {
        $payload = [
            'dados' => [
                'campos' => [
                    'local' => 'Sala 1',
                    'senha_wifi' => 'segredo',
                ],
            ],
        ];

        $masked = agente_mask_payload_by_schema($payload, [
            ['chave' => 'senha_wifi', 'tipo' => 'string', 'sensivel' => true],
        ]);

        $this->assertSame('Sala 1', $masked['dados']['campos']['local']);
        $this->assertSame('[REDACTED]', $masked['dados']['campos']['senha_wifi']);
    }

    public function test_legacy_helper_emits_response_with_request_id(): void
    {
        $response = [
            'status' => 202,
            'body' => [
                'protocolo' => 'OS-123',
            ],
        ];

        ob_start();
        agente_emit_response($response, 'req-abc');
        $json = ob_get_clean();

        $this->assertIsString($json);
        $decoded = json_decode($json, true);

        $this->assertSame('OS-123', $decoded['protocolo']);
        $this->assertSame('req-abc', $decoded['request_id']);
    }

    public function test_legacy_helper_builds_field_error(): void
    {
        $this->assertSame([
            'campo' => 'usuario.codpes',
            'codigo' => 'valor_invalido',
            'mensagem' => 'usuario.codpes deve ser numérico.',
        ], agente_field_error('usuario.codpes', 'valor_invalido', 'usuario.codpes deve ser numérico.'));
    }

    public function test_legacy_helper_resolves_operation_mode_from_config(): void
    {
        $operation = agente_resolve_operation_mode([
            'modo_operacao' => ' persist ',
        ]);

        $this->assertSame('persist', $operation['modo_operacao']);
    }

    public function test_legacy_helper_accepts_incoming_request_id(): void
    {
        $_SERVER['HTTP_X_REQUEST_ID'] = 'req-test-123';

        try {
            $this->assertSame('req-test-123', agente_generate_request_id());
        } finally {
            unset($_SERVER['HTTP_X_REQUEST_ID']);
        }
    }

    public function test_legacy_helper_sanitizes_log_context(): void
    {
        $sanitized = agente_sanitize_log_context([
            'Authorization' => 'Bearer abc',
            'nested' => [
                'client_secret' => 'secret-value',
                'normal' => 'ok',
            ],
            'long' => str_repeat('x', 2100),
        ]);

        $this->assertSame('[REDACTED]', $sanitized['Authorization']);
        $this->assertSame('[REDACTED]', $sanitized['nested']['client_secret']);
        $this->assertSame('ok', $sanitized['nested']['normal']);
        $this->assertStringEndsWith('...[truncated]', $sanitized['long']);
    }

    public function test_legacy_helper_writes_jsonl_log_event(): void
    {
        $dir = sys_get_temp_dir() . '/sso-client-log-test-' . bin2hex(random_bytes(4));

        agente_configure_log([
            'dir' => $dir,
            'prefix' => 'test-agente',
            'request_id' => 'req-log-test',
        ]);

        agente_log_event('info', 'test_event', [
            'token' => 'secret-token',
            'ok' => true,
        ]);

        $file = $dir . DIRECTORY_SEPARATOR . 'test-agente-' . date('Y-m-d') . '.log';
        $this->assertFileExists($file);

        $line = trim((string) file_get_contents($file));
        $decoded = json_decode($line, true);

        $this->assertSame('info', $decoded['level']);
        $this->assertSame('test_event', $decoded['event']);
        $this->assertSame('req-log-test', $decoded['request_id']);
        $this->assertSame('[REDACTED]', $decoded['context']['token']);
        $this->assertTrue($decoded['context']['ok']);

        unlink($file);
        rmdir($dir);
    }
}
