<?php

namespace PortalSistemas\SSOClient\Tests\Agente;

use PortalSistemas\SSOClient\Agente\AgentePayloadValidator;
use PortalSistemas\SSOClient\Agente\AgenteRequest;
use PortalSistemas\SSOClient\Agente\AgenteValidationException;
use PHPUnit\Framework\TestCase;

class AgentePayloadValidatorTest extends TestCase
{
    public function test_accepts_valid_payload_v1(): void
    {
        $request = AgenteRequest::fromArray($this->validPayload(), [
            'X-Agente-Payload-Version' => 'v1',
            'X-Agente-Schema-Version' => '1',
        ]);

        (new AgentePayloadValidator([
            'acao' => 'abrir_chamado',
            'required_usuario' => ['nome', 'email', 'codpes'],
        ]))->validate($request);

        $this->assertTrue(true);
    }

    public function test_rejects_invalid_version_headers(): void
    {
        $request = AgenteRequest::fromArray($this->validPayload(), [
            'X-Agente-Payload-Version' => 'v2',
            'X-Agente-Schema-Version' => '2',
        ]);

        try {
            (new AgentePayloadValidator(['acao' => 'abrir_chamado']))->validate($request);
            $this->fail('Expected validation exception.');
        } catch (AgenteValidationException $e) {
            $this->assertSame('header.X-Agente-Payload-Version', $e->errors()[0]['campo']);
            $this->assertSame('header.X-Agente-Schema-Version', $e->errors()[1]['campo']);
        }
    }

    public function test_rejects_missing_required_fields(): void
    {
        $payload = $this->validPayload();
        unset($payload['dados']['titulo'], $payload['usuario']['codpes']);

        try {
            (new AgentePayloadValidator([
                'acao' => 'abrir_chamado',
                'required_usuario' => ['nome', 'email', 'codpes'],
            ]))->validate(AgenteRequest::fromArray($payload));
            $this->fail('Expected validation exception.');
        } catch (AgenteValidationException $e) {
            $fields = array_column($e->errors(), 'campo');

            $this->assertContains('usuario.codpes', $fields);
            $this->assertContains('dados.titulo', $fields);
        }
    }

    public function test_requires_payload_version_in_body_for_v2_contract(): void
    {
        $payload = $this->validPayload();
        $payload['acao'] = 'consultar';
        $payload['dados']['titulo'] = 'Consulta de solicitações';
        $payload['dados']['descricao'] = 'Consulta de solicitações do usuário.';
        $payload['dados']['campos'] = ['protocolo' => 'OS-123'];

        $request = AgenteRequest::fromArray($payload, [
            'X-Agente-Payload-Version' => 'v2',
            'X-Agente-Schema-Version' => '1',
        ]);

        try {
        (new AgentePayloadValidator([
            'acao' => 'consultar',
            'payload_version' => 'v2',
        ]))->validate($request);
            $this->fail('Expected validation exception.');
        } catch (AgenteValidationException $e) {
            $this->assertSame('payload_version', $e->errors()[0]['campo']);
        }
    }

    public function test_accepts_payload_version_in_body_for_v2_contract(): void
    {
        $payload = $this->validPayload();
        $payload['acao'] = 'consultar';
        $payload['payload_version'] = 'v2';
        $payload['dados']['titulo'] = 'Consulta de solicitações';
        $payload['dados']['descricao'] = 'Consulta de solicitações do usuário.';
        $payload['dados']['campos'] = ['protocolo' => 'OS-123'];

        $request = AgenteRequest::fromArray($payload, [
            'X-Agente-Payload-Version' => 'v2',
            'X-Agente-Schema-Version' => '1',
        ]);

            (new AgentePayloadValidator([
                'acao' => 'consultar',
                'payload_version' => 'v2',
            ]))->validate($request);

        $this->assertTrue(true);
    }

    public function test_accepts_action_from_configured_allowed_list(): void
    {
        $payload = $this->validPayload();
        $payload['acao'] = 'abrir_solicitacao';
        $payload['payload_version'] = 'v2';

        $request = AgenteRequest::fromArray($payload, [
            'X-Agente-Payload-Version' => 'v2',
            'X-Agente-Schema-Version' => '1',
        ]);

        (new AgentePayloadValidator([
            'acao' => ['abrir_chamado', 'abrir_solicitacao'],
            'payload_version' => 'v2',
            'required_usuario' => ['nome', 'email', 'codpes'],
        ]))->validate($request);

        $this->assertTrue(true);
    }

    public function test_accepts_generic_query_payload_without_title_or_description_when_not_required(): void
    {
        $payload = $this->validPayload();
        $payload['acao'] = 'consultar';
        $payload['payload_version'] = 'v2';
        unset($payload['dados']['titulo'], $payload['dados']['descricao']);
        $payload['dados']['campos'] = [
            'descricao' => 'informática',
            'limite' => 5,
        ];

        $request = AgenteRequest::fromArray($payload, [
            'X-Agente-Payload-Version' => 'v2',
            'X-Agente-Schema-Version' => '1',
        ]);

        (new AgentePayloadValidator([
            'acao' => 'consultar',
            'payload_version' => 'v2',
            'required_usuario' => ['nome', 'codpes'],
            'required_dados' => [],
        ]))->validate($request);

        $this->assertTrue(true);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'origem' => 'agente_ia',
            'acao' => 'abrir_chamado',
            'schema_version' => 1,
            'solicitacao_id_origem' => 123,
            'usuario' => [
                'nome' => 'Usuario Teste',
                'email' => 'usuario@example.test',
                'codpes' => '123456',
            ],
            'dados' => [
                'titulo' => 'Computador sem rede',
                'descricao' => 'Computador sem acesso a rede.',
                'campos' => [],
            ],
        ];
    }
}
