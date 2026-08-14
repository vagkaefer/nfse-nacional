<?php

declare(strict_types=1);

namespace NFSe\Tests\Unit;

use NFSe\Utils\DANFSeGenerator;
use PHPUnit\Framework\TestCase;

final class DANFSeGeneratorTest extends TestCase
{
    public function testGerarPdfRetornaBytesDePdf(): void
    {
        $xml = file_get_contents(__DIR__ . '/../Fixtures/nfse-exemplo.xml');
        $this->assertNotFalse($xml);

        $gerador = new DANFSeGenerator();
        $pdf = $gerador->gerarPDF($xml);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf), 'PDF gerado é pequeno demais para ser válido');
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
