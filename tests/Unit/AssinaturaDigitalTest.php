<?php

declare(strict_types=1);

namespace NFSe\Tests\Unit;

use DOMDocument;
use DOMXPath;
use NFSe\Certificate\Certificado;
use NFSe\Tests\Support\CertificateFactory;
use NFSe\Utils\AssinaturaDigital;
use PHPUnit\Framework\TestCase;

/**
 * Testes de caracterização da assinatura XMLDSig.
 *
 * A verificação NÃO reutiliza o código da biblioteca: o digest é recomputado
 * e a assinatura é conferida com openssl_verify contra o certificado embutido
 * no próprio XML. Isso protege o comportamento da assinatura contra mudanças
 * silenciosas durante a refatoração.
 */
final class AssinaturaDigitalTest extends TestCase
{
    private const NS_DSIG = 'http://www.w3.org/2000/09/xmldsig#';

    private static string $pfxPath;
    private static string $senha;

    public static function setUpBeforeClass(): void
    {
        $cert = CertificateFactory::criarPfx();
        self::$pfxPath = $cert['path'];
        self::$senha = $cert['senha'];
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$pfxPath);
    }

    private function xmlDeExemplo(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<DPS versao="1.00" xmlns="http://www.sped.fazenda.gov.br/nfse">'
            . '<infDPS Id="DPS421690921122233300018100900000000000000007">'
            . '<tpAmb>2</tpAmb><serie>900</serie><nDPS>7</nDPS>'
            . '</infDPS></DPS>';
    }

    private function assinar(): string
    {
        $assinatura = new AssinaturaDigital(new Certificado(self::$pfxPath, self::$senha));

        return $assinatura->assinarXML($this->xmlDeExemplo(), 'infDPS', 'Id');
    }

    public function testEstruturaDaAssinatura(): void
    {
        $xmlAssinado = $this->assinar();

        $dom = new DOMDocument();
        $dom->loadXML($xmlAssinado);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ds', self::NS_DSIG);

        $signature = $xpath->query('//ds:Signature')->item(0);
        $this->assertNotNull($signature, 'Elemento Signature ausente');
        $this->assertSame($dom->documentElement, $signature->parentNode, 'Signature deve ser filha do elemento raiz (enveloped)');

        $this->assertSame(
            'http://www.w3.org/2001/10/xml-exc-c14n#WithComments',
            $xpath->evaluate('string(//ds:SignedInfo/*[local-name()="CanonicalizationMethod"]/@Algorithm)'),
        );
        $this->assertSame(
            'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
            $xpath->evaluate('string(//ds:SignedInfo/*[local-name()="SignatureMethod"]/@Algorithm)'),
        );
        $this->assertSame(
            '#DPS421690921122233300018100900000000000000007',
            $xpath->evaluate('string(//ds:SignedInfo/*[local-name()="Reference"]/@URI)'),
        );
        $this->assertSame(
            'http://www.w3.org/2001/04/xmlenc#sha256',
            $xpath->evaluate('string(//*[local-name()="DigestMethod"]/@Algorithm)'),
        );
        $this->assertNotSame('', $xpath->evaluate('string(//*[local-name()="SignatureValue"])'));
        $this->assertNotSame('', $xpath->evaluate('string(//*[local-name()="X509Certificate"])'));
    }

    public function testDigestValueConfereComConteudoAssinado(): void
    {
        $xmlAssinado = $this->assinar();

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xmlAssinado);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ds', self::NS_DSIG);

        $digestDeclarado = $xpath->evaluate('string(//*[local-name()="DigestValue"])');

        // A Signature é irmã de infDPS (enveloped no elemento raiz), então a
        // canonicalização de infDPS já não a inclui.
        $infDPS = $dom->getElementsByTagName('infDPS')->item(0);
        $canonico = $infDPS->C14N(false, false);
        $digestRecomputado = base64_encode(hash('sha256', $canonico, true));

        $this->assertSame($digestRecomputado, $digestDeclarado);
    }

    public function testSignatureValueVerificaComOpensslContraCertificadoEmbutido(): void
    {
        $xmlAssinado = $this->assinar();

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xmlAssinado);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ds', self::NS_DSIG);

        $signedInfo = $xpath->query('//ds:SignedInfo')->item(0);
        $this->assertNotNull($signedInfo);

        $canonico = $signedInfo->C14N(true, true);
        $assinatura = base64_decode($xpath->evaluate('string(//*[local-name()="SignatureValue"])'), true);
        $this->assertNotFalse($assinatura);

        $certB64 = $xpath->evaluate('string(//*[local-name()="X509Certificate"])');
        $certPem = "-----BEGIN CERTIFICATE-----\n"
            . chunk_split($certB64, 64, "\n")
            . "-----END CERTIFICATE-----\n";
        $chavePublica = openssl_pkey_get_public($certPem);
        $this->assertNotFalse($chavePublica, 'Certificado embutido inválido');

        $resultado = openssl_verify($canonico, $assinatura, $chavePublica, OPENSSL_ALGO_SHA256);
        $this->assertSame(1, $resultado, 'Assinatura não confere com o SignedInfo canonicalizado');
    }

    public function testSenhaErradaLancaExcecao(): void
    {
        $this->expectException(\Exception::class);

        new Certificado(self::$pfxPath, 'senha-incorreta');
    }

    public function testTagInexistenteLancaExcecao(): void
    {
        $assinatura = new AssinaturaDigital(new Certificado(self::$pfxPath, self::$senha));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('não encontrada');

        $assinatura->assinarXML($this->xmlDeExemplo(), 'tagQueNaoExiste', 'Id');
    }
}
