<?php

declare(strict_types=1);

namespace NFSe\Tests\Unit;

use DOMDocument;
use DOMXPath;
use NFSe\Models\DPS;
use PHPUnit\Framework\TestCase;

final class DPSTest extends TestCase
{
    private const NS = 'http://www.sped.fazenda.gov.br/nfse';
    private const DH_EMI_FIXO = '2026-01-15T10:30:00-03:00';

    private function criarDpsCompleta(): DPS
    {
        $dps = (new DPS())
            ->setTpAmb(2)
            ->setVerAplic('teste-1.0.0')
            ->setSerie('900')
            ->setNDPS('7')
            ->setDCompet('2026-01-15')
            ->setCLocEmi('4216909')
            ->setPrestador([
                'cnpj' => '11.222.333/0001-81',
                'fone' => '(49) 99999-9999',
                'email' => 'prestador@example.com',
                'regTrib' => [
                    'opSimpNac' => 3,
                    'regApTribSN' => 1,
                    'regEspTrib' => 0,
                ],
            ])
            ->setTomador([
                'cnpj' => '44.555.666/0001-72',
                'xNome' => 'TOMADOR EXEMPLO LTDA',
                'endereco' => [
                    'cMun' => '4204202',
                    'CEP' => '89802-112',
                    'xLog' => 'RUA EXEMPLO',
                    'nLog' => '100',
                    'xBairro' => 'CENTRO',
                ],
            ])
            ->setServico([
                'cLocPrestacao' => '4204202',
                'cTribNac' => '01.06.01',
                'xDescServ' => 'Consultoria em tecnologia da informacao',
                'xInfComp' => 'Pagamento via PIX',
            ])
            ->setValores([
                'vServ' => 1500.5,
                'tribISSQN' => 1,
                'tpRetISSQN' => 1,
                'CST' => '00',
                'pTotTribSN' => 13.45,
            ]);

        $this->fixarDhEmi($dps);

        return $dps;
    }

    /**
     * dhEmi é definido no construtor a partir do relógio; fixa um valor
     * determinístico para que o XML gerado seja comparável a uma fixture.
     */
    private function fixarDhEmi(DPS $dps): void
    {
        $ref = new \ReflectionProperty(DPS::class, 'dhEmi');
        $ref->setValue($dps, self::DH_EMI_FIXO);
    }

    public function testXmlGeradoCorrespondeAFixture(): void
    {
        $xml = $this->criarDpsCompleta()->gerarXML();

        $esperado = file_get_contents(__DIR__ . '/../Fixtures/dps-esperada.xml');
        $this->assertNotFalse($esperado, 'Fixture dps-esperada.xml não encontrada');

        $this->assertXmlStringEqualsXmlString($esperado, $xml);
    }

    public function testEstruturaDoXml(): void
    {
        $xml = $this->criarDpsCompleta()->gerarXML();

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', self::NS);

        $this->assertSame(self::NS, $dom->documentElement->namespaceURI);
        $this->assertSame('1.00', $dom->documentElement->getAttribute('versao'));

        $infDPS = $xpath->query('/n:DPS/n:infDPS')->item(0);
        $this->assertNotNull($infDPS);

        $id = $infDPS->getAttribute('Id');
        $this->assertSame(45, strlen($id));
        // "DPS" + codMun(7) + tpInsc(1) + CNPJ(14) + serie(5) + numero(15)
        $this->assertSame('DPS' . '4216909' . '2' . '11222333000181' . '00900' . '000000000000007', $id);
        $this->assertMatchesRegularExpression('/^DPS\d{42}$/', $id);

        $this->assertSame('2', $xpath->evaluate('string(/n:DPS/n:infDPS/n:tpAmb)'));
        $this->assertSame(self::DH_EMI_FIXO, $xpath->evaluate('string(/n:DPS/n:infDPS/n:dhEmi)'));
        $this->assertSame('900', $xpath->evaluate('string(/n:DPS/n:infDPS/n:serie)'));
        $this->assertSame('7', $xpath->evaluate('string(/n:DPS/n:infDPS/n:nDPS)'));

        // CNPJ deve ser emitido sem máscara
        $this->assertSame('11222333000181', $xpath->evaluate('string(/n:DPS/n:infDPS/n:prest/n:CNPJ)'));
        $this->assertSame('44555666000172', $xpath->evaluate('string(/n:DPS/n:infDPS/n:toma/n:CNPJ)'));

        // Prestador emitente (tpEmit=1) não deve ter xNome nem endereço
        $this->assertSame(0.0, $xpath->evaluate('count(/n:DPS/n:infDPS/n:prest/n:xNome)'));
        $this->assertSame(0.0, $xpath->evaluate('count(/n:DPS/n:infDPS/n:prest/n:end)'));

        // CEP do tomador sem máscara
        $this->assertSame('89802112', $xpath->evaluate('string(/n:DPS/n:infDPS/n:toma/n:end/n:endNac/n:CEP)'));

        // Código de tributação sem pontos
        $this->assertSame('010601', $xpath->evaluate('string(/n:DPS/n:infDPS/n:serv/n:cServ/n:cTribNac)'));

        // Valores formatados com 2 casas decimais
        $this->assertSame('1500.50', $xpath->evaluate('string(/n:DPS/n:infDPS/n:valores/n:vServPrest/n:vServ)'));

        // Com pTotTribSN informado, não deve existir vTotTrib
        $this->assertSame('13.45', $xpath->evaluate('string(/n:DPS/n:infDPS/n:valores/n:trib/n:totTrib/n:pTotTribSN)'));
        $this->assertSame(0.0, $xpath->evaluate('count(/n:DPS/n:infDPS/n:valores/n:trib/n:totTrib/n:vTotTrib)'));
    }

    public function testIdComCpfUsaTipoInscricao1(): void
    {
        $dps = (new DPS())
            ->setTpAmb(2)
            ->setVerAplic('teste')
            ->setSerie('1')
            ->setNDPS('42')
            ->setDCompet('2026-01-15')
            ->setCLocEmi('4216909')
            ->setPrestador(['cpf' => '123.456.789-09'])
            ->setValores(['vServ' => 10.0]);
        $this->fixarDhEmi($dps);

        $dom = new DOMDocument();
        $dom->loadXML($dps->gerarXML());
        $id = $dom->getElementsByTagName('infDPS')->item(0)->getAttribute('Id');

        // "DPS" + codMun(7) + tpInsc(1) + doc com pad p/ 14 + serie(5) + numero(15)
        $this->assertSame('DPS' . '4216909' . '1' . '00012345678909' . '00001' . '000000000000042', $id);
    }

    public function testXmlValidaContraXsdOficial(): void
    {
        $xsd = __DIR__
            . '/../../docs/nfse-esquemas_xsd-anexos_i_ii_iv-sefin_adn-prod-v1-00-20251226/DPS_v1.00.xsd';
        if (!file_exists($xsd)) {
            $this->markTestSkipped('XSDs oficiais não disponíveis em docs/');
        }

        $dom = new DOMDocument();
        $dom->loadXML($this->criarDpsCompleta()->gerarXML());

        libxml_use_internal_errors(true);
        $dom->schemaValidate($xsd);
        $erros = array_map(
            static fn(\LibXMLError $e): string => trim($e->message),
            libxml_get_errors(),
        );
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        // O tipo TSSerieDPS do XSD oficial tem o pattern "^0{0,4}\d{1,5}$";
        // em regex de XML Schema, ^ e $ são caracteres literais (não âncoras),
        // então NENHUM valor de série passa nesse facet — é um defeito do
        // próprio schema do governo. Ignora apenas esse erro conhecido.
        $erros = array_filter(
            $erros,
            static fn(string $erro): bool => !(str_contains($erro, 'serie') && str_contains($erro, 'pattern')),
        );

        $this->assertSame([], array_values($erros), 'DPS não valida contra o XSD oficial');
    }

    public function testSemPTotTribSNGeraVTotTrib(): void
    {
        $dps = (new DPS())
            ->setTpAmb(2)
            ->setVerAplic('teste')
            ->setSerie('1')
            ->setNDPS('1')
            ->setDCompet('2026-01-15')
            ->setCLocEmi('4216909')
            ->setPrestador(['cnpj' => '11222333000181'])
            ->setValores(['vServ' => 100.0, 'vTotTribFed' => 1.5, 'vISSQN' => 3.0]);
        $this->fixarDhEmi($dps);

        $dom = new DOMDocument();
        $dom->loadXML($dps->gerarXML());
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', self::NS);

        $this->assertSame('1.50', $xpath->evaluate('string(//n:DPS/n:infDPS/n:valores/n:trib/n:totTrib/n:vTotTrib/n:vTotTribFed)'));
        $this->assertSame('0.00', $xpath->evaluate('string(//n:DPS/n:infDPS/n:valores/n:trib/n:totTrib/n:vTotTrib/n:vTotTribEst)'));
        $this->assertSame('3.00', $xpath->evaluate('string(//n:DPS/n:infDPS/n:valores/n:trib/n:totTrib/n:vTotTrib/n:vTotTribMun)'));
        $this->assertSame(0.0, $xpath->evaluate('count(//n:DPS/n:infDPS/n:valores/n:trib/n:totTrib/n:pTotTribSN)'));
    }
}
