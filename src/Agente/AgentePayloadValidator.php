<?php

namespace PortalSistemas\SSOClient\Agente;

class AgentePayloadValidator
{
    /** @var array<string, mixed> */
    private $config;

    /** @var array<int, array<string, mixed>> */
    private $errors = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'origem' => 'agente_ia',
            'acao' => null,
            'schema_version' => 1,
            'payload_version' => 'v1',
            'required_usuario' => ['nome', 'email'],
            'required_dados' => ['titulo', 'descricao'],
        ], $config);
    }

    public function validate(AgenteRequest $request): void
    {
        $this->errors = [];
        $payload = $request->payload();

        $this->validateHeaders($request);
        $this->validateEnvelope($payload);
        $this->validateUsuario($payload);
        $this->validateDados($payload);

        if (!empty($this->errors)) {
            throw new AgenteValidationException($this->errors);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    private function validateHeaders(AgenteRequest $request): void
    {
        $expectedPayloadVersion = $this->stringConfig('payload_version');
        $payloadVersion = $request->payloadVersion();
        if ($expectedPayloadVersion !== null && $payloadVersion !== null && $payloadVersion !== $expectedPayloadVersion) {
            $this->addError(
                'header.X-Agente-Payload-Version',
                'valor_invalido',
                sprintf('X-Agente-Payload-Version deve ser %s.', $expectedPayloadVersion)
            );
        }

        $expectedSchemaVersion = $this->intConfig('schema_version');
        $schemaVersionHeader = $request->header('x-agente-schema-version');
        if ($expectedSchemaVersion !== null && $schemaVersionHeader !== null && trim($schemaVersionHeader) !== (string) $expectedSchemaVersion) {
            $this->addError(
                'header.X-Agente-Schema-Version',
                'valor_invalido',
                sprintf('X-Agente-Schema-Version deve ser %d.', $expectedSchemaVersion)
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validateEnvelope(array $payload): void
    {
        $expectedOrigem = $this->stringConfig('origem');
        if ($expectedOrigem !== null && ($payload['origem'] ?? null) !== $expectedOrigem) {
            $this->addError('origem', 'valor_invalido', sprintf('origem deve ser %s.', $expectedOrigem));
        }

        $expectedAcao = $this->stringConfig('acao');
        if ($expectedAcao !== null && ($payload['acao'] ?? null) !== $expectedAcao) {
            $this->addError('acao', 'valor_invalido', sprintf('ação deve ser %s.', $expectedAcao));
        }

        $expectedSchemaVersion = $this->intConfig('schema_version');
        if ($expectedSchemaVersion !== null && ($payload['schema_version'] ?? null) !== $expectedSchemaVersion) {
            $this->addError('schema_version', 'valor_invalido', sprintf('schema_version deve ser %d.', $expectedSchemaVersion));
        }

        if (!is_array($payload['usuario'] ?? null)) {
            $this->addError('usuario', 'campo_obrigatorio', 'usuário é obrigatório no payload v1.');
        }

        if (!is_array($payload['dados'] ?? null)) {
            $this->addError('dados', 'campo_obrigatorio', 'dados são obrigatórios no payload v1.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validateUsuario(array $payload): void
    {
        $usuario = $payload['usuario'] ?? null;
        if (!is_array($usuario)) {
            return;
        }

        foreach ($this->stringListConfig('required_usuario') as $field) {
            $value = $usuario[$field] ?? null;

            if ($field === 'email') {
                if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $this->addError('usuario.email', 'valor_invalido', 'usuario.email deve ser válido.');
                }
                continue;
            }

            if (!is_scalar($value) || trim((string) $value) === '') {
                $this->addError('usuario.' . $field, 'campo_obrigatorio', sprintf('usuario.%s é obrigatório.', $field));
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validateDados(array $payload): void
    {
        $dados = $payload['dados'] ?? null;
        if (!is_array($dados)) {
            return;
        }

        foreach ($this->stringListConfig('required_dados') as $field) {
            $value = $dados[$field] ?? null;

            if (!is_scalar($value) || trim((string) $value) === '') {
                $this->addError('dados.' . $field, 'campo_obrigatorio', sprintf('dados.%s é obrigatório.', $field));
            }
        }
    }

    private function stringConfig(string $key): ?string
    {
        $value = $this->config[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function intConfig(string $key): ?int
    {
        $value = $this->config[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function stringListConfig(string $key): array
    {
        $values = $this->config[$key] ?? [];
        if (!is_array($values)) {
            return [];
        }

        $result = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '') {
                $result[] = $value;
            }
        }

        return array_values(array_unique($result));
    }

    private function addError(string $campo, string $codigo, string $mensagem): void
    {
        $this->errors[] = [
            'campo' => $campo,
            'codigo' => $codigo,
            'mensagem' => $mensagem,
        ];
    }
}
