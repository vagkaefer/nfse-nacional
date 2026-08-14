<?php

declare(strict_types=1);

namespace NFSe\Certificate;

use NFSe\Exception\CertificadoException;
use SensitiveParameter;

/**
 * Certificado digital A1 (PFX/PKCS#12).
 *
 * Carrega o certificado preferencialmente em memória via openssl_pkcs12_read.
 * Certificados antigos cifrados com RC2-40-CBC não são suportados pelo
 * OpenSSL 3.x por padrão; nesses casos há um fallback via CLI do openssl com
 * o legacy provider — a senha é passada por arquivo temporário (nunca em
 * argumentos de linha de comando, que seriam visíveis em `ps`).
 */
class Certificado
{
    private string $certPem;
    private string $keyPem;
    private ?string $certPemPath = null;
    private ?string $keyPemPath = null;

    public function __construct(
        private readonly string $pfxPath,
        #[SensitiveParameter]
        private readonly string $senha,
    ) {
        $this->carregar();
    }

    public function __destruct()
    {
        foreach ([$this->certPemPath, $this->keyPemPath] as $arquivo) {
            if ($arquivo !== null && file_exists($arquivo)) {
                @unlink($arquivo);
            }
        }
    }

    public function getCertPem(): string
    {
        return $this->certPem;
    }

    public function getKeyPem(): string
    {
        return $this->keyPem;
    }

    /**
     * Caminho de um arquivo temporário (0600) com o certificado em PEM,
     * criado sob demanda para uso no cURL (mTLS) e removido no destructor.
     */
    public function getCertPemPath(): string
    {
        return $this->certPemPath ??= $this->criarArquivoTemporario('nfse_cert_', $this->certPem);
    }

    /**
     * Caminho de um arquivo temporário (0600) com a chave privada em PEM.
     */
    public function getKeyPemPath(): string
    {
        return $this->keyPemPath ??= $this->criarArquivoTemporario('nfse_key_', $this->keyPem);
    }

    /**
     * Extrai o CNPJ ou CPF do CN do certificado (formato ICP-Brasil "NOME:DOCUMENTO").
     */
    public function getCnpjCpf(): ?string
    {
        $dados = openssl_x509_parse($this->certPem);
        if ($dados === false || !isset($dados['subject']['CN'])) {
            return null;
        }

        if (preg_match('/(\d{14}|\d{11})/', (string) $dados['subject']['CN'], $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function carregar(): void
    {
        if (!file_exists($this->pfxPath)) {
            throw new CertificadoException("Certificado não encontrado: {$this->pfxPath}");
        }

        $pfxContent = file_get_contents($this->pfxPath);
        if ($pfxContent === false) {
            throw new CertificadoException("Erro ao ler o arquivo do certificado: {$this->pfxPath}");
        }

        $certs = [];
        if (@openssl_pkcs12_read($pfxContent, $certs, $this->senha)) {
            $this->certPem = $certs['cert'];
            $this->keyPem = $certs['pkey'];

            return;
        }

        // Fallback: certificados antigos (RC2-40-CBC) exigem o legacy provider
        // do OpenSSL 3.x, disponível apenas via CLI.
        $this->carregarViaCli();
    }

    private function carregarViaCli(): void
    {
        $tempPem = $this->criarArquivoTemporario('nfse_pem_', '');
        $senhaFile = $this->criarArquivoTemporario('nfse_pass_', $this->senha);

        try {
            $cmd = sprintf(
                'openssl pkcs12 -in %s -out %s -nodes -passin file:%s -provider legacy -provider default 2>&1',
                escapeshellarg($this->pfxPath),
                escapeshellarg($tempPem),
                escapeshellarg($senhaFile),
            );
            exec($cmd, $output, $returnCode);

            if ($returnCode !== 0) {
                $detalhes = implode("\n", $output);
                $mensagem = "Erro ao ler certificado PFX. Verifique a senha. Detalhes: {$detalhes}";
                if (str_contains($detalhes, 'provider')) {
                    $mensagem .= "\nEste certificado usa cifra legada (RC2-40-CBC) e requer o "
                        . 'legacy provider do OpenSSL 3.x instalado no sistema.';
                }

                throw new CertificadoException($mensagem);
            }

            $pemContent = (string) file_get_contents($tempPem);

            if (preg_match('/-----BEGIN (?:RSA )?PRIVATE KEY-----.*?-----END (?:RSA )?PRIVATE KEY-----/s', $pemContent, $matches) === 1) {
                $this->keyPem = $matches[0];
            }
            if (preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pemContent, $matches) === 1) {
                $this->certPem = $matches[0];
            }

            if (!isset($this->certPem) || !isset($this->keyPem)) {
                throw new CertificadoException('Erro ao extrair chaves do certificado PEM convertido.');
            }
        } finally {
            @unlink($tempPem);
            @unlink($senhaFile);
        }
    }

    private function criarArquivoTemporario(string $prefixo, string $conteudo): string
    {
        $arquivo = tempnam(sys_get_temp_dir(), $prefixo);
        if ($arquivo === false) {
            throw new CertificadoException('Erro ao criar arquivo temporário.');
        }

        chmod($arquivo, 0600);
        if (file_put_contents($arquivo, $conteudo) === false) {
            @unlink($arquivo);

            throw new CertificadoException('Erro ao gravar arquivo temporário.');
        }

        return $arquivo;
    }
}
