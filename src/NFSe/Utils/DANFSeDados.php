<?php

declare(strict_types=1);

namespace NFSe\Utils;

use NFSe\Exception\NFSeException;
use SimpleXMLElement;

/**
 * Extração dos dados de um XML de NFS-e para o array usado na renderização
 * do DANFSe v2.0 (NT 008).
 *
 * Campos ausentes no XML são devolvidos como '-', conforme a nota 12 do
 * item 2.4.5 da NT 008.
 */
final class DANFSeDados
{
    public const TRACO = '-';

    // UF pelos dois primeiros dígitos do código IBGE do município.
    private const UF_POR_CODIGO = [
        '11' => 'RO', '12' => 'AC', '13' => 'AM', '14' => 'RR', '15' => 'PA',
        '16' => 'AP', '17' => 'TO', '21' => 'MA', '22' => 'PI', '23' => 'CE',
        '24' => 'RN', '25' => 'PB', '26' => 'PE', '27' => 'AL', '28' => 'SE',
        '29' => 'BA', '31' => 'MG', '32' => 'ES', '33' => 'RJ', '35' => 'SP',
        '41' => 'PR', '42' => 'SC', '43' => 'RS', '50' => 'MS', '51' => 'MT',
        '52' => 'GO', '53' => 'DF',
    ];

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

        $dados = self::identificacao($infNFSe, $dps);

        $dados['prestador'] = self::prestador($infNFSe->emit, $dps->prest ?? null, $municipios);
        $dados['tomador'] = self::pessoa($dps->toma ?? null, $municipios);
        $dados['intermediario'] = self::pessoa($dps->interm ?? null, $municipios);
        $dados['destinatario'] = self::destinatario($dps, $municipios);
        $dados['servico'] = self::servico($infNFSe, $dps, $municipios);
        $dados['issqn'] = self::issqn($infNFSe, $dps, $municipios);
        $dados['federal'] = self::federal($dps);
        $dados['ibscbs'] = self::ibsCbs($infNFSe, $dps, $municipios);
        $dados['valores'] = self::valores($infNFSe, $dps);
        $dados['informacoesComplementares'] = self::informacoesComplementares($infNFSe, $dps);
        $dados['linhaTotaisAproximados'] = self::totaisAproximados($dps);

        return $dados;
    }

    /**
     * @return array<string, mixed>
     */
    private static function identificacao(SimpleXMLElement $infNFSe, SimpleXMLElement $dps): array
    {
        $tpAmb = self::texto($dps->tpAmb);

        // Cabeçalho: "Município: CCCC / CC" (NT 008, item 2.4.5, campo Município)
        $municipioEmissor = self::texto($infNFSe->xLocEmi);
        $ufEmissor = self::texto($infNFSe->emit->enderNac->UF ?? null);
        $municipioEmissorComUf = $ufEmissor !== self::TRACO
            ? $municipioEmissor . ' / ' . $ufEmissor
            : $municipioEmissor;

        return [
            'chaveAcesso' => str_replace('NFS', '', (string) $infNFSe['Id']),
            'numero' => self::texto($infNFSe->nNFSe),
            'serie' => self::texto($dps->serie),
            'numeroDPS' => self::texto($dps->nDPS),
            'competencia' => self::formatarData(self::texto($dps->dCompet)),
            'dhEmissao' => self::formatarDataHora(self::texto($infNFSe->dhProc)),
            'dhEmissaoDPS' => self::formatarDataHora(self::texto($dps->dhEmi)),
            'ambiente' => $tpAmb !== self::TRACO ? $tpAmb : '1',
            'ambienteGerador' => self::texto($infNFSe->ambGer),
            'emitente' => self::descreverEmitente(self::texto($dps->tpEmit)),
            'situacao' => self::descreverSituacao(self::texto($infNFSe->cStat)),
            'finalidade' => self::descreverFinalidade(self::texto($dps->IBSCBS->finNFSe ?? null)),
            'municipioEmissor' => $municipioEmissor,
            'municipioEmissorComUf' => $municipioEmissorComUf,
        ];
    }

    /**
     * @param array<string, string> $municipios
     * @return array<string, mixed>
     */
    private static function prestador(
        SimpleXMLElement $emit,
        ?SimpleXMLElement $prest,
        array $municipios,
    ): array {
        $dados = self::pessoaBase($emit, $municipios, $emit->enderNac ?? null);

        // Telefone e e-mail vêm de DPS/infDPS/prest (caminho do item 2.4.5 da
        // NT 008 — é o que o portal nacional imprime), com fallback para emit.
        $fone = self::texto($prest->fone ?? null);
        if ($fone === self::TRACO) {
            $fone = self::texto($emit->fone);
        }
        $dados['telefone'] = self::formatarTelefone($fone === self::TRACO ? '' : $fone);

        $email = self::texto($prest->email ?? null);
        $dados['email'] = $email !== self::TRACO ? $email : self::texto($emit->email);

        $dados['simplesNacional'] = self::descreverSimplesNacional(
            self::texto($prest->regTrib->opSimpNac ?? null),
        );
        $dados['regimeApuracao'] = self::descreverRegimeApuracaoSN(
            self::texto($prest->regTrib->regApTribSN ?? null),
        );
        $dados['regimeApuracaoIbsCbs'] = self::descreverRegimeApuracaoIbsCbs(
            self::texto($prest->regTrib->regApIBSCBSSN ?? null),
        );

        return $dados;
    }

    /**
     * @param array<string, string> $municipios
     * @return array<string, mixed>|null
     */
    private static function pessoa(?SimpleXMLElement $no, array $municipios): ?array
    {
        if ($no === null) {
            return null;
        }

        return self::pessoaBase($no, $municipios, $no->end ?? null);
    }

    /**
     * Destinatário da operação: vive no grupo IBSCBS da DPS. Quando indDest = 0,
     * o destinatário é o próprio tomador/adquirente.
     *
     * @param array<string, string> $municipios
     * @return array<string, mixed>|null
     */
    private static function destinatario(SimpleXMLElement $dps, array $municipios): ?array
    {
        $ibsCbs = $dps->IBSCBS ?? null;
        if ($ibsCbs === null) {
            return null;
        }

        if (self::texto($ibsCbs->indDest) === '0') {
            return ['ehProprioTomador' => true];
        }

        $dest = $ibsCbs->dest ?? null;
        if ($dest === null) {
            return null;
        }

        $dados = self::pessoaBase($dest, $municipios, $dest->end ?? null);
        $dados['ehProprioTomador'] = false;

        return $dados;
    }

    /**
     * @param array<string, string> $municipios
     * @return array<string, mixed>
     */
    private static function pessoaBase(
        SimpleXMLElement $no,
        array $municipios,
        ?SimpleXMLElement $endereco,
    ): array {
        $documento = (string) ($no->CNPJ ?? $no->CPF ?? $no->NIF ?? '');

        return [
            'cnpj' => self::formatarCnpjCpf($documento),
            'inscricaoMunicipal' => self::texto($no->IM),
            'nome' => self::texto($no->xNome),
            'telefone' => self::formatarTelefone(self::texto($no->fone)),
            'email' => self::texto($no->email),
            'endereco' => self::montarEndereco($endereco, $municipios),
        ];
    }

    /**
     * @param array<string, string> $municipios
     * @return array<string, mixed>
     */
    private static function servico(
        SimpleXMLElement $infNFSe,
        SimpleXMLElement $dps,
        array $municipios,
    ): array {
        $serv = $dps->serv ?? null;

        $codigoNacional = self::formatarCodigoTrib(self::texto($serv->cServ->cTribNac ?? null));
        $codigoMunicipal = self::texto($serv->cServ->cTribMun ?? null);

        $codigoLocal = self::texto($serv->locPrest->cLocPrestacao ?? null);
        $local = self::texto($infNFSe->xLocPrestacao);
        if ($local === self::TRACO) {
            $local = self::nomeMunicipio($codigoLocal, $municipios);
        }

        return [
            'codigoTributacao' => $codigoNacional . ' / ' . $codigoMunicipal,
            'codigoNBS' => self::texto($serv->cServ->cNBS ?? null),
            'localPrestacao' => self::localComUfEPais(
                $local,
                $codigoLocal,
                self::texto($serv->locPrest->cPaisPrestacao ?? null),
            ),
            'descricaoCodigo' => self::texto($infNFSe->xTribNac),
            'descricao' => self::texto($serv->cServ->xDescServ ?? null),
        ];
    }

    /**
     * @param array<string, string> $municipios
     * @return array<string, mixed>
     */
    private static function issqn(
        SimpleXMLElement $infNFSe,
        SimpleXMLElement $dps,
        array $municipios,
    ): array {
        $tribMun = $dps->valores->trib->tribMun ?? null;
        $valores = $infNFSe->valores ?? null;

        $tributacao = self::texto($tribMun->tribISSQN ?? null);

        // O município de incidência traz nome do município, UF e país. Quando o
        // XML só devolve o nome (sem UF), completa pelo mapa de municípios.
        $municipioIncidencia = self::nomeMunicipio(self::texto($infNFSe->cLocIncid), $municipios);
        if ($municipioIncidencia === self::TRACO) {
            $municipioIncidencia = self::texto($infNFSe->xLocIncid);
        }

        $regimeEspecial = self::texto($dps->prest->regTrib->regEspTrib ?? null);
        $tipoImunidade = self::texto($tribMun->tpImunidade ?? null);
        $suspensao = self::texto($tribMun->exigSusp->tpSusp ?? null);
        $numeroProcesso = self::texto($tribMun->exigSusp->nProcesso ?? null);
        $beneficio = self::texto($tribMun->BM->nBM ?? null);
        $calculoBM = self::valorOuTraco($valores->vCalcBM ?? null);
        $totalDeducoes = self::valorOuTraco($valores->vCalcDR ?? null);
        $descontoIncond = self::valorOuTraco($dps->valores->vDescCondIncond->vDescIncond ?? null);

        // Nota 5 da NT 008: as linhas opcionais do bloco ISSQN podem ser
        // suprimidas quando todos os campos da linha estão sem dados no XML.
        // regEspTrib = 0 ("Nenhum") conta como sem dados, como faz o portal.
        $semDado = static fn(string $v): bool => $v === self::TRACO || $v === '0';

        return [
            'tributacao' => self::descreverTributacaoISSQN($tributacao),
            'sujeitoAoIssqn' => $tributacao !== '5',
            'municipioIncidencia' => self::localComUfEPais(
                $municipioIncidencia,
                self::texto($infNFSe->cLocIncid),
                self::texto($tribMun->cPaisResult ?? null),
            ),
            'regimeEspecial' => self::descreverRegimeEspecial($regimeEspecial),
            'tipoImunidade' => $tipoImunidade,
            'suspensaoExigibilidade' => $suspensao,
            'numeroProcessoSuspensao' => $numeroProcesso,
            'beneficioMunicipal' => $beneficio,
            'calculoBM' => $calculoBM,
            'totalDeducoes' => $totalDeducoes,
            'descontoIncondicionado' => $descontoIncond,
            'linhaRegimeVazia' => $semDado($regimeEspecial) && $semDado($tipoImunidade)
                && $semDado($suspensao) && $semDado($numeroProcesso),
            'linhaBeneficioVazia' => $semDado($beneficio) && $semDado($calculoBM)
                && $semDado($totalDeducoes) && $semDado($descontoIncond),
            'baseCalculo' => self::valorOuTraco($valores->vBC ?? null),
            'aliquota' => self::percentualOuTraco($valores->pAliqAplic ?? null),
            'retencao' => self::descreverRetencaoISSQN(self::texto($tribMun->tpRetISSQN ?? null)),
            'valorApurado' => self::valorOuTraco($valores->vISSQN ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function federal(SimpleXMLElement $dps): array
    {
        $tribFed = $dps->valores->trib->tribFed ?? null;
        $piscofins = $tribFed->piscofins ?? null;

        $retido = self::texto($piscofins->tpRetPisCofins ?? null) === '1';

        // Quando PIS/COFINS são retidos, os débitos de apuração própria zeram e
        // o total retido soma CSLL + PIS + COFINS (NT 008, item 2.4.5).
        $vPis = (float) ($piscofins->vPis ?? 0);
        $vCofins = (float) ($piscofins->vCofins ?? 0);
        $vCsll = (float) ($tribFed->vRetCSLL ?? 0);

        return [
            'irrf' => self::valorOuTraco($tribFed->vRetIRRF ?? null),
            'contribuicaoPrevidenciaria' => self::valorOuTraco($tribFed->vRetCP ?? null),
            'contribuicoesSociais' => $retido
                ? self::formatarValor($vCsll + $vPis + $vCofins)
                : self::valorOuTraco($tribFed->vRetCSLL ?? null),
            'pis' => $retido ? self::formatarValor(0.0) : self::valorOuTraco($piscofins->vPis ?? null),
            'cofins' => $retido ? self::formatarValor(0.0) : self::valorOuTraco($piscofins->vCofins ?? null),
            'descricaoContribuicoes' => self::descreverRetencaoPisCofins(
                self::texto($piscofins->tpRetPisCofins ?? null),
            ),
        ];
    }

    /**
     * Grupo IBS/CBS calculado pela Sefin. Ainda ausente dos XML emitidos em
     * 2026: todos os campos caem para '-'.
     *
     * @param array<string, string> $municipios
     * @return array<string, mixed>
     */
    private static function ibsCbs(
        SimpleXMLElement $infNFSe,
        SimpleXMLElement $dps,
        array $municipios,
    ): array {
        $grupo = $infNFSe->IBSCBS ?? null;
        $valores = $grupo->valores ?? null;
        $totais = $grupo->totCIBS ?? null;
        $situacao = $dps->IBSCBS->valores->trib->gIBSCBS ?? null;

        $municipioIncidencia = self::texto($grupo->xLocalidadeIncid ?? null);
        if ($municipioIncidencia === self::TRACO) {
            $municipioIncidencia = self::nomeMunicipio(
                self::texto($grupo->cLocalidadeIncid ?? null),
                $municipios,
            );
        }

        return [
            'cst' => self::texto($situacao->CST ?? null),
            'cClassTrib' => self::texto($situacao->cClassTrib ?? null),
            'indicadorOperacao' => self::texto($dps->IBSCBS->cIndOp ?? null),
            'codigoIbgeIncidencia' => self::texto($grupo->cLocalidadeIncid ?? null),
            'municipioIncidencia' => $municipioIncidencia,
            'exclusoesReducoes' => self::calcularExclusoesReducoes($infNFSe, $dps),
            'baseCalculo' => self::valorOuTraco($valores->vBC ?? null),
            'reducaoAliquotaIbsUf' => self::percentualOuTraco($valores->uf->pRedAliqUF ?? null),
            'reducaoAliquotaIbsMun' => self::percentualOuTraco($valores->mun->pRedAliqMun ?? null),
            'reducaoAliquotaCbs' => self::percentualOuTraco($valores->fed->pRedAliqCBS ?? null),
            'aliquotaIbsUf' => self::percentualOuTraco($valores->uf->pIBSUF ?? null),
            'aliquotaIbsMun' => self::percentualOuTraco($valores->mun->pIBSMun ?? null),
            'aliquotaEfetivaIbsMun' => self::percentualOuTraco($valores->mun->pAliqEfetMun ?? null),
            'valorApuradoIbsMun' => self::valorOuTraco($totais->gIBS->gIBSMunTot->vIBSMun ?? null),
            'aliquotaEfetivaIbsUf' => self::percentualOuTraco($valores->uf->pAliqEfetUF ?? null),
            'valorApuradoIbsUf' => self::valorOuTraco($totais->gIBS->gIBSUFTot->vIBSUF ?? null),
            'valorTotalIbs' => self::valorOuTraco($totais->gIBS->vIBSTot ?? null),
            'aliquotaCbs' => self::percentualOuTraco($valores->fed->pCBS ?? null),
            'aliquotaEfetivaCbs' => self::percentualOuTraco($valores->fed->pAliqEfetCBS ?? null),
            'valorTotalCbs' => self::valorOuTraco($totais->gCBS->vCBS ?? null),
            'valorTotalNota' => self::valorOuTraco($totais->vTotNF ?? null),
            'totalIbsCbs' => self::somarOuTraco(
                $totais->gIBS->vIBSTot ?? null,
                $totais->gCBS->vCBS ?? null,
            ),
        ];
    }

    /**
     * Exclusões e reduções da BC do IBS/CBS, conforme a NT 008:
     * vDescIncond + vCalcReeRepRes + vISSQN + vPIS + vCOFINS.
     */
    private static function calcularExclusoesReducoes(
        SimpleXMLElement $infNFSe,
        SimpleXMLElement $dps,
    ): string {
        $piscofins = $dps->valores->trib->tribFed->piscofins ?? null;

        $total = (float) ($dps->valores->vDescCondIncond->vDescIncond ?? 0)
            + (float) ($infNFSe->IBSCBS->valores->vCalcReeRepRes ?? 0)
            + (float) ($infNFSe->valores->vISSQN ?? 0)
            + (float) ($piscofins->vPis ?? 0)
            + (float) ($piscofins->vCofins ?? 0);

        return self::formatarValor($total);
    }

    /**
     * @return array<string, mixed>
     */
    private static function valores(
        SimpleXMLElement $infNFSe,
        SimpleXMLElement $dps,
    ): array {
        $valoresNFSe = $infNFSe->valores ?? null;
        $descontos = $dps->valores->vDescCondIncond ?? null;
        $totais = $infNFSe->IBSCBS->totCIBS ?? null;

        return [
            'valorServico' => self::valorOuTraco($dps->valores->vServPrest->vServ ?? null),
            'descontoIncondicionado' => self::valorOuTraco($descontos->vDescIncond ?? null),
            'descontoCondicionado' => self::valorOuTraco($descontos->vDescCond ?? null),
            'totalRetencoes' => self::valorOuTraco($valoresNFSe->vTotalRet ?? null),
            'valorLiquido' => self::valorOuTraco($valoresNFSe->vLiq ?? null),
            'totalIbsCbs' => self::somarOuTraco(
                $totais->gIBS->vIBSTot ?? null,
                $totais->gCBS->vCBS ?? null,
            ),
            // vTotNF vem calculado pela Sefin; ausente no XML, imprime traço
            // (NT 008, nota 12) — sem fallback aritmético.
            'valorLiquidoComIbsCbs' => self::valorOuTraco($totais->vTotNF ?? null),
            'tribISSQN' => self::texto($dps->valores->trib->tribMun->tribISSQN ?? null),
            'retencaoISSQN' => self::texto($dps->valores->trib->tribMun->tpRetISSQN ?? null),
            'totTribSN' => (float) ($dps->valores->trib->totTrib->pTotTribSN ?? 0),
            'totTribFed' => (float) ($dps->valores->trib->totTrib->vTotTrib->vTotTribFed ?? 0),
            'totTribEst' => (float) ($dps->valores->trib->totTrib->vTotTrib->vTotTribEst ?? 0),
            'totTribMun' => (float) ($dps->valores->trib->totTrib->vTotTrib->vTotTribMun ?? 0),
        ];
    }

    /**
     * União ordenada dos campos de informações complementares, separados por
     * " | " e prefixados conforme as notas 7 a 10 da NT 008.
     *
     * @return array<int, string>
     */
    private static function informacoesComplementares(
        SimpleXMLElement $infNFSe,
        SimpleXMLElement $dps,
    ): array {
        $partes = [];

        $prefixos = [
            'Inf. Cont.: ' => self::texto($dps->serv->infoCompl->xInfComp ?? $dps->xInfComp ?? null),
            'NFS-e Subst.: ' => self::texto($dps->subst->chSubstda ?? null),
            'Cod. Obra: ' => self::texto($dps->serv->obra->cObra ?? null),
            'Insc. Imob.: ' => self::texto($dps->serv->obra->inscImobFisc ?? null),
            'Cod. Evt.: ' => self::texto($dps->serv->atvEvento->idAtvEvt ?? null),
        ];

        foreach ($prefixos as $prefixo => $valor) {
            if ($valor !== self::TRACO && $valor !== '') {
                $partes[] = $prefixo . $valor;
            }
        }

        $usoMunicipal = self::texto($infNFSe->xOutInf);
        if ($usoMunicipal !== self::TRACO) {
            $partes[] = 'Inf. A. T. Mun.: ' . $usoMunicipal;
        }

        return $partes;
    }

    /**
     * Linha obrigatória e fixa da Lei nº 12.741/2012 (nota 10 da NT 008),
     * impressa em linha própria após as demais informações complementares.
     * Aceita valores monetários (vTotTrib) ou percentuais (pTotTrib); o
     * pTotTribSN não alimenta esses campos — sem eles, imprime traços.
     */
    private static function totaisAproximados(SimpleXMLElement $dps): string
    {
        $totTrib = $dps->valores->trib->totTrib ?? null;

        if (isset($totTrib->pTotTrib)) {
            $federais = self::percentualOuTraco($totTrib->pTotTrib->pTotTribFed ?? null);
            $estaduais = self::percentualOuTraco($totTrib->pTotTrib->pTotTribEst ?? null);
            $municipais = self::percentualOuTraco($totTrib->pTotTrib->pTotTribMun ?? null);
        } else {
            $federais = self::valorOuTraco($totTrib->vTotTrib->vTotTribFed ?? null);
            $estaduais = self::valorOuTraco($totTrib->vTotTrib->vTotTribEst ?? null);
            $municipais = self::valorOuTraco($totTrib->vTotTrib->vTotTribMun ?? null);
        }

        return 'Totais Aproximados dos Tributos cfe. Lei nº 12.741/2012: '
            . "Federais: {$federais}; Estaduais: {$estaduais}; Municipais: {$municipais};";
    }

    /**
     * Monta o endereço a partir de <enderNac> (emitente, campos planos) ou
     * <end> (tomador/intermediário, com <endNac> aninhado para cMun/CEP).
     *
     * @param array<string, string> $municipios
     * @return array{logradouro: string, municipio: string, cep: string, codigoIbge: string}
     */
    private static function montarEndereco(?SimpleXMLElement $end, array $municipios): array
    {
        if ($end === null) {
            return [
                'logradouro' => self::TRACO,
                'municipio' => self::TRACO,
                'cep' => self::TRACO,
                'codigoIbge' => self::TRACO,
            ];
        }

        $partes = array_filter([
            (string) $end->xLgr,
            (string) $end->nro,
            (string) $end->xCpl,
            (string) $end->xBairro,
        ], static fn(string $parte): bool => $parte !== '');

        $cMun = (string) ($end->endNac->cMun ?? $end->cMun ?? '');
        $cep = (string) ($end->endNac->CEP ?? $end->CEP ?? '');
        $uf = (string) ($end->UF ?? '');

        $municipio = self::nomeMunicipio($cMun, $municipios);
        if ($uf !== '' && !str_contains($municipio, $uf)) {
            $municipio .= ' / ' . $uf;
        }

        return [
            'logradouro' => $partes === [] ? self::TRACO : implode(', ', $partes),
            'municipio' => $municipio,
            'cep' => self::formatarCep($cep),
            'codigoIbge' => $cMun !== '' ? $cMun : self::TRACO,
        ];
    }

    /**
     * @param array<string, string> $municipios
     */
    private static function nomeMunicipio(string $codigo, array $municipios): string
    {
        if ($codigo === '' || $codigo === self::TRACO) {
            return self::TRACO;
        }

        return $municipios[$codigo] ?? $codigo;
    }

    /**
     * "Município / UF / País" (NT 008, item 2.4.5). A UF vem dos dois
     * primeiros dígitos do código IBGE; se o nome já traz a UF (mapa de
     * municípios no formato "Nome / UF"), não duplica. País ausente => traço.
     */
    private static function localComUfEPais(string $local, string $codigoIbge, string $pais): string
    {
        if ($local !== self::TRACO && !str_contains($local, ' / ')) {
            $local .= ' / ' . self::ufPorCodigoIbge($codigoIbge);
        }

        return $local . ' / ' . ($pais === '' ? self::TRACO : $pais);
    }

    private static function ufPorCodigoIbge(string $codigoIbge): string
    {
        return self::UF_POR_CODIGO[substr($codigoIbge, 0, 2)] ?? self::TRACO;
    }

    /**
     * Texto do nó, ou traço quando ausente/vazio.
     */
    private static function texto(mixed $no): string
    {
        if ($no === null) {
            return self::TRACO;
        }

        $valor = trim((string) $no);

        return $valor === '' ? self::TRACO : $valor;
    }

    public static function valorOuTraco(mixed $no): string
    {
        if ($no === null || (string) $no === '') {
            return self::TRACO;
        }

        return self::formatarValor((float) $no);
    }

    public static function percentualOuTraco(mixed $no): string
    {
        if ($no === null || (string) $no === '') {
            return self::TRACO;
        }

        return self::formatarPercentual((float) $no);
    }

    private static function somarOuTraco(mixed $a, mixed $b): string
    {
        if (($a === null || (string) $a === '') && ($b === null || (string) $b === '')) {
            return self::TRACO;
        }

        return self::formatarValor((float) $a + (float) $b);
    }

    public static function formatarValor(float $valor): string
    {
        return 'R$ ' . number_format($valor, 2, ',', '.');
    }

    public static function formatarPercentual(float $valor): string
    {
        return number_format($valor, 2, ',', '.') . '%';
    }

    public static function formatarCnpjCpf(string $numero): string
    {
        $numero = trim($numero);
        if ($numero === '') {
            return self::TRACO;
        }

        // CNPJ alfanumérico (a partir de julho/2026) não é reformatável por
        // máscara numérica: devolve como veio.
        $limpo = (string) preg_replace('/[^A-Za-z0-9]/', '', $numero);

        if (strlen($limpo) === 14 && ctype_digit($limpo)) {
            return (string) preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $limpo);
        }
        if (strlen($limpo) === 11 && ctype_digit($limpo)) {
            return (string) preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $limpo);
        }

        return $limpo;
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

        return $telefone !== '' ? $telefone : self::TRACO;
    }

    public static function formatarData(string $data): string
    {
        if ($data === '' || $data === self::TRACO) {
            return self::TRACO;
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $data);

        return $dt !== false ? $dt->format('d/m/Y') : $data;
    }

    public static function formatarDataHora(string $dataHora): string
    {
        if ($dataHora === '' || $dataHora === self::TRACO) {
            return self::TRACO;
        }
        $dt = \DateTime::createFromFormat(\DateTime::ATOM, $dataHora)
            ?: \DateTime::createFromFormat('Y-m-d\TH:i:sP', $dataHora);

        return $dt !== false ? $dt->format('d/m/Y H:i:s') : $dataHora;
    }

    /**
     * Máscara "nn.nnn-nnn" do item 2.4.5 da NT 008 (campo Código IBGE / CEP).
     */
    public static function formatarCep(string $cep): string
    {
        $cep = (string) preg_replace('/\D/', '', $cep);
        if (strlen($cep) === 8) {
            return (string) preg_replace('/(\d{2})(\d{3})(\d{3})/', '$1.$2-$3', $cep);
        }

        return $cep !== '' ? $cep : self::TRACO;
    }

    public static function formatarCodigoTrib(string $codigo): string
    {
        if (strlen($codigo) === 6) {
            return substr($codigo, 0, 2) . '.' . substr($codigo, 2, 2) . '.' . substr($codigo, 4, 2);
        }

        return $codigo;
    }

    private static function descreverEmitente(string $codigo): string
    {
        return match ($codigo) {
            '1' => 'Prestador',
            '2' => 'Tomador',
            '3' => 'Intermediário',
            default => self::TRACO,
        };
    }

    private static function descreverSituacao(string $cStat): string
    {
        return match ($cStat) {
            '100' => 'NFS-e Gerada',
            '101' => 'NFS-e Cancelada',
            '102' => 'NFS-e Substituída',
            default => $cStat === self::TRACO ? self::TRACO : "NFS-e ({$cStat})",
        };
    }

    private static function descreverFinalidade(string $finNFSe): string
    {
        return match ($finNFSe) {
            '0' => 'NFS-e regular',
            '1' => 'NFS-e de crédito',
            '2' => 'NFS-e de débito',
            default => self::TRACO,
        };
    }

    private static function descreverSimplesNacional(string $codigo): string
    {
        return match ($codigo) {
            '1' => 'Não Optante',
            '2' => 'Optante - Microempreendedor Individual (MEI)',
            '3' => 'Optante - Microempresa ou Empresa de Pequeno Porte (ME/EPP)',
            '4' => 'Optante Pendente',
            default => self::TRACO,
        };
    }

    private static function descreverRegimeApuracaoSN(string $codigo): string
    {
        return match ($codigo) {
            '1' => 'Regime de apuração dos tributos federais e municipal pelo Simples Nacional',
            '2' => 'Regime de apuração dos tributos federais pelo SN e o ISSQN pela prefeitura',
            '3' => 'Regime de apuração dos tributos federais e municipal por fora do SN',
            default => self::TRACO,
        };
    }

    private static function descreverRegimeApuracaoIbsCbs(string $codigo): string
    {
        return match ($codigo) {
            '1' => 'IBS e CBS apurados pelo SN',
            '2' => 'CBS apurada pelo SN e IBS apurado pelo regime regular',
            '3' => 'IBS e CBS apurados pelo regime regular',
            default => self::TRACO,
        };
    }

    private static function descreverRegimeEspecial(string $codigo): string
    {
        return match ($codigo) {
            '0' => 'Nenhum',
            '1' => 'Ato Cooperado (Cooperativa)',
            '2' => 'Estimativa',
            '3' => 'Microempresa Municipal',
            '4' => 'Notário ou Registrador',
            '5' => 'Profissional Autônomo',
            '6' => 'Sociedade de Profissionais',
            default => $codigo,
        };
    }

    private static function descreverTributacaoISSQN(string $codigo): string
    {
        return match ($codigo) {
            '1' => 'Operação Tributável',
            '2' => 'Exportação de Serviço',
            '3' => 'Não Incidência',
            '4' => 'Imunidade',
            default => self::TRACO,
        };
    }

    private static function descreverRetencaoISSQN(string $codigo): string
    {
        return match ($codigo) {
            '1' => 'Não Retido',
            '2' => 'Retido pelo Tomador',
            '3' => 'Retido pelo Intermediário',
            default => self::TRACO,
        };
    }

    private static function descreverRetencaoPisCofins(string $codigo): string
    {
        return match ($codigo) {
            '1' => 'PIS/COFINS Retido',
            '2' => 'PIS/COFINS Não Retido',
            default => self::TRACO,
        };
    }
}
