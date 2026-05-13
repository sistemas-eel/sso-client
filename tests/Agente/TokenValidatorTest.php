<?php

namespace PortalSistemas\SSOClient\Tests\Agente;

use PortalSistemas\SSOClient\Agente\AgenteAuthException;
use PortalSistemas\SSOClient\Agente\TokenValidator;
use PortalSistemas\SSOClient\Cache\CacheHandlerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class TokenValidatorTest extends TestCase
{
    public function test_throws_401_when_introspection_returns_inactive_token(): void
    {
        $validator = $this->buildValidatorWithResponses([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['active' => false])),
        ]);

        $this->expectException(AgenteAuthException::class);
        $this->expectExceptionMessage('Token ausente ou inválido.');

        try {
            $validator->validate('token-invalido');
        } catch (AgenteAuthException $e) {
            $this->assertSame(401, $e->httpStatus());
            $this->assertSame('unauthorized', $e->error());
            throw $e;
        }
    }

    public function test_throws_403_when_scope_is_missing(): void
    {
        $validator = $this->buildValidatorWithResponses([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'active' => true,
                'client_id' => 'client-id',
                'scopes' => ['read-user'],
            ])),
        ]);

        $this->expectException(AgenteAuthException::class);
        $this->expectExceptionMessage('Token válido, mas sem escopo obrigatório.');

        try {
            $validator->validate('token-sem-escopo');
        } catch (AgenteAuthException $e) {
            $this->assertSame(403, $e->httpStatus());
            $this->assertSame('forbidden', $e->error());
            throw $e;
        }
    }

    public function test_returns_claims_for_active_token_with_required_scope(): void
    {
        $validator = $this->buildValidatorWithResponses([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'active' => true,
                'client_id' => 'client-id',
                'scopes' => ['agente:executar'],
            ])),
        ]);

        $claims = $validator->validate('token-ok');

        $this->assertTrue($claims['active']);
        $this->assertSame('client-id', $claims['client_id']);
        $this->assertSame(['agente:executar'], $claims['scopes']);
    }

    public function test_reuses_positive_cache_and_avoids_second_http_call(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'active' => true,
                'client_id' => 'client-id',
                'scopes' => ['agente:executar'],
            ])),
        ]);

        $history = [];
        $stack = HandlerStack::create($mock);
        $stack->push(\GuzzleHttp\Middleware::history($history));

        $cache = new InMemoryCacheHandler();
        $validator = $this->buildValidator(new Client(['handler' => $stack]), $cache);

        $first = $validator->validate('token-cache');
        $second = $validator->validate('token-cache');

        $this->assertTrue($first['active']);
        $this->assertTrue($second['active']);
        $this->assertCount(1, $history);
    }

    public function test_throws_401_when_token_client_id_does_not_match_expected_client(): void
    {
        $validator = $this->buildValidatorWithResponses([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'active' => true,
                'client_id' => 'other-client',
                'scopes' => ['agente:executar'],
            ])),
        ]);

        $this->expectException(AgenteAuthException::class);

        try {
            $validator->validate('token-client-diferente');
        } catch (AgenteAuthException $e) {
            $this->assertSame(401, $e->httpStatus());
            $this->assertSame('unauthorized', $e->error());
            throw $e;
        }
    }

    public function test_cache_ttl_is_limited_by_token_expiration(): void
    {
        $cache = new InMemoryCacheHandler();
        $validator = $this->buildValidatorWithResponses([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'active' => true,
                'client_id' => 'client-id',
                'scopes' => ['agente:executar'],
                'expires_at' => gmdate('c', time() + 20),
            ])),
        ], $cache);

        $validator->validate('token-curto');

        $this->assertNotNull($cache->lastTtl());
        $this->assertLessThanOrEqual(15, (int) $cache->lastTtl());
        $this->assertGreaterThan(0, (int) $cache->lastTtl());
    }

    /**
     * @param array<int, Response> $responses
     */
    private function buildValidatorWithResponses(array $responses, ?CacheHandlerInterface $cache = null): TokenValidator
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $http = new Client(['handler' => $stack]);

        return $this->buildValidator($http, $cache ?: new InMemoryCacheHandler());
    }

    private function buildValidator(Client $http, CacheHandlerInterface $cache): TokenValidator
    {
        return new TokenValidator([
            'introspection_endpoint' => 'https://sso.example/api/oauth/introspect',
            'client_id' => 'client-id',
            'expected_client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'required_scopes' => ['agente:executar'],
            'cache_ttl' => 60,
            'timeout' => 5,
            'verify_ssl' => true,
        ], $cache, $http);
    }
}

class InMemoryCacheHandler implements CacheHandlerInterface
{
    /** @var array<string, mixed> */
    private $items = [];
    /** @var int|null */
    private $lastTtl;

    public function put(string $key, $value, int $ttl): bool
    {
        $this->items[$key] = $value;
        $this->lastTtl = $ttl;
        return true;
    }

    public function get(string $key, $default = null)
    {
        return array_key_exists($key, $this->items) ? $this->items[$key] : $default;
    }

    public function forget(string $key): bool
    {
        unset($this->items[$key]);
        return true;
    }

    public function lastTtl(): ?int
    {
        return $this->lastTtl;
    }
}
