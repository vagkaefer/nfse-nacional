<?php

namespace NFSe\Services;

use NFSe\Config\Config;
use NFSe\Models\DPS;
use NFSe\Utils\AssinaturaDigital;
use Exception;

/**
 * Cliente para comunicação com a API do Sistema Nacional de NFS-e
 */
class NFSeClient
{
    private $config;
    private $assinatura;
    private $certPemFile;
    private $keyPemFile;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->assinatura = new AssinaturaDigital(
            $config->getCertificadoPfx(),
            $config->getCertificadoSenha()
        );

        // Converte PFX para PEM para uso no cURL
        $this->converterCertificadoParaPem();
    }

    public function __destruct()
    {
        // Limpa arquivos temporários
        if ($this->certPemFile && file_exists($this->certPemFile)) {
            @unlink($this->certPemFile);
        }
        if ($this->keyPemFile && file_exists($this->keyPemFile)) {
            @unlink($this->keyPemFile);
        }
    }

    /**
     * Converte certificado PFX para PEM para uso no cURL
     */
    private function converterCertificadoParaPem(): void
    {
        $pfxFile = $this->config->getCertificadoPfx();
        $senha = $this->config->getCertificadoSenha();

        // Cria arquivos temporários
        $this->certPemFile = tempnam(sys_get_temp_dir(), 'nfse_cert_') . '.pem';
        $this->keyPemFile = tempnam(sys_get_temp_dir(), 'nfse_key_') . '.pem';

        // Extrai certificado
        $cmd = sprintf(
            'openssl pkcs12 -in %s -out %s -clcerts -nokeys -passin pass:%s -provider legacy -provider default 2>&1',
            escapeshellarg($pfxFile),
            escapeshellarg($this->certPemFile),
            escapeshellarg($senha)
        );
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new Exception("Erro ao extrair certificado: " . implode("\n", $output));
        }

        // Extrai chave privada
        $cmd = sprintf(
            'openssl pkcs12 -in %s -out %s -nocerts -nodes -passin pass:%s -provider legacy -provider default 2>&1',
            escapeshellarg($pfxFile),
            escapeshellarg($this->keyPemFile),
            escapeshellarg($senha)
        );
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new Exception("Erro ao extrair chave privada: " . implode("\n", $output));
        }
    }

    /**
     * Envia uma DPS para emissão de NFS-e
     *
     * @param DPS $dps Declaração de Prestação de Serviço
     * @return array Resposta da API
     */
    public function emitirNFSe(DPS $dps): array
    {
        // Gera o XML da DPS
        $xmlDPS = $dps->gerarXML();

        // Assina o XML
        $xmlAssinado = $this->assinatura->assinarXML($xmlDPS, 'infDPS', 'Id');

        // Compacta em GZIP e codifica em Base64
        $xmlGzip = gzencode($xmlAssinado);
        $xmlBase64 = base64_encode($xmlGzip);

        // Monta o JSON conforme esperado pela API Sefin Nacional
        $payload = json_encode([
            'dpsXmlGZipB64' => $xmlBase64
        ]);

        // Envia para a API Sefin Nacional
        $endpoint = '/nfse';
        $response = $this->enviarRequisicao('POST', $endpoint, $payload, 'application/json');

        return $this->processarResposta($response);
    }

    /**
     * Consulta uma NFS-e pela chave de acesso
     *
     * @param string $chaveAcesso Chave de acesso da NFS-e (50 caracteres)
     * @return array Dados da NFS-e
     */
    public function consultarNFSe(string $chaveAcesso): array
    {
        $endpoint = "/nfse/{$chaveAcesso}";
        $response = $this->enviarRequisicao('GET', $endpoint);

        return $this->processarResposta($response);
    }

    /**
     * Cancela uma NFS-e
     *
     * @param string $chaveAcesso Chave de acesso da NFS-e
     * @param string $motivo Motivo do cancelamento (texto)
     * @param int $codigoMotivo Código do motivo (1-9)
     * @return array Resposta da API
     */
    public function cancelarNFSe(string $chaveAcesso, string $motivo, int $codigoMotivo = 9): array
    {
        // Cria o XML do pedido de cancelamento (evento)
        $xmlEvento = $this->criarEventoCancelamento($chaveAcesso, $motivo, $codigoMotivo);

        // Assina o XML
        $xmlAssinado = $this->assinatura->assinarXML($xmlEvento, 'infPedReg', 'Id');

        // Compacta em GZIP e codifica em Base64 (igual ao envio de DPS)
        $xmlGzip = gzencode($xmlAssinado);
        $xmlBase64 = base64_encode($xmlGzip);

        // Monta o JSON conforme esperado pela API
        $payload = json_encode([
            'pedRegEventoXmlGZipB64' => $xmlBase64
        ]);

        // Envia para a API
        $endpoint = "/nfse/{$chaveAcesso}/eventos";
        $response = $this->enviarRequisicao('POST', $endpoint, $payload, 'application/json');

        return $this->processarResposta($response);
    }

    /**
     * Consulta DPS pelo ID para obter chave de acesso da NFS-e
     *
     * @param string $idDPS ID da DPS (formato: DPS + CNPJ/CPF + série + número)
     * @return array Dados com a chave de acesso
     */
    public function consultarDPS(string $idDPS): array
    {
        $endpoint = "/dps/{$idDPS}";
        $response = $this->enviarRequisicao('GET', $endpoint);

        return $this->processarResposta($response);
    }

    /**
     * Baixa o XML da NFS-e
     *
     * @param string $chaveAcesso Chave de acesso da NFS-e
     * @param string|null $caminhoArquivo Caminho onde salvar o arquivo (opcional)
     * @return string Conteúdo do XML
     */
    public function baixarXML(string $chaveAcesso, ?string $caminhoArquivo = null): string
    {
        // Consulta a NFS-e para obter o XML compactado
        $nfse = $this->consultarNFSe($chaveAcesso);

        if (!isset($nfse['nfseXmlGZipB64'])) {
            throw new Exception("XML da NFS-e não encontrado na resposta");
        }

        // Decodifica o XML (Base64 + GZIP)
        $xmlCompactado = base64_decode($nfse['nfseXmlGZipB64']);
        $xml = gzdecode($xmlCompactado);

        if ($xml === false) {
            throw new Exception("Erro ao descompactar XML da NFS-e");
        }

        // Salva em arquivo se caminho foi fornecido
        if ($caminhoArquivo !== null) {
            $resultado = file_put_contents($caminhoArquivo, $xml);
            if ($resultado === false) {
                throw new Exception("Erro ao salvar XML no caminho: {$caminhoArquivo}");
            }
        }

        return $xml;
    }

    /**
     * Baixa o PDF (DANFSe) da NFS-e
     *
     * Tenta primeiro baixar do endpoint oficial da NFS-e.
     * Em caso de falha (erro 429, timeout, etc), gera localmente usando o gerador de PDF.
     *
     * IMPORTANTE: O endpoint oficial tem rate limiting severo (429 Too Many Requests).
     * Recomenda-se aguardar 20 segundos entre requisições para evitar bloqueios.
     *
     * @param string $chaveAcesso Chave de acesso da NFS-e
     * @param string|null $caminhoArquivo Caminho onde salvar o arquivo (opcional)
     * @param string|null $logoPath Caminho para logo personalizado (usado apenas no fallback)
     * @param int $sleepSeconds Segundos de espera antes da requisição (padrão: 20)
     * @return string Conteúdo do PDF em binário
     */
    public function baixarPDF(
        string $chaveAcesso,
        ?string $caminhoArquivo = null,
        ?string $logoPath = null,
        int $sleepSeconds = 20
    ): string {
        $pdfContent = null;
        $usouFallback = false;

        // try {
        // Aguarda para evitar rate limiting (429)
        if ($sleepSeconds > 0) {
            sleep($sleepSeconds);
        }

        // Tenta baixar do endpoint oficial
        $pdfContent = $this->baixarPDFOficial($chaveAcesso);
        // } catch (Exception $e) {
        // // Se falhar (429, timeout, erro de rede, etc), usa o gerador local
        // $usouFallback = true;

        // // Baixa o XML da NFS-e
        // $xml = $this->baixarXML($chaveAcesso);

        // // Gera o PDF a partir do XML usando gerador local
        // $gerador = new \NFSe\Utils\DANFSeGenerator();
        // $pdfContent = $gerador->gerarPDF($xml, $logoPath);
        // }

        // Salva em arquivo se caminho foi fornecido
        if ($caminhoArquivo !== null && $pdfContent !== null) {
            $resultado = file_put_contents($caminhoArquivo, $pdfContent);
            if ($resultado === false) {
                throw new Exception("Erro ao salvar PDF no caminho: {$caminhoArquivo}");
            }
        }

        return $pdfContent;
    }

    /**
     * Baixa o PDF diretamente do endpoint oficial da NFS-e
     *
     * Endpoint: https://adn.nfse.gov.br/danfse/{chaveAcesso} (produção)
     *           https://adn.producaorestrita.nfse.gov.br/danfse/{chaveAcesso} (homologação)
     *
     * ATENÇÃO: Este endpoint:
     * - Requer certificado digital (mTLS)
     * - Tem rate limiting extremamente severo!
     * - Pode retornar 429 (Too Many Requests) facilmente
     * - Recomenda-se usar sleep de 20 segundos entre requisições
     *
     * @param string $chaveAcesso Chave de acesso da NFS-e (50 caracteres)
     * @return string Conteúdo do PDF em binário
     * @throws Exception Se houver erro no download (429, timeout, etc)
     */
    private function baixarPDFOficial(string $chaveAcesso): string
    {
        $url = $this->config->getUrlPDF() . '/' . $chaveAcesso;

        $ch = curl_init($url);

        // Configurações
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        // IMPORTANTE: Certificado digital (mTLS) - O endpoint requer autenticação
        curl_setopt($ch, CURLOPT_SSLCERT, $this->certPemFile);
        curl_setopt($ch, CURLOPT_SSLKEY, $this->keyPemFile);

        // Headers
        $headers = [
            'Accept: application/pdf',
            'User-Agent: Cloudger-NFSe-Client/1.0'
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Timeout generoso (o servidor pode demorar)
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($error) {
            throw new Exception("Erro na requisição ao endpoint de PDF: {$error}");
        }

        // 496 = SSL Certificate Required (certificado não aceito ou faltando)
        if ($httpCode === 496) {
            throw new Exception("Certificado SSL requerido ou inválido (HTTP 496). Verifique o certificado digital.");
        }

        // 429 = Too Many Requests (rate limit excedido)
        if ($httpCode === 429) {
            throw new Exception("Rate limit excedido (429 Too Many Requests). Aguarde antes de tentar novamente.");
        }

        // 404 = Not Found (chave de acesso não existe)
        if ($httpCode === 404) {
            throw new Exception("NFS-e não encontrada (HTTP 404). Verifique a chave de acesso e o ambiente.");
        }

        if ($httpCode !== 200) {
            throw new Exception("Erro HTTP {$httpCode} ao baixar PDF do endpoint oficial");
        }

        // Verifica se realmente é um PDF
        if (substr($response, 0, 4) !== '%PDF') {
            throw new Exception("Resposta do endpoint não é um PDF válido");
        }

        return $response;
    }

    /**
     * Consulta eventos de uma NFS-e
     *
     * @param string $chaveAcesso Chave de acesso da NFS-e
     * @param string|null $tipoEvento Tipo do evento (opcional)
     * @return array Lista de eventos
     */
    public function consultarEventos(string $chaveAcesso, ?string $tipoEvento = null): array
    {
        $endpoint = "/nfse/{$chaveAcesso}/eventos";
        if ($tipoEvento) {
            $endpoint .= "/{$tipoEvento}";
        }
        $response = $this->enviarRequisicao('GET', $endpoint);

        return $this->processarResposta($response);
    }

    /**
     * Gera o ID DPS a partir dos parâmetros
     * Formato: "DPS" + Cód.Mun (7) + Tipo Inscrição (1) + CNPJ/CPF (14) + Série (5) + Número (15)
     *
     * @param string $codigoMunicipio Código do município IBGE (7 dígitos)
     * @param string $cnpjCpf CNPJ ou CPF do prestador
     * @param string $serie Série do DPS (5 dígitos)
     * @param string $numero Número do DPS (15 dígitos)
     * @return string ID DPS completo (45 caracteres)
     */
    public function gerarIdDPS(string $codigoMunicipio, string $cnpjCpf, string $serie, string $numero): string
    {
        // Remove caracteres não numéricos
        $documento = preg_replace('/\D/', '', $cnpjCpf);

        // Determina tipo de inscrição: 1=CPF, 2=CNPJ
        $tpInscricao = strlen($documento) === 11 ? '1' : '2';

        // Formata cada parte
        $codMun = str_pad($codigoMunicipio, 7, '0', STR_PAD_LEFT);
        $inscricaoFederal = str_pad($documento, 14, '0', STR_PAD_LEFT);
        $seriePad = str_pad($serie, 5, '0', STR_PAD_LEFT);
        $numeroPad = str_pad($numero, 15, '0', STR_PAD_LEFT);

        return 'DPS' . $codMun . $tpInscricao . $inscricaoFederal . $seriePad . $numeroPad;
    }

    /**
     * Lista NFS-e por faixa de números
     *
     * @param string $codigoMunicipio Código do município IBGE
     * @param string $cnpjCpf CNPJ ou CPF do prestador
     * @param string $serie Série do DPS
     * @param int $numeroInicial Número inicial da faixa
     * @param int $numeroFinal Número final da faixa
     * @return array Lista com informações das NFS-e encontradas
     */
    public function listarNFSePorFaixa(
        string $codigoMunicipio,
        string $cnpjCpf,
        string $serie,
        int $numeroInicial,
        int $numeroFinal
    ): array {
        $resultado = [];

        for ($num = $numeroInicial; $num <= $numeroFinal; $num++) {
            try {
                // Gera o ID DPS
                $idDPS = $this->gerarIdDPS($codigoMunicipio, $cnpjCpf, $serie, (string)$num);

                // Consulta a DPS para obter a chave de acesso
                $dadosDPS = $this->consultarDPS($idDPS);

                if (isset($dadosDPS['chaveAcesso'])) {
                    $resultado[] = [
                        'numero' => $num,
                        'idDPS' => $idDPS,
                        'chaveAcesso' => $dadosDPS['chaveAcesso'],
                        'dados' => $dadosDPS
                    ];
                }
            } catch (Exception $e) {
                // Ignora erros (DPS não encontrada, etc) e continua
                continue;
            }
        }

        return $resultado;
    }

    /**
     * Lista NFS-e com detalhes completos (XML) por faixa de números
     *
     * @param string $codigoMunicipio Código do município IBGE
     * @param string $cnpjCpf CNPJ ou CPF do prestador
     * @param string $serie Série do DPS
     * @param int $numeroInicial Número inicial da faixa
     * @param int $numeroFinal Número final da faixa
     * @return array Lista com informações completas das NFS-e (incluindo XML)
     */
    public function listarNFSeCompletaPorFaixa(
        string $codigoMunicipio,
        string $cnpjCpf,
        string $serie,
        int $numeroInicial,
        int $numeroFinal
    ): array {
        $resultado = [];

        // Primeiro obtém a lista com chaves de acesso
        $lista = $this->listarNFSePorFaixa($codigoMunicipio, $cnpjCpf, $serie, $numeroInicial, $numeroFinal);

        // Para cada NFS-e encontrada, busca os dados completos
        foreach ($lista as $item) {
            try {
                $nfseCompleta = $this->consultarNFSe($item['chaveAcesso']);

                $resultado[] = [
                    'numero' => $item['numero'],
                    'idDPS' => $item['idDPS'],
                    'chaveAcesso' => $item['chaveAcesso'],
                    'nfse' => $nfseCompleta
                ];
            } catch (Exception $e) {
                // Em caso de erro, inclui o item básico
                $resultado[] = [
                    'numero' => $item['numero'],
                    'idDPS' => $item['idDPS'],
                    'chaveAcesso' => $item['chaveAcesso'],
                    'erro' => $e->getMessage()
                ];
            }
        }

        return $resultado;
    }

    /**
     * Envia requisição para a API
     */
    private function enviarRequisicao(string $metodo, string $endpoint, ?string $body = null, string $contentType = 'application/xml'): string
    {
        $url = $this->config->getUrlBase() . $endpoint;

        $ch = curl_init($url);

        // Configurações comuns
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        // Certificado digital (formato PEM)
        curl_setopt($ch, CURLOPT_SSLCERT, $this->certPemFile);
        curl_setopt($ch, CURLOPT_SSLKEY, $this->keyPemFile);

        // Headers
        $headers = [
            'Content-Type: ' . $contentType . '; charset=utf-8',
            'Accept: application/json',
            'User-Agent: Cloudger-NFSe-Client/1.0'
        ];

        // Método e body
        if ($metodo === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                $headers[] = 'Content-Length: ' . strlen($body);
            }
        } elseif ($metodo === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($body) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        } elseif ($metodo === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Timeout
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($error) {
            throw new Exception("Erro na requisição: {$error}");
        }

        if ($httpCode >= 400) {
            throw new Exception("Erro HTTP {$httpCode}: {$response}");
        }

        return $response;
    }

    /**
     * Processa a resposta XML da API
     */
    private function processarResposta(string $response): array
    {
        if (empty($response)) {
            return [];
        }

        // Tenta processar como XML
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response);

        if ($xml === false) {
            // Se não for XML, tenta JSON
            $json = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }

            throw new Exception("Resposta inválida da API: " . $response);
        }

        // Converte XML para array
        return $this->xmlParaArray($xml);
    }

    /**
     * Converte SimpleXMLElement para array
     */
    private function xmlParaArray($xml): array
    {
        $json = json_encode($xml);
        return json_decode($json, true);
    }

    /**
     * Cria o XML de evento de cancelamento
     */
    private function criarEventoCancelamento(string $chaveAcesso, string $motivo, int $codigoMotivo): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        // Elemento raiz
        $pedRegEvento = $dom->createElementNS('http://www.sped.fazenda.gov.br/nfse', 'pedRegEvento');
        $pedRegEvento->setAttribute('versao', '1.00');
        $dom->appendChild($pedRegEvento);

        // infPedReg
        $infPedReg = $dom->createElement('infPedReg');
        // Id deve ter formato PRE + 56 dígitos (PRE + chave de 50 dígitos + 6 dígitos do timestamp)
        $timestamp = str_pad(substr((string)time(), -6), 6, '0', STR_PAD_LEFT);
        $idEvento = 'PRE' . $chaveAcesso . $timestamp;
        $infPedReg->setAttribute('Id', $idEvento);

        $infPedReg->appendChild($dom->createElement('tpAmb', $this->config->getAmbiente()));
        $infPedReg->appendChild($dom->createElement('verAplic', $this->config->getVersaoAplicativo()));

        // Data/hora do evento com timezone de São Paulo
        $dataHora = new \DateTime('now', new \DateTimeZone('America/Sao_Paulo'));
        $infPedReg->appendChild($dom->createElement('dhEvento', $dataHora->format('Y-m-d\TH:i:sP')));

        // CNPJ ou CPF do autor do evento (prestador que está cancelando)
        // Extrai do certificado PEM que já foi gerado
        $certContent = file_get_contents($this->certPemFile);
        $certData = openssl_x509_parse($certContent);
        if ($certData && isset($certData['subject']['CN'])) {
            // Extrai CNPJ/CPF do CN (formato: "NOME:CNPJ")
            if (preg_match('/(\d{14}|\d{11})/', $certData['subject']['CN'], $matches)) {
                $documento = $matches[1];
                if (strlen($documento) == 14) {
                    $infPedReg->appendChild($dom->createElement('CNPJAutor', $documento));
                } elseif (strlen($documento) == 11) {
                    $infPedReg->appendChild($dom->createElement('CPFAutor', $documento));
                }
            }
        }

        $infPedReg->appendChild($dom->createElement('chNFSe', $chaveAcesso));

        // Evento de cancelamento (e101101)
        $e101101 = $dom->createElement('e101101');
        $e101101->appendChild($dom->createElement('xDesc', 'Cancelamento de NFS-e'));
        $e101101->appendChild($dom->createElement('cMotivo', $codigoMotivo)); // Código do motivo (1, 2 ou 9)
        $e101101->appendChild($dom->createElement('xMotivo', substr($motivo, 0, 255))); // Descrição do motivo

        $infPedReg->appendChild($e101101);

        $pedRegEvento->appendChild($infPedReg);

        return $dom->saveXML();
    }
}
