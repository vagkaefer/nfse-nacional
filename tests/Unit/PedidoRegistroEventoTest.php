<?php

declare(strict_types=1);

namespace NFSe\Tests\Unit;

use DOMDocument;
use NFSe\Exception\NFSeException;
use NFSe\Models\PedidoRegistroEvento;
use PHPUnit\Framework\TestCase;

final class PedidoRegistroEventoTest extends TestCase
{
    private const CHAVE = '12345678901234567890123456789012345678901234567890';
    private const XSD = __DIR__
        . '/../../docs/nfse-esquemas_xsd-anexos_i_ii_iv-sefin_adn-prod-v1-00-20251226/pedRegEvento_v1.00.xsd';

    private const XSD_V1_01 = __DIR__
        . '/../../docs/nfse-esquemas_xsd-rtc-v1-01/pedRegEvento_v1.01.xsd';

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

    public function testMotivoCurtoEhRejeitado(): void
    {
        $evento = new PedidoRegistroEvento(self::CHAVE, 2, '1.0.0', '11222333000181');

        $this->expectException(NFSeException::class);
        $this->expectExceptionMessage('ao menos 15 caracteres');

        // TSMotivo exige minLength=15; recusar aqui evita um XML que só a Sefin recusaria.
        $evento->cancelamento('Erro');
    }

    public function testLeiaute101IncluiNPedRegEventoEAmpliaOId(): void
    {
        $evento = new PedidoRegistroEvento(
            self::CHAVE,
            2,
            '1.0.0',
            '11222333000181',
            PedidoRegistroEvento::LEIAUTE_V1_01,
        );

        $dom = new DOMDocument();
        $dom->loadXML($evento->cancelamento('Erro na emissão da nota', 1));

        $this->assertSame('1.01', $dom->documentElement->getAttribute('versao'));

        $infPedReg = $dom->getElementsByTagName('infPedReg')->item(0);
        $this->assertNotNull($infPedReg);

        // No Id o número entra com 3 dígitos (PRE[0-9]{59}); no elemento, sem padding.
        $this->assertSame('PRE' . self::CHAVE . '101101' . '001', $infPedReg->getAttribute('Id'));
        $this->assertMatchesRegularExpression('/^PRE\d{59}$/', $infPedReg->getAttribute('Id'));
        $this->assertSame('1', $dom->getElementsByTagName('nPedRegEvento')->item(0)?->textContent);

        $nomes = [];
        foreach ($infPedReg->childNodes as $filho) {
            if ($filho instanceof \DOMElement) {
                $nomes[] = $filho->localName;
            }
        }
        $this->assertSame(
            ['tpAmb', 'verAplic', 'dhEvento', 'CNPJAutor', 'chNFSe', 'nPedRegEvento', 'e101101'],
            $nomes,
        );
    }

    public function testLeiaute101ValidaContraXsdOficial(): void
    {
        if (!file_exists(self::XSD_V1_01)) {
            $this->markTestSkipped('XSDs oficiais do leiaute 1.01 não disponíveis em docs/');
        }

        $evento = new PedidoRegistroEvento(
            self::CHAVE,
            2,
            '1.0.0',
            '11222333000181',
            PedidoRegistroEvento::LEIAUTE_V1_01,
        );

        $dom = new DOMDocument();
        $dom->loadXML($evento->cancelamento('Erro na emissão da nota', 1));

        libxml_use_internal_errors(true);
        $valido = $dom->schemaValidate($this->xsd101Compilavel());
        $erros = array_map(
            static fn(\LibXMLError $e): string => trim($e->message),
            libxml_get_errors(),
        );
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        $this->assertTrue($valido, 'XML 1.01 não valida contra o XSD oficial: ' . implode('; ', $erros));
    }

    /**
     * Devolve o caminho de uma cópia do XSD 1.01 que o libxml consegue compilar.
     *
     * O tiposSimples_v1.01.xsd oficial traz, no tipo TSNumDFe, o pattern
     * "^(?!0{1,5}$)\d{1,5}$" — um lookahead negativo do Perl que a especificação
     * de XML Schema não admite. O libxml recusa o schema inteiro por causa dele,
     * impedindo validar qualquer documento 1.01. O pattern é substituído por um
     * equivalente aceito, sem tocar nos tipos que este teste exercita.
     */
    private function xsd101Compilavel(): string
    {
        $origem = dirname(self::XSD_V1_01);
        $destino = sys_get_temp_dir() . '/nfse-xsd-101-' . md5($origem);

        if (!is_dir($destino)) {
            mkdir($destino, 0777, true);
        }

        foreach ((array) glob($origem . '/*.xsd') as $arquivo) {
            $conteudo = (string) file_get_contents((string) $arquivo);
            $conteudo = str_replace('^(?!0{1,5}$)\d{1,5}$', '[1-9][0-9]{0,4}', $conteudo);
            file_put_contents($destino . '/' . basename((string) $arquivo), $conteudo);
        }

        return $destino . '/' . basename(self::XSD_V1_01);
    }
}
