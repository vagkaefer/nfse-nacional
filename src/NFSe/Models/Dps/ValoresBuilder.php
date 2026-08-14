<?php

declare(strict_types=1);

namespace NFSe\Models\Dps;

use DOMDocument;
use DOMElement;

/**
 * Montagem do grupo <valores> da DPS: serviço prestado, descontos, deduções e
 * tributos (municipais, federais e totais).
 */
final class ValoresBuilder
{
    public function __construct(
        private readonly bool $leiauteEstendido = false,
    ) {}

    /**
     * @param array<string, mixed> $valores
     */
    public function construir(DOMDocument $dom, array $valores): DOMElement
    {
        $element = $dom->createElement('valores');

        $vServPrest = Xml::filho($dom, $element, 'vServPrest');
        Xml::opcionalValor($dom, $vServPrest, $valores, 'vReceb');
        $vServPrest->appendChild($dom->createElement('vServ', Xml::valor($valores['vServ'] ?? 0)));

        $this->descontos($dom, $element, $valores);
        $this->deducoesReducoes($dom, $element, $valores);

        if ($this->leiauteEstendido) {
            $this->ajusteBaseCalculo($dom, $element, $valores);
        }

        $element->appendChild($this->tributos($dom, $valores));

        return $element;
    }

    /**
     * Descontos condicionados e incondicionados vivem em <vDescCondIncond>,
     * fora de <vServPrest>.
     *
     * @param array<string, mixed> $valores
     */
    private function descontos(DOMDocument $dom, DOMElement $pai, array $valores): void
    {
        $incondicionado = (float) ($valores['vDescIncond'] ?? 0);
        $condicionado = (float) ($valores['vDescCond'] ?? 0);

        if ($incondicionado <= 0 && $condicionado <= 0) {
            return;
        }

        $element = Xml::filho($dom, $pai, 'vDescCondIncond');

        if ($incondicionado > 0) {
            $element->appendChild($dom->createElement('vDescIncond', Xml::valor($incondicionado)));
        }
        if ($condicionado > 0) {
            $element->appendChild($dom->createElement('vDescCond', Xml::valor($condicionado)));
        }
    }

    /**
     * Deduções/reduções da base de cálculo: percentual, valor ou lista de
     * documentos (escolha mutuamente exclusiva no schema).
     *
     * @param array<string, mixed> $valores
     */
    private function deducoesReducoes(DOMDocument $dom, DOMElement $pai, array $valores): void
    {
        $temPercentual = isset($valores['pDR']);
        $temValor = isset($valores['vDR']);
        /** @var array<int, array<string, mixed>> $documentos */
        $documentos = (array) ($valores['documentosDedRed'] ?? []);

        if (!$temPercentual && !$temValor && $documentos === []) {
            return;
        }

        $element = Xml::filho($dom, $pai, 'vDedRed');

        if ($temPercentual) {
            $element->appendChild($dom->createElement('pDR', Xml::valor($valores['pDR'])));

            return;
        }

        if ($temValor) {
            $element->appendChild($dom->createElement('vDR', Xml::valor($valores['vDR'])));

            return;
        }

        $lista = Xml::filho($dom, $element, 'documentos');
        foreach ($documentos as $documento) {
            $doc = Xml::filho($dom, $lista, 'docDedRed');
            Xml::opcional($dom, $doc, $documento, 'chNFSe');
            Xml::opcional($dom, $doc, $documento, 'chNFe');
            Xml::opcional($dom, $doc, $documento, 'nDocFisc');
            Xml::opcional($dom, $doc, $documento, 'dtEmiDoc');
            Xml::opcionalValor($dom, $doc, $documento, 'vDedutivelRedutivel');
            Xml::opcionalValor($dom, $doc, $documento, 'vDeducaoReducao');
        }
    }

    /**
     * Ajuste da base de cálculo do ISSQN (NT 009).
     *
     * @param array<string, mixed> $valores
     */
    private function ajusteBaseCalculo(DOMDocument $dom, DOMElement $pai, array $valores): void
    {
        if (!isset($valores['vAjusteBC'])) {
            return;
        }

        /** @var array<string, mixed> $ajuste */
        $ajuste = $valores['vAjusteBC'];
        $element = Xml::filho($dom, $pai, 'vAjusteBC');

        Xml::opcionalValor($dom, $element, $ajuste, 'pAjusteBCISSQN');
        Xml::opcionalValor($dom, $element, $ajuste, 'vAjusteBCISSQN');

        /** @var array<int, array<string, mixed>> $documentos */
        $documentos = (array) ($ajuste['documentos'] ?? []);
        if ($documentos === []) {
            return;
        }

        $lista = Xml::filho($dom, $element, 'documentos');
        foreach ($documentos as $documento) {
            $doc = Xml::filho($dom, $lista, 'docAjusteBC');
            Xml::opcional($dom, $doc, $documento, 'tpAjusteBC');
            Xml::opcional($dom, $doc, $documento, 'xTpAjusteBC');
            Xml::opcionalValor($dom, $doc, $documento, 'vTotDoc');
            Xml::opcionalValor($dom, $doc, $documento, 'vAjuteAplic');
            Xml::opcional($dom, $doc, $documento, 'dtEmiDoc');
            Xml::opcional($dom, $doc, $documento, 'dtCompDoc');
        }
    }

    /**
     * @param array<string, mixed> $valores
     */
    private function tributos(DOMDocument $dom, array $valores): DOMElement
    {
        $trib = $dom->createElement('trib');

        $trib->appendChild($this->tributosMunicipais($dom, $valores));

        $tribFed = $this->tributosFederais($dom, $valores);
        if ($tribFed->hasChildNodes()) {
            $trib->appendChild($tribFed);
        }

        $trib->appendChild($this->tributosTotais($dom, $valores));

        return $trib;
    }

    /**
     * @param array<string, mixed> $valores
     */
    private function tributosMunicipais(DOMDocument $dom, array $valores): DOMElement
    {
        $element = $dom->createElement('tribMun');

        // tribISSQN: 1=Tributável, 2=Isento, 3=Imune, 4=Exig. suspensa, 5=Não tributável
        $element->appendChild($dom->createElement('tribISSQN', (string) ($valores['tribISSQN'] ?? 1)));

        Xml::opcional($dom, $element, $valores, 'cPaisResult');
        Xml::opcional($dom, $element, $valores, 'tpImunidade');

        if (isset($valores['tpSusp'])) {
            $exig = Xml::filho($dom, $element, 'exigSusp');
            Xml::opcional($dom, $exig, $valores, 'tpSusp');
            Xml::opcional($dom, $exig, $valores, 'nProcesso');
        }

        if (isset($valores['nBM'])) {
            $bm = Xml::filho($dom, $element, 'BM');
            Xml::opcional($dom, $bm, $valores, 'nBM');
            Xml::opcionalValor($dom, $bm, $valores, 'vRedBCBM');
            Xml::opcionalValor($dom, $bm, $valores, 'pRedBCBM');
        }

        // tpRetISSQN: 1=Não retido, 2=Retido pelo tomador, 3=Retido pelo intermediário
        $element->appendChild($dom->createElement('tpRetISSQN', (string) ($valores['tpRetISSQN'] ?? 1)));

        Xml::opcionalValor($dom, $element, $valores, 'pAliq');

        return $element;
    }

    /**
     * @param array<string, mixed> $valores
     */
    private function tributosFederais(DOMDocument $dom, array $valores): DOMElement
    {
        $element = $dom->createElement('tribFed');

        $piscofins = $dom->createElement('piscofins');
        $piscofins->appendChild($dom->createElement('CST', (string) ($valores['CST'] ?? '00')));
        Xml::opcionalValor($dom, $piscofins, $valores, 'vBCPisCofins');
        Xml::opcionalValor($dom, $piscofins, $valores, 'pAliqPis');
        Xml::opcionalValor($dom, $piscofins, $valores, 'pAliqCofins');
        Xml::opcionalValor($dom, $piscofins, $valores, 'vPis');
        Xml::opcionalValor($dom, $piscofins, $valores, 'vCofins');
        Xml::opcional($dom, $piscofins, $valores, 'tpRetPisCofins');
        $element->appendChild($piscofins);

        Xml::opcionalValor($dom, $element, $valores, 'vRetCP');
        Xml::opcionalValor($dom, $element, $valores, 'vRetIRRF');
        Xml::opcionalValor($dom, $element, $valores, 'vRetCSLL');

        return $element;
    }

    /**
     * Totais de tributos: percentual do Simples Nacional ou valores absolutos
     * (escolha mutuamente exclusiva no schema).
     *
     * @param array<string, mixed> $valores
     */
    private function tributosTotais(DOMDocument $dom, array $valores): DOMElement
    {
        $element = $dom->createElement('totTrib');

        if (isset($valores['pTotTribSN']) && (float) $valores['pTotTribSN'] > 0) {
            $element->appendChild($dom->createElement('pTotTribSN', Xml::valor($valores['pTotTribSN'])));

            return $element;
        }

        if (isset($valores['indTotTrib'])) {
            $element->appendChild($dom->createElement('indTotTrib', (string) $valores['indTotTrib']));

            return $element;
        }

        $vTotTrib = Xml::filho($dom, $element, 'vTotTrib');
        $vTotTrib->appendChild($dom->createElement('vTotTribFed', Xml::valor($valores['vTotTribFed'] ?? 0.0)));
        $vTotTrib->appendChild($dom->createElement('vTotTribEst', Xml::valor($valores['vTotTribEst'] ?? 0.0)));
        $vTotTrib->appendChild($dom->createElement('vTotTribMun', Xml::valor($valores['vISSQN'] ?? 0.0)));

        return $element;
    }
}
