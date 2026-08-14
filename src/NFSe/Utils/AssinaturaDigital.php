<?php

declare(strict_types=1);

namespace NFSe\Utils;

use DOMDocument;
use DOMElement;
use NFSe\Certificate\Certificado;
use NFSe\Exception\AssinaturaException;

/**
 * Assinatura digital de XML conforme o padrão XMLDSig (enveloped signature),
 * com SHA-256 / RSA, como exigido pelo Sistema Nacional de NFS-e.
 */
class AssinaturaDigital
{
    private const NS_DSIG = 'http://www.w3.org/2000/09/xmldsig#';
    private const ALGO_C14N = 'http://www.w3.org/2001/10/xml-exc-c14n#WithComments';
    private const ALGO_ASSINATURA = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
    private const ALGO_DIGEST = 'http://www.w3.org/2001/04/xmlenc#sha256';
    private const ALGO_ENVELOPED = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    public function __construct(private readonly Certificado $certificado) {}

    /**
     * Assina o XML (enveloped signature sobre o elemento indicado).
     *
     * @param string $xml XML a ser assinado
     * @param string $tagAssinatura Tag cujo conteúdo será assinado (ex.: 'infDPS')
     * @param string $atributoId Nome do atributo de identificação (ex.: 'Id')
     */
    public function assinarXML(string $xml, string $tagAssinatura = 'infDPS', string $atributoId = 'Id'): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        $dom->loadXML($xml);

        $node = $dom->getElementsByTagName($tagAssinatura)->item(0);
        if ($node === null) {
            throw new AssinaturaException("Tag '{$tagAssinatura}' não encontrada no XML");
        }

        $idValue = $node->getAttribute($atributoId);
        if ($idValue === '') {
            throw new AssinaturaException("Atributo '{$atributoId}' não encontrado na tag '{$tagAssinatura}'");
        }

        $canonicalData = $node->C14N(false, false);
        $digestValue = base64_encode(hash('sha256', $canonicalData, true));

        $signedInfo = $this->criarSignedInfo($idValue, $digestValue);

        // Canonicaliza o SignedInfo num documento próprio, como será verificado
        $tempDom = new DOMDocument('1.0', 'UTF-8');
        $tempDom->formatOutput = false;
        $importedSignedInfo = $tempDom->importNode($signedInfo, true);
        $tempDom->appendChild($importedSignedInfo);
        $signedInfoCanonical = $importedSignedInfo->C14N(true, true);

        $keyResource = openssl_pkey_get_private($this->certificado->getKeyPem());
        if ($keyResource === false) {
            throw new AssinaturaException('Erro ao carregar chave privada para assinatura: ' . openssl_error_string());
        }

        if (!openssl_sign($signedInfoCanonical, $signature, $keyResource, OPENSSL_ALGO_SHA256)) {
            throw new AssinaturaException('Erro ao assinar XML: ' . openssl_error_string());
        }

        $signatureNode = $this->criarSignature($dom, $signedInfo, base64_encode($signature), $this->getCertificadoBase64());

        $node->parentNode?->appendChild($signatureNode);

        return (string) $dom->saveXML();
    }

    /**
     * Valida a assinatura XMLDSig de um XML: recomputa o digest do elemento
     * referenciado e verifica o SignatureValue contra o certificado embutido
     * no próprio XML (KeyInfo/X509Certificate).
     *
     * Não valida a cadeia de confiança do certificado — apenas a integridade
     * criptográfica da assinatura.
     */
    public function validarAssinatura(string $xml): bool
    {
        return self::verificarAssinatura($xml);
    }

    /**
     * Versão estática de validarAssinatura — não requer certificado próprio,
     * pois a verificação usa o certificado embutido no XML.
     */
    public static function verificarAssinatura(string $xml): bool
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        if (!@$dom->loadXML($xml)) {
            return false;
        }

        $signature = $dom->getElementsByTagNameNS(self::NS_DSIG, 'Signature')->item(0);
        if ($signature === null) {
            return false;
        }

        $signedInfo = $dom->getElementsByTagNameNS(self::NS_DSIG, 'SignedInfo')->item(0);
        $signatureValueNode = $dom->getElementsByTagNameNS(self::NS_DSIG, 'SignatureValue')->item(0);
        $certNode = $dom->getElementsByTagNameNS(self::NS_DSIG, 'X509Certificate')->item(0);
        $referenceNode = $dom->getElementsByTagNameNS(self::NS_DSIG, 'Reference')->item(0);
        $digestValueNode = $dom->getElementsByTagNameNS(self::NS_DSIG, 'DigestValue')->item(0);

        if (
            $signedInfo === null || $signatureValueNode === null || $certNode === null
            || $referenceNode === null || $digestValueNode === null
        ) {
            return false;
        }

        // 1. Localiza o elemento referenciado pela URI (#Id)
        $uri = ltrim($referenceNode->getAttribute('URI'), '#');
        $alvo = self::localizarElementoPorId($dom, $uri);
        if ($alvo === null) {
            return false;
        }

        // 2. Recomputa o digest. A transformação enveloped-signature exige
        //    canonicalizar o alvo sem a Signature; quando a Signature é irmã
        //    do alvo (caso desta biblioteca), remover não altera o alvo, mas
        //    remove-se mesmo assim para o caso geral de assinatura aninhada.
        $clone = new DOMDocument('1.0', 'UTF-8');
        $clone->preserveWhiteSpace = false;
        $clone->loadXML($dom->saveXML() ?: '');
        $cloneSignature = $clone->getElementsByTagNameNS(self::NS_DSIG, 'Signature')->item(0);
        $cloneSignature?->parentNode?->removeChild($cloneSignature);
        $cloneAlvo = self::localizarElementoPorId($clone, $uri);
        if ($cloneAlvo === null) {
            return false;
        }

        $digestRecomputado = base64_encode(hash('sha256', $cloneAlvo->C14N(false, false), true));
        $digestDeclarado = trim($digestValueNode->textContent);
        if (!hash_equals($digestRecomputado, $digestDeclarado)) {
            return false;
        }

        // 3. Verifica o SignatureValue sobre o SignedInfo canonicalizado
        $assinatura = base64_decode(trim($signatureValueNode->textContent), true);
        if ($assinatura === false) {
            return false;
        }

        $certPem = "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(trim($certNode->textContent), 64, "\n")
            . "-----END CERTIFICATE-----\n";
        $chavePublica = openssl_pkey_get_public($certPem);
        if ($chavePublica === false) {
            return false;
        }

        $canonicalSignedInfo = $signedInfo->C14N(true, true);

        return openssl_verify($canonicalSignedInfo, $assinatura, $chavePublica, OPENSSL_ALGO_SHA256) === 1;
    }

    private static function localizarElementoPorId(DOMDocument $dom, string $id): ?DOMElement
    {
        $xpath = new \DOMXPath($dom);
        $lista = $xpath->query("//*[@Id='{$id}']");
        $node = $lista === false ? null : $lista->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function criarSignedInfo(string $uri, string $digestValue): DOMElement
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $signedInfo = $dom->createElementNS(self::NS_DSIG, 'SignedInfo');

        $canonicalizationMethod = $dom->createElement('CanonicalizationMethod');
        $canonicalizationMethod->setAttribute('Algorithm', self::ALGO_C14N);
        $signedInfo->appendChild($canonicalizationMethod);

        $signatureMethod = $dom->createElement('SignatureMethod');
        $signatureMethod->setAttribute('Algorithm', self::ALGO_ASSINATURA);
        $signedInfo->appendChild($signatureMethod);

        $reference = $dom->createElement('Reference');
        $reference->setAttribute('URI', '#' . $uri);

        $transforms = $dom->createElement('Transforms');

        $transform1 = $dom->createElement('Transform');
        $transform1->setAttribute('Algorithm', self::ALGO_ENVELOPED);
        $transforms->appendChild($transform1);

        $transform2 = $dom->createElement('Transform');
        $transform2->setAttribute('Algorithm', self::ALGO_C14N);
        $transforms->appendChild($transform2);

        $reference->appendChild($transforms);

        $digestMethod = $dom->createElement('DigestMethod');
        $digestMethod->setAttribute('Algorithm', self::ALGO_DIGEST);
        $reference->appendChild($digestMethod);

        $reference->appendChild($dom->createElement('DigestValue', $digestValue));

        $signedInfo->appendChild($reference);

        return $signedInfo;
    }

    private function criarSignature(
        DOMDocument $dom,
        DOMElement $signedInfo,
        string $signatureValue,
        string $certData,
    ): DOMElement {
        $signature = $dom->createElementNS(self::NS_DSIG, 'Signature');

        $importedSignedInfo = $dom->importNode($signedInfo, true);
        $signature->appendChild($importedSignedInfo);

        $signature->appendChild($dom->createElement('SignatureValue', $signatureValue));

        $keyInfo = $dom->createElement('KeyInfo');
        $x509Data = $dom->createElement('X509Data');
        $x509Data->appendChild($dom->createElement('X509Certificate', $certData));
        $keyInfo->appendChild($x509Data);
        $signature->appendChild($keyInfo);

        return $signature;
    }

    private function getCertificadoBase64(): string
    {
        return str_replace(
            ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\r", "\n", ' '],
            '',
            $this->certificado->getCertPem(),
        );
    }
}
