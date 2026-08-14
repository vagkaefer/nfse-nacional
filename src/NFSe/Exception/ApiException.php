<?php

declare(strict_types=1);

namespace NFSe\Exception;

/**
 * Erro retornado pela API do Sistema Nacional de NFS-e (status HTTP >= 400).
 */
class ApiException extends NFSeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly string $body = '',
    ) {
        parent::__construct($message, $statusCode);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
