<?php

namespace PortalSistemas\SSOClient\Tests\Agente;

use PortalSistemas\SSOClient\Agente\AgenteRequest;
use PHPUnit\Framework\TestCase;

class AgenteRequestTest extends TestCase
{
    public function test_reads_payload_v1_and_headers(): void
    {
        $request = AgenteRequest::fromArray([
            'origem' => 'agente_ia',
            'acao' => 'abrir_chamado',
            'schema_version' => 1,
            'solicitacao_id_origem' => 123,
            'usuario' => [
                'nome' => 'Teste Usuario',
                'email' => 'teste@usp.br',
            ],
            'dados' => [
                'titulo' => 'Sem rede',
                'descricao' => 'Computador sem rede na sala B12',
                'campos' => [
                    'patrimonio' => 'USP-12345',
                ],
            ],
            'ia' => [
                'classificacao' => [
                    'sistema' => 'os',
                ],
            ],
        ], [
            'X-Agente-Payload-Version' => 'v1',
        ]);

        $this->assertSame('agente_ia', $request->origem());
        $this->assertSame('abrir_chamado', $request->acao());
        $this->assertSame(1, $request->schemaVersion());
        $this->assertSame('123', $request->solicitacaoIdOrigem());
        $this->assertSame('Sem rede', $request->titulo());
        $this->assertSame('Computador sem rede na sala B12', $request->descricao());
        $this->assertSame('USP-12345', $request->campo('patrimonio'));
        $this->assertSame('Teste Usuario', $request->solicitanteNome());
        $this->assertSame('teste@usp.br', $request->solicitanteEmail());
        $this->assertSame(['sistema' => 'os'], $request->classificacaoIa());
        $this->assertSame('v1', $request->payloadVersion());
    }

    public function test_returns_default_when_dynamic_field_does_not_exist(): void
    {
        $request = AgenteRequest::fromArray([
            'dados' => [
                'campos' => [
                    'local' => 'B12',
                ],
            ],
        ]);

        $this->assertSame('padrao', $request->campo('patrimonio', 'padrao'));
    }

    public function test_reads_dynamic_fields_only_from_dados_campos(): void
    {
        $request = AgenteRequest::fromArray([
            'patrimonio' => 'TOPO',
            'dados' => [
                'campos' => [
                    'patrimonio' => 'DADOS',
                ],
            ],
        ]);

        $this->assertSame(['patrimonio' => 'DADOS'], $request->campos());
        $this->assertSame('DADOS', $request->campo('patrimonio'));
    }
}
