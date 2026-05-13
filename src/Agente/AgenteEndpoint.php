<?php

namespace PortalSistemas\SSOClient\Agente;

class AgenteEndpoint
{
    /** @var AgenteRequest */
    private $request;

    /** @var array<string, mixed>|null */
    private $claims;

    private function __construct(AgenteRequest $request)
    {
        $this->request = $request;
        $this->claims = null;
    }

    public static function fromGlobals(): self
    {
        return new self(AgenteRequest::fromGlobals());
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $headers
     */
    public static function fromArray(array $payload, array $headers = []): self
    {
        return new self(AgenteRequest::fromArray($payload, $headers));
    }

    public function authenticate(TokenValidator $validator): self
    {
        $authorization = $this->request->header('authorization');
        if (!is_string($authorization) || stripos($authorization, 'Bearer ') !== 0) {
            throw new AgenteAuthException('unauthorized', 'Token ausente ou inválido.', 401);
        }

        $token = trim(substr($authorization, 7));
        $this->claims = $validator->validate($token);

        return $this;
    }

    public function validatePayload(SchemaValidator $validator): self
    {
        $validator->validate($this->request->campos());
        return $this;
    }

    public function request(): AgenteRequest
    {
        return $this->request;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function claims(): ?array
    {
        return $this->claims;
    }
}

