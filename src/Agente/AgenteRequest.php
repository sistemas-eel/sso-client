<?php

namespace PortalSistemas\SSOClient\Agente;

class AgenteRequest
{
    /** @var array<string, mixed> */
    private $payload;

    /** @var array<string, string> */
    private $headers;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $headers
     */
    private function __construct(array $payload, array $headers = [])
    {
        $this->payload = $payload;
        $this->headers = self::normalizeHeaders($headers);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $headers
     */
    public static function fromArray(array $payload, array $headers = []): self
    {
        return new self($payload, $headers);
    }

    /**
     * @param mixed $request
     */
    public static function fromLaravelRequest($request): self
    {
        $payload = [];
        $headers = [];

        if (is_object($request) && method_exists($request, 'all')) {
            $rawPayload = $request->all();
            if (is_array($rawPayload)) {
                $payload = $rawPayload;
            }
        }

        if (is_object($request) && isset($request->headers) && is_object($request->headers) && method_exists($request->headers, 'all')) {
            $rawHeaders = $request->headers->all();
            if (is_array($rawHeaders)) {
                foreach ($rawHeaders as $key => $value) {
                    if (is_array($value)) {
                        $headers[$key] = implode(', ', array_map('strval', $value));
                        continue;
                    }

                    if (is_scalar($value)) {
                        $headers[$key] = (string) $value;
                    }
                }
            }
        }

        return new self($payload, $headers);
    }

    public static function fromGlobals(): self
    {
        $rawBody = file_get_contents('php://input');
        $payload = [];

        if (is_string($rawBody) && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        return new self($payload, self::extractHeadersFromServer($_SERVER));
    }

    public function origem(): ?string
    {
        return $this->stringOrNull($this->payload['origem'] ?? null);
    }

    public function acao(): ?string
    {
        return $this->stringOrNull($this->payload['acao'] ?? null);
    }

    public function schemaVersion(): ?int
    {
        return $this->intOrNull($this->payload['schema_version'] ?? null);
    }

    public function solicitacaoIdOrigem(): ?string
    {
        return $this->stringOrNull($this->payload['solicitacao_id_origem'] ?? null);
    }

    public function titulo(): ?string
    {
        return $this->stringOrNull($this->payload['dados']['titulo'] ?? null);
    }

    public function descricao(): ?string
    {
        return $this->stringOrNull($this->payload['dados']['descricao'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function campos(): array
    {
        $campos = $this->payload['dados']['campos'] ?? [];

        return is_array($campos) ? $campos : [];
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function campo(string $chave, $default = null)
    {
        $campos = $this->campos();

        return array_key_exists($chave, $campos) ? $campos[$chave] : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function usuario(): array
    {
        $usuario = $this->payload['usuario'] ?? [];

        return is_array($usuario) ? $usuario : [];
    }

    public function solicitanteNome(): ?string
    {
        return $this->stringOrNull($this->usuario()['nome'] ?? null);
    }

    public function solicitanteEmail(): ?string
    {
        return $this->stringOrNull($this->usuario()['email'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function classificacaoIa(): array
    {
        $classificacao = $this->payload['ia']['classificacao'] ?? [];

        return is_array($classificacao) ? $classificacao : [];
    }

    public function payloadVersion(): ?string
    {
        return $this->header('x-agente-payload-version');
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        $normalized = strtolower(trim($name));

        return $this->headers[$normalized] ?? null;
    }

    /**
     * @param mixed $value
     */
    private function stringOrNull($value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param mixed $value
     */
    private function intOrNull($value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, string>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $key => $value) {
            $normalizedKey = strtolower(trim((string) $key));
            if ($normalizedKey === '') {
                continue;
            }

            if (is_array($value)) {
                $normalized[$normalizedKey] = implode(', ', array_map('strval', $value));
                continue;
            }

            if (is_scalar($value)) {
                $normalized[$normalizedKey] = (string) $value;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function extractHeadersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (strpos($key, 'HTTP_') === 0) {
                $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
                if (is_scalar($value)) {
                    $headers[$headerName] = (string) $value;
                }
            }
        }

        if (isset($server['CONTENT_TYPE']) && is_scalar($server['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $server['CONTENT_TYPE'];
        }

        if (isset($server['CONTENT_LENGTH']) && is_scalar($server['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $server['CONTENT_LENGTH'];
        }

        if (isset($server['AUTHORIZATION']) && is_scalar($server['AUTHORIZATION'])) {
            $headers['authorization'] = (string) $server['AUTHORIZATION'];
        }

        return $headers;
    }
}
