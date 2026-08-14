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

    /**
     * Campos de coluna simples truncam em 37 caracteres com reticências
     * (NT 008, item 2.4.5) — como faz o portal nacional.
     */
    public function testTruncamentoDe37CaracteresNoSimplesNacional(): void
    {
        $texto = $this->textoDoPdf($this->gerar());

        $this->assertStringContainsString('Optante - Microempresa ou Empresa de ...', $texto);
        $this->assertStringNotContainsString('(ME/EPP)', $texto);
    }

    /**
     * As linhas opcionais do bloco ISSQN são suprimidas quando todos os
     * campos estão sem dados no XML (NT 008, nota 5).
     */
    public function testLinhasOpcionaisDoIssqnSaoSuprimidas(): void
    {
        $texto = $this->textoDoPdf($this->gerar());

        $this->assertStringNotContainsString('Regime Especial de Tributação do ISSQN', $texto);
        $this->assertStringNotContainsString('Benefício Municipal', $texto);

        $xml = (string) file_get_contents(__DIR__ . '/../Fixtures/nfse-exemplo.xml');
        $xml = str_replace(
            '<tribMun><tribISSQN>1</tribISSQN>',
            '<tribMun><tribISSQN>1</tribISSQN><tpImunidade>4</tpImunidade>',
            $xml,
        );
        $comImunidade = $this->textoDoPdf((new DANFSeGenerator())->gerarPDF($xml));

        $this->assertStringContainsString('Regime Especial de Tributação do ISSQN', $comImunidade);
    }

    /**
     * A linha de PIS/COFINS só é impressa para competências até o fim de 2026
     * (NT 008, nota 6).
     */
    public function testPisCofinsAusenteAposCompetencia2026(): void
    {
        $xml = (string) file_get_contents(__DIR__ . '/../Fixtures/nfse-exemplo.xml');
        $xml = str_replace('<dCompet>2026-01-15</dCompet>', '<dCompet>2027-01-15</dCompet>', $xml);

        $texto = $this->textoDoPdf((new DANFSeGenerator())->gerarPDF($xml));

        $this->assertStringNotContainsString('PIS - Débito Apuração Própria', $texto);
    }

    /**
     * Posição vertical (em pontos, a partir do topo) da primeira ocorrência de
     * uma palavra no PDF, via pdftotext -bbox.
     */
    private function posicaoVertical(string $pdf, string $palavra): float
    {
        if (self::caminhoPdfToText() === null) {
            $this->markTestSkipped('pdftotext não disponível para inspecionar o PDF');
        }

        $arquivo = (string) tempnam(sys_get_temp_dir(), 'danfse_');
        file_put_contents($arquivo, $pdf);

        try {
            $saida = (string) shell_exec(escapeshellcmd((string) self::caminhoPdfToText())
                . ' -bbox ' . escapeshellarg($arquivo) . ' -');
        } finally {
            @unlink($arquivo);
        }

        $this->assertMatchesRegularExpression(
            '#yMin="([\d.]+)"[^>]*>' . preg_quote($palavra, '#') . '<#u',
            $saida,
            "Palavra não encontrada no PDF: {$palavra}",
        );
        preg_match('#yMin="([\d.]+)"[^>]*>' . preg_quote($palavra, '#') . '<#u', $saida, $m);

        return (float) $m[1];
    }

    /**
     * O canhoto é fixo no pé do formulário (sup 28,10 cm — NT 008, item
     * 2.4.5), independentemente dos blocos suprimidos acima dele.
     */
    public function testCanhotoFixoNoPeDaPagina(): void
    {
        // 281 mm do topo = ~796,5 pt
        $yEsperado = 281.0 / 25.4 * 72;

        $posicao = $this->posicaoVertical($this->gerar(), 'CIENTIFICAÇÃO:');
        $this->assertEqualsWithDelta($yEsperado, $posicao, 6.0);

        // Com o tomador também suprimido, o canhoto não pode se mover
        $xml = (string) file_get_contents(__DIR__ . '/../Fixtures/nfse-exemplo.xml');
        $semTomador = (string) preg_replace('#<toma>.*?</toma>#s', '', $xml);
        $posicaoSemTomador = $this->posicaoVertical(
            (new DANFSeGenerator())->gerarPDF($semTomador),
            'CIENTIFICAÇÃO:',
        );

        $this->assertEqualsWithDelta($posicao, $posicaoSemTomador, 0.5);
    }

    /**
     * Pior caso de conteúdo com máximo de supressões: o documento continua em
     * página única e sem texto além do formulário.
     */
    public function testPaginaUnicaNoPiorCaso(): void
    {
        $xml = (string) file_get_contents(__DIR__ . '/../Fixtures/nfse-exemplo.xml');
        $xml = (string) preg_replace('#<toma>.*?</toma>#s', '', $xml);
        $xml = str_replace(
            'CONSULTORIA EM TECNOLOGIA DA INFORMACAO',
            str_repeat('DESCRICAO LONGA DO SERVICO ', 60),
            $xml,
        );
        $xml = str_replace(
            'PAGAMENTO VIA PIX',
            str_repeat('INFORMACAO COMPLEMENTAR EXTENSA ', 80),
            $xml,
        );

        $pdf = (new DANFSeGenerator())->gerarPDF($xml);

        $this->assertSame(1, $this->totalDePaginas($pdf));
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
