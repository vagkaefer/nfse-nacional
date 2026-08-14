<?php

declare(strict_types=1);

namespace NFSe\Tests\Unit;

use NFSe\Certificate\Certificado;
use NFSe\Exception\CertificadoException;
use NFSe\Tests\Support\CertificateFactory;
use PHPUnit\Framework\TestCase;

final class CertificadoTest extends TestCase
{
    private static string $pfxPath;

    public static function setUpBeforeClass(): void
    {
        self::$pfxPath = CertificateFactory::criarPfx()['path'];
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$pfxPath);
    }

    public function testCarregaPemsEmMemoria(): void
    {
        $cert = new Certificado(self::$pfxPath, CertificateFactory::SENHA);

        $this->assertStringContainsString('BEGIN CERTIFICATE', $cert->getCertPem());
        $this->assertStringContainsString('PRIVATE KEY', $cert->getKeyPem());
    }

    public function testArquivosTemporariosCriadosCom0600ERemovidosNoDestructor(): void
    {
        $cert = new Certificado(self::$pfxPath, CertificateFactory::SENHA);

        $certPath = $cert->getCertPemPath();
        $keyPath = $cert->getKeyPemPath();

        $this->assertFileExists($certPath);
        $this->assertFileExists($keyPath);
        $this->assertSame('0600', substr(sprintf('%o', (int) fileperms($certPath)), -4));
        $this->assertSame('0600', substr(sprintf('%o', (int) fileperms($keyPath)), -4));

        // Chamadas repetidas devem reutilizar o mesmo arquivo
        $this->assertSame($certPath, $cert->getCertPemPath());

        unset($cert);

        $this->assertFileDoesNotExist($certPath);
        $this->assertFileDoesNotExist($keyPath);
    }

    public function testExtraiCnpjDoCommonName(): void
    {
        $cert = new Certificado(self::$pfxPath, CertificateFactory::SENHA);

        $this->assertSame(CertificateFactory::CNPJ, $cert->getCnpjCpf());
    }

    public function testExtraiCpfDoCommonName(): void
    {
        $pfxCpf = CertificateFactory::criarPfx('PESSOA FISICA TESTE:12345678909');
        try {
            $cert = new Certificado($pfxCpf['path'], $pfxCpf['senha']);

            $this->assertSame('12345678909', $cert->getCnpjCpf());
        } finally {
            @unlink($pfxCpf['path']);
        }
    }

    public function testSenhaErradaLancaCertificadoException(): void
    {
        $this->expectException(CertificadoException::class);

        new Certificado(self::$pfxPath, 'senha-errada');
    }

    public function testArquivoInexistenteLancaCertificadoException(): void
    {
        $this->expectException(CertificadoException::class);
        $this->expectExceptionMessage('não encontrado');

        new Certificado('/caminho/que/nao/existe.pfx', 'qualquer');
    }
}
