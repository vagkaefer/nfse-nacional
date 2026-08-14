<?php

declare(strict_types=1);

namespace NFSe\Models\Dps;

use DOMDocument;
use DOMElement;

/**
 * Montagem dos blocos de pessoa (prestador, tomador, intermediário,
 * destinatário e fornecedor) e de seus endereços.
 */
final class PessoaBuilder
{
    /**
     * @param array<string, mixed> $dados
     */
    public function pessoa(
        DOMDocument $dom,
        string $tag,
        array $dados,
        bool $isPrestador,
        int $tpEmit,
    ): DOMElement {
        $element = $dom->createElement($tag);

        Xml::identificacao($dom, $element, $dados);

        Xml::opcional($dom, $element, $dados, 'CAEPF');
        Xml::opcional($dom, $element, $dados, 'im', 'IM');

        // Nome/razão social e endereço não devem ser informados quando o
        // prestador é o próprio emitente (tpEmit = 1)
        $omitirIdentificacao = $isPrestador && $tpEmit === 1;

        if (isset($dados['xNome']) && !$omitirIdentificacao) {
            $element->appendChild($dom->createElement('xNome', (string) $dados['xNome']));
        }

        // Nome fantasia: apenas tomador/intermediário
        if (!$isPrestador && isset($dados['xFant'])) {
            $element->appendChild($dom->createElement('xFant', (string) $dados['xFant']));
        }

        if (isset($dados['endereco']) && !$omitirIdentificacao) {
            /** @var array<string, mixed> $endereco */
            $endereco = $dados['endereco'];
            $element->appendChild($this->endereco($dom, $endereco));
        }

        if (isset($dados['fone'])) {
            $element->appendChild($dom->createElement('fone', Xml::digitos((string) $dados['fone'])));
        }

        Xml::opcional($dom, $element, $dados, 'email');

        if ($isPrestador && isset($dados['regTrib'])) {
            /** @var array<string, mixed> $regTrib */
            $regTrib = $dados['regTrib'];
            $element->appendChild($this->regimeTributario($dom, $regTrib));
        }

        return $element;
    }

    /**
     * Endereço nacional (endNac) ou no exterior (endExt).
     *
     * Ordem exigida pelo schema: endNac|endExt, xLgr, nro, xCpl, xBairro.
     *
     * @param array<string, mixed> $endereco
     */
    public function endereco(DOMDocument $dom, array $endereco, string $tag = 'end'): DOMElement
    {
        $element = $dom->createElement($tag);

        if (isset($endereco['cPais'])) {
            $endExt = Xml::filho($dom, $element, 'endExt');
            Xml::opcional($dom, $endExt, $endereco, 'cPais');
            Xml::opcional($dom, $endExt, $endereco, 'cEndPost');
            Xml::opcional($dom, $endExt, $endereco, 'xCidade');
            Xml::opcional($dom, $endExt, $endereco, 'xEstProvReg');
        } else {
            $endNac = Xml::filho($dom, $element, 'endNac');
            Xml::opcional($dom, $endNac, $endereco, 'cMun');
            if (isset($endereco['CEP'])) {
                $endNac->appendChild($dom->createElement('CEP', Xml::digitos((string) $endereco['CEP'])));
            }
        }

        // Aceita tanto 'xLog'/'nLog' (nomes históricos da lib) quanto os do leiaute
        $logradouro = $endereco['xLgr'] ?? $endereco['xLog'] ?? null;
        if ($logradouro !== null) {
            $element->appendChild($dom->createElement('xLgr', (string) $logradouro));
        }

        $numero = $endereco['nro'] ?? $endereco['nLog'] ?? null;
        if ($numero !== null) {
            $element->appendChild($dom->createElement('nro', (string) $numero));
        }

        Xml::opcional($dom, $element, $endereco, 'xCpl');
        Xml::opcional($dom, $element, $endereco, 'xBairro');

        return $element;
    }

    /**
     * @param array<string, mixed> $regTrib
     */
    private function regimeTributario(DOMDocument $dom, array $regTrib): DOMElement
    {
        $element = $dom->createElement('regTrib');

        // opSimpNac: 1=Não Optante, 2=MEI, 3=ME/EPP, 4=Optante Pendente (NT 009)
        $element->appendChild($dom->createElement('opSimpNac', (string) ($regTrib['opSimpNac'] ?? 1)));

        Xml::opcional($dom, $element, $regTrib, 'regApTribSN');

        // regApIBSCBSSN (NT 009): 1=IBS e CBS pelo SN; 2=CBS pelo SN e IBS regular;
        // 3=IBS e CBS pelo regime regular
        Xml::opcional($dom, $element, $regTrib, 'regApIBSCBSSN');

        $element->appendChild($dom->createElement('regEspTrib', (string) ($regTrib['regEspTrib'] ?? 0)));

        return $element;
    }
}
