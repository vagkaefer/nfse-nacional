<?php

declare(strict_types=1);

namespace NFSe\Services;

use NFSe\Certificate\Certificado;
use NFSe\Config\Config;
use NFSe\Exception\ApiException;
use NFSe\Exception\NFSeException;
use NFSe\Http\HttpClient;
use NFSe\Http\HttpResponse;
use NFSe\Http\ResponseParser;
use NFSe\Models\DPS;
use NFSe\Models\PedidoRegistroEvento;
use NFSe\Utils\AssinaturaDigital;
use NFSe\Utils\DANFSeGenerator;
use NFSe\Utils\Ids;

/**
 * Cliente da API do Sistema Nacional de NFS-e (Sefin Nacional / ADN).
 *
 * Os parâmetros opcionais do construtor existem como costura de teste:
 * em produção basta passar o Config.
 */
class NFSeClient
{
    private readonly Certificado $certificado;
    private readonly HttpClient $http;
    private readonly AssinaturaDigital $assinatura;

    public function __construct(
        private readonly Config $config,
        ?HttpClient $http = null,
        ?Certificado $certificado = null,
    ) {
        $this->certificado = $certificado ?? new Certificado(
            $config->getCertificadoPfx(),
            $config->getCertificadoSenha(),
        );
        $this->http = $http ?? new HttpClient($this->certificado, $config->getUserAgent());
        $this->assinatura = new AssinaturaDigital($this->certificado);
    }

    /**
     * Envia uma DPS para emissão de NFS-e.
     *
     * @return array<mixed> Resposta da API
     */
    public function emitirNFSe(DPS $dps): array
    {
        $xmlAssinado = $this->assinatura->assinarXML($dps->gerarXML(), 'infDPS', 'Id');

        $payload = (string) json_encode([
            'dpsXmlGZipB64' => base64_encode((string) gzencode($xmlAssinado)),
        ]);

        return ResponseParser::parse($this->requisitar('POST', '/nfse', $payload));
    }

    /**
     * Consulta uma NFS-e pela chave de acesso (50 dígitos).
     *
     * @return array<mixed>
     */
    public function consultarNFSe(string $chaveAcesso): array
    {
        return ResponseParser::parse($this->requisitar('GET', "/nfse/{$chaveAcesso}"));
    }

    /**
     * Cancela uma NFS-e (evento e101101).
     *
     * @param int $codigoMotivo 1 = Erro na emissão; 2 = Serviço não prestado; 9 = Outros
     * @return array<mixed>
     */
    public function cancelarNFSe(string $chaveAcesso, string $motivo, int $codigoMotivo = 9): array
    {
        $evento = new PedidoRegistroEvento(
            $chaveAcesso,
            $this->config->getAmbiente(),
            $this->config->getVersaoAplicativo(),
            $this->certificado->getCnpjCpf(),
        );

        $xmlAssinado = $this->assinatura->assinarXML(
            $evento->cancelamento($motivo, $codigoMotivo),
            'infPedReg',
            'Id',
        );

        $payload = (string) json_encode([
            'pedRegEventoXmlGZipB64' => base64_encode((string) gzencode($xmlAssinado)),
        ]);

        return ResponseParser::parse($this->requisitar('POST', "/nfse/{$chaveAcesso}/eventos", $payload));
    }

    /**
     * Consulta uma DPS pelo ID para obter a chave de acesso da NFS-e.
     *
     * @return array<mixed>
     */
    public function consultarDPS(string $idDPS): array
    {
        return ResponseParser::parse($this->requisitar('GET', "/dps/{$idDPS}"));
    }

    /**
     * Baixa o XML da NFS-e, opcionalmente salvando em arquivo.
     */
    public function baixarXML(string $chaveAcesso, ?string $caminhoArquivo = null): string
    {
        $nfse = $this->consultarNFSe($chaveAcesso);

        if (!isset($nfse['nfseXmlGZipB64'])) {
            throw new NFSeException('XML da NFS-e não encontrado na resposta');
        }

        $xml = gzdecode((string) base64_decode((string) $nfse['nfseXmlGZipB64'], true));
        if ($xml === false) {
            throw new NFSeException('Erro ao descompactar XML da NFS-e');
        }

        if ($caminhoArquivo !== null) {
            $this->salvarArquivo($caminhoArquivo, $xml);
        }

        return $xml;
    }

    /**
     * Baixa o PDF (DANFSe) da NFS-e.
     *
     * Tenta o endpoint oficial; em caso de falha (rate limit 429, timeout etc.)
     * gera o PDF localmente a partir do XML da NFS-e.
     *
     * ATENÇÃO: o endpoint oficial tem rate limiting severo. Se for baixar vários
     * PDFs em sequência, recomenda-se `$sleepSeconds = 20` entre requisições —
     * o padrão é 0 para não bloquear o chamador sem necessidade.
     *
     * @param string|null $logoPath Logo usado apenas na geração local (fallback)
     * @param int $sleepSeconds Espera antes da requisição ao endpoint oficial
     */
    public function baixarPDF(
        string $chaveAcesso,
        ?string $caminhoArquivo = null,
        ?string $logoPath = null,
        int $sleepSeconds = 0,
    ): string {
        if ($sleepSeconds > 0) {
            sleep($sleepSeconds);
        }

        try {
            $pdfContent = $this->baixarPDFOficial($chaveAcesso);
        } catch (NFSeException) {
            // Fallback: gera o DANFSe localmente a partir do XML
            $xml = $this->baixarXML($chaveAcesso);
            $gerador = new DANFSeGenerator($this->config->getDanfseOptions());
            $pdfContent = $gerador->gerarPDF($xml, $logoPath);
        }

        if ($caminhoArquivo !== null) {
            $this->salvarArquivo($caminhoArquivo, $pdfContent);
        }

        return $pdfContent;
    }

    /**
     * Consulta os eventos de uma NFS-e.
     *
     * @return array<mixed>
     */
    public function consultarEventos(string $chaveAcesso, ?string $tipoEvento = null): array
    {
        $endpoint = "/nfse/{$chaveAcesso}/eventos";
        if ($tipoEvento !== null && $tipoEvento !== '') {
            $endpoint .= "/{$tipoEvento}";
        }

        return ResponseParser::parse($this->requisitar('GET', $endpoint));
    }

    /**
     * Lista NFS-e por faixa de números de DPS.
     *
     * ATENÇÃO: executa uma requisição HTTP sequencial por número da faixa.
     * DPS inexistentes (404) são ignoradas; qualquer outro erro (rede, 429,
     * 5xx) é propagado para não mascarar falhas como "não encontrada".
     *
     * @return array<int, array<string, mixed>>
     */
    public function listarNFSePorFaixa(
        string $codigoMunicipio,
        string $cnpjCpf,
        string $serie,
        int $numeroInicial,
        int $numeroFinal,
    ): array {
        $resultado = [];

        for ($num = $numeroInicial; $num <= $numeroFinal; $num++) {
            $idDPS = Ids::dps($codigoMunicipio, $cnpjCpf, $serie, (string) $num);

            try {
                $dadosDPS = $this->consultarDPS($idDPS);
            } catch (ApiException $e) {
                if ($e->getStatusCode() === 404) {
                    continue;
                }

                throw $e;
            }

            if (isset($dadosDPS['chaveAcesso'])) {
                $resultado[] = [
                    'numero' => $num,
                    'idDPS' => $idDPS,
                    'chaveAcesso' => $dadosDPS['chaveAcesso'],
                    'dados' => $dadosDPS,
                ];
            }
        }

        return $resultado;
    }

    /**
     * Lista NFS-e com dados completos por faixa de números de DPS.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listarNFSeCompletaPorFaixa(
        string $codigoMunicipio,
        string $cnpjCpf,
        string $serie,
        int $numeroInicial,
        int $numeroFinal,
    ): array {
        $resultado = [];

        $lista = $this->listarNFSePorFaixa($codigoMunicipio, $cnpjCpf, $serie, $numeroInicial, $numeroFinal);

        foreach ($lista as $item) {
            try {
                $item['nfse'] = $this->consultarNFSe((string) $item['chaveAcesso']);
            } catch (NFSeException $e) {
                $item['erro'] = $e->getMessage();
            }
            unset($item['dados']);
            $resultado[] = $item;
        }

        return $resultado;
    }

    /**
     * Baixa o PDF do endpoint oficial (requer mTLS; rate limiting severo).
     */
    private function baixarPDFOficial(string $chaveAcesso): string
    {
        $url = $this->config->getUrlPDF() . '/' . $chaveAcesso;

        $response = $this->http->request('GET', $url, null, ['Accept: application/pdf']);

        if (!$response->isSuccess()) {
            throw ResponseParser::toException($response);
        }

        if (!str_starts_with($response->body, '%PDF')) {
            throw new NFSeException('Resposta do endpoint de PDF não é um PDF válido');
        }

        return $response->body;
    }

    /**
     * Envia uma requisição à API Sefin e devolve a resposta bruta (já
     * convertendo status de erro em ApiException).
     */
    private function requisitar(string $metodo, string $endpoint, ?string $body = null): HttpResponse
    {
        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
        ];

        $response = $this->http->request($metodo, $this->config->getUrlBase() . $endpoint, $body, $headers);

        if ($response->statusCode >= 400) {
            throw ResponseParser::toException($response);
        }

        return $response;
    }

    private function salvarArquivo(string $caminho, string $conteudo): void
    {
        if (file_put_contents($caminho, $conteudo) === false) {
            throw new NFSeException("Erro ao salvar arquivo em: {$caminho}");
        }
    }
}
