<?php

declare(strict_types=1);

namespace NFSe\Tests\Support;

use RuntimeException;

/**
 * Gera certificados A1 (PFX) descartáveis para uso em testes.
 *
 * Usa apenas as funções openssl_* do PHP — nenhuma dependência do binário
 * openssl nem do legacy provider, pois o PFX gerado usa cifras modernas.
 */
final class CertificateFactory
{
    public const SENHA = 'senha-teste';
    public const CNPJ = '12345678000195';

    /**
     * Cria um PFX self-signed e retorna o caminho do arquivo.
     *
     * O CN segue o formato dos certificados ICP-Brasil ("NOME:CNPJ") para
     * que a extração de CNPJ a partir do certificado seja testável.
     *
     * @return array{path: string, senha: string, certPem: string, keyPem: string}
     */
    public static function criarPfx(?string $cn = null): array
    {
        $cn ??= 'EMPRESA TESTE LTDA:' . self::CNPJ;

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($key === false) {
            throw new RuntimeException('Falha ao gerar chave: ' . openssl_error_string());
        }

        $dn = [
            'countryName' => 'BR',
            'organizationName' => 'Teste',
            'commonName' => $cn,
        ];

        $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
        if ($csr === false) {
            throw new RuntimeException('Falha ao gerar CSR: ' . openssl_error_string());
        }

        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        if ($cert === false) {
            throw new RuntimeException('Falha ao assinar certificado: ' . openssl_error_string());
        }

        if (!openssl_pkcs12_export($cert, $pfx, $key, self::SENHA)) {
            throw new RuntimeException('Falha ao exportar PFX: ' . openssl_error_string());
        }

        $path = tempnam(sys_get_temp_dir(), 'nfse_test_') . '.pfx';
        file_put_contents($path, $pfx);

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);

        return [
            'path' => $path,
            'senha' => self::SENHA,
            'certPem' => $certPem,
            'keyPem' => $keyPem,
        ];
    }
}
