<?php

namespace PortalSistemas\SSOClient\Tests\Agente;

use PortalSistemas\SSOClient\Agente\AgenteAuthException;
use PortalSistemas\SSOClient\Agente\AgenteEndpoint;
use PortalSistemas\SSOClient\Agente\SchemaValidator;
use PortalSistemas\SSOClient\Agente\TokenValidator;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class AgenteEndpointTest extends TestCase
{
    public function test_returns_401_when_authorization_header_is_missing(): void
    {
        $endpoint = AgenteEndpoint::fromArray(['dados' => ['campos' => []]], []);
        $validator = $this->buildValidatorActive();

        $this->expectException(AgenteAuthException::class);

        try {
            $endpoint->authenticate($validator);
        } catch (AgenteAuthException $e) {
            $this->assertSame(401, $e->httpStatus());
            throw $e;
        }
    }

    public function test_returns_401_when_authorization_header_is_not_bearer(): void
    {
        $endpoint = AgenteEndpoint::fromArray(['dados' => ['campos' => []]], [
            'Authorization' => 'Token abc',
        ]);
        $validator = $this->buildValidatorActive();

        $this->expectException(AgenteAuthException::class);

        try {
            $endpoint->authenticate($validator);
        } catch (AgenteAuthException $e) {
            $this->assertSame(401, $e->httpStatus());
            throw $e;
        }
    }

    public function test_accepts_request_with_valid_bearer_token_and_scope(): void
    {
        $endpoint = AgenteEndpoint::fromArray([
            'dados' => [
                'campos' => [
                    'local' => 'Laboratorio 1',
                ],
            ],
        ], [
            'Authorization' => 'Bearer token-valido',
            'X-Usuario-ID' => '999',
        ]);
        $validator = $this->buildValidatorActive();

        $endpoint->authenticate($validator)->validatePayload(new SchemaValidator([
            ['chave' => 'local', 'tipo' => 'string', 'obrigatorio' => true],
        ]));

        $this->assertSame('Laboratorio 1', $endpoint->request()->campo('local'));
    }

    private function buildValidatorActive(): TokenValidator
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'active' => true,
                'client_id' => 'client-id',
                'scopes' => ['agente:executar'],
            ])),
        ]);

        return new TokenValidator([
            'introspection_endpoint' => 'https://sso.example/api/oauth/introspect',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'required_scopes' => ['agente:executar'],
        ], null, new Client(['handler' => HandlerStack::create($mock)]));
    }
}
