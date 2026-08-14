<?php

declare(strict_types=1);

namespace NFSe\Models;

use DateTime;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use NFSe\Utils\Ids;

/**
 * DPS (Declaração de Prestação de Serviço), conforme o schema DPS_v1.00.xsd
 * do Sistema Nacional de NFS-e.
 */
class DPS
{
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

    private ?string $xInfComp = null;

    public function __construct()
    {
        // Fuso de Brasília, com 10 segundos de folga para evitar rejeição por
        // relógio adiantado em relação ao servidor da Sefin.
        $dataHora = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        $dataHora->modify('-10 seconds');
        $this->dhEmi = $dataHora->format('Y-m-d\TH:i:sP');
    }

    public function gerarXML(): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;
        $dom->preserveWhiteSpace = false;

        $dps = $dom->createElementNS('http://www.sped.fazenda.gov.br/nfse', 'DPS');
        $dps->setAttribute('versao', '1.00');
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
            $infDPS->appendChild($this->criarElementoPessoa($dom, 'prest', $this->prestador, true));
        }

        if ($this->tomador !== null) {
            $infDPS->appendChild($this->criarElementoPessoa($dom, 'toma', $this->tomador, false));
        }

        if ($this->intermediario !== null) {
            $infDPS->appendChild($this->criarElementoPessoa($dom, 'interm', $this->intermediario, false));
        }

        if ($this->servico !== null) {
            $infDPS->appendChild($this->criarElementoServico($dom));
        }

        if ($this->valores !== null) {
            $infDPS->appendChild($this->criarElementoValores($dom));
        }

        if ($this->xInfComp !== null && $this->xInfComp !== '') {
            $infDPS->appendChild($dom->createElement('xInfComp', $this->xInfComp));
        }

        $dps->appendChild($infDPS);

        return (string) $dom->saveXML();
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

    /**
     * @param array<string, mixed> $dados
     */
    private function criarElementoPessoa(DOMDocument $dom, string $tag, array $dados, bool $isPrestador): DOMElement
    {
        $element = $dom->createElement($tag);

        if (isset($dados['cnpj'])) {
            $element->appendChild($dom->createElement('CNPJ', (string) preg_replace('/\D/', '', (string) $dados['cnpj'])));
        } elseif (isset($dados['cpf'])) {
            $element->appendChild($dom->createElement('CPF', (string) preg_replace('/\D/', '', (string) $dados['cpf'])));
        }

        if (isset($dados['im'])) {
            $element->appendChild($dom->createElement('IM', (string) $dados['im']));
        }

        // Nome/razão social e endereço não devem ser informados quando o
        // prestador é o próprio emitente (tpEmit = 1)
        if (isset($dados['xNome']) && !($isPrestador && $this->tpEmit === 1)) {
            $element->appendChild($dom->createElement('xNome', (string) $dados['xNome']));
        }

        // Nome fantasia: apenas tomador/intermediário
        if (!$isPrestador && isset($dados['xFant'])) {
            $element->appendChild($dom->createElement('xFant', (string) $dados['xFant']));
        }

        if (isset($dados['endereco']) && !($isPrestador && $this->tpEmit === 1)) {
            $element->appendChild($this->criarElementoEndereco($dom, $dados['endereco']));
        }

        if (isset($dados['fone'])) {
            $element->appendChild($dom->createElement('fone', (string) preg_replace('/\D/', '', (string) $dados['fone'])));
        }

        if (isset($dados['email'])) {
            $element->appendChild($dom->createElement('email', (string) $dados['email']));
        }

        if ($isPrestador && isset($dados['regTrib'])) {
            $regTrib = $dom->createElement('regTrib');

            // opSimpNac: 1=Não Optante, 2=MEI, 3=ME/EPP
            $regTrib->appendChild($dom->createElement('opSimpNac', (string) ($dados['regTrib']['opSimpNac'] ?? 1)));

            if (isset($dados['regTrib']['regApTribSN'])) {
                $regTrib->appendChild($dom->createElement('regApTribSN', (string) $dados['regTrib']['regApTribSN']));
            }

            $regTrib->appendChild($dom->createElement('regEspTrib', (string) ($dados['regTrib']['regEspTrib'] ?? 0)));

            $element->appendChild($regTrib);
        }

        return $element;
    }

    /**
     * @param array<string, mixed> $endereco
     */
    private function criarElementoEndereco(DOMDocument $dom, array $endereco): DOMElement
    {
        $element = $dom->createElement('end');

        $endNac = $dom->createElement('endNac');
        if (isset($endereco['cMun'])) {
            $endNac->appendChild($dom->createElement('cMun', (string) $endereco['cMun']));
        }
        if (isset($endereco['CEP'])) {
            $endNac->appendChild($dom->createElement('CEP', (string) preg_replace('/\D/', '', (string) $endereco['CEP'])));
        }
        $element->appendChild($endNac);

        // Ordem exigida pelo schema: xLgr, nro, xCpl, xBairro
        if (isset($endereco['xLog'])) {
            $element->appendChild($dom->createElement('xLgr', (string) $endereco['xLog']));
        }
        if (isset($endereco['nLog'])) {
            $element->appendChild($dom->createElement('nro', (string) $endereco['nLog']));
        }
        if (isset($endereco['xCpl'])) {
            $element->appendChild($dom->createElement('xCpl', (string) $endereco['xCpl']));
        }
        if (isset($endereco['xBairro'])) {
            $element->appendChild($dom->createElement('xBairro', (string) $endereco['xBairro']));
        }

        return $element;
    }

    private function criarElementoServico(DOMDocument $dom): DOMElement
    {
        $serv = $dom->createElement('serv');

        if (isset($this->servico['cLocPrestacao'])) {
            $locPrest = $dom->createElement('locPrest');
            $locPrest->appendChild($dom->createElement('cLocPrestacao', (string) $this->servico['cLocPrestacao']));
            $serv->appendChild($locPrest);
        }

        $cServ = $dom->createElement('cServ');

        if (isset($this->servico['cTribNac'])) {
            $codTrib = str_replace(['.', '-', ' '], '', (string) $this->servico['cTribNac']);
            $cServ->appendChild($dom->createElement('cTribNac', $codTrib));
        }

        if (isset($this->servico['xDescServ'])) {
            $cServ->appendChild($dom->createElement('xDescServ', (string) $this->servico['xDescServ']));
        }

        $serv->appendChild($cServ);

        if (isset($this->servico['xInfComp']) && $this->servico['xInfComp'] !== '') {
            $infoCompl = $dom->createElement('infoCompl');
            $infoCompl->appendChild($dom->createElement('xInfComp', (string) $this->servico['xInfComp']));
            $serv->appendChild($infoCompl);
        }

        return $serv;
    }

    private function criarElementoValores(DOMDocument $dom): DOMElement
    {
        $valores = $dom->createElement('valores');

        $vServPrest = $dom->createElement('vServPrest');
        if (isset($this->valores['vServ'])) {
            $vServPrest->appendChild($dom->createElement('vServ', $this->formatarValor($this->valores['vServ'])));
        }

        if (isset($this->valores['vDescIncond']) && $this->valores['vDescIncond'] > 0) {
            $vServPrest->appendChild($dom->createElement('vDescIncond', $this->formatarValor($this->valores['vDescIncond'])));
        }
        if (isset($this->valores['vDescCond']) && $this->valores['vDescCond'] > 0) {
            $vServPrest->appendChild($dom->createElement('vDescCond', $this->formatarValor($this->valores['vDescCond'])));
        }

        $valores->appendChild($vServPrest);

        $trib = $dom->createElement('trib');

        $tribMun = $dom->createElement('tribMun');
        // tribISSQN: 1=Tributável, 2=Isento, 3=Imune, 4=Exig. suspensa, 5=Não tributável
        $tribMun->appendChild($dom->createElement('tribISSQN', (string) ($this->valores['tribISSQN'] ?? 1)));
        // tpRetISSQN: 1=Não retido, 2=Retido pelo tomador, 3=Retido pelo intermediário
        $tribMun->appendChild($dom->createElement('tpRetISSQN', (string) ($this->valores['tpRetISSQN'] ?? 1)));
        $trib->appendChild($tribMun);

        $tribFed = $dom->createElement('tribFed');
        $piscofins = $dom->createElement('piscofins');
        $piscofins->appendChild($dom->createElement('CST', (string) ($this->valores['CST'] ?? '00')));
        $tribFed->appendChild($piscofins);
        $trib->appendChild($tribFed);

        $totTrib = $dom->createElement('totTrib');

        // Com pTotTribSN (Simples Nacional) não deve haver vTotTrib, e vice-versa
        if (isset($this->valores['pTotTribSN']) && $this->valores['pTotTribSN'] > 0) {
            $totTrib->appendChild($dom->createElement('pTotTribSN', $this->formatarValor($this->valores['pTotTribSN'])));
        } else {
            $vTotTrib = $dom->createElement('vTotTrib');
            $vTotTrib->appendChild($dom->createElement('vTotTribFed', $this->formatarValor($this->valores['vTotTribFed'] ?? 0.0)));
            $vTotTrib->appendChild($dom->createElement('vTotTribEst', $this->formatarValor($this->valores['vTotTribEst'] ?? 0.0)));
            $vTotTrib->appendChild($dom->createElement('vTotTribMun', $this->formatarValor($this->valores['vISSQN'] ?? 0.0)));
            $totTrib->appendChild($vTotTrib);
        }

        $trib->appendChild($totTrib);
        $valores->appendChild($trib);

        return $valores;
    }

    private function formatarValor(mixed $valor): string
    {
        return number_format((float) $valor, 2, '.', '');
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

    public function setInformacoesComplementares(string $xInfComp): self
    {
        $this->xInfComp = $xInfComp;

        return $this;
    }
}
