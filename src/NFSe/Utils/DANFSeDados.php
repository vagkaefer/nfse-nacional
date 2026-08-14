<?php

declare(strict_types=1);

namespace NFSe\Utils;

use NFSe\Exception\NFSeException;
use SimpleXMLElement;

/**
 * Extração dos dados de um XML de NFS-e para o array usado na renderização
 * do DANFSe.
 */
final class DANFSeDados
{
    /**
     * @param array<string, string> $municipios Mapa opcional codIBGE => "Nome - UF"
     * @return array<string, mixed>
     */
    public static function extrair(string $xmlNFSe, array $municipios = []): array
    {
        $anterior = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlNFSe);
        libxml_use_internal_errors($anterior);

        if ($xml === false) {
            throw new NFSeException('XML da NFS-e inválido');
        }

        $infNFSe = $xml->infNFSe;
        $dps = $infNFSe->DPS->infDPS;

        $dados = [
            'chaveAcesso' => str_replace('NFS', '', (string) $infNFSe['Id']),
            'numero' => (string) $infNFSe->nNFSe,
            'serie' => (string) ($dps->serie ?? ''),
            'numeroDPS' => (string) ($dps->nDPS ?? ''),
            'competencia' => self::formatarData((string) ($dps->dCompet ?? '')),
            'dhEmissao' => self::formatarDataHora((string) ($infNFSe->dhProc ?? '')),
            'dhEmissaoDPS' => self::formatarDataHora((string) ($dps->dhEmi ?? '')),
            'ambiente' => (string) ($dps->tpAmb ?? '1'),
        ];

        $emit = $infNFSe->emit;
        $prest = $dps->prest ?? null;

        $dados['prestador'] = [
            'cnpj' => self::formatarCnpjCpf((string) ($emit->CNPJ ?? $emit->CPF ?? '')),
            'inscricaoMunicipal' => '-',
            'nome' => (string) $emit->xNome,
            'telefone' => self::formatarTelefone((string) ($emit->fone ?? '')),
            'email' => (string) ($emit->email ?? ''),
            'endereco' => self::montarEndereco($emit->enderNac ?? null, $municipios),
            'simplesNacional' => (string) ($prest->regTrib->opSimpNac ?? '2'),
            'regimeApuracao' => (string) ($prest->regTrib->regApTribSN ?? ''),
        ];

        $tom = $dps->toma ?? null;
        if ($tom !== null) {
            $dados['tomador'] = [
                'cnpj' => self::formatarCnpjCpf((string) ($tom->CNPJ ?? $tom->CPF ?? '')),
                'inscricaoMunicipal' => '-',
                'nome' => (string) $tom->xNome,
                'telefone' => self::formatarTelefone((string) ($tom->fone ?? '')),
                'email' => (string) ($tom->email ?? ''),
                'endereco' => self::montarEndereco($tom->end ?? null, $municipios),
            ];
        } else {
            $dados['tomador'] = [
                'cnpj' => '-',
                'inscricaoMunicipal' => '-',
                'nome' => 'Não informado',
                'telefone' => '-',
                'email' => '-',
                'endereco' => ['logradouro' => '-', 'municipio' => '-', 'cep' => '-'],
            ];
        }

        $serv = $dps->serv ?? null;
        $dados['servico'] = [
            'codigoTributacao' => self::formatarCodigoTrib((string) ($serv->cServ->cTribNac ?? '')),
            'localPrestacao' => self::nomeMunicipio(
                (string) ($serv->locPrest->cLocPrestacao ?? (string) $infNFSe->cLocIncid),
                $municipios,
            ),
            'descricao' => (string) ($serv->cServ->xDescServ ?? ''),
        ];

        $valoresDPS = $dps->valores ?? null;
        $dados['valores'] = [
            'valorServico' => (float) ($valoresDPS->vServPrest->vServ ?? 0),
            'valorLiquido' => (float) ($infNFSe->valores->vLiq ?? 0),
            'tribISSQN' => (string) ($valoresDPS->trib->tribMun->tribISSQN ?? '1'),
            'retencaoISSQN' => (string) ($valoresDPS->trib->tribMun->tpRetISSQN ?? '1'),
            'totTribSN' => (float) ($valoresDPS->trib->totTrib->pTotTribSN ?? 0),
        ];

        return $dados;
    }

    /**
     * Monta o endereço a partir de <enderNac> (emitente, campos planos) ou
     * <end> (tomador/intermediário, com <endNac> aninhado para cMun/CEP).
     *
     * @param array<string, string> $municipios
     * @return array{logradouro: string, municipio: string, cep: string}
     */
    private static function montarEndereco(?SimpleXMLElement $end, array $municipios): array
    {
        if ($end === null) {
            return ['logradouro' => '-', 'municipio' => '-', 'cep' => '-'];
        }

        $logradouro = (string) $end->xLgr . ', ' . (string) $end->nro;
        if ((string) $end->xBairro !== '') {
            $logradouro .= ', ' . (string) $end->xBairro;
        }

        $cMun = (string) ($end->endNac->cMun ?? $end->cMun ?? '');
        $cep = (string) ($end->endNac->CEP ?? $end->CEP ?? '');
        $uf = (string) ($end->UF ?? '');

        $municipio = self::nomeMunicipio($cMun, $municipios);
        if ($uf !== '') {
            $municipio .= ' - ' . $uf;
        }

        return [
            'logradouro' => $logradouro,
            'municipio' => $municipio,
            'cep' => self::formatarCep($cep),
        ];
    }

    /**
     * @param array<string, string> $municipios
     */
    private static function nomeMunicipio(string $codigo, array $municipios): string
    {
        return $municipios[$codigo] ?? "Município {$codigo}";
    }

    public static function formatarCnpjCpf(string $numero): string
    {
        $numero = (string) preg_replace('/\D/', '', $numero);
        if (strlen($numero) === 14) {
            return (string) preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $numero);
        }
        if (strlen($numero) === 11) {
            return (string) preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $numero);
        }

        return $numero !== '' ? $numero : '-';
    }

    public static function formatarTelefone(string $telefone): string
    {
        $telefone = (string) preg_replace('/\D/', '', $telefone);
        if (strlen($telefone) === 11) {
            return (string) preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $telefone);
        }
        if (strlen($telefone) === 10) {
            return (string) preg_replace('/(\d{2})(\d{4})(\d{4})/', '($1) $2-$3', $telefone);
        }

        return $telefone !== '' ? $telefone : '-';
    }

    public static function formatarData(string $data): string
    {
        if ($data === '') {
            return '-';
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $data);

        return $dt !== false ? $dt->format('d/m/Y') : $data;
    }

    public static function formatarDataHora(string $dataHora): string
    {
        if ($dataHora === '') {
            return '-';
        }
        $dt = \DateTime::createFromFormat(\DateTime::ATOM, $dataHora)
            ?: \DateTime::createFromFormat('Y-m-d\TH:i:sP', $dataHora);

        return $dt !== false ? $dt->format('d/m/Y H:i:s') : $dataHora;
    }

    public static function formatarCep(string $cep): string
    {
        $cep = (string) preg_replace('/\D/', '', $cep);
        if (strlen($cep) === 8) {
            return (string) preg_replace('/(\d{5})(\d{3})/', '$1-$2', $cep);
        }

        return $cep !== '' ? $cep : '-';
    }

    public static function formatarCodigoTrib(string $codigo): string
    {
        if (strlen($codigo) === 6) {
            return substr($codigo, 0, 2) . '.' . substr($codigo, 2, 2) . '.' . substr($codigo, 4, 2);
        }

        return $codigo;
    }
}
