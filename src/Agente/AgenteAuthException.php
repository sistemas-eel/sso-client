<?php

namespace PortalSistemas\SSOClient\Agente;

use RuntimeException;

class AgenteAuthException extends RuntimeException
{
    /** @var int */
    private $httpStatus;

    /** @var string */
    private $error;

    public function __construct(string $error, string $message, int $httpStatus = 401, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->error = $error;
        $this->httpStatus = $httpStatus;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function error(): string
    {
        return $this->error;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'error' => $this->error,
            'message' => $this->getMessage(),
        ];
    }
}

