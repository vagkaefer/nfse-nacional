<?php

declare(strict_types=1);

namespace NFSe\Models;

use DateTime;
use DateTimeZone;
use DOMDocument;
use NFSe\Exception\NFSeException;
use NFSe\Utils\Ids;

/**
 * Construtor do XML de pedido de registro de evento (pedRegEvento),
 * conforme os schemas pedRegEvento_v1.00.xsd e pedRegEvento_v1.01.xsd.
 */
class PedidoRegistroEvento
{
    public const LEIAUTE_V1_00 = '1.00';
    public const LEIAUTE_V1_01 = '1.01';

    /**
     * Tamanho mínimo de xMotivo exigido pelo tipo TSMotivo do schema.
     */
    private const MOTIVO_TAMANHO_MINIMO = 15;

    /**
     * Tamanho máximo de xMotivo exigido pelo tipo TSMotivo do schema.
     */
    private const MOTIVO_TAMANHO_MAXIMO = 255;

    public function __construct(
        private readonly string $chaveAcesso,
        private readonly int $ambiente,
        private readonly string $verAplic,
        private readonly ?string $cnpjCpfAutor,
        private readonly string $versaoLeiaute = self::LEIAUTE_V1_00,
    ) {}

    /**
     * Gera o XML (não assinado) do evento de cancelamento (e101101).
     *
     * @param int $codigoMotivo 1 = Erro na emissão; 2 = Serviço não prestado; 9 = Outros
     * @param int $nPedRegEvento Número do pedido para o mesmo tipo de evento. O
     *                           cancelamento ocorre uma única vez, então é sempre 1.
     *                           Só é emitido no leiaute 1.01, que o introduziu.
     */
    public function cancelamento(string $motivo, int $codigoMotivo = 9, int $nPedRegEvento = 1): string
    {
        $motivo = $this->validarMotivo($motivo);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $pedRegEvento = $dom->createElementNS('http://www.sped.fazenda.gov.br/nfse', 'pedRegEvento');
        $pedRegEvento->setAttribute('versao', $this->versaoLeiaute);
        $dom->appendChild($pedRegEvento);

        $infPedReg = $dom->createElement('infPedReg');
        $infPedReg->setAttribute('Id', Ids::pedRegEvento(
            $this->chaveAcesso,
            Ids::EVENTO_CANCELAMENTO,
            $this->usaLeiaute101() ? $nPedRegEvento : null,
        ));

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

        // Introduzido pelo leiaute 1.01 (RTC), entre chNFSe e o grupo do evento.
        if ($this->usaLeiaute101()) {
            $infPedReg->appendChild($dom->createElement('nPedRegEvento', (string) $nPedRegEvento));
        }

        $e101101 = $dom->createElement('e101101');
        $e101101->appendChild($dom->createElement('xDesc', 'Cancelamento de NFS-e'));
        $e101101->appendChild($dom->createElement('cMotivo', (string) $codigoMotivo));
        $e101101->appendChild($dom->createElement('xMotivo', $motivo));

        $infPedReg->appendChild($e101101);
        $pedRegEvento->appendChild($infPedReg);

        return (string) $dom->saveXML();
    }

    private function usaLeiaute101(): bool
    {
        return $this->versaoLeiaute === self::LEIAUTE_V1_01;
    }

    /**
     * Valida xMotivo contra o tipo TSMotivo (minLength 15, maxLength 255).
     *
     * O limite mínimo é verificado com exceção — e não silenciosamente — porque
     * um motivo curto gera um XML que só seria recusado pela Sefin.
     */
    private function validarMotivo(string $motivo): string
    {
        $motivo = trim($motivo);

        if (mb_strlen($motivo) < self::MOTIVO_TAMANHO_MINIMO) {
            throw new NFSeException(sprintf(
                'O motivo do evento deve ter ao menos %d caracteres (recebido: %d).',
                self::MOTIVO_TAMANHO_MINIMO,
                mb_strlen($motivo),
            ));
        }

        return mb_substr($motivo, 0, self::MOTIVO_TAMANHO_MAXIMO);
    }
}
