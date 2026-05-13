<?php

namespace PortalSistemas\SSOClient\Tests\Agente;

use PortalSistemas\SSOClient\Agente\AgenteResponse;
use PortalSistemas\SSOClient\Tests\TestCase;
use Illuminate\Http\JsonResponse;

class AgenteResponseTest extends TestCase
{
    public function test_success_returns_laravel_json_response_with_expected_payload(): void
    {
        $response = AgenteResponse::success('CH-2026-000123', 'Chamado criado com sucesso.');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame([
            'protocolo' => 'CH-2026-000123',
            'resposta_direta' => 'Chamado criado com sucesso.',
        ], $response->getData(true));
    }

    public function test_error_returns_laravel_json_response_with_expected_payload(): void
    {
        $response = AgenteResponse::error('Campo patrimonio obrigatorio.', 422, [
            ['campo' => 'patrimonio'],
        ]);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([
            'error' => 'Campo patrimonio obrigatorio.',
            'details' => [
                ['campo' => 'patrimonio'],
            ],
        ], $response->getData(true));
    }

    public function test_accepted_returns_status_202(): void
    {
        $response = AgenteResponse::accepted('OS-123', 'Ordem de servico recebida.');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('OS-123', $response->getData(true)['protocolo']);
    }
}
