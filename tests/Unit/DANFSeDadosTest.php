<?php

declare(strict_types=1);

namespace NFSe\Tests\Unit;

use NFSe\Exception\NFSeException;
use NFSe\Utils\DANFSeDados;
use PHPUnit\Framework\TestCase;

final class DANFSeDadosTest extends TestCase
{
    /**
     * @param array<string, string> $municipios
     * @return array<string, mixed>
     */
    private function extrair(array $municipios = [], string $fixture = 'nfse-exemplo.xml'): array
    {
        $xml = (string) file_get_contents(__DIR__ . '/../Fixtures/' . $fixture);

        return DANFSeDados::extrair($xml, $municipios);
    }

    public function testDadosBasicos(): void
    {
        $dados = $this->extrair();

        $this->assertSame('12345678901234567890123456789012345678901234567890', $dados['chaveAcesso']);
        $this->assertSame('123', $dados['numero']);
        $this->assertSame('900', $dados['serie']);
        $this->assertSame('7', $dados['numeroDPS']);
        $this->assertSame('15/01/2026', $dados['competencia']);
        $this->assertSame('15/01/2026 10:30:00', $dados['dhEmissao']);
        $this->assertSame('2', $dados['ambiente']);
        $this->assertSame('Prestador', $dados['emitente']);
        $this->assertSame('NFS-e Gerada', $dados['situacao']);
    }

    public function testPrestador(): void
    {
        $dados = $this->extrair();
        $prest = $dados['prestador'];

        $this->assertSame('11.222.333/0001-81', $prest['cnpj']);
        $this->assertSame('EMPRESA EXEMPLO LTDA', $prest['nome']);
        $this->assertSame('(49) 0000-0000', $prest['telefone']);
        $this->assertSame('contato@example.com', $prest['email']);
        $this->assertSame('RUA DAS FLORES, 410, CENTRO', $prest['endereco']['logradouro']);
        // Máscara de CEP do item 2.4.5 da NT 008: nn.nnn-nnn
        $this->assertSame('89.990-000', $prest['endereco']['cep']);
        $this->assertSame('4216909', $prest['endereco']['codigoIbge']);

        // O DANFSe exibe a descrição das opções, não o código (NT 008, 2.4.5)
        $this->assertSame(
            'Optante - Microempresa ou Empresa de Pequeno Porte (ME/EPP)',
            $prest['simplesNacional'],
        );

        // Sem mapa de municípios, exibe o código com a UF
        $this->assertSame('4216909 / SC', $prest['endereco']['municipio']);
    }

    public function testTomador(): void
    {
        $dados = $this->extrair();
        $tom = $dados['tomador'];

        $this->assertSame('44.555.666/0001-72', $tom['cnpj']);
        $this->assertSame('TOMADOR EXEMPLO LTDA', $tom['nome']);
        // O complemento integra o endereço concatenado do DANFSe
        $this->assertSame('AVENIDA PRINCIPAL, 141, SALA 1403, CENTRO', $tom['endereco']['logradouro']);
        $this->assertSame('89.802-112', $tom['endereco']['cep']);
        $this->assertSame('4204202', $tom['endereco']['municipio']);
    }

    public function testServicoEValores(): void
    {
        $dados = $this->extrair();

        // Código nacional e municipal são concatenados no mesmo campo
        $this->assertSame('01.06.01 / -', $dados['servico']['codigoTributacao']);
        $this->assertSame('CONSULTORIA EM TECNOLOGIA DA INFORMACAO', $dados['servico']['descricao']);
        // "Local da Prestação / Sigla UF / País" com a UF derivada do código
        // IBGE e traço no país ausente (NT 008, item 2.4.5)
        $this->assertSame('Cidade Destino / SC / -', $dados['servico']['localPrestacao']);

        $this->assertSame('R$ 1.500,50', $dados['valores']['valorServico']);
        $this->assertSame('R$ 1.500,50', $dados['valores']['valorLiquido']);
        $this->assertSame('1', $dados['valores']['tribISSQN']);
        $this->assertSame('1', $dados['valores']['retencaoISSQN']);
        $this->assertSame(13.45, $dados['valores']['totTribSN']);
    }

    public function testMapaDeMunicipiosResolveNomes(): void
    {
        $dados = $this->extrair([
            '4216909' => 'São Lourenço do Oeste',
            '4204202' => 'Chapecó / SC',
        ]);

        $this->assertSame('São Lourenço do Oeste / SC', $dados['prestador']['endereco']['municipio']);
        $this->assertSame('Chapecó / SC', $dados['tomador']['endereco']['municipio']);
    }

    /**
     * A nota 12 da NT 008 exige traço nos campos sem informação no XML — e as
     * NFS-e emitidas em 2026 ainda não trazem os grupos IBS/CBS.
     */
    public function testCamposAusentesViramTraco(): void
    {
        $dados = $this->extrair();

        $this->assertSame(DANFSeDados::TRACO, $dados['ibscbs']['cst']);
        $this->assertSame(DANFSeDados::TRACO, $dados['ibscbs']['valorTotalIbs']);
        $this->assertSame(DANFSeDados::TRACO, $dados['ibscbs']['valorTotalCbs']);
        $this->assertSame(DANFSeDados::TRACO, $dados['issqn']['baseCalculo']);
        $this->assertSame(DANFSeDados::TRACO, $dados['federal']['irrf']);
        $this->assertNull($dados['intermediario']);
    }

    public function testGruposIbsCbsSaoExtraidos(): void
    {
        $dados = $this->extrair([], 'nfse-exemplo-ibscbs.xml');
        $ibsCbs = $dados['ibscbs'];

        $this->assertSame('000', $ibsCbs['cst']);
        $this->assertSame('000001', $ibsCbs['cClassTrib']);
        $this->assertSame('110101', $ibsCbs['indicadorOperacao']);
        $this->assertSame('R$ 1.500,50', $ibsCbs['baseCalculo']);
        $this->assertSame('0,10%', $ibsCbs['aliquotaIbsUf']);
        $this->assertSame('0,90%', $ibsCbs['aliquotaCbs']);
        $this->assertSame('R$ 2,25', $ibsCbs['valorTotalIbs']);
        $this->assertSame('R$ 13,50', $ibsCbs['valorTotalCbs']);

        $this->assertSame('R$ 15,75', $dados['valores']['totalIbsCbs']);
        // vTotNF lido direto da tag calculada pela Sefin
        $this->assertSame('R$ 1.516,25', $dados['valores']['valorLiquidoComIbsCbs']);
    }

    /**
     * Sem o grupo IBSCBS no XML não há vTotNF: o campo imprime traço
     * (NT 008, nota 12), sem fallback aritmético.
     */
    public function testValorLiquidoComIbsCbsSemGrupoViraTraco(): void
    {
        $dados = $this->extrair();

        $this->assertSame(DANFSeDados::TRACO, $dados['valores']['totalIbsCbs']);
        $this->assertSame(DANFSeDados::TRACO, $dados['valores']['valorLiquidoComIbsCbs']);
    }

    /**
     * O destinatário é o próprio tomador quando indDest = 0 (NT 008, item 2.3.2).
     */
    public function testDestinatarioIgualAoTomador(): void
    {
        $dados = $this->extrair([], 'nfse-exemplo-ibscbs.xml');

        $this->assertTrue($dados['destinatario']['ehProprioTomador']);
    }

    /**
     * A linha dos totais aproximados é obrigatória, fixa e separada das demais
     * informações complementares (NT 008, nota 10). O pTotTribSN do Simples
     * Nacional não alimenta os campos Federais/Estaduais/Municipais: sem
     * vTotTrib/pTotTrib, imprime traços — como faz o portal nacional.
     */
    public function testInformacoesComplementaresETotaisAproximados(): void
    {
        $dados = $this->extrair();

        $this->assertStringContainsString(
            'Inf. Cont.: PAGAMENTO VIA PIX',
            implode(' | ', $dados['informacoesComplementares']),
        );
        $this->assertSame(
            'Totais Aproximados dos Tributos cfe. Lei nº 12.741/2012: '
                . 'Federais: -; Estaduais: -; Municipais: -;',
            $dados['linhaTotaisAproximados'],
        );
    }

    public function testTotaisAproximadosComValoresEComPercentuais(): void
    {
        $xml = (string) file_get_contents(__DIR__ . '/../Fixtures/nfse-exemplo.xml');

        $comValores = str_replace(
            '<totTrib><pTotTribSN>13.45</pTotTribSN></totTrib>',
            '<totTrib><vTotTrib><vTotTribFed>10.00</vTotTribFed>'
                . '<vTotTribEst>0.00</vTotTribEst><vTotTribMun>30.05</vTotTribMun></vTotTrib></totTrib>',
            $xml,
        );
        $this->assertSame(
            'Totais Aproximados dos Tributos cfe. Lei nº 12.741/2012: '
                . 'Federais: R$ 10,00; Estaduais: R$ 0,00; Municipais: R$ 30,05;',
            DANFSeDados::extrair($comValores)['linhaTotaisAproximados'],
        );

        $comPercentuais = str_replace(
            '<totTrib><pTotTribSN>13.45</pTotTribSN></totTrib>',
            '<totTrib><pTotTrib><pTotTribFed>6.00</pTotTribFed>'
                . '<pTotTribEst>0.00</pTotTribEst><pTotTribMun>2.00</pTotTribMun></pTotTrib></totTrib>',
            $xml,
        );
        $this->assertSame(
            'Totais Aproximados dos Tributos cfe. Lei nº 12.741/2012: '
                . 'Federais: 6,00%; Estaduais: 0,00%; Municipais: 2,00%;',
            DANFSeDados::extrair($comPercentuais)['linhaTotaisAproximados'],
        );
    }

    /**
     * Telefone e e-mail do prestador vêm de DPS/infDPS/prest (caminho da
     * NT 008), não do emit — os XML reais divergem entre os dois nós.
     */
    public function testTelefoneEEmailDoPrestadorVemDaDps(): void
    {
        $xml = (string) file_get_contents(__DIR__ . '/../Fixtures/nfse-exemplo.xml');
        $xml = str_replace(
            '<prest><CNPJ>11222333000181</CNPJ><fone>4900000000</fone><email>contato@example.com</email>',
            '<prest><CNPJ>11222333000181</CNPJ><fone>49999561125</fone><email>nf@example.com</email>',
            $xml,
        );

        $prest = DANFSeDados::extrair($xml)['prestador'];

        $this->assertSame('(49) 99956-1125', $prest['telefone']);
        $this->assertSame('nf@example.com', $prest['email']);
    }

    /**
     * Nota 5 da NT 008: as linhas opcionais do ISSQN são suprimíveis quando
     * todos os campos estão sem dados (regEspTrib = 0 conta como sem dado).
     */
    public function testLinhasOpcionaisDoIssqnDetectadasComoVazias(): void
    {
        $dados = $this->extrair();

        $this->assertTrue($dados['issqn']['linhaRegimeVazia']);
        $this->assertTrue($dados['issqn']['linhaBeneficioVazia']);

        $xml = (string) file_get_contents(__DIR__ . '/../Fixtures/nfse-exemplo.xml');
        $xml = str_replace(
            '<tribMun><tribISSQN>1</tribISSQN>',
            '<tribMun><tribISSQN>1</tribISSQN><tpImunidade>4</tpImunidade>',
            $xml,
        );
        $issqn = DANFSeDados::extrair($xml)['issqn'];

        $this->assertFalse($issqn['linhaRegimeVazia']);
        $this->assertSame('4', $issqn['tipoImunidade']);
    }

    public function testXmlInvalidoLancaExcecao(): void
    {
        $this->expectException(NFSeException::class);

        DANFSeDados::extrair('não é xml');
    }
}
