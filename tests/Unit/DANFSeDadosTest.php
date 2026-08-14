<?php

declare(strict_types=1);

namespace NFSe\Tests\Unit;

use NFSe\Exception\NFSeException;
use NFSe\Utils\DANFSeDados;
use PHPUnit\Framework\TestCase;

final class DANFSeDadosTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function extrair(array $municipios = []): array
    {
        $xml = (string) file_get_contents(__DIR__ . '/../Fixtures/nfse-exemplo.xml');

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
    }

    public function testPrestador(): void
    {
        $dados = $this->extrair();
        $prest = $dados['prestador'];

        $this->assertSame('11.222.333/0001-81', $prest['cnpj']);
        $this->assertSame('EMPRESA EXEMPLO LTDA', $prest['nome']);
        $this->assertSame('(49) 0000-0000', $prest['telefone']);
        $this->assertSame('contato@example.com', $prest['email']);
        $this->assertSame('3', $prest['simplesNacional']);
        $this->assertSame('RUA DAS FLORES, 410, CENTRO', $prest['endereco']['logradouro']);
        $this->assertSame('89990-000', $prest['endereco']['cep']);
        // Sem mapa de municípios, exibe o código com a UF
        $this->assertSame('Município 4216909 - SC', $prest['endereco']['municipio']);
    }

    public function testTomador(): void
    {
        $dados = $this->extrair();
        $tom = $dados['tomador'];

        $this->assertSame('44.555.666/0001-72', $tom['cnpj']);
        $this->assertSame('TOMADOR EXEMPLO LTDA', $tom['nome']);
        $this->assertSame('AVENIDA PRINCIPAL, 141, CENTRO', $tom['endereco']['logradouro']);
        $this->assertSame('89802-112', $tom['endereco']['cep']);
        $this->assertSame('Município 4204202', $tom['endereco']['municipio']);
    }

    public function testServicoEValores(): void
    {
        $dados = $this->extrair();

        $this->assertSame('01.06.01', $dados['servico']['codigoTributacao']);
        $this->assertSame('CONSULTORIA EM TECNOLOGIA DA INFORMACAO', $dados['servico']['descricao']);
        $this->assertSame('Município 4204202', $dados['servico']['localPrestacao']);

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
            '4204202' => 'Chapecó - SC',
        ]);

        $this->assertSame('São Lourenço do Oeste - SC', $dados['prestador']['endereco']['municipio']);
        $this->assertSame('Chapecó - SC', $dados['tomador']['endereco']['municipio']);
        $this->assertSame('Chapecó - SC', $dados['servico']['localPrestacao']);
    }

    public function testXmlInvalidoLancaExcecao(): void
    {
        $this->expectException(NFSeException::class);

        DANFSeDados::extrair('não é xml');
    }
}
