<?php

namespace NFSe\Config;

/**
 * Classe de configuração para integração com Sistema Nacional de NFS-e
 */
class Config
{
    /**
     * Ambiente de produção
     */
    public const AMBIENTE_PRODUCAO = 1;

    /**
     * Ambiente de homologação
     */
    public const AMBIENTE_HOMOLOGACAO = 2;

    /**
     * URLs da API - Produção
     */
    public const URL_PRODUCAO = 'https://sefin.nfse.gov.br/SefinNacional';
    public const URL_PRODUCAO_CNC = 'https://adn.nfse.gov.br/cnc';
    public const URL_PRODUCAO_PDF = 'https://adn.nfse.gov.br/danfse';

    /**
     * URLs da API - Homologação/Produção Restrita
     */
    public const URL_HOMOLOGACAO = 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional';
    public const URL_HOMOLOGACAO_CNC = 'https://adn.producaorestrita.nfse.gov.br/cnc';
    public const URL_HOMOLOGACAO_PDF = 'https://adn.producaorestrita.nfse.gov.br/danfse';

    private $ambiente;
    private $certificadoPfx;
    private $certificadoSenha;
    private $versaoAplicativo;
    private $codigoMunicipioIBGE;

    /**
     * Configuração da integração
     *
     * @param int $ambiente Ambiente (1 = Produção, 2 = Homologação)
     * @param string $certificadoPfx Caminho do certificado PFX
     * @param string $certificadoSenha Senha do certificado
     * @param string $codigoMunicipioIBGE Código do município (7 dígitos)
     * @param string $versaoAplicativo Versão do aplicativo integrador
     */
    public function __construct(
        int $ambiente = self::AMBIENTE_HOMOLOGACAO,
        string $certificadoPfx = '',
        string $certificadoSenha = '',
        string $codigoMunicipioIBGE = '',
        string $versaoAplicativo = '1.0.0'
    ) {
        $this->ambiente = $ambiente;
        $this->certificadoPfx = $certificadoPfx;
        $this->certificadoSenha = $certificadoSenha;
        $this->versaoAplicativo = $versaoAplicativo;
        $this->codigoMunicipioIBGE = $codigoMunicipioIBGE;
    }

    /**
     * Retorna a URL base da API conforme ambiente
     */
    public function getUrlBase(): string
    {
        return $this->ambiente === self::AMBIENTE_PRODUCAO
            ? self::URL_PRODUCAO
            : self::URL_HOMOLOGACAO;
    }

    /**
     * Retorna a URL da API CNC conforme ambiente
     */
    public function getUrlCNC(): string
    {
        return $this->ambiente === self::AMBIENTE_PRODUCAO
            ? self::URL_PRODUCAO_CNC
            : self::URL_HOMOLOGACAO_CNC;
    }

    /**
     * Retorna a URL base para download de PDF (DANFSe) conforme ambiente
     */
    public function getUrlPDF(): string
    {
        return $this->ambiente === self::AMBIENTE_PRODUCAO
            ? self::URL_PRODUCAO_PDF
            : self::URL_HOMOLOGACAO_PDF;
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

    public function setCertificadoSenha(string $certificadoSenha): self
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
}
