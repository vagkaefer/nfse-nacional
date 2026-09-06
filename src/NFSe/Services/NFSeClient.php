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

    private ?string $motivoUltimoFallback = null;

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
     * @param string $versaoLeiaute Leiaute do pedido de evento. O padrão 1.00 é o
     *                              aceito hoje pela Sefin; 1.01 (RTC) acrescenta
     *                              nPedRegEvento e amplia o Id.
     * @return array<mixed>
     */
    public function cancelarNFSe(
        string $chaveAcesso,
        string $motivo,
        int $codigoMotivo = 9,
        string $versaoLeiaute = PedidoRegistroEvento::LEIAUTE_V1_00,
    ): array {
        $evento = new PedidoRegistroEvento(
            $chaveAcesso,
            $this->config->getAmbiente(),
            $this->config->getVersaoAplicativo(),
            $this->certificado->getCnpjCpf(),
            $versaoLeiaute,
        );

        $xmlAssinado = $this->assinatura->assinarXML(
            $evento->cancelamento($motivo, $codigoMotivo),
            'infPedReg',
            'Id',
        );

        $payload = (string) json_encode([
            'pedidoRegistroEventoXmlGZipB64' => base64_encode((string) gzencode($xmlAssinado)),
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
     * Gera o PDF (DANFSe v2.0) da NFS-e a partir do seu XML.
     *
     * A API oficial de geração do DANFSe foi sobrestada em 03/08/2026 pela Nota
     * Técnica nº 008/2026 — desde então cabe ao emissor produzir o documento.
     * Por isso o padrão é gerar localmente; `$tentarOficial` existe apenas para
     * quem ainda queira consultar o endpoint antes (ele responde 5xx).
     *
     * @param string|null $logoPath Logomarca da NFS-e; nulo usa a embarcada
     * @param bool $tentarOficial Consulta o endpoint sobrestado antes de gerar
     */
    public function baixarPDF(
        string $chaveAcesso,
        ?string $caminhoArquivo = null,
        ?string $logoPath = null,
        bool $tentarOficial = false,
    ): string {
        $pdfContent = null;

        if ($tentarOficial) {
            try {
                $pdfContent = $this->baixarPDFOficial($chaveAcesso);
            } catch (NFSeException $e) {
                $this->motivoUltimoFallback = $e->getMessage();
            }
        }

        if ($pdfContent === null) {
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
     * Motivo pelo qual o endpoint oficial não foi usado na última chamada a
     * baixarPDF() com $tentarOficial — útil para diagnosticar por que o PDF veio
     * do gerador local. Nulo quando não houve tentativa ou ela teve sucesso.
     */
    public function getMotivoUltimoFallback(): ?string
    {
        return $this->motivoUltimoFallback;
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
     * Baixa o PDF do endpoint oficial (requer mTLS).
     *
     * Mantido por compatibilidade: a API está sobrestada desde 03/08/2026
     * (Config::DANFSE_API_SOBRESTADA_EM) e responde erro.
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
