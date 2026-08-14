<?php

declare(strict_types=1);

namespace NFSe\Tests\Unit;

use DOMDocument;
use NFSe\Models\PedidoRegistroEvento;
use PHPUnit\Framework\TestCase;

final class PedidoRegistroEventoTest extends TestCase
{
    private const CHAVE = '12345678901234567890123456789012345678901234567890';
    private const XSD = __DIR__
        . '/../../docs/nfse-esquemas_xsd-anexos_i_ii_iv-sefin_adn-prod-v1-00-20251226/pedRegEvento_v1.00.xsd';

    private function gerarXml(): string
    {
        $evento = new PedidoRegistroEvento(self::CHAVE, 2, '1.0.0', '11222333000181');

        return $evento->cancelamento('Erro na emissão da nota', 1);
    }

    public function testEstruturaDoEvento(): void
    {
        $dom = new DOMDocument();
        $dom->loadXML($this->gerarXml());

        $this->assertSame('pedRegEvento', $dom->documentElement->localName);
        $this->assertSame('http://www.sped.fazenda.gov.br/nfse', $dom->documentElement->namespaceURI);
        $this->assertSame('1.00', $dom->documentElement->getAttribute('versao'));

        $infPedReg = $dom->getElementsByTagName('infPedReg')->item(0);
        $this->assertNotNull($infPedReg);
        $this->assertSame('PRE' . self::CHAVE . '101101', $infPedReg->getAttribute('Id'));

        // Ordem dos filhos exigida pelo schema
        $nomes = [];
        foreach ($infPedReg->childNodes as $filho) {
            if ($filho instanceof \DOMElement) {
                $nomes[] = $filho->localName;
            }
        }
        $this->assertSame(['tpAmb', 'verAplic', 'dhEvento', 'CNPJAutor', 'chNFSe', 'e101101'], $nomes);

        $this->assertSame('Cancelamento de NFS-e', $dom->getElementsByTagName('xDesc')->item(0)?->textContent);
        $this->assertSame('1', $dom->getElementsByTagName('cMotivo')->item(0)?->textContent);
        $this->assertSame('Erro na emissão da nota', $dom->getElementsByTagName('xMotivo')->item(0)?->textContent);
    }

    public function testAutorComCpf(): void
    {
        $evento = new PedidoRegistroEvento(self::CHAVE, 2, '1.0.0', '123.456.789-09');
        $dom = new DOMDocument();
        $dom->loadXML($evento->cancelamento('Motivo qualquer'));

        $this->assertSame('12345678909', $dom->getElementsByTagName('CPFAutor')->item(0)?->textContent);
        $this->assertSame(0, $dom->getElementsByTagName('CNPJAutor')->length);
    }

    public function testMotivoLongoETruncadoEm255(): void
    {
        $evento = new PedidoRegistroEvento(self::CHAVE, 2, '1.0.0', '11222333000181');
        $dom = new DOMDocument();
        $dom->loadXML($evento->cancelamento(str_repeat('a', 400)));

        $this->assertSame(255, strlen((string) $dom->getElementsByTagName('xMotivo')->item(0)?->textContent));
    }

    public function testXmlValidaContraXsdOficial(): void
    {
        if (!file_exists(self::XSD)) {
            $this->markTestSkipped('XSDs oficiais não disponíveis em docs/');
        }

        $dom = new DOMDocument();
        $dom->loadXML($this->gerarXml());

        libxml_use_internal_errors(true);
        $valido = $dom->schemaValidate(self::XSD);
        $erros = array_map(
            static fn(\LibXMLError $e): string => trim($e->message),
            libxml_get_errors(),
        );
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        $this->assertTrue($valido, 'XML não valida contra o XSD oficial: ' . implode('; ', $erros));
    }
}
