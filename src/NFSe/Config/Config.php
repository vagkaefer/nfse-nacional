<?php

declare(strict_types=1);

namespace NFSe\Config;

use SensitiveParameter;

/**
 * Configuração da integração com o Sistema Nacional de NFS-e.
 */
class Config
{
    public const AMBIENTE_PRODUCAO = 1;
    public const AMBIENTE_HOMOLOGACAO = 2;

    public const URL_PRODUCAO = 'https://sefin.nfse.gov.br/SefinNacional';
    public const URL_PRODUCAO_CNC = 'https://adn.nfse.gov.br/cnc';
    public const URL_PRODUCAO_PDF = 'https://adn.nfse.gov.br/danfse';

    public const URL_HOMOLOGACAO = 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional';
    public const URL_HOMOLOGACAO_CNC = 'https://adn.producaorestrita.nfse.gov.br/cnc';
    public const URL_HOMOLOGACAO_PDF = 'https://adn.producaorestrita.nfse.gov.br/danfse';

    public const USER_AGENT_PADRAO = 'nfse-nacional-php/2.0';

    /**
     * A API de geração do DANFSe foi sobrestada nesta data pela Nota Técnica
     * nº 008/2026: desde então o PDF é gerado localmente pelo emissor.
     */
    public const DANFSE_API_SOBRESTADA_EM = '2026-08-03';

    /**
     * @param int $ambiente Ambiente (1 = Produção, 2 = Homologação)
     * @param string $certificadoPfx Caminho do certificado PFX
     * @param string $certificadoSenha Senha do certificado
     * @param string $codigoMunicipioIBGE Código do município (7 dígitos)
     * @param string $versaoAplicativo Versão do aplicativo integrador
     * @param string $userAgent User-Agent enviado nas requisições HTTP
     * @param array<string, mixed> $danfseOptions Opções do gerador de DANFSe
     *        (chaves: creator, author, municipios [codIBGE => "Nome / UF"],
     *        logoPath, exibirCanhoto, fonteTitulos, fonteConteudo, marcaDagua)
     */
    public function __construct(
        private int $ambiente = self::AMBIENTE_HOMOLOGACAO,
        private string $certificadoPfx = '',
        #[SensitiveParameter]
        private string $certificadoSenha = '',
        private string $codigoMunicipioIBGE = '',
        private string $versaoAplicativo = '1.0.0',
        private string $userAgent = self::USER_AGENT_PADRAO,
        private array $danfseOptions = [],
    ) {}

    public function getUrlBase(): string
    {
        return $this->porAmbiente(self::URL_PRODUCAO, self::URL_HOMOLOGACAO);
    }

    public function getUrlCNC(): string
    {
        return $this->porAmbiente(self::URL_PRODUCAO_CNC, self::URL_HOMOLOGACAO_CNC);
    }

    public function getUrlPDF(): string
    {
        return $this->porAmbiente(self::URL_PRODUCAO_PDF, self::URL_HOMOLOGACAO_PDF);
    }

    private function porAmbiente(string $producao, string $homologacao): string
    {
        return $this->ambiente === self::AMBIENTE_PRODUCAO ? $producao : $homologacao;
    }

    public function getAmbiente(): int
    {
        return $this->ambiente;
    }

    public function getCertificadoPfx(): string
    {
        return $this->certificadoPfx;
    }

    public function getCertificadoSenha(): string
    {
        return $this->certificadoSenha;
    }

    public function getVersaoAplicativo(): string
    {
        return $this->versaoAplicativo;
    }

    public function getCodigoMunicipioIBGE(): string
    {
        return $this->codigoMunicipioIBGE;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDanfseOptions(): array
    {
        return $this->danfseOptions;
    }

    public function setAmbiente(int $ambiente): self
    {
        $this->ambiente = $ambiente;

        return $this;
    }

    public function setCertificadoPfx(string $certificadoPfx): self
    {
        $this->certificadoPfx = $certificadoPfx;

        return $this;
    }

    public function setCertificadoSenha(#[SensitiveParameter] string $certificadoSenha): self
    {
        $this->certificadoSenha = $certificadoSenha;

        return $this;
    }

    public function setVersaoAplicativo(string $versaoAplicativo): self
    {
        $this->versaoAplicativo = $versaoAplicativo;

        return $this;
    }

    public function setCodigoMunicipioIBGE(string $codigoMunicipioIBGE): self
    {
        $this->codigoMunicipioIBGE = $codigoMunicipioIBGE;

        return $this;
    }

    public function setUserAgent(string $userAgent): self
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    /**
     * @param array<string, mixed> $danfseOptions
     */
    public function setDanfseOptions(array $danfseOptions): self
    {
        $this->danfseOptions = $danfseOptions;

        return $this;
    }
}
