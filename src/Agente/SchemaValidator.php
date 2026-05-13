<?php

namespace PortalSistemas\SSOClient\Agente;

class SchemaValidator
{
    private const SUPPORTED_TYPES = [
        'string',
        'text',
        'integer',
        'number',
        'boolean',
        'date',
        'datetime',
        'email',
        'enum',
        'multi_enum',
    ];

    /** @var array<int, array<string, mixed>> */
    private $schema;

    /** @var array<int, array<string, mixed>> */
    private $errors = [];

    /**
     * @param array<int, array<string, mixed>> $schema
     */
    public function __construct(array $schema)
    {
        $this->schema = $schema;
    }

    /**
     * @param array<string, mixed> $campos
     */
    public function validate(array $campos): void
    {
        $this->errors = [];

        foreach ($this->schema as $field) {
            $chave = $this->readFieldKey($field);
            if ($chave === null) {
                continue;
            }

            $tipo = $this->normalizeType($field['tipo'] ?? 'string');
            if (!in_array($tipo, self::SUPPORTED_TYPES, true)) {
                $this->addError($chave, 'schema_invalido', sprintf('Tipo de schema não suportado para "%s".', $chave));
                continue;
            }

            $obrigatorio = isset($field['obrigatorio']) ? (bool) $field['obrigatorio'] : false;
            $valorExiste = array_key_exists($chave, $campos);
            $valor = $valorExiste ? $campos[$chave] : null;

            if (!$valorExiste || $this->isEmptyValue($valor, $tipo)) {
                if ($obrigatorio) {
                    $this->addError($chave, 'campo_obrigatorio', sprintf('O campo "%s" é obrigatório.', $chave));
                }
                continue;
            }

            $this->validateFieldValue($chave, $tipo, $valor, $field);
        }

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

    /**
     * @param mixed $value
     * @param array<string, mixed> $field
     */
    private function validateFieldValue(string $chave, string $tipo, $value, array $field): void
    {
        switch ($tipo) {
            case 'string':
            case 'text':
                if (!is_string($value)) {
                    $this->addTypeError($chave, $tipo);
                }
                return;
            case 'integer':
                if (!(is_int($value) || (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1))) {
                    $this->addTypeError($chave, $tipo);
                }
                return;
            case 'number':
                if (!(is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))) {
                    $this->addTypeError($chave, $tipo);
                }
                return;
            case 'boolean':
                if (!is_bool($value)) {
                    $this->addTypeError($chave, $tipo);
                }
                return;
            case 'date':
                if (!is_string($value) || !$this->isValidDateFormat($value, 'Y-m-d')) {
                    $this->addTypeError($chave, $tipo);
                }
                return;
            case 'datetime':
                if (!is_string($value) || !$this->isValidIsoDateTime($value)) {
                    $this->addTypeError($chave, $tipo);
                }
                return;
            case 'email':
                if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $this->addTypeError($chave, $tipo);
                }
                return;
            case 'enum':
                $this->validateEnum($chave, $value, $field);
                return;
            case 'multi_enum':
                $this->validateMultiEnum($chave, $value, $field);
                return;
            default:
                $this->addError($chave, 'tipo_nao_suportado', sprintf('Tipo "%s" não suportado.', $tipo));
        }
    }

    /**
     * @param mixed $value
     * @param array<string, mixed> $field
     */
    private function validateEnum(string $chave, $value, array $field): void
    {
        if (!is_scalar($value)) {
            $this->addTypeError($chave, 'enum');
            return;
        }

        $opcoes = $this->extractOptions($field);
        if (!empty($opcoes) && !in_array((string) $value, $opcoes, true)) {
            $this->addError($chave, 'enum_invalido', sprintf('O campo "%s" deve ser um dos valores permitidos.', $chave));
        }
    }

    /**
     * @param mixed $value
     * @param array<string, mixed> $field
     */
    private function validateMultiEnum(string $chave, $value, array $field): void
    {
        if (!is_array($value)) {
            $this->addTypeError($chave, 'multi_enum');
            return;
        }

        $opcoes = $this->extractOptions($field);

        foreach ($value as $item) {
            if (!is_scalar($item)) {
                $this->addTypeError($chave, 'multi_enum');
                return;
            }

            if (!empty($opcoes) && !in_array((string) $item, $opcoes, true)) {
                $this->addError($chave, 'enum_invalido', sprintf('O campo "%s" contém valor fora das opções permitidas.', $chave));
                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $field
     * @return list<string>
     */
    private function extractOptions(array $field): array
    {
        $opcoes = $field['opcoes'] ?? [];
        if (!is_array($opcoes)) {
            return [];
        }

        $resultado = [];

        foreach ($opcoes as $opcao) {
            if (is_scalar($opcao)) {
                $resultado[] = (string) $opcao;
            }
        }

        return $resultado;
    }

    /**
     * @param mixed $value
     */
    private function isEmptyValue($value, string $tipo): bool
    {
        if ($value === null) {
            return true;
        }

        if (($tipo === 'multi_enum') && is_array($value) && count($value) === 0) {
            return true;
        }

        return is_string($value) && trim($value) === '';
    }

    /**
     * @param mixed $value
     */
    private function isValidDateFormat($value, string $format): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $dt = \DateTimeImmutable::createFromFormat('!' . $format, $value);

        return $dt !== false && $dt->format($format) === $value;
    }

    private function isValidIsoDateTime(string $value): bool
    {
        $formats = [
            \DateTimeInterface::ATOM,
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:s.uP',
            'Y-m-d H:i:s',
        ];

        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value);
            if ($dt !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function readFieldKey(array $field): ?string
    {
        $candidate = $field['chave'] ?? ($field['nome'] ?? null);

        if (!is_scalar($candidate)) {
            return null;
        }

        $value = trim((string) $candidate);

        return $value !== '' ? $value : null;
    }

    /**
     * @param mixed $value
     */
    private function normalizeType($value): string
    {
        if (!is_scalar($value)) {
            return 'string';
        }

        return strtolower(trim((string) $value));
    }

    private function addTypeError(string $chave, string $tipo): void
    {
        $this->addError(
            $chave,
            'tipo_invalido',
            sprintf('O campo "%s" deve ser do tipo "%s".', $chave, $tipo)
        );
    }

    private function addError(string $chave, string $code, string $message): void
    {
        $this->errors[] = [
            'campo' => $chave,
            'codigo' => $code,
            'mensagem' => $message,
        ];
    }
}
