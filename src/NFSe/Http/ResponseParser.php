<?php

declare(strict_types=1);

namespace NFSe\Http;

use NFSe\Exception\ApiException;
use NFSe\Exception\NFSeException;

/**
 * Converte respostas da API (JSON ou XML) em array e mapeia erros HTTP.
 */
final class ResponseParser
{
    /**
     * @return array<mixed>
     */
    public static function parse(HttpResponse|string $response): array
    {
        $body = $response instanceof HttpResponse ? $response->body : $response;

        if ($body === '') {
            return [];
        }

        $anterior = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($anterior);

        if ($xml !== false) {
            $convertido = json_decode((string) json_encode($xml), true);

            return is_array($convertido) ? $convertido : [];
        }

        $json = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }

        throw new NFSeException('Resposta inválida da API: ' . $body);
    }

    /**
     * Constrói a exceção apropriada para uma resposta de erro (status >= 400).
     */
    public static function toException(HttpResponse $response): ApiException
    {
        $mensagem = match ($response->statusCode) {
            404 => 'NFS-e/DPS não encontrada (HTTP 404). Verifique a chave/ID e o ambiente.',
            429 => 'Rate limit excedido (HTTP 429 Too Many Requests). Aguarde antes de tentar novamente.',
            496 => 'Certificado SSL requerido ou inválido (HTTP 496). Verifique o certificado digital.',
            default => "Erro HTTP {$response->statusCode}: {$response->body}",
        };

        return new ApiException($mensagem, $response->statusCode, $response->body);
    }
}
