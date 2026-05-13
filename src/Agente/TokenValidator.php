<?php

namespace PortalSistemas\SSOClient\Agente;

use PortalSistemas\SSOClient\Cache\CacheHandlerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class TokenValidator
{
    private const DEFAULT_CACHE_TTL = 60;
    private const EXPIRATION_SAFETY_SECONDS = 5;

    /** @var array<string, mixed> */
    private $config;

    /** @var CacheHandlerInterface|null */
    private $cache;

    /** @var Client */
    private $http;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config, ?CacheHandlerInterface $cache = null, ?Client $http = null)
    {
        $this->config = $config;
        $this->cache = $cache;
        $this->http = $http ?: new Client([
            'verify' => isset($config['verify_ssl']) ? (bool) $config['verify_ssl'] : true,
            'timeout' => isset($config['timeout']) ? (float) $config['timeout'] : 5.0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function validate(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new AgenteAuthException('unauthorized', 'Token ausente ou inválido.', 401);
        }

        $cacheKey = 'agente_introspection:' . sha1($token);
        $cached = $this->readActiveCache($cacheKey);
        if ($cached !== null) {
            $this->assertExpectedClient($cached);
            $this->assertRequiredScopes($cached);
            return $cached;
        }

        $claims = $this->introspect($token);
        if (($claims['active'] ?? false) !== true) {
            throw new AgenteAuthException('unauthorized', 'Token ausente ou inválido.', 401);
        }

        $this->assertExpectedClient($claims);
        $this->assertRequiredScopes($claims);
        $this->writeActiveCache($cacheKey, $claims);

        return $claims;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertRequiredScopes(array $claims): void
    {
        $requiredScopes = $this->requiredScopes();
        if (empty($requiredScopes)) {
            return;
        }

        $actualScopes = $this->normalizeScopes($claims['scopes'] ?? []);
        foreach ($requiredScopes as $scope) {
            if (!in_array($scope, $actualScopes, true)) {
                throw new AgenteAuthException('forbidden', 'Token válido, mas sem escopo obrigatório.', 403);
            }
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertExpectedClient(array $claims): void
    {
        $expectedClientId = trim((string) ($this->config['expected_client_id'] ?? $this->config['client_id'] ?? ''));
        if ($expectedClientId === '') {
            return;
        }

        $tokenClientId = trim((string) ($claims['client_id'] ?? ''));
        if ($tokenClientId === '' || !hash_equals($expectedClientId, $tokenClientId)) {
            throw new AgenteAuthException('unauthorized', 'Token ausente ou inválido.', 401);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function introspect(string $token): array
    {
        $endpoint = (string) ($this->config['introspection_endpoint'] ?? '');
        $clientId = (string) ($this->config['client_id'] ?? '');
        $clientSecret = (string) ($this->config['client_secret'] ?? '');

        if ($endpoint === '' || $clientId === '' || $clientSecret === '') {
            throw new AgenteAuthException('unauthorized', 'Token ausente ou inválido.', 401);
        }

        try {
            $response = $this->http->post($endpoint, [
                'auth' => [$clientId, $clientSecret],
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'token' => $token,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new AgenteAuthException('unauthorized', 'Token ausente ou inválido.', 401, 0, $e);
        }

        if ($response->getStatusCode() !== 200) {
            throw new AgenteAuthException('unauthorized', 'Token ausente ou inválido.', 401);
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw new AgenteAuthException('unauthorized', 'Token ausente ou inválido.', 401);
        }

        return $decoded;
    }

    /**
     * @return array<int, string>
     */
    private function requiredScopes(): array
    {
        $required = $this->config['required_scopes'] ?? ['agente:executar'];
        if (!is_array($required)) {
            return ['agente:executar'];
        }

        $result = [];
        foreach ($required as $scope) {
            if (!is_scalar($scope)) {
                continue;
            }
            $value = trim((string) $scope);
            if ($value !== '') {
                $result[] = $value;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @param mixed $rawScopes
     * @return array<int, string>
     */
    private function normalizeScopes($rawScopes): array
    {
        if (is_string($rawScopes)) {
            $rawScopes = preg_split('/\s+/', trim($rawScopes)) ?: [];
        }

        if (!is_array($rawScopes)) {
            return [];
        }

        $result = [];
        foreach ($rawScopes as $scope) {
            if (!is_scalar($scope)) {
                continue;
            }
            $value = trim((string) $scope);
            if ($value !== '') {
                $result[] = $value;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readActiveCache(string $cacheKey): ?array
    {
        if ($this->cache === null) {
            return null;
        }

        $cached = $this->cache->get($cacheKey);
        if (!is_array($cached)) {
            return null;
        }

        if (($cached['active'] ?? false) !== true) {
            return null;
        }

        return $cached;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function writeActiveCache(string $cacheKey, array $claims): void
    {
        if ($this->cache === null) {
            return;
        }

        if (($claims['active'] ?? false) !== true) {
            return;
        }

        $ttl = isset($this->config['cache_ttl']) ? (int) $this->config['cache_ttl'] : self::DEFAULT_CACHE_TTL;
        if ($ttl < 1) {
            $ttl = self::DEFAULT_CACHE_TTL;
        }

        $expiresIn = $this->secondsUntilExpiration($claims);
        if ($expiresIn !== null) {
            $ttl = min($ttl, $expiresIn);
        }

        if ($ttl < 1) {
            return;
        }

        $this->cache->put($cacheKey, $claims, $ttl);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function secondsUntilExpiration(array $claims): ?int
    {
        $expiresAt = $claims['expires_at'] ?? null;
        if (!is_string($expiresAt) || trim($expiresAt) === '') {
            return null;
        }

        try {
            $expiresTs = (new \DateTimeImmutable($expiresAt))->getTimestamp();
        } catch (\Exception $e) {
            return null;
        }

        return $expiresTs - time() - self::EXPIRATION_SAFETY_SECONDS;
    }
}
