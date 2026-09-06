<?php

declare(strict_types=1);

namespace NFSe\Tests\Unit;

use NFSe\Certificate\Certificado;
use NFSe\Config\Config;
use NFSe\Exception\ApiException;
use NFSe\Http\HttpResponse;
use NFSe\Models\DPS;
use NFSe\Services\NFSeClient;
use NFSe\Tests\Support\CertificateFactory;
use NFSe\Tests\Support\StubHttpClient;
use NFSe\Utils\AssinaturaDigital;
use PHPUnit\Framework\TestCase;

final class NFSeClientTest extends TestCase
{
    private const CHAVE = '12345678901234567890123456789012345678901234567890';

    /**
     * Contrato OpenAPI oficial da Sefin Nacional, versionado no repositório.
     */
    private const OPENAPI = __DIR__ . '/../../docs/v1.json';

    private static string $pfxPath;
    private static Certificado $certificado;

    public static function setUpBeforeClass(): void
    {
        $cert = CertificateFactory::criarPfx();
        self::$pfxPath = $cert['path'];
        self::$certificado = new Certificado($cert['path'], $cert['senha']);
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$pfxPath);
    }

    private function criarCliente(StubHttpClient $http): NFSeClient
    {
        $config = new Config(Config::AMBIENTE_HOMOLOGACAO, self::$pfxPath, CertificateFactory::SENHA, '4216909');

        return new NFSeClient($config, $http, self::$certificado);
    }

    private function criarDps(): DPS
    {
        return (new DPS())
            ->setTpAmb(2)
            ->setVerAplic('teste')
            ->setSerie('900')
            ->setNDPS('7')
            ->setDCompet('2026-01-15')
            ->setCLocEmi('4216909')
            ->setPrestador(['cnpj' => CertificateFactory::CNPJ])
            ->setValores(['vServ' => 100.0]);
    }

    public function testEmitirEnviaDpsAssinadaGzipBase64(): void
    {
        $http = (new StubHttpClient())->enfileirar(new HttpResponse(201, '{"chaveAcesso":"' . self::CHAVE . '"}'));
        $cliente = $this->criarCliente($http);

        $resultado = $cliente->emitirNFSe($this->criarDps());

        $this->assertSame(['chaveAcesso' => self::CHAVE], $resultado);

        $this->assertCount(1, $http->requisicoes);
        $req = $http->requisicoes[0];
        $this->assertSame('POST', $req['method']);
        $this->assertSame(Config::URL_HOMOLOGACAO . '/nfse', $req['url']);

        $payload = json_decode((string) $req['body'], true);
        $this->assertArrayHasKey('dpsXmlGZipB64', $payload);

        $xmlAssinado = gzdecode((string) base64_decode((string) $payload['dpsXmlGZipB64'], true));
        $this->assertNotFalse($xmlAssinado);
        $this->assertStringContainsString('<infDPS', $xmlAssinado);
        $this->assertTrue(
            AssinaturaDigital::verificarAssinatura($xmlAssinado),
            'O XML enviado deve conter uma assinatura válida',
        );
    }

    /**
     * Nome do campo do corpo da requisição de evento, lido do contrato OpenAPI
     * oficial versionado em docs/v1.json — e não escrito à mão, para que o
     * contrato continue sendo a fonte da verdade.
     */
    private function campoDoPedidoDeEvento(): string
    {
        $contrato = json_decode((string) file_get_contents(self::OPENAPI), true);
        $campo = $contrato['definitions']['EventosPostRequest']['required'][0] ?? null;

        $this->assertIsString($campo, 'docs/v1.json deve declarar o campo obrigatório do pedido de evento');

        return $campo;
    }

    public function testCampoDoPedidoDeEventoSegueOContratoOficial(): void
    {
        $this->assertSame('pedidoRegistroEventoXmlGZipB64', $this->campoDoPedidoDeEvento());
    }

    public function testCancelarEnviaEventoAssinadoComIdDeterministico(): void
    {
        // A API responde 201 (Created) ao registrar o evento, não 200.
        $http = (new StubHttpClient())->enfileirar(new HttpResponse(201, '{"status":"cancelada"}'));
        $cliente = $this->criarCliente($http);

        $resultado = $cliente->cancelarNFSe(self::CHAVE, 'Erro na emissão', 1);

        $this->assertSame(['status' => 'cancelada'], $resultado);

        $req = $http->requisicoes[0];
        $this->assertSame('POST', $req['method']);
        $this->assertSame(Config::URL_HOMOLOGACAO . '/nfse/' . self::CHAVE . '/eventos', $req['url']);

        $payload = json_decode((string) $req['body'], true);
        $campo = $this->campoDoPedidoDeEvento();
        $this->assertArrayHasKey($campo, $payload, 'O corpo deve usar o campo exigido pelo contrato da Sefin');

        $xmlEvento = gzdecode((string) base64_decode((string) $payload[$campo], true));
        $this->assertNotFalse($xmlEvento);

        $dom = new \DOMDocument();
        $dom->loadXML($xmlEvento);
        $infPedReg = $dom->getElementsByTagName('infPedReg')->item(0);
        $this->assertNotNull($infPedReg);

        $id = $infPedReg->getAttribute('Id');
        $this->assertMatchesRegularExpression('/^PRE\d{56}$/', $id);
        $this->assertSame('PRE' . self::CHAVE . '101101', $id, 'Sufixo deve ser o código do evento, não um timestamp');

        $this->assertSame(
            CertificateFactory::CNPJ,
            $dom->getElementsByTagName('CNPJAutor')->item(0)?->textContent,
            'CNPJAutor deve vir do CN do certificado',
        );
        $this->assertSame(self::CHAVE, $dom->getElementsByTagName('chNFSe')->item(0)?->textContent);
        $this->assertSame('1', $dom->getElementsByTagName('cMotivo')->item(0)?->textContent);

        $this->assertTrue(AssinaturaDigital::verificarAssinatura($xmlEvento), 'infPedReg deve estar assinado');
    }

    /**
     * A API oficial do DANFSe está sobrestada desde 03/08/2026 (NT 008): o PDF
     * passa a ser gerado localmente sem sequer consultar o endpoint.
     */
    public function testBaixarPdfGeraLocalmenteSemConsultarEndpointOficial(): void
    {
        $http = (new StubHttpClient())->comResolvedor(
            fn(string $method, string $url): HttpResponse => new HttpResponse(200, $this->consultaBody()),
        );
        $cliente = $this->criarCliente($http);

        $pdf = $cliente->baixarPDF(self::CHAVE);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));

        foreach ($http->requisicoes as $requisicao) {
            $this->assertStringNotContainsString('/danfse/', (string) $requisicao['url']);
        }
    }

    public function testBaixarPdfRetornaPdfOficialQuandoSolicitadoEDisponivel(): void
    {
        $http = (new StubHttpClient())->enfileirar(new HttpResponse(200, '%PDF-1.4 conteudo oficial'));
        $cliente = $this->criarCliente($http);

        $pdf = $cliente->baixarPDF(self::CHAVE, tentarOficial: true);

        $this->assertSame('%PDF-1.4 conteudo oficial', $pdf);
        $this->assertSame(Config::URL_HOMOLOGACAO_PDF . '/' . self::CHAVE, $http->requisicoes[0]['url']);
        $this->assertNull($cliente->getMotivoUltimoFallback());
    }

    public function testBaixarPdfUsaGeracaoLocalQuandoOficialFalha(): void
    {
        $http = (new StubHttpClient())->comResolvedor(
            function (string $method, string $url): HttpResponse {
                if (str_contains($url, '/danfse/')) {
                    return new HttpResponse(503, 'servico indisponivel');
                }

                return new HttpResponse(200, $this->consultaBody());
            },
        );
        $cliente = $this->criarCliente($http);

        $pdf = $cliente->baixarPDF(self::CHAVE, tentarOficial: true);

        $this->assertStringStartsWith('%PDF', $pdf, 'Geração local deve produzir um PDF válido');
        $this->assertGreaterThan(1000, strlen($pdf));
        $this->assertNotNull(
            $cliente->getMotivoUltimoFallback(),
            'O motivo do fallback deve ficar registrado para diagnóstico',
        );
    }

    private function consultaBody(): string
    {
        $xmlNFSe = (string) file_get_contents(__DIR__ . '/../Fixtures/nfse-exemplo.xml');

        return (string) json_encode([
            'nfseXmlGZipB64' => base64_encode((string) gzencode($xmlNFSe)),
        ]);
    }

    public function testBaixarXmlDecodificaESalvaArquivo(): void
    {
        $xmlNFSe = '<NFSe><infNFSe>teste</infNFSe></NFSe>';
        $body = (string) json_encode(['nfseXmlGZipB64' => base64_encode((string) gzencode($xmlNFSe))]);

        $http = (new StubHttpClient())->enfileirar(new HttpResponse(200, $body));
        $cliente = $this->criarCliente($http);

        $destino = tempnam(sys_get_temp_dir(), 'nfse_xml_test_');
        try {
            $xml = $cliente->baixarXML(self::CHAVE, $destino);

            $this->assertSame($xmlNFSe, $xml);
            $this->assertSame($xmlNFSe, file_get_contents($destino));
        } finally {
            @unlink((string) $destino);
        }
    }

    public function testListarPorFaixaIgnora404EPropagaOutrosErros(): void
    {
        // Faixa 1..3: número 1 existe, número 2 não existe (404), número 3 existe
        $http = (new StubHttpClient())->enfileirar(
            new HttpResponse(200, '{"chaveAcesso":"chave-1"}'),
            new HttpResponse(404, '{"erro":"nao encontrada"}'),
            new HttpResponse(200, '{"chaveAcesso":"chave-3"}'),
        );
        $cliente = $this->criarCliente($http);

        $resultado = $cliente->listarNFSePorFaixa('4216909', CertificateFactory::CNPJ, '900', 1, 3);

        $this->assertCount(2, $resultado);
        $this->assertSame([1, 3], array_column($resultado, 'numero'));
        $this->assertSame('chave-1', $resultado[0]['chaveAcesso']);

        // Erro 500 deve interromper e propagar, não ser engolido
        $http2 = (new StubHttpClient())->enfileirar(new HttpResponse(500, 'erro interno'));
        $cliente2 = $this->criarCliente($http2);

        $this->expectException(ApiException::class);
        $cliente2->listarNFSePorFaixa('4216909', CertificateFactory::CNPJ, '900', 1, 3);
    }

    public function testErroHttpViraApiExceptionComStatus(): void
    {
        $http = (new StubHttpClient())->enfileirar(new HttpResponse(429, 'muitas requisicoes'));
        $cliente = $this->criarCliente($http);

        try {
            $cliente->consultarNFSe(self::CHAVE);
            $this->fail('Deveria ter lançado ApiException');
        } catch (ApiException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertSame('muitas requisicoes', $e->getBody());
        }
    }
}
