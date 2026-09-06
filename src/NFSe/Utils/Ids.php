<?php

declare(strict_types=1);

namespace NFSe\Utils;

/**
 * Geração dos identificadores padronizados do Sistema Nacional de NFS-e.
 */
final class Ids
{
    /**
     * Código do evento de cancelamento (elemento e101101 do pedRegEvento).
     */
    public const EVENTO_CANCELAMENTO = '101101';

    /**
     * ID da DPS (45 caracteres):
     * "DPS" + cód. município (7) + tipo de inscrição (1) + CNPJ/CPF (14) + série (5) + número (15).
     */
    public static function dps(string $codigoMunicipio, string $cnpjCpf, string $serie, string $numero): string
    {
        $documento = (string) preg_replace('/\D/', '', $cnpjCpf);

        // 1 = CPF, 2 = CNPJ
        $tpInscricao = strlen($documento) === 11 ? '1' : '2';

        return 'DPS'
            . str_pad($codigoMunicipio, 7, '0', STR_PAD_LEFT)
            . $tpInscricao
            . str_pad($documento, 14, '0', STR_PAD_LEFT)
            . str_pad($serie, 5, '0', STR_PAD_LEFT)
            . str_pad($numero, 15, '0', STR_PAD_LEFT);
    }

    /**
     * ID do pedido de registro de evento:
     * "PRE" + chave de acesso (50) + código do tipo de evento (6)
     * [+ número do pedido de registro do evento (3), a partir do leiaute 1.01].
     *
     * Sem $nPedRegEvento gera o formato do leiaute 1.00 (TSIdPedRegEvt,
     * 59 caracteres, PRE[0-9]{56}); com ele, o do 1.01 (TSIdPedRefEvt,
     * 62 caracteres, PRE[0-9]{59}).
     */
    public static function pedRegEvento(
        string $chaveAcesso,
        string $tipoEvento = self::EVENTO_CANCELAMENTO,
        ?int $nPedRegEvento = null,
    ): string {
        $id = 'PRE' . $chaveAcesso . $tipoEvento;

        if ($nPedRegEvento === null) {
            return $id;
        }

        return $id . str_pad((string) $nPedRegEvento, 3, '0', STR_PAD_LEFT);
    }
}
