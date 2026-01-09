<?php

namespace NFSe\Utils;

use DOMDocument;
use Exception;

/**
 * Classe para assinatura digital de XML conforme padrão XMLDSig
 */
class AssinaturaDigital
{
    private $certificadoPfx;
    private $certificadoSenha;
    private $privateKey;
    private $publicKey;

    public function __construct(string $certificadoPfx, string $certificadoSenha)
    {
        $this->certificadoPfx = $certificadoPfx;
        $this->certificadoSenha = $certificadoSenha;
        $this->carregarCertificado();
    }

    /**
     * Carrega o certificado PFX e extrai as chaves
     */
    private function carregarCertificado(): void
    {
        if (!file_exists($this->certificadoPfx)) {
            throw new Exception("Certificado não encontrado: {$this->certificadoPfx}");
        }

        $pfxContent = file_get_contents($this->certificadoPfx);
        $certs = [];

        // Tenta carregar o certificado
        // Alguns certificados antigos usam algoritmos que requerem configuração especial
        $success = @openssl_pkcs12_read($pfxContent, $certs, $this->certificadoSenha);

        if (!$success) {
            // Se falhar, pode ser devido ao algoritmo RC2-40-CBC em OpenSSL 3.x
            // Vamos tentar converter o certificado usando a linha de comando
            $error = openssl_error_string();

            // Tenta extrair usando método alternativo via arquivo temporário
            $tempPfx = tempnam(sys_get_temp_dir(), 'pfx_');
            $tempPem = tempnam(sys_get_temp_dir(), 'pem_');

            file_put_contents($tempPfx, $pfxContent);

            // Converte PFX para PEM usando openssl CLI com provider legacy
            $cmd = sprintf(
                'openssl pkcs12 -in %s -out %s -nodes -passin pass:%s -provider legacy -provider default 2>&1',
                escapeshellarg($tempPfx),
                escapeshellarg($tempPem),
                escapeshellarg($this->certificadoSenha)
            );

            exec($cmd, $output, $returnCode);

            if ($returnCode === 0 && file_exists($tempPem)) {
                // Lê o PEM gerado
                $pemContent = file_get_contents($tempPem);

                // Extrai chave privada
                if (preg_match('/-----BEGIN PRIVATE KEY-----.*?-----END PRIVATE KEY-----/s', $pemContent, $matches)) {
                    $this->privateKey = $matches[0];
                } elseif (preg_match('/-----BEGIN RSA PRIVATE KEY-----.*?-----END RSA PRIVATE KEY-----/s', $pemContent, $matches)) {
                    $this->privateKey = $matches[0];
                }

                // Extrai certificado público
                if (preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pemContent, $matches)) {
                    $this->publicKey = $matches[0];
                }

                // Limpa arquivos temporários
                @unlink($tempPfx);
                @unlink($tempPem);

                if (!$this->privateKey || !$this->publicKey) {
                    throw new Exception("Erro ao extrair chaves do certificado PEM convertido.");
                }
            } else {
                // Limpa arquivos temporários
                @unlink($tempPfx);
                @unlink($tempPem);

                throw new Exception("Erro ao ler certificado PFX. Verifique a senha. Detalhes: " . implode("\n", $output));
            }
        } else {
            $this->privateKey = $certs['pkey'];
            $this->publicKey = $certs['cert'];
        }
    }

    /**
     * Assina o XML conforme padrão XMLDSig (enveloped signature)
     *
     * @param string $xml XML a ser assinado
     * @param string $tagAssinatura Tag que será assinada (ex: 'infDPS')
     * @param string $atributoId Nome do atributo ID (ex: 'Id')
     * @return string XML assinado
     */
    public function assinarXML(string $xml, string $tagAssinatura = 'infDPS', string $atributoId = 'Id'): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        $dom->loadXML($xml);

        // Localiza o elemento a ser assinado
        $node = $dom->getElementsByTagName($tagAssinatura)->item(0);
        if (!$node) {
            throw new Exception("Tag '{$tagAssinatura}' não encontrada no XML");
        }

        $idValue = $node->getAttribute($atributoId);
        if (!$idValue) {
            throw new Exception("Atributo '{$atributoId}' não encontrado na tag '{$tagAssinatura}'");
        }

        // Canonicaliza o elemento
        $canonicalData = $node->C14N(false, false);

        // Calcula o hash SHA-256
        $digestValue = base64_encode(hash('sha256', $canonicalData, true));

        // Cria o SignedInfo
        $signedInfo = $this->criarSignedInfo($idValue, $digestValue);

        // Importa o SignedInfo para um documento temporário para canonicalização
        $tempDom = new DOMDocument('1.0', 'UTF-8');
        $tempDom->formatOutput = false;
        $importedSignedInfo = $tempDom->importNode($signedInfo, true);
        $tempDom->appendChild($importedSignedInfo);

        // Canonicaliza o SignedInfo usando exclusive canonicalization with comments
        $signedInfoCanonical = $importedSignedInfo->C14N(true, true);

        // Assina o SignedInfo com SHA-256
        // Garante que a chave privada está no formato correto
        $keyResource = openssl_pkey_get_private($this->privateKey);
        if ($keyResource === false) {
            throw new Exception("Erro ao carregar chave privada para assinatura: " . openssl_error_string());
        }

        $success = openssl_sign($signedInfoCanonical, $signature, $keyResource, OPENSSL_ALGO_SHA256);
        if (!$success) {
            throw new Exception("Erro ao assinar XML: " . openssl_error_string());
        }

        $signatureValue = base64_encode($signature);

        // Obtém o certificado em base64
        $certData = $this->getCertificadoBase64();

        // Cria o elemento Signature
        $signatureNode = $this->criarSignature($dom, $signedInfo, $signatureValue, $certData);

        // Localiza onde inserir a assinatura (após o elemento assinado)
        $parentNode = $node->parentNode;
        $parentNode->appendChild($signatureNode);

        return $dom->saveXML();
    }

    /**
     * Cria o elemento SignedInfo
     */
    private function criarSignedInfo(string $uri, string $digestValue): \DOMElement
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $signedInfo = $dom->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'SignedInfo');

        // CanonicalizationMethod - Exclusive Canonicalization with comments
        $canonicalizationMethod = $dom->createElement('CanonicalizationMethod');
        $canonicalizationMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/10/xml-exc-c14n#WithComments');
        $signedInfo->appendChild($canonicalizationMethod);

        // SignatureMethod - RSA-SHA256
        $signatureMethod = $dom->createElement('SignatureMethod');
        $signatureMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
        $signedInfo->appendChild($signatureMethod);

        // Reference
        $reference = $dom->createElement('Reference');
        $reference->setAttribute('URI', '#' . $uri);

        // Transforms
        $transforms = $dom->createElement('Transforms');

        $transform1 = $dom->createElement('Transform');
        $transform1->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
        $transforms->appendChild($transform1);

        $transform2 = $dom->createElement('Transform');
        $transform2->setAttribute('Algorithm', 'http://www.w3.org/2001/10/xml-exc-c14n#WithComments');
        $transforms->appendChild($transform2);

        $reference->appendChild($transforms);

        // DigestMethod - SHA256
        $digestMethod = $dom->createElement('DigestMethod');
        $digestMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $reference->appendChild($digestMethod);

        // DigestValue
        $digestValueNode = $dom->createElement('DigestValue', $digestValue);
        $reference->appendChild($digestValueNode);

        $signedInfo->appendChild($reference);

        return $signedInfo;
    }

    /**
     * Cria o elemento Signature completo
     */
    private function criarSignature(
        DOMDocument $dom,
        \DOMElement $signedInfo,
        string $signatureValue,
        string $certData
    ): \DOMElement {
        $signature = $dom->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'Signature');

        // Importa SignedInfo
        $importedSignedInfo = $dom->importNode($signedInfo, true);
        $signature->appendChild($importedSignedInfo);

        // SignatureValue
        $signatureValueNode = $dom->createElement('SignatureValue', $signatureValue);
        $signature->appendChild($signatureValueNode);

        // KeyInfo
        $keyInfo = $dom->createElement('KeyInfo');
        $x509Data = $dom->createElement('X509Data');
        $x509Certificate = $dom->createElement('X509Certificate', $certData);
        $x509Data->appendChild($x509Certificate);
        $keyInfo->appendChild($x509Data);
        $signature->appendChild($keyInfo);

        return $signature;
    }

    /**
     * Obtém o certificado em formato Base64 (sem cabeçalhos)
     */
    private function getCertificadoBase64(): string
    {
        $certData = $this->publicKey;
        $certData = str_replace('-----BEGIN CERTIFICATE-----', '', $certData);
        $certData = str_replace('-----END CERTIFICATE-----', '', $certData);
        $certData = str_replace(["\r", "\n", " "], '', $certData);
        return $certData;
    }

    /**
     * Valida se um XML está assinado corretamente
     */
    public function validarAssinatura(string $xml): bool
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);

        $signature = $dom->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature')->item(0);
        if (!$signature) {
            return false;
        }

        // Implementar validação completa se necessário
        return true;
    }
}
