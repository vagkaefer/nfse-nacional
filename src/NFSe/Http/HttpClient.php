<?php

declare(strict_types=1);

namespace NFSe\Http;

use NFSe\Certificate\Certificado;
use NFSe\Exception\NFSeException;

/**
 * Transporte HTTP com autenticação mTLS.
 *
 * Único ponto da biblioteca que toca o cURL. Não lança exceção para status
 * HTTP 4xx/5xx — devolve a resposta e deixa a decisão para a camada de cima;
 * lança NFSeException apenas em erros de transporte (DNS, timeout, TLS).
 *
 * A classe não é final de propósito: testes a estendem com respostas
 * enfileiradas (ver tests/Support/StubHttpClient.php).
 */
class HttpClient
{
    public function __construct(
        private readonly ?Certificado $certificado,
        private readonly string $userAgent,
        private readonly int $timeout = 60,
        private readonly int $connectTimeout = 30,
    ) {}

    /**
     * @param array<string> $headers Headers adicionais no formato "Nome: valor"
     */
    public function request(string $method, string $url, ?string $body = null, array $headers = []): HttpResponse
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new NFSeException("Erro ao inicializar cURL para {$url}");
        }

        try {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);

            if ($this->certificado !== null) {
                curl_setopt($ch, CURLOPT_SSLCERT, $this->certificado->getCertPemPath());
                curl_setopt($ch, CURLOPT_SSLKEY, $this->certificado->getKeyPemPath());
            }

            $headers[] = 'User-Agent: ' . $this->userAgent;

            switch ($method) {
                case 'POST':
                    curl_setopt($ch, CURLOPT_POST, true);
                    break;
                case 'GET':
                    break;
                default:
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            }

            if ($body !== null && $method !== 'GET') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                $headers[] = 'Content-Length: ' . strlen($body);
            }

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $responseBody = curl_exec($ch);

            if ($responseBody === false) {
                throw new NFSeException('Erro na requisição para ' . $url . ': ' . curl_error($ch));
            }

            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            return new HttpResponse($statusCode, (string) $responseBody);
        } finally {
            curl_close($ch);
        }
    }
}
