<?php

declare(strict_types=1);

namespace NFSe\Tests\Support;

use NFSe\Exception\NFSeException;
use NFSe\Http\HttpClient;
use NFSe\Http\HttpResponse;

/**
 * HttpClient de teste: devolve respostas enfileiradas (ou calculadas por um
 * resolvedor) e grava cada requisição para asserções.
 */
final class StubHttpClient extends HttpClient
{
    /** @var list<HttpResponse> */
    private array $fila = [];

    /** @var callable(string, string, ?string, array<string>): HttpResponse|null */
    private $resolvedor = null;

    /** @var list<array{method: string, url: string, body: ?string, headers: array<string>}> */
    public array $requisicoes = [];

    public function __construct()
    {
        parent::__construct(null, 'stub');
    }

    public function enfileirar(HttpResponse ...$respostas): self
    {
        foreach ($respostas as $resposta) {
            $this->fila[] = $resposta;
        }

        return $this;
    }

    /**
     * @param callable(string, string, ?string, array<string>): HttpResponse $resolvedor
     */
    public function comResolvedor(callable $resolvedor): self
    {
        $this->resolvedor = $resolvedor;

        return $this;
    }

    public function request(string $method, string $url, ?string $body = null, array $headers = []): HttpResponse
    {
        $this->requisicoes[] = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers];

        if ($this->resolvedor !== null) {
            return ($this->resolvedor)($method, $url, $body, $headers);
        }

        $resposta = array_shift($this->fila);
        if ($resposta === null) {
            throw new NFSeException("StubHttpClient: nenhuma resposta enfileirada para {$method} {$url}");
        }

        return $resposta;
    }
}
