<?php

declare(strict_types=1);

namespace NFSe\Models;

use DateTime;
use DateTimeZone;
use DOMDocument;
use NFSe\Utils\Ids;

/**
 * Construtor do XML de pedido de registro de evento (pedRegEvento),
 * conforme o schema pedRegEvento_v1.00.xsd.
 */
class PedidoRegistroEvento
{
    public function __construct(
        private readonly string $chaveAcesso,
        private readonly int $ambiente,
        private readonly string $verAplic,
        private readonly ?string $cnpjCpfAutor,
    ) {}

    /**
     * Gera o XML (não assinado) do evento de cancelamento (e101101).
     *
     * @param int $codigoMotivo 1 = Erro na emissão; 2 = Serviço não prestado; 9 = Outros
     */
    public function cancelamento(string $motivo, int $codigoMotivo = 9): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $pedRegEvento = $dom->createElementNS('http://www.sped.fazenda.gov.br/nfse', 'pedRegEvento');
        $pedRegEvento->setAttribute('versao', '1.00');
        $dom->appendChild($pedRegEvento);

        $infPedReg = $dom->createElement('infPedReg');
        $infPedReg->setAttribute('Id', Ids::pedRegEvento($this->chaveAcesso, Ids::EVENTO_CANCELAMENTO));

        $infPedReg->appendChild($dom->createElement('tpAmb', (string) $this->ambiente));
        $infPedReg->appendChild($dom->createElement('verAplic', $this->verAplic));

        $dataHora = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        $infPedReg->appendChild($dom->createElement('dhEvento', $dataHora->format('Y-m-d\TH:i:sP')));

        if ($this->cnpjCpfAutor !== null) {
            $documento = (string) preg_replace('/\D/', '', $this->cnpjCpfAutor);
            if (strlen($documento) === 14) {
                $infPedReg->appendChild($dom->createElement('CNPJAutor', $documento));
            } elseif (strlen($documento) === 11) {
                $infPedReg->appendChild($dom->createElement('CPFAutor', $documento));
            }
        }

        $infPedReg->appendChild($dom->createElement('chNFSe', $this->chaveAcesso));

        $e101101 = $dom->createElement('e101101');
        $e101101->appendChild($dom->createElement('xDesc', 'Cancelamento de NFS-e'));
        $e101101->appendChild($dom->createElement('cMotivo', (string) $codigoMotivo));
        $e101101->appendChild($dom->createElement('xMotivo', substr($motivo, 0, 255)));

        $infPedReg->appendChild($e101101);
        $pedRegEvento->appendChild($infPedReg);

        return (string) $dom->saveXML();
    }
}
