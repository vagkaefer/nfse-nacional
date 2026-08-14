<?php

declare(strict_types=1);

namespace NFSe\Http;

/**
 * Resposta HTTP imutável retornada pelo HttpClient.
 */
final readonly class HttpResponse
{
    public function __construct(
        public int $statusCode,
        public string $body,
    ) {}

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
