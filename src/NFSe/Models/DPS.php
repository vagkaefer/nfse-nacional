<?php

declare(strict_types=1);

namespace NFSe\Models;

use DateTime;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use NFSe\Models\Dps\IbsCbsBuilder;
use NFSe\Models\Dps\PessoaBuilder;
use NFSe\Models\Dps\ValoresBuilder;
use NFSe\Models\Dps\Xml;
use NFSe\Utils\Ids;

/**
 * DPS (Declaração de Prestação de Serviço), conforme os schemas DPS_v1.00.xsd
 * (leiaute original) e DPS_v1.01.xsd (leiaute RTC, com o grupo IBSCBS) do
 * Sistema Nacional de NFS-e.
 *
 * A versão do leiaute é escolhida automaticamente: informar o grupo IBSCBS
 * (via setIbsCbs()) promove o documento para a versão 1.01.
 */
class DPS
{
    public const LEIAUTE_V1_00 = '1.00';
    public const LEIAUTE_V1_01 = '1.01';

    private ?int $tpAmb = null;              // 1=Produção, 2=Homologação
    private string $dhEmi;                   // Data/hora de emissão
    private ?string $verAplic = null;        // Versão da aplicação
    private ?string $serie = null;           // Série do DPS
    private ?string $nDPS = null;            // Número do DPS
    private ?string $dCompet = null;         // Data de competência
    private int $tpEmit = 1;                 // 1=Prestador, 2=Tomador, 3=Intermediário
    private ?string $cLocEmi = null;         // Código município emissor (IBGE)

    /** @var array<string, mixed>|null */
    private ?array $prestador = null;

    /** @var array<string, mixed>|null */
    private ?array $tomador = null;

    /** @var array<string, mixed>|null */
    private ?array $intermediario = null;

    /** @var array<string, mixed>|null */
    private ?array $servico = null;

    /** @var array<string, mixed>|null */
    private ?array $valores = null;

    /** @var array<string, mixed>|null */
    private ?array $ibsCbs = null;

    private ?string $xInfComp = null;

    private ?string $versaoLeiaute = null;

    /**
     * Campos das notas técnicas ainda ausentes dos XSDs publicados (NT 009:
     * notas de ajuste, estorno de crédito, bens móveis, pagamentos vinculados).
     * Desligado por padrão: emiti-los hoje causa rejeição por schema.
     */
    private bool $leiauteEstendido = false;

    private readonly PessoaBuilder $pessoas;

    public function __construct()
    {
        // Fuso de Brasília, com 10 segundos de folga para evitar rejeição por
        // relógio adiantado em relação ao servidor da Sefin.
        $dataHora = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        $dataHora->modify('-10 seconds');
        $this->dhEmi = $dataHora->format('Y-m-d\TH:i:sP');

        $this->pessoas = new PessoaBuilder();
    }

    public function gerarXML(): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;
        $dom->preserveWhiteSpace = false;

        $dps = $dom->createElementNS('http://www.sped.fazenda.gov.br/nfse', 'DPS');
        $dps->setAttribute('versao', $this->getVersaoLeiaute());
        $dom->appendChild($dps);

        $infDPS = $dom->createElement('infDPS');
        $infDPS->setAttribute('Id', $this->gerarIdDPS());

        $infDPS->appendChild($dom->createElement('tpAmb', (string) $this->tpAmb));
        $infDPS->appendChild($dom->createElement('dhEmi', $this->dhEmi));
        $infDPS->appendChild($dom->createElement('verAplic', (string) $this->verAplic));
        $infDPS->appendChild($dom->createElement('serie', (string) $this->serie));
        $infDPS->appendChild($dom->createElement('nDPS', (string) $this->nDPS));
        $infDPS->appendChild($dom->createElement('dCompet', (string) $this->dCompet));
        $infDPS->appendChild($dom->createElement('tpEmit', (string) $this->tpEmit));
        $infDPS->appendChild($dom->createElement('cLocEmi', (string) $this->cLocEmi));

        if ($this->prestador !== null) {
            $infDPS->appendChild($this->pessoas->pessoa($dom, 'prest', $this->prestador, true, $this->tpEmit));
        }

        if ($this->tomador !== null) {
            $infDPS->appendChild($this->pessoas->pessoa($dom, 'toma', $this->tomador, false, $this->tpEmit));
        }

        if ($this->intermediario !== null) {
            $infDPS->appendChild($this->pessoas->pessoa($dom, 'interm', $this->intermediario, false, $this->tpEmit));
        }

        if ($this->servico !== null) {
            $infDPS->appendChild($this->criarElementoServico($dom));
        }

        if ($this->valores !== null) {
            $builder = new ValoresBuilder($this->leiauteEstendido);
            $infDPS->appendChild($builder->construir($dom, $this->valores));
        }

        if ($this->ibsCbs !== null) {
            $builder = new IbsCbsBuilder($this->pessoas, $this->leiauteEstendido);
            $infDPS->appendChild($builder->construir($dom, $this->ibsCbs));
        }

        if ($this->xInfComp !== null && $this->xInfComp !== '') {
            $infDPS->appendChild($dom->createElement('xInfComp', $this->xInfComp));
        }

        $dps->appendChild($infDPS);

        return (string) $dom->saveXML();
    }

    /**
     * Versão do leiaute efetivamente usada: 1.01 quando há grupo IBSCBS,
     * 1.00 caso contrário — salvo quando fixada por setVersaoLeiaute().
     */
    public function getVersaoLeiaute(): string
    {
        if ($this->versaoLeiaute !== null) {
            return $this->versaoLeiaute;
        }

        return $this->ibsCbs !== null ? self::LEIAUTE_V1_01 : self::LEIAUTE_V1_00;
    }

    private function gerarIdDPS(): string
    {
        $documento = $this->prestador['cnpj'] ?? $this->prestador['cpf'] ?? '';

        return Ids::dps(
            (string) $this->cLocEmi,
            (string) $documento,
            (string) $this->serie,
            (string) $this->nDPS,
        );
    }

    private function criarElementoServico(DOMDocument $dom): DOMElement
    {
        $serv = $dom->createElement('serv');

        if (isset($this->servico['cLocPrestacao'])) {
            $locPrest = $dom->createElement('locPrest');
            $locPrest->appendChild($dom->createElement('cLocPrestacao', (string) $this->servico['cLocPrestacao']));
            Xml::opcional($dom, $locPrest, $this->servico, 'cPaisPrestacao');
            $serv->appendChild($locPrest);
        }

        $cServ = $dom->createElement('cServ');

        if (isset($this->servico['cTribNac'])) {
            $codTrib = str_replace(['.', '-', ' '], '', (string) $this->servico['cTribNac']);
            $cServ->appendChild($dom->createElement('cTribNac', $codTrib));
        }

        Xml::opcional($dom, $cServ, $this->servico, 'cTribMun');

        if (isset($this->servico['xDescServ'])) {
            $cServ->appendChild($dom->createElement('xDescServ', (string) $this->servico['xDescServ']));
        }

        Xml::opcional($dom, $cServ, $this->servico, 'cNBS');

        // cAtvSN (NT 009): enquadramento da atividade no Simples Nacional
        if ($this->leiauteEstendido) {
            Xml::opcional($dom, $cServ, $this->servico, 'cAtvSN');
        }

        Xml::opcional($dom, $cServ, $this->servico, 'cIntContrib');

        $serv->appendChild($cServ);

        if (isset($this->servico['xInfComp']) && $this->servico['xInfComp'] !== '') {
            $infoCompl = $dom->createElement('infoCompl');
            $infoCompl->appendChild($dom->createElement('xInfComp', (string) $this->servico['xInfComp']));
            $serv->appendChild($infoCompl);
        }

        return $serv;
    }

    // Setters

    public function setTpAmb(int $tpAmb): self
    {
        $this->tpAmb = $tpAmb;

        return $this;
    }

    public function setVerAplic(string $verAplic): self
    {
        $this->verAplic = $verAplic;

        return $this;
    }

    public function setSerie(string $serie): self
    {
        $this->serie = $serie;

        return $this;
    }

    public function setNDPS(string $nDPS): self
    {
        $this->nDPS = $nDPS;

        return $this;
    }

    public function setDCompet(string $dCompet): self
    {
        $this->dCompet = $dCompet;

        return $this;
    }

    public function setTpEmit(int $tpEmit): self
    {
        $this->tpEmit = $tpEmit;

        return $this;
    }

    public function setCLocEmi(string $cLocEmi): self
    {
        $this->cLocEmi = $cLocEmi;

        return $this;
    }

    /**
     * @param array<string, mixed> $prestador
     */
    public function setPrestador(array $prestador): self
    {
        $this->prestador = $prestador;

        return $this;
    }

    /**
     * @param array<string, mixed> $tomador
     */
    public function setTomador(array $tomador): self
    {
        $this->tomador = $tomador;

        return $this;
    }

    /**
     * @param array<string, mixed> $intermediario
     */
    public function setIntermediario(array $intermediario): self
    {
        $this->intermediario = $intermediario;

        return $this;
    }

    /**
     * @param array<string, mixed> $servico
     */
    public function setServico(array $servico): self
    {
        $this->servico = $servico;

        return $this;
    }

    /**
     * @param array<string, mixed> $valores
     */
    public function setValores(array $valores): self
    {
        $this->valores = $valores;

        return $this;
    }

    /**
     * Grupo IBSCBS (Reforma Tributária do Consumo). Informá-lo promove o
     * documento para o leiaute 1.01.
     *
     * @param array<string, mixed> $ibsCbs
     */
    public function setIbsCbs(array $ibsCbs): self
    {
        $this->ibsCbs = $ibsCbs;

        return $this;
    }

    /**
     * Fixa a versão do leiaute, sobrepondo a escolha automática.
     */
    public function setVersaoLeiaute(string $versao): self
    {
        $this->versaoLeiaute = $versao;

        return $this;
    }

    /**
     * Habilita os campos da NT 009 que ainda não constam dos XSDs publicados.
     * Enquanto a Sefin não atualizar os schemas, ativá-lo causa rejeição.
     */
    public function setLeiauteEstendido(bool $habilitado): self
    {
        $this->leiauteEstendido = $habilitado;

        return $this;
    }

    public function setInformacoesComplementares(string $xInfComp): self
    {
        $this->xInfComp = $xInfComp;

        return $this;
    }
}
