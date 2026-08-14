<?php

declare(strict_types=1);

namespace NFSe\Tests\Unit;

use NFSe\Exception\NFSeException;
use NFSe\Http\HttpResponse;
use NFSe\Http\ResponseParser;
use PHPUnit\Framework\TestCase;

final class ResponseParserTest extends TestCase
{
    public function testParseJson(): void
    {
        $this->assertSame(
            ['chaveAcesso' => 'abc', 'numero' => 1],
            ResponseParser::parse('{"chaveAcesso":"abc","numero":1}'),
        );
    }

    public function testParseXml(): void
    {
        $resultado = ResponseParser::parse('<resposta><chave>abc</chave><numero>1</numero></resposta>');

        $this->assertSame(['chave' => 'abc', 'numero' => '1'], $resultado);
    }

    public function testParseRespostaVaziaRetornaArrayVazio(): void
    {
        $this->assertSame([], ResponseParser::parse(''));
        $this->assertSame([], ResponseParser::parse(new HttpResponse(204, '')));
    }

    public function testParseHttpResponseUsaOBody(): void
    {
        $this->assertSame(['ok' => true], ResponseParser::parse(new HttpResponse(200, '{"ok":true}')));
    }

    public function testParseConteudoInvalidoLancaExcecao(): void
    {
        $this->expectException(NFSeException::class);

        ResponseParser::parse('nem json nem xml');
    }

    public function testToExceptionMapeiaStatusConhecidos(): void
    {
        $casos = [
            404 => 'não encontrada',
            429 => 'Rate limit',
            496 => 'Certificado SSL',
            500 => 'Erro HTTP 500',
        ];

        foreach ($casos as $status => $trecho) {
            $e = ResponseParser::toException(new HttpResponse($status, 'corpo'));
            $this->assertSame($status, $e->getStatusCode());
            $this->assertSame('corpo', $e->getBody());
            $this->assertStringContainsString($trecho, $e->getMessage());
        }
    }
}
