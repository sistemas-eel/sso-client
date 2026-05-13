<?php

namespace PortalSistemas\SSOClient\Agente;

use RuntimeException;

class AgenteValidationException extends RuntimeException
{
    /** @var array<int, array<string, mixed>> */
    private $errors;

    /**
     * @param array<int, array<string, mixed>> $errors
     */
    public function __construct(array $errors, string $message = 'Payload do agente IA inválido.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
