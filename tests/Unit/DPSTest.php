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

    // ------------------------------------------------- Reforma Tributária

    /**
     * Sem setIbsCbs(), o documento permanece no leiaute 1.00 e o XML não muda —
     * garantia de que a reforma não afeta quem ainda não a informa.
     */
    public function testSemIbsCbsMantemLeiaute100(): void
    {
        $dps = $this->criarDpsCompleta();

        $this->assertSame(DPS::LEIAUTE_V1_00, $dps->getVersaoLeiaute());

        $dom = new DOMDocument();
        $dom->loadXML($dps->gerarXML());
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', self::NS);

        $this->assertSame('1.00', $dom->documentElement->getAttribute('versao'));
        $this->assertSame(0.0, $xpath->evaluate('count(//n:DPS/n:infDPS/n:IBSCBS)'));
    }

    public function testIbsCbsPromoveLeiautePara101(): void
    {
        $dps = $this->criarDpsComIbsCbs();

        $this->assertSame(DPS::LEIAUTE_V1_01, $dps->getVersaoLeiaute());

        $dom = new DOMDocument();
        $dom->loadXML($dps->gerarXML());

        $this->assertSame('1.01', $dom->documentElement->getAttribute('versao'));
    }

    public function testEstruturaDoGrupoIbsCbs(): void
    {
        $dom = new DOMDocument();
        $dom->loadXML($this->criarDpsComIbsCbs()->gerarXML());
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', self::NS);

        $base = '/n:DPS/n:infDPS/n:IBSCBS';

        $this->assertSame('0', $xpath->evaluate("string({$base}/n:finNFSe)"));
        $this->assertSame('110101', $xpath->evaluate("string({$base}/n:cIndOp)"));
        $this->assertSame('0', $xpath->evaluate("string({$base}/n:indDest)"));
        $this->assertSame('000', $xpath->evaluate("string({$base}/n:valores/n:trib/n:gIBSCBS/n:CST)"));
        $this->assertSame('000001', $xpath->evaluate("string({$base}/n:valores/n:trib/n:gIBSCBS/n:cClassTrib)"));

        // O grupo IBSCBS é o último filho de infDPS (o schema é uma sequence)
        $this->assertSame(
            'IBSCBS',
            $xpath->query('/n:DPS/n:infDPS/*[last()]')->item(0)?->localName,
        );
    }

    public function testGrupoDiferimentoEtributacaoRegular(): void
    {
        $dps = $this->criarDpsComIbsCbs([
            'valores' => [
                'gIBSCBS' => [
                    'CST' => '200',
                    'cClassTrib' => '200001',
                    'gTribRegular' => ['CSTReg' => '000', 'cClassTribReg' => '000001'],
                    'gDif' => ['pDifUF' => 10.0, 'pDifMun' => 5.5, 'pDifCBS' => 20.0],
                ],
            ],
        ]);

        $dom = new DOMDocument();
        $dom->loadXML($dps->gerarXML());
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', self::NS);

        $grupo = '/n:DPS/n:infDPS/n:IBSCBS/n:valores/n:trib/n:gIBSCBS';

        $this->assertSame('000', $xpath->evaluate("string({$grupo}/n:gTribRegular/n:CSTReg)"));
        $this->assertSame('10.00', $xpath->evaluate("string({$grupo}/n:gDif/n:pDifUF)"));
        $this->assertSame('5.50', $xpath->evaluate("string({$grupo}/n:gDif/n:pDifMun)"));
        $this->assertSame('20.00', $xpath->evaluate("string({$grupo}/n:gDif/n:pDifCBS)"));
    }

    /**
     * Os campos criados pela NT 009 ainda não constam dos XSDs publicados, então
     * só saem no XML com o leiaute estendido ligado explicitamente.
     */
    public function testCamposDaNt009ExigemLeiauteEstendido(): void
    {
        $ajuste = ['valores' => ['gIBSCBSAjuste' => ['vIBS' => 10.0, 'vCBS' => 20.0]]];

        $padrao = new DOMDocument();
        $padrao->loadXML($this->criarDpsComIbsCbs($ajuste)->gerarXML());
        $xpathPadrao = new DOMXPath($padrao);
        $xpathPadrao->registerNamespace('n', self::NS);
        $this->assertSame(0.0, $xpathPadrao->evaluate('count(//n:gIBSCBSAjuste)'));

        $estendido = new DOMDocument();
        $estendido->loadXML($this->criarDpsComIbsCbs($ajuste)->setLeiauteEstendido(true)->gerarXML());
        $xpathEstendido = new DOMXPath($estendido);
        $xpathEstendido->registerNamespace('n', self::NS);
        $this->assertSame('10.00', $xpathEstendido->evaluate('string(//n:gIBSCBSAjuste/n:vIBS)'));
        $this->assertSame('20.00', $xpathEstendido->evaluate('string(//n:gIBSCBSAjuste/n:vCBS)'));
    }

    public function testXmlComIbsCbsValidaContraXsdRtc(): void
    {
        $xsd = $this->prepararXsdRtc();
        if ($xsd === null) {
            $this->markTestSkipped('XSDs do leiaute RTC não disponíveis em docs/');
        }

        $dom = new DOMDocument();
        $dom->loadXML($this->criarDpsComIbsCbs()->gerarXML());

        libxml_use_internal_errors(true);
        $dom->schemaValidate($xsd);
        $erros = array_map(
            static fn(\LibXMLError $e): string => trim($e->message),
            libxml_get_errors(),
        );
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        $this->assertSame([], array_values($erros), 'DPS com IBSCBS não valida contra o XSD RTC');
    }

    /**
     * O TSSerieDPS do XSD RTC usa o pattern "^(?!0{1,5}$)\d{1,5}$", que emprega
     * lookahead — recurso inexistente em regex de XML Schema. O libxml falha ao
     * compilar o schema inteiro por causa disso, então a validação roda sobre
     * uma cópia com esse facet substituído por um equivalente válido.
     *
     * @return string|null Caminho do XSD utilizável, ou null se indisponível
     */
    private function prepararXsdRtc(): ?string
    {
        $origem = __DIR__ . '/../../docs/nfse-esquemas_xsd-rtc-v1-01';
        if (!is_dir($origem)) {
            return null;
        }

        $destino = sys_get_temp_dir() . '/nfse-xsd-rtc-' . md5($origem);
        if (!is_dir($destino) && !mkdir($destino, 0777, true) && !is_dir($destino)) {
            return null;
        }

        foreach ((array) glob($origem . '/*.xsd') as $arquivo) {
            $conteudo = (string) file_get_contents((string) $arquivo);
            $conteudo = str_replace('^(?!0{1,5}$)\d{1,5}$', '[0-9]{1,5}', $conteudo);
            file_put_contents($destino . '/' . basename((string) $arquivo), $conteudo);
        }

        return $destino . '/DPS_v1.01.xsd';
    }

    /**
     * @param array<string, mixed> $ibsCbs Sobrescreve o grupo IBSCBS padrão
     */
    private function criarDpsComIbsCbs(array $ibsCbs = []): DPS
    {
        $padrao = [
            'finNFSe' => '0',
            'cIndOp' => '110101',
            'indDest' => '0',
            'valores' => [
                'gIBSCBS' => [
                    'CST' => '000',
                    'cClassTrib' => '000001',
                ],
            ],
        ];

        // No leiaute RTC o código da NBS passou a ser obrigatório em cServ.
        return $this->criarDpsCompleta()
            ->setServico([
                'cLocPrestacao' => '4204202',
                'cTribNac' => '01.06.01',
                'xDescServ' => 'Consultoria em tecnologia da informacao',
                'cNBS' => '115011000',
                'xInfComp' => 'Pagamento via PIX',
            ])
            ->setIbsCbs(array_replace($padrao, $ibsCbs));
    }
}
