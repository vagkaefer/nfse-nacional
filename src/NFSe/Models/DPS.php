<?php

namespace NFSe\Models;

use DateTime;
use DOMDocument;

/**
 * Classe para geração de DPS (Declaração de Prestação de Serviço)
 * Baseado no esquema DPS_v1.00.xsd do Sistema Nacional de NFS-e
 */
class DPS
{
    // Campos obrigatórios do DPS
    private $tpAmb;              // Tipo de ambiente (1=Produção, 2=Homologação)
    private $dhEmi;              // Data/hora de emissão
    private $verAplic;           // Versão da aplicação
    private $serie;              // Série do DPS
    private $nDPS;               // Número do DPS
    private $dCompet;            // Data de competência (início da prestação)
    private $tpEmit;             // Tipo de emitente (1=Prestador, 2=Tomador, 3=Intermediário)
    private $cLocEmi;            // Código município emissor (IBGE)

    // Dados do Prestador
    private $prestador;          // Array com dados do prestador

    // Dados do Tomador (opcional)
    private $tomador;            // Array com dados do tomador

    // Dados do Intermediário (opcional)
    private $intermediario;      // Array com dados do intermediário

    // Dados do Serviço
    private $servico;            // Array com dados do serviço

    // Valores
    private $valores;            // Array com valores do serviço

    // Informações complementares (opcional)
    private $xInfComp;           // Informações complementares

    public function __construct()
    {
        // Garante que usa o fuso horário de Brasília e subtrai 10 segundos para evitar problemas de sincronia
        $dataHora = new DateTime('now', new \DateTimeZone('America/Sao_Paulo'));
        $dataHora->modify('-10 seconds');
        $this->dhEmi = $dataHora->format('Y-m-d\TH:i:sP');
        $this->tpEmit = 1; // Padrão: Prestador
    }

    /**
     * Gera o XML da DPS conforme schema
     */
    public function gerarXML(): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;
        $dom->preserveWhiteSpace = false;

        // Elemento raiz DPS
        $dps = $dom->createElementNS('http://www.sped.fazenda.gov.br/nfse', 'DPS');
        $dps->setAttribute('versao', '1.00');
        $dom->appendChild($dps);

        // infDPS
        $infDPS = $dom->createElement('infDPS');
        $idDPS = $this->gerarIdDPS();
        $infDPS->setAttribute('Id', $idDPS);

        // Campos obrigatórios
        $infDPS->appendChild($dom->createElement('tpAmb', $this->tpAmb));
        $infDPS->appendChild($dom->createElement('dhEmi', $this->dhEmi));
        $infDPS->appendChild($dom->createElement('verAplic', $this->verAplic));
        $infDPS->appendChild($dom->createElement('serie', $this->serie));
        $infDPS->appendChild($dom->createElement('nDPS', $this->nDPS));
        $infDPS->appendChild($dom->createElement('dCompet', $this->dCompet));
        $infDPS->appendChild($dom->createElement('tpEmit', $this->tpEmit));
        $infDPS->appendChild($dom->createElement('cLocEmi', $this->cLocEmi));

        // Prestador
        if ($this->prestador) {
            $infDPS->appendChild($this->criarElementoPessoa($dom, 'prest', $this->prestador, true));
        }

        // Tomador
        if ($this->tomador) {
            $infDPS->appendChild($this->criarElementoPessoa($dom, 'toma', $this->tomador, false));
        }

        // Intermediário
        if ($this->intermediario) {
            $infDPS->appendChild($this->criarElementoPessoa($dom, 'interm', $this->intermediario, false));
        }

        // Serviço
        if ($this->servico) {
            $infDPS->appendChild($this->criarElementoServico($dom));
        }

        // Valores
        if ($this->valores) {
            $infDPS->appendChild($this->criarElementoValores($dom));
        }

        // Informações Complementares
        if ($this->xInfComp) {
            $infDPS->appendChild($dom->createElement('xInfComp', $this->xInfComp));
        }

        $dps->appendChild($infDPS);

        return $dom->saveXML();
    }

    /**
     * Gera o ID da DPS
     * Formato: "DPS" + Cód.Mun (7) + Tipo de Inscrição Federal (1) + Inscrição Federal (14) + Série DPS (5) + Núm. DPS (15)
     * Total: 3 + 7 + 1 + 14 + 5 + 15 = 45 caracteres
     */
    private function gerarIdDPS(): string
    {
        // Código do município (7 dígitos)
        $codMun = str_pad($this->cLocEmi, 7, '0', STR_PAD_LEFT);

        // Tipo de inscrição: 1=CPF, 2=CNPJ
        $documento = '';
        $tpInscricao = '2'; // Padrão CNPJ

        if (isset($this->prestador['cnpj'])) {
            $documento = preg_replace('/\D/', '', $this->prestador['cnpj']);
            $tpInscricao = '2';
        } elseif (isset($this->prestador['cpf'])) {
            $documento = preg_replace('/\D/', '', $this->prestador['cpf']);
            $tpInscricao = '1';
        }

        // Inscrição Federal (14 dígitos - CPF completar com 000 à esquerda)
        $inscricaoFederal = str_pad($documento, 14, '0', STR_PAD_LEFT);

        // Série (5 dígitos)
        $serie = str_pad($this->serie, 5, '0', STR_PAD_LEFT);

        // Número (15 dígitos)
        $numero = str_pad($this->nDPS, 15, '0', STR_PAD_LEFT);

        return 'DPS' . $codMun . $tpInscricao . $inscricaoFederal . $serie . $numero;
    }

    /**
     * Cria elemento de pessoa (prestador, tomador, intermediário)
     */
    private function criarElementoPessoa(DOMDocument $dom, string $tag, array $dados, bool $isPrestador): \DOMElement
    {
        $element = $dom->createElement($tag);

        // CNPJ ou CPF
        if (isset($dados['cnpj'])) {
            $element->appendChild($dom->createElement('CNPJ', preg_replace('/\D/', '', $dados['cnpj'])));
        } elseif (isset($dados['cpf'])) {
            $element->appendChild($dom->createElement('CPF', preg_replace('/\D/', '', $dados['cpf'])));
        }

        // Inscrição Municipal (opcional)
        if (isset($dados['im'])) {
            $element->appendChild($dom->createElement('IM', $dados['im']));
        }

        // Nome/Razão Social - NÃO deve ser informado quando o prestador é o emitente (tpEmit = 1)
        if (isset($dados['xNome']) && !($isPrestador && $this->tpEmit == 1)) {
            $element->appendChild($dom->createElement('xNome', $dados['xNome']));
        }

        // Nome Fantasia (opcional - APENAS para tomador/intermediário, NÃO para prestador)
        if (!$isPrestador && isset($dados['xFant'])) {
            $element->appendChild($dom->createElement('xFant', $dados['xFant']));
        }

        // Endereço - NÃO deve ser informado quando o prestador é o emitente da DPS (tpEmit = 1)
        if (isset($dados['endereco']) && !($isPrestador && $this->tpEmit == 1)) {
            $element->appendChild($this->criarElementoEndereco($dom, $dados['endereco'], $isPrestador));
        }

        // Telefone (opcional)
        if (isset($dados['fone'])) {
            $element->appendChild($dom->createElement('fone', preg_replace('/\D/', '', $dados['fone'])));
        }

        // E-mail (opcional)
        if (isset($dados['email'])) {
            $element->appendChild($dom->createElement('email', $dados['email']));
        }

        // regTrib (Regime de Tributação) - obrigatório para prestador
        if ($isPrestador && isset($dados['regTrib'])) {
            $regTrib = $dom->createElement('regTrib');

            // opSimpNac (obrigatório): 1=Não Optante, 2=MEI, 3=ME/EPP
            $opSimpNac = $dados['regTrib']['opSimpNac'] ?? 1;
            $regTrib->appendChild($dom->createElement('opSimpNac', $opSimpNac));

            // regApTribSN (opcional)
            if (isset($dados['regTrib']['regApTribSN'])) {
                $regTrib->appendChild($dom->createElement('regApTribSN', $dados['regTrib']['regApTribSN']));
            }

            // regEspTrib (obrigatório): 0=Nenhum, 1=Ato Cooperado, etc.
            $regEspTrib = $dados['regTrib']['regEspTrib'] ?? 0;
            $regTrib->appendChild($dom->createElement('regEspTrib', $regEspTrib));

            $element->appendChild($regTrib);
        }

        return $element;
    }

    /**
     * Cria elemento de endereço
     */
    private function criarElementoEndereco(DOMDocument $dom, array $endereco, bool $isPrestador): \DOMElement
    {
        // Todos usam <end><endNac>...</endNac>...</end>
        $element = $dom->createElement('end');

        // endNac interno
        $endNac = $dom->createElement('endNac');
        if (isset($endereco['cMun'])) {
            $endNac->appendChild($dom->createElement('cMun', $endereco['cMun']));
        }
        if (isset($endereco['CEP'])) {
            $endNac->appendChild($dom->createElement('CEP', preg_replace('/\D/', '', $endereco['CEP'])));
        }
        $element->appendChild($endNac);

        // Dados fora de endNac (ordem correta: xLgr, nro, xCpl, xBairro)
        if (isset($endereco['xLog'])) {
            $element->appendChild($dom->createElement('xLgr', $endereco['xLog']));
        }
        if (isset($endereco['nLog'])) {
            $element->appendChild($dom->createElement('nro', $endereco['nLog']));
        }
        if (isset($endereco['xCpl'])) {
            $element->appendChild($dom->createElement('xCpl', $endereco['xCpl']));
        }
        if (isset($endereco['xBairro'])) {
            $element->appendChild($dom->createElement('xBairro', $endereco['xBairro']));
        }

        return $element;
    }

    /**
     * Cria elemento de serviço
     */
    private function criarElementoServico(DOMDocument $dom): \DOMElement
    {
        $serv = $dom->createElement('serv');

        // locPrest
        if (isset($this->servico['cLocPrestacao'])) {
            $locPrest = $dom->createElement('locPrest');
            $locPrest->appendChild($dom->createElement('cLocPrestacao', $this->servico['cLocPrestacao']));
            $serv->appendChild($locPrest);
        }

        // cServ
        $cServ = $dom->createElement('cServ');

        if (isset($this->servico['cTribNac'])) {
            // Remove pontos do código de tributação
            $codTrib = str_replace(['.', '-', ' '], '', $this->servico['cTribNac']);
            $cServ->appendChild($dom->createElement('cTribNac', $codTrib));
        }

        if (isset($this->servico['xDescServ'])) {
            $cServ->appendChild($dom->createElement('xDescServ', $this->servico['xDescServ']));
        }

        $serv->appendChild($cServ);

        // infoCompl (Informações Complementares do Serviço)
        if (isset($this->servico['xInfComp']) && !empty($this->servico['xInfComp'])) {
            $infoCompl = $dom->createElement('infoCompl');
            $infoCompl->appendChild($dom->createElement('xInfComp', $this->servico['xInfComp']));
            $serv->appendChild($infoCompl);
        }

        return $serv;
    }

    /**
     * Cria elemento de valores
     */
    private function criarElementoValores(DOMDocument $dom): \DOMElement
    {
        $valores = $dom->createElement('valores');

        // vServPrest
        $vServPrest = $dom->createElement('vServPrest');
        if (isset($this->valores['vServ'])) {
            $vServPrest->appendChild($dom->createElement('vServ', number_format($this->valores['vServ'], 2, '.', '')));
        }

        // Descontos (opcionais)
        if (isset($this->valores['vDescIncond']) && $this->valores['vDescIncond'] > 0) {
            $vServPrest->appendChild($dom->createElement('vDescIncond', number_format($this->valores['vDescIncond'], 2, '.', '')));
        }
        if (isset($this->valores['vDescCond']) && $this->valores['vDescCond'] > 0) {
            $vServPrest->appendChild($dom->createElement('vDescCond', number_format($this->valores['vDescCond'], 2, '.', '')));
        }

        $valores->appendChild($vServPrest);

        // trib (Tributação)
        $trib = $dom->createElement('trib');

        // tribMun (Tributação Municipal)
        $tribMun = $dom->createElement('tribMun');

        // tribISSQN: 1=Tributável, 2=Isento, 3=Imune, 4=Exigibilidade Suspensa, 5=Não Tributável
        $tribISSQN = isset($this->valores['tribISSQN']) ? $this->valores['tribISSQN'] : 1;
        $tribMun->appendChild($dom->createElement('tribISSQN', $tribISSQN));

        // tpRetISSQN: 1=Não retido, 2=Retido pelo tomador, 3=Retido pelo intermediário
        $tpRetISSQN = isset($this->valores['tpRetISSQN']) ? $this->valores['tpRetISSQN'] : 1;
        $tribMun->appendChild($dom->createElement('tpRetISSQN', $tpRetISSQN));

        $trib->appendChild($tribMun);

        // tribFed (Tributação Federal)
        $tribFed = $dom->createElement('tribFed');
        $piscofins = $dom->createElement('piscofins');

        // CST PIS/COFINS: 00=Tributável, 01=Não tributável, etc
        $cst = isset($this->valores['CST']) ? $this->valores['CST'] : '00';
        $piscofins->appendChild($dom->createElement('CST', $cst));

        $tribFed->appendChild($piscofins);
        $trib->appendChild($tribFed);

        // totTrib (Total de Tributos)
        $totTrib = $dom->createElement('totTrib');

        // pTotTribSN (Percentual Total de Tributos - Simples Nacional)
        // Quando informado pTotTribSN, NÃO deve ter vTotTrib
        if (isset($this->valores['pTotTribSN']) && $this->valores['pTotTribSN'] > 0) {
            $totTrib->appendChild($dom->createElement('pTotTribSN', number_format($this->valores['pTotTribSN'], 2, '.', '')));
        } else {
            // vTotTrib apenas quando NÃO usar Simples Nacional
            $vTotTrib = $dom->createElement('vTotTrib');

            $vTotTribFed = isset($this->valores['vTotTribFed']) ? $this->valores['vTotTribFed'] : 0.00;
            $vTotTrib->appendChild($dom->createElement('vTotTribFed', number_format($vTotTribFed, 2, '.', '')));

            $vTotTribEst = isset($this->valores['vTotTribEst']) ? $this->valores['vTotTribEst'] : 0.00;
            $vTotTrib->appendChild($dom->createElement('vTotTribEst', number_format($vTotTribEst, 2, '.', '')));

            // Total de tributos municipais (ISSQN)
            $vTotTribMun = isset($this->valores['vISSQN']) ? $this->valores['vISSQN'] : 0.00;
            $vTotTrib->appendChild($dom->createElement('vTotTribMun', number_format($vTotTribMun, 2, '.', '')));

            $totTrib->appendChild($vTotTrib);
        }

        $trib->appendChild($totTrib);

        $valores->appendChild($trib);

        return $valores;
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

    public function setPrestador(array $prestador): self
    {
        $this->prestador = $prestador;
        return $this;
    }

    public function setTomador(array $tomador): self
    {
        $this->tomador = $tomador;
        return $this;
    }

    public function setIntermediario(array $intermediario): self
    {
        $this->intermediario = $intermediario;
        return $this;
    }

    public function setServico(array $servico): self
    {
        $this->servico = $servico;
        return $this;
    }

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
