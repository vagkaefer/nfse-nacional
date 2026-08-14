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
        $this->assertSame('89990-000', $prest['endereco']['cep']);
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
        $this->assertSame('89802-112', $tom['endereco']['cep']);
        $this->assertSame('4204202', $tom['endereco']['municipio']);
    }

    public function testServicoEValores(): void
    {
        $dados = $this->extrair();

        // Código nacional e municipal são concatenados no mesmo campo
        $this->assertSame('01.06.01 / -', $dados['servico']['codigoTributacao']);
        $this->assertSame('CONSULTORIA EM TECNOLOGIA DA INFORMACAO', $dados['servico']['descricao']);
        $this->assertStringContainsString('Cidade Destino', (string) $dados['servico']['localPrestacao']);

        $this->assertSame(1500.50, $dados['valores']['valorServico']);
        $this->assertSame(1500.50, $dados['valores']['valorLiquido']);
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

        // vTotNF = líquido + IBS + CBS
        $this->assertSame(15.75, $dados['valores']['totalIbsCbs']);
        $this->assertSame(1516.25, $dados['valores']['valorLiquidoComIbsCbs']);
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
     * A linha dos totais aproximados é obrigatória e fixa (NT 008, nota 10).
     */
    public function testInformacoesComplementaresIncluemTotaisAproximados(): void
    {
        $dados = $this->extrair();
        $partes = $dados['informacoesComplementares'];

        $this->assertStringContainsString('Inf. Cont.: PAGAMENTO VIA PIX', implode(' | ', $partes));
        $this->assertStringContainsString(
            'Totais Aproximados dos Tributos cfe. Lei nº 12.741/2012',
            (string) end($partes),
        );
    }

    public function testXmlInvalidoLancaExcecao(): void
    {
        $this->expectException(NFSeException::class);

        DANFSeDados::extrair('não é xml');
    }
}
