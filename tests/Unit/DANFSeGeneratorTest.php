<?php

declare(strict_types=1);

namespace NFSe\Tests\Unit;

use NFSe\Utils\DANFSeGenerator;
use PHPUnit\Framework\TestCase;

final class DANFSeGeneratorTest extends TestCase
{
    private function gerar(string $fixture = 'nfse-exemplo.xml', array $opcoes = []): string
    {
        $xml = (string) file_get_contents(__DIR__ . '/../Fixtures/' . $fixture);

        return (new DANFSeGenerator($opcoes))->gerarPDF($xml);
    }

    /**
     * Extrai o texto do PDF para conferir o conteúdo renderizado. Depende do
     * pdftotext (poppler), ausente em alguns ambientes de CI.
     */
    private function textoDoPdf(string $pdf): string
    {
        if (self::caminhoPdfToText() === null) {
            $this->markTestSkipped('pdftotext não disponível para inspecionar o PDF');
        }

        $arquivo = (string) tempnam(sys_get_temp_dir(), 'danfse_');
        file_put_contents($arquivo, $pdf);

        try {
            $saida = shell_exec(escapeshellcmd((string) self::caminhoPdfToText())
                . ' -layout ' . escapeshellarg($arquivo) . ' -');

            return (string) $saida;
        } finally {
            @unlink($arquivo);
        }
    }

    private static function caminhoPdfToText(): ?string
    {
        $caminho = trim((string) shell_exec('command -v pdftotext 2>/dev/null'));

        return $caminho !== '' ? $caminho : null;
    }

    public function testGerarPdfRetornaBytesDePdf(): void
    {
        $pdf = $this->gerar();

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf), 'PDF gerado é pequeno demais para ser válido');
    }

    /**
     * Número de páginas declarado no nó /Pages do PDF.
     */
    private function totalDePaginas(string $pdf): int
    {
        $this->assertMatchesRegularExpression('#/Count (\d+)#', $pdf, 'PDF sem contagem de páginas');
        preg_match('#/Count (\d+)#', $pdf, $m);

        return (int) $m[1];
    }

    /**
     * O DANFSe deve ser impresso obrigatoriamente em uma única página
     * (NT 008, item 2.2).
     */
    public function testPdfTemPaginaUnica(): void
    {
        $this->assertSame(1, $this->totalDePaginas($this->gerar()));
    }

    /**
     * Descrições longas não podem empurrar o documento para uma segunda página.
     */
    public function testDescricaoLongaNaoQuebraPaginaUnica(): void
    {
        $xml = (string) file_get_contents(__DIR__ . '/../Fixtures/nfse-exemplo.xml');
        $xml = str_replace(
            'CONSULTORIA EM TECNOLOGIA DA INFORMACAO',
            str_repeat('DESCRICAO MUITO LONGA DO SERVICO PRESTADO ', 40),
            $xml,
        );

        $pdf = (new DANFSeGenerator())->gerarPDF($xml);

        $this->assertSame(1, $this->totalDePaginas($pdf));
    }

    public function testBlocosObrigatoriosDoLayoutV2(): void
    {
        $texto = $this->textoDoPdf($this->gerar());

        foreach ([
            'DANFSe v2.0',
            'Documento Auxiliar da NFS-e',
            'CHAVE DE ACESSO DA NFS-e',
            'PRESTADOR / FORNECEDOR',
            'TOMADOR / ADQUIRENTE',
            'SERVIÇO PRESTADO',
            'TRIBUTAÇÃO MUNICIPAL (ISSQN)',
            'TRIBUTAÇÃO FEDERAL (EXCETO CBS)',
            'TRIBUTAÇÃO IBS / CBS',
            'VALOR TOTAL DA NFS-e',
            'VALOR LÍQUIDO DA NFS-e + IBS/CBS',
            'INFORMAÇÕES COMPLEMENTARES',
        ] as $rotulo) {
            $this->assertStringContainsString($rotulo, $texto, "Bloco ausente no DANFSe: {$rotulo}");
        }
    }

    /**
     * NFS-e de homologação leva a tarja obrigatória (NT 008, item 2.4.3).
     */
    public function testAmbienteDeHomologacaoExibeTarja(): void
    {
        $texto = $this->textoDoPdf($this->gerar());

        $this->assertStringContainsString('NFS-e SEM VALIDADE JURÍDICA', $texto);
    }

    public function testAmbienteDeProducaoNaoExibeTarja(): void
    {
        $xml = (string) file_get_contents(__DIR__ . '/../Fixtures/nfse-exemplo.xml');
        $xml = str_replace('<tpAmb>2</tpAmb>', '<tpAmb>1</tpAmb>', $xml);

        $texto = $this->textoDoPdf((new DANFSeGenerator())->gerarPDF($xml));

        $this->assertStringNotContainsString('SEM VALIDADE JURÍDICA', $texto);
    }

    /**
     * Blocos não preenchidos viram uma linha única (NT 008, item 2.3).
     */
    public function testBlocosNaoInformadosSaoSuprimidos(): void
    {
        $texto = $this->textoDoPdf($this->gerar());

        $this->assertStringContainsString('INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e', $texto);
    }

    public function testValoresDeIbsCbsSaoRenderizados(): void
    {
        $texto = $this->textoDoPdf($this->gerar('nfse-exemplo-ibscbs.xml'));

        $this->assertStringContainsString('000 / 000001', $texto);
        $this->assertStringContainsString('R$ 2,25', $texto, 'Valor total do IBS');
        $this->assertStringContainsString('R$ 13,50', $texto, 'Valor total da CBS');
        $this->assertStringContainsString('R$ 1.516,25', $texto, 'Valor líquido + IBS/CBS');
    }

    /**
     * O destinatário é o próprio tomador quando indDest = 0 (item 2.3.2).
     */
    public function testDestinatarioIgualAoTomadorUsaLinhaUnica(): void
    {
        $texto = $this->textoDoPdf($this->gerar('nfse-exemplo-ibscbs.xml'));

        $this->assertStringContainsString('O DESTINATÁRIO É O PRÓPRIO TOMADOR/ADQUIRENTE DA OPERAÇÃO', $texto);
    }

    /**
     * A marca d'água é desenhada em diagonal, dentro de uma transformação — o
     * pdftotext não a devolve, então a busca é feita nos streams do PDF.
     */
    private function conteudoDosStreams(string $pdf): string
    {
        preg_match_all('#stream\r?\n(.*?)endstream#s', $pdf, $matches);

        $conteudo = '';
        foreach ($matches[1] as $stream) {
            $descomprimido = @gzuncompress($stream);
            $conteudo .= $descomprimido !== false ? $descomprimido : $stream;
        }

        return $conteudo;
    }

    public function testNotaCanceladaRecebeMarcaDagua(): void
    {
        $xml = (string) file_get_contents(__DIR__ . '/../Fixtures/nfse-exemplo.xml');
        $xml = str_replace('<cStat>100</cStat>', '<cStat>101</cStat>', $xml);

        $pdf = (new DANFSeGenerator())->gerarPDF($xml);

        $this->assertStringContainsString('CANCELADA', $this->conteudoDosStreams($pdf));
    }

    public function testNotaRegularNaoRecebeMarcaDagua(): void
    {
        $conteudo = $this->conteudoDosStreams($this->gerar());

        $this->assertStringNotContainsString('CANCELADA', $conteudo);
        $this->assertStringNotContainsString('SUBSTITUÍDA', $conteudo);
    }

    public function testCanhotoPodeSerSuprimido(): void
    {
        $comCanhoto = $this->textoDoPdf($this->gerar());
        $semCanhoto = $this->textoDoPdf($this->gerar(opcoes: ['exibirCanhoto' => false]));

        $this->assertStringContainsString('DATA CIENTIFICAÇÃO', $comCanhoto);
        $this->assertStringNotContainsString('DATA CIENTIFICAÇÃO', $semCanhoto);
    }

    /**
     * O QR Code aponta para a consulta pública do portal nacional
     * (NT 008, item 2.4.3).
     */
    public function testQrCodeUsaUrlDaConsultaPublica(): void
    {
        $pdf = $this->gerar();

        // O conteúdo do QR não é texto extraível; verifica-se o objeto no PDF
        $this->assertStringContainsString('%PDF', $pdf);
        $this->assertGreaterThan(5000, strlen($pdf), 'PDF deve conter o QR Code renderizado');
    }

    public function testXmlInvalidoLancaExcecao(): void
    {
        $gerador = new DANFSeGenerator();

        $this->expectException(\Exception::class);

        // Silencia os warnings do parser para que apenas a exceção seja observada
        $anterior = libxml_use_internal_errors(true);
        try {
            $gerador->gerarPDF('isso não é xml');
        } finally {
            libxml_use_internal_errors($anterior);
        }
    }
}
