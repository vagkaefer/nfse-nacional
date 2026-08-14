<?php

declare(strict_types=1);

namespace NFSe\Models\Dps;

use DOMDocument;
use DOMElement;

/**
 * Montagem do grupo IBSCBS da DPS (Reforma Tributária do Consumo).
 *
 * Segue a ordem do leiaute RTC — o schema usa <xs:sequence>, então a ordem dos
 * elementos é normativa: fora de ordem, a Sefin rejeita o documento.
 *
 * Os campos introduzidos pela NT 009 (notas de ajuste, estorno de crédito,
 * pagamento antecipado, bens móveis, pagamentos vinculados) ainda não constam
 * dos XSDs publicados e só são emitidos quando o leiaute estendido está ativo.
 */
final class IbsCbsBuilder
{
    public function __construct(
        private readonly PessoaBuilder $pessoas = new PessoaBuilder(),
        private readonly bool $leiauteEstendido = false,
    ) {}

    /**
     * @param array<string, mixed> $ibsCbs
     */
    public function construir(DOMDocument $dom, array $ibsCbs): DOMElement
    {
        $element = $dom->createElement('IBSCBS');

        // finNFSe: 0=NFS-e regular; 1=NFS-e de crédito; 2=NFS-e de débito (NT 009)
        $element->appendChild($dom->createElement('finNFSe', (string) ($ibsCbs['finNFSe'] ?? '0')));

        if ($this->leiauteEstendido) {
            Xml::opcional($dom, $element, $ibsCbs, 'tpNFSeDebito');
            Xml::opcional($dom, $element, $ibsCbs, 'tpNFSeCredito');
        }

        Xml::opcional($dom, $element, $ibsCbs, 'indFinal');
        Xml::opcional($dom, $element, $ibsCbs, 'cIndOp');

        if ($this->leiauteEstendido) {
            Xml::opcional($dom, $element, $ibsCbs, 'indZFMALC');
        }

        Xml::opcional($dom, $element, $ibsCbs, 'tpOper');

        $this->referenciasNFSe($dom, $element, $ibsCbs);

        Xml::opcional($dom, $element, $ibsCbs, 'tpEnteGov');

        if ($this->leiauteEstendido) {
            Xml::opcional($dom, $element, $ibsCbs, 'indDoacao');
        }

        // indDest: 0=destinatário é o próprio tomador; 1=destinatário distinto
        $element->appendChild($dom->createElement('indDest', (string) ($ibsCbs['indDest'] ?? '0')));

        $this->destinatario($dom, $element, $ibsCbs);
        $this->imovel($dom, $element, $ibsCbs);

        if ($this->leiauteEstendido) {
            $this->bensMoveis($dom, $element, $ibsCbs);
        }

        $element->appendChild($this->valores($dom, (array) ($ibsCbs['valores'] ?? [])));

        if ($this->leiauteEstendido) {
            $this->pagamentosVinculados($dom, $element, $ibsCbs);
        }

        return $element;
    }

    /**
     * @param array<string, mixed> $ibsCbs
     */
    private function referenciasNFSe(DOMDocument $dom, DOMElement $pai, array $ibsCbs): void
    {
        $chaves = (array) ($ibsCbs['gRefNFSe'] ?? []);
        if ($chaves === []) {
            return;
        }

        $grupo = Xml::filho($dom, $pai, 'gRefNFSe');
        foreach ($chaves as $chave) {
            $grupo->appendChild($dom->createElement('refNFSe', (string) $chave));
        }
    }

    /**
     * @param array<string, mixed> $ibsCbs
     */
    private function destinatario(DOMDocument $dom, DOMElement $pai, array $ibsCbs): void
    {
        if (!isset($ibsCbs['dest'])) {
            return;
        }

        /** @var array<string, mixed> $dest */
        $dest = $ibsCbs['dest'];
        $element = Xml::filho($dom, $pai, 'dest');

        Xml::identificacao($dom, $element, $dest);
        Xml::opcional($dom, $element, $dest, 'xNome');

        if (isset($dest['endereco'])) {
            /** @var array<string, mixed> $endereco */
            $endereco = $dest['endereco'];
            $element->appendChild($this->pessoas->endereco($dom, $endereco));
        }

        if (isset($dest['fone'])) {
            $element->appendChild($dom->createElement('fone', Xml::digitos((string) $dest['fone'])));
        }

        Xml::opcional($dom, $element, $dest, 'email');
    }

    /**
     * Operações relacionadas a bens imóveis, exceto obras.
     *
     * @param array<string, mixed> $ibsCbs
     */
    private function imovel(DOMDocument $dom, DOMElement $pai, array $ibsCbs): void
    {
        if (!isset($ibsCbs['imovel'])) {
            return;
        }

        /** @var array<string, mixed> $imovel */
        $imovel = $ibsCbs['imovel'];
        $element = Xml::filho($dom, $pai, 'imovel');

        if ($this->leiauteEstendido) {
            // A NT 009 reestruturou o bloco: cMun no topo, unidades imobiliárias
            // em gUnidImob e o grupo de locação em gLocacao.
            Xml::opcional($dom, $element, $imovel, 'cMun');
            $this->locacaoImovel($dom, $element, $imovel);
            $this->unidadesImobiliarias($dom, $element, $imovel);

            return;
        }

        Xml::opcional($dom, $element, $imovel, 'inscImobFisc');
        Xml::opcional($dom, $element, $imovel, 'cCIB');

        if (isset($imovel['endereco'])) {
            /** @var array<string, mixed> $endereco */
            $endereco = $imovel['endereco'];
            $element->appendChild($this->pessoas->endereco($dom, $endereco));
        }
    }

    /**
     * @param array<string, mixed> $imovel
     */
    private function locacaoImovel(DOMDocument $dom, DOMElement $pai, array $imovel): void
    {
        if (!isset($imovel['gLocacao'])) {
            return;
        }

        /** @var array<string, mixed> $locacao */
        $locacao = $imovel['gLocacao'];
        $element = Xml::filho($dom, $pai, 'gLocacao');

        Xml::opcionalValor($dom, $element, $locacao, 'pCopropriedade');
        Xml::opcionalValor($dom, $element, $locacao, 'vTotOper');
        Xml::opcionalValor($dom, $element, $locacao, 'vDescIncondTot');
        Xml::opcionalValor($dom, $element, $locacao, 'vDescCondTot');
        Xml::opcional($dom, $element, $locacao, 'dVencOrig');
    }

    /**
     * @param array<string, mixed> $imovel
     */
    private function unidadesImobiliarias(DOMDocument $dom, DOMElement $pai, array $imovel): void
    {
        /** @var array<int, array<string, mixed>> $unidades */
        $unidades = (array) ($imovel['gUnidImob'] ?? []);

        foreach ($unidades as $unidade) {
            $element = Xml::filho($dom, $pai, 'gUnidImob');

            Xml::opcional($dom, $element, $unidade, 'inscImobFisc');
            Xml::opcional($dom, $element, $unidade, 'cCIB');

            if (isset($unidade['endereco'])) {
                /** @var array<string, mixed> $endereco */
                $endereco = $unidade['endereco'];
                $element->appendChild($this->pessoas->endereco($dom, $endereco));
            }

            /** @var array<int, array<string, mixed>> $ajustes */
            $ajustes = (array) ($unidade['gAjusteBCLocImoveis'] ?? []);
            foreach ($ajustes as $ajuste) {
                $grupo = Xml::filho($dom, $element, 'gAjusteBCLocImoveis');
                Xml::opcional($dom, $grupo, $ajuste, 'tpAjusteBCLocImoveis');
                Xml::opcional($dom, $grupo, $ajuste, 'xTpAjusteBCLocImoveis');
                Xml::opcionalValor($dom, $grupo, $ajuste, 'vAjusteBCLocImoveis');
            }
        }
    }

    /**
     * @param array<string, mixed> $ibsCbs
     */
    private function bensMoveis(DOMDocument $dom, DOMElement $pai, array $ibsCbs): void
    {
        /** @var array<int, array<string, mixed>> $bens */
        $bens = (array) ($ibsCbs['bensMoveis'] ?? []);

        foreach ($bens as $bem) {
            $element = Xml::filho($dom, $pai, 'bensMoveis');
            Xml::opcional($dom, $element, $bem, 'cNCMBemMovel');
            Xml::opcional($dom, $element, $bem, 'xNCMBemMovel');
            Xml::opcional($dom, $element, $bem, 'qtdNCMBemMovel');
        }
    }

    /**
     * Grupo IBSCBS/valores: reembolsos/repasses e a situação tributária.
     *
     * @param array<string, mixed> $valores
     */
    private function valores(DOMDocument $dom, array $valores): DOMElement
    {
        $element = $dom->createElement('valores');

        $this->reembolsosRepasses($dom, $element, $valores);

        $trib = Xml::filho($dom, $element, 'trib');
        $trib->appendChild($this->situacaoTributaria($dom, (array) ($valores['gIBSCBS'] ?? [])));

        if ($this->leiauteEstendido && isset($valores['gIBSCBSAjuste'])) {
            /** @var array<string, mixed> $ajuste */
            $ajuste = $valores['gIBSCBSAjuste'];
            $grupo = Xml::filho($dom, $trib, 'gIBSCBSAjuste');
            Xml::opcionalValor($dom, $grupo, $ajuste, 'vIBS');
            Xml::opcionalValor($dom, $grupo, $ajuste, 'vCBS');
        }

        return $element;
    }

    /**
     * Valores recebidos por conta e ordem de terceiros (reembolso, repasse,
     * ressarcimento) que não integram a base de cálculo.
     *
     * @param array<string, mixed> $valores
     */
    private function reembolsosRepasses(DOMDocument $dom, DOMElement $pai, array $valores): void
    {
        /** @var array<int, array<string, mixed>> $documentos */
        $documentos = (array) ($valores['gReeRepRes'] ?? []);
        if ($documentos === []) {
            return;
        }

        $grupo = Xml::filho($dom, $pai, 'gReeRepRes');

        foreach ($documentos as $documento) {
            $element = Xml::filho($dom, $grupo, 'documentos');

            $this->documentoReferenciado($dom, $element, $documento);
            $this->fornecedor($dom, $element, $documento);

            Xml::opcional($dom, $element, $documento, 'dtEmiDoc');
            Xml::opcional($dom, $element, $documento, 'dtCompDoc');
            Xml::opcional($dom, $element, $documento, 'tpReeRepRes');
            Xml::opcional($dom, $element, $documento, 'xTpReeRepRes');
            Xml::opcionalValor($dom, $element, $documento, 'vlrReeRepRes');
        }
    }

    /**
     * Escolha entre documento fiscal nacional, outro documento fiscal ou
     * documento não fiscal.
     *
     * @param array<string, mixed> $documento
     */
    private function documentoReferenciado(DOMDocument $dom, DOMElement $pai, array $documento): void
    {
        if (isset($documento['chaveDFe'])) {
            $grupo = Xml::filho($dom, $pai, 'dFeNacional');
            Xml::opcional($dom, $grupo, $documento, 'tipoChaveDFe');
            Xml::opcional($dom, $grupo, $documento, 'xTipoChaveDFe');
            Xml::opcional($dom, $grupo, $documento, 'chaveDFe');

            return;
        }

        if (isset($documento['nDocFiscal'])) {
            $grupo = Xml::filho($dom, $pai, 'docFiscalOutro');
            Xml::opcional($dom, $grupo, $documento, 'cMunDocFiscal');
            Xml::opcional($dom, $grupo, $documento, 'nDocFiscal');
            Xml::opcional($dom, $grupo, $documento, 'xDocFiscal');

            return;
        }

        if (isset($documento['nDoc'])) {
            $grupo = Xml::filho($dom, $pai, 'docOutro');
            Xml::opcional($dom, $grupo, $documento, 'nDoc');
            Xml::opcional($dom, $grupo, $documento, 'xDoc');
        }
    }

    /**
     * @param array<string, mixed> $documento
     */
    private function fornecedor(DOMDocument $dom, DOMElement $pai, array $documento): void
    {
        if (!isset($documento['fornec'])) {
            return;
        }

        /** @var array<string, mixed> $fornecedor */
        $fornecedor = $documento['fornec'];
        $element = Xml::filho($dom, $pai, 'fornec');

        Xml::identificacao($dom, $element, $fornecedor);
        Xml::opcional($dom, $element, $fornecedor, 'xNome');
    }

    /**
     * CST, classificação tributária e seus grupos derivados.
     *
     * @param array<string, mixed> $gIbsCbs
     */
    private function situacaoTributaria(DOMDocument $dom, array $gIbsCbs): DOMElement
    {
        $element = $dom->createElement('gIBSCBS');

        $element->appendChild($dom->createElement('CST', (string) ($gIbsCbs['CST'] ?? '000')));
        $element->appendChild($dom->createElement('cClassTrib', (string) ($gIbsCbs['cClassTrib'] ?? '000001')));

        Xml::opcional($dom, $element, $gIbsCbs, 'cCredPres');

        if (isset($gIbsCbs['gTribRegular'])) {
            /** @var array<string, mixed> $regular */
            $regular = $gIbsCbs['gTribRegular'];
            $grupo = Xml::filho($dom, $element, 'gTribRegular');
            Xml::opcional($dom, $grupo, $regular, 'CSTReg');
            Xml::opcional($dom, $grupo, $regular, 'cClassTribReg');
        }

        if (isset($gIbsCbs['gDif'])) {
            /** @var array<string, mixed> $diferimento */
            $diferimento = $gIbsCbs['gDif'];
            $grupo = Xml::filho($dom, $element, 'gDif');
            Xml::opcionalValor($dom, $grupo, $diferimento, 'pDifUF');
            Xml::opcionalValor($dom, $grupo, $diferimento, 'pDifMun');
            Xml::opcionalValor($dom, $grupo, $diferimento, 'pDifCBS');
        }

        if (!$this->leiauteEstendido) {
            return $element;
        }

        if (isset($gIbsCbs['gEstornoCred'])) {
            /** @var array<string, mixed> $estorno */
            $estorno = $gIbsCbs['gEstornoCred'];
            $grupo = Xml::filho($dom, $element, 'gEstornoCred');
            Xml::opcionalValor($dom, $grupo, $estorno, 'vIBSEstCred');
            Xml::opcionalValor($dom, $grupo, $estorno, 'vCBSEstCred');
        }

        /** @var array<int, string> $antecipados */
        $antecipados = (array) ($gIbsCbs['gPagAntecipado'] ?? []);
        if ($antecipados !== []) {
            $grupo = Xml::filho($dom, $element, 'gPagAntecipado');
            foreach ($antecipados as $chave) {
                $grupo->appendChild($dom->createElement('refNFSe', (string) $chave));
            }
        }

        return $element;
    }

    /**
     * Pagamentos vinculados (NT 009).
     *
     * @param array<string, mixed> $ibsCbs
     */
    private function pagamentosVinculados(DOMDocument $dom, DOMElement $pai, array $ibsCbs): void
    {
        /** @var array<int, array<string, mixed>> $pagamentos */
        $pagamentos = (array) ($ibsCbs['gPgtoVinc'] ?? []);
        if ($pagamentos === []) {
            return;
        }

        $grupo = Xml::filho($dom, $pai, 'gPgtoVinc');

        foreach ($pagamentos as $pagamento) {
            $element = Xml::filho($dom, $grupo, 'pgto');
            Xml::opcional($dom, $element, $pagamento, 'nPag');
            Xml::opcional($dom, $element, $pagamento, 'idTransacao');
            Xml::opcional($dom, $element, $pagamento, 'tpMeioPgto');

            if (isset($pagamento['CNPJReceb'])) {
                $element->appendChild($dom->createElement('CNPJReceb', Xml::documento((string) $pagamento['CNPJReceb'])));
            }
            if (isset($pagamento['CNPJBasePSP'])) {
                $element->appendChild($dom->createElement('CNPJBasePSP', Xml::documento((string) $pagamento['CNPJBasePSP'])));
            }
        }
    }
}
