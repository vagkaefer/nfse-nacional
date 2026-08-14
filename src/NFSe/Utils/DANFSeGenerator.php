<?php

declare(strict_types=1);

namespace NFSe\Utils;

use TCPDF;

/**
 * Gerador do DANFSe v2.0 conforme a Nota Técnica nº 008/2026 (SE/CGNFS-e).
 *
 * Desde 03/08/2026 a API oficial de geração do DANFSe está sobrestada, então
 * este gerador é o caminho normal de produção do PDF — não mais um paliativo.
 *
 * O documento é uma grade fechada de blocos, em página única A4 retrato. As
 * medidas do item 2.4.5 da NT são dadas em centímetros; aqui trabalhamos em
 * milímetros (unidade do TCPDF), daí os valores multiplicados por 10.
 *
 * Opções aceitas no construtor:
 *  - creator / author: metadados do PDF
 *  - municipios: array<string, string> mapa codIBGE => "Nome / UF"
 *  - logoPath: caminho da logomarca oficial da NFS-e (canto superior esquerdo)
 *  - exibirCanhoto: bool, bloco opcional de recibo (padrão: true)
 *  - fonteTitulos / fonteConteudo: famílias TCPDF para títulos e conteúdo
 *  - marcaDagua: 'CANCELADA' | 'SUBSTITUIDA' | null
 */
class DANFSeGenerator extends TCPDF
{
    private const CREATOR_PADRAO = 'nfse-nacional-php';

    private const URL_CONSULTA = 'https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave=';

    private const TEXTO_QR = 'A autenticidade desta NFS-e pode ser verificada pela leitura deste código QR '
        . 'ou pela consulta da chave de acesso no portal nacional da NFS-e';

    // Formulário (NT 008, itens 2.2.1 e 2.2.2): A4 retrato, margens de 0,15 a 0,20 cm
    private const MARGEM = 3.0;
    private const LARGURA_UTIL = 204.0;

    // Colunas do leiaute: as coordenadas X da NT são 0,30 / 5,41 / 10,51 / 15,62 cm
    private const COL_X = [3.0, 54.1, 105.1, 156.2];
    private const COL_LARGURA = 51.0;

    // Alturas (NT 008, item 2.4.5)
    private const ALTURA_LINHA = 6.7;
    private const ALTURA_TITULO_BLOCO = 6.3;
    private const ALTURA_LINHA_SUPRIMIDA = 3.2;
    private const ALTURA_CABECALHO = 11.6;

    private const QR_TAMANHO = 15.2;

    // Fontes (NT 008, item 2.4): Arial para títulos, Microsoft Sans Serif para
    // conteúdo. O TCPDF não embarca essas famílias; helvetica é o substituto
    // métrico equivalente.
    private const PT_TITULO_BLOCO = 7;
    private const PT_LABEL = 6;
    private const PT_CONTEUDO = 7;

    /** @var array<string, mixed> */
    private array $dados = [];

    private float $cursorY = self::MARGEM;

    private readonly string $fonteTitulos;
    private readonly string $fonteConteudo;
    private readonly bool $exibirCanhoto;
    private readonly ?string $logoOpcao;
    private ?string $logoPath = null;
    private readonly ?string $marcaDagua;

    /** @var array<string, string> */
    private readonly array $municipios;

    /**
     * @param array<string, mixed> $opcoes
     */
    public function __construct(array $opcoes = [])
    {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8', false);

        $this->SetCreator((string) ($opcoes['creator'] ?? self::CREATOR_PADRAO));
        $this->SetAuthor((string) ($opcoes['author'] ?? self::CREATOR_PADRAO));
        $this->SetTitle('DANFSe');
        $this->SetSubject('Documento Auxiliar da NFS-e');

        $this->municipios = (array) ($opcoes['municipios'] ?? []);
        $this->fonteTitulos = (string) ($opcoes['fonteTitulos'] ?? 'helvetica');
        $this->fonteConteudo = (string) ($opcoes['fonteConteudo'] ?? 'helvetica');
        $this->exibirCanhoto = (bool) ($opcoes['exibirCanhoto'] ?? true);
        $this->logoOpcao = isset($opcoes['logoPath']) ? (string) $opcoes['logoPath'] : null;
        $this->marcaDagua = isset($opcoes['marcaDagua']) ? (string) $opcoes['marcaDagua'] : null;

        $this->setPrintHeader(false);
        $this->setPrintFooter(false);

        $this->SetMargins(self::MARGEM, self::MARGEM, self::MARGEM);
        // O DANFSe é obrigatoriamente de página única (NT 008, item 2.2).
        $this->SetAutoPageBreak(false, 0);
    }

    /**
     * Gera o DANFSe a partir do XML da NFS-e e devolve o PDF em binário.
     */
    public function gerarPDF(string $xmlNFSe, ?string $logoPath = null): string
    {
        $this->logoPath = $logoPath ?? $this->logoOpcao ?? $this->logoPadrao();
        $this->dados = DANFSeDados::extrair($xmlNFSe, $this->municipios);

        $this->AddPage();
        $this->SetLineWidth(0.1);
        $this->SetDrawColor(0, 0, 0);
        $this->SetTextColor(0, 0, 0);

        $this->cursorY = self::MARGEM;

        $this->cabecalho();
        $this->identificacao();
        $this->prestador();
        $this->tomador();
        $this->destinatario();
        $this->intermediario();
        $this->servico();
        $this->tributacaoMunicipal();
        $this->tributacaoFederal();
        $this->tributacaoIbsCbs();
        $this->valorTotal();
        $this->informacoesComplementares();
        $this->canhoto();

        $this->bordaPagina();
        $this->aplicarMarcaDagua();

        return $this->Output('', 'S');
    }

    // ---------------------------------------------------------------- blocos

    private function cabecalho(): void
    {
        $altura = self::ALTURA_CABECALHO;
        $this->caixa(self::MARGEM, $this->cursorY, self::LARGURA_UTIL, $altura, true);

        if ($this->logoPath !== null && file_exists($this->logoPath)) {
            // Logomarca da NFS-e no canto esquerdo (NT 008, item 2.4.3):
            // 4,00 cm de largura, alinhada verticalmente ao centro do bloco.
            // PNGs com canal alfa exigiriam GD/Imagick, que a lib não impõe como
            // dependência — por isso a imagem embarcada já vem sem transparência.
            try {
                $this->Image($this->logoPath, 4.9, $this->cursorY + 1.8, 40.0, 0, '', '', '', true, 300);
            } catch (\Throwable) {
                // Logo é opcional: falha ao carregar não impede o documento
            }
        }

        $homologacao = ($this->dados['ambiente'] ?? '1') === '2';

        $this->SetFont($this->fonteTitulos, 'B', 9);
        $this->SetXY(54.1, $this->cursorY + ($homologacao ? 0.8 : 2.0));
        $this->Cell(101.9, 4, 'DANFSe v2.0', 0, 0, 'C');

        $this->SetXY(54.1, $this->cursorY + ($homologacao ? 4.3 : 5.5));
        $this->Cell(101.9, 4, 'Documento Auxiliar da NFS-e', 0, 0, 'C');

        if ($homologacao) {
            $this->SetTextColor(255, 0, 0);
            $this->SetXY(54.1, $this->cursorY + 7.8);
            $this->Cell(101.9, 4, 'NFS-e SEM VALIDADE JURÍDICA', 0, 0, 'C');
            $this->SetTextColor(0, 0, 0);
        }

        $municipio = (string) ($this->dados['prestador']['endereco']['municipio'] ?? DANFSeDados::TRACO);
        $this->SetFont($this->fonteConteudo, '', 8);
        $this->SetXY(156.2, $this->cursorY + 1.0);
        $this->MultiCell(50.9, 3, 'Município: ' . $municipio, 0, 'L');

        $this->SetFont($this->fonteConteudo, '', 6);
        $this->SetXY(156.2, $this->cursorY + 7.2);
        $this->Cell(50.9, 2.4, 'Ambiente Gerador: ' . ($this->dados['ambienteGerador'] ?? ''), 0, 0, 'L');
        $this->SetXY(156.2, $this->cursorY + 9.4);
        $this->Cell(50.9, 2.4, 'Tipo de Ambiente: ' . ($this->dados['ambiente'] ?? ''), 0, 0, 'L');

        $this->cursorY += $altura;
    }

    /**
     * Bloco "DADOS DA NFS-e": chave de acesso, numeração e QR Code à direita.
     */
    private function identificacao(): void
    {
        $inicio = $this->cursorY;

        // Coluna esquerda: chave + três linhas de campos (largura reduzida para
        // reservar o espaço do QR Code, cuja posição X é normativa).
        $this->campo(self::COL_X[0], $this->cursorY, 150.0, 'CHAVE DE ACESSO DA NFS-e', (string) $this->dados['chaveAcesso'], maiusculo: true);
        $this->cursorY += self::ALTURA_LINHA;

        $this->linhaCampos([
            ['NÚMERO DA NFS-e', (string) $this->dados['numero'], 51.0],
            ['COMPETÊNCIA DA NFS-e', (string) $this->dados['competencia'], 51.0],
            ['DATA E HORA DA EMISSÃO DA NFS-e', (string) $this->dados['dhEmissao'], 48.0],
        ], maiusculo: true);

        $this->linhaCampos([
            ['NÚMERO DA DPS', (string) $this->dados['numeroDPS'], 51.0],
            ['SÉRIE DA DPS', (string) $this->dados['serie'], 51.0],
            ['DATA E HORA DA EMISSÃO DA DPS', (string) $this->dados['dhEmissaoDPS'], 48.0],
        ], maiusculo: true);

        $this->linhaCampos([
            ['EMITENTE DA NFS-e', (string) $this->dados['emitente'], 51.0, true],
            ['SITUAÇÃO DA NFS-e', (string) $this->dados['situacao'], 51.0],
            ['FINALIDADE', (string) $this->dados['finalidade'], 48.0],
        ], maiusculo: true);

        $this->qrCode($inicio);
    }

    private function qrCode(float $inicioBloco): void
    {
        $x = 177.8;  // 17,48 cm da margem esquerda do formulário
        $y = $inicioBloco + 1.2;

        $estilo = [
            'border' => false,
            'padding' => 0,
            'fgcolor' => [0, 0, 0],
            'bgcolor' => [255, 255, 255],
        ];

        $this->write2DBarcode(
            self::URL_CONSULTA . $this->dados['chaveAcesso'],
            'QRCODE,M',
            $x,
            $y,
            self::QR_TAMANHO,
            self::QR_TAMANHO,
            $estilo,
            'N',
        );

        // O texto de autenticidade fica sob o QR, dentro do bloco de
        // identificação (NT 008, item 2.4.3): 3 linhas de 6 pt.
        $this->SetFont($this->fonteConteudo, '', 5);
        $this->SetXY($x - 4.6, $y + self::QR_TAMANHO + 0.4);
        $this->MultiCell(33.8, 1.9, self::TEXTO_QR, 0, 'L');
    }

    private function prestador(): void
    {
        $p = $this->dados['prestador'];

        $this->tituloComCampos('PRESTADOR / FORNECEDOR', [
            ['CNPJ / CPF / NIF', (string) $p['cnpj']],
            ['Indicador Municipal (Inscrição)', (string) $p['inscricaoMunicipal']],
            ['Telefone', (string) $p['telefone']],
        ]);

        $this->linhaCampos([
            ['Nome / Nome Empresarial', (string) $p['nome'], 102.1],
            ['Município / Sigla UF', (string) $p['endereco']['municipio'], 51.0],
            ['Código IBGE / CEP', $p['endereco']['codigoIbge'] . ' / ' . $p['endereco']['cep'], 51.0],
        ]);

        $this->linhaCampos([
            ['Endereço', (string) $p['endereco']['logradouro'], 102.1],
            ['E-mail', (string) $p['email'], 102.0],
        ]);

        $this->linhaCampos([
            ['Simples Nacional na Data de Competência', (string) $p['simplesNacional'], 102.1],
            ['Regime de Apuração Tributária pelo SN', (string) $p['regimeApuracao'], 102.0],
        ]);
    }

    private function tomador(): void
    {
        $t = $this->dados['tomador'] ?? null;

        if ($t === null) {
            $this->linhaSuprimida('TOMADOR/ADQUIRENTE DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e');

            return;
        }

        $this->tituloComCampos('TOMADOR / ADQUIRENTE', [
            ['CNPJ / CPF / NIF', (string) $t['cnpj']],
            ['Indicador Municipal (Inscrição)', (string) $t['inscricaoMunicipal']],
            ['Telefone', (string) $t['telefone']],
        ]);

        $this->linhaCampos([
            ['Nome / Nome Empresarial', (string) $t['nome'], 102.1],
            ['Município / Sigla UF', (string) $t['endereco']['municipio'], 51.0],
            ['Código IBGE / CEP', $t['endereco']['codigoIbge'] . ' / ' . $t['endereco']['cep'], 51.0],
        ]);

        $this->linhaCampos([
            ['Endereço', (string) $t['endereco']['logradouro'], 102.1],
            ['E-mail', (string) $t['email'], 102.0],
        ]);
    }

    private function destinatario(): void
    {
        $d = $this->dados['destinatario'] ?? null;

        if ($d === null) {
            $this->linhaSuprimida('DESTINATÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e');

            return;
        }

        if (($d['ehProprioTomador'] ?? false) === true) {
            $this->linhaSuprimida('O DESTINATÁRIO É O PRÓPRIO TOMADOR/ADQUIRENTE DA OPERAÇÃO');

            return;
        }

        $this->tituloComCampos('DESTINATÁRIO DA OPERAÇÃO', [
            ['CNPJ / CPF / NIF', (string) $d['cnpj']],
            ['', ''],
            ['Telefone', (string) $d['telefone']],
        ]);

        $this->linhaCampos([
            ['Nome / Nome Empresarial', (string) $d['nome'], 102.1],
            ['Município / Sigla UF', (string) $d['endereco']['municipio'], 51.0],
            ['Código IBGE / CEP', $d['endereco']['codigoIbge'] . ' / ' . $d['endereco']['cep'], 51.0],
        ]);

        $this->linhaCampos([
            ['Endereço', (string) $d['endereco']['logradouro'], 102.1],
            ['E-mail', (string) $d['email'], 102.0],
        ]);
    }

    private function intermediario(): void
    {
        $i = $this->dados['intermediario'] ?? null;

        if ($i === null) {
            $this->linhaSuprimida('INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e');

            return;
        }

        $this->tituloComCampos('INTERMEDIÁRIO DA OPERAÇÃO', [
            ['CNPJ / CPF / NIF', (string) $i['cnpj']],
            ['Indicador Municipal (Inscrição)', (string) $i['inscricaoMunicipal']],
            ['Telefone', (string) $i['telefone']],
        ]);

        $this->linhaCampos([
            ['Nome / Nome Empresarial', (string) $i['nome'], 102.1],
            ['Município / Sigla UF', (string) $i['endereco']['municipio'], 51.0],
            ['Código IBGE / CEP', $i['endereco']['codigoIbge'] . ' / ' . $i['endereco']['cep'], 51.0],
        ]);

        $this->linhaCampos([
            ['Endereço', (string) $i['endereco']['logradouro'], 102.1],
            ['E-mail', (string) $i['email'], 102.0],
        ]);
    }

    private function servico(): void
    {
        $s = $this->dados['servico'];

        $this->tituloComCampos('SERVIÇO PRESTADO', [
            ['Código de Tributação Nacional/Municipal', (string) $s['codigoTributacao']],
            ['Código da NBS', (string) $s['codigoNBS']],
            ['Local da Prestação / Sigla UF / País', (string) $s['localPrestacao']],
        ]);

        // A descrição do código não tem label no DANFSe (NT 008, item 2.4.5).
        $this->caixa(self::MARGEM, $this->cursorY, self::LARGURA_UTIL, 3.8);
        $this->SetFont($this->fonteConteudo, '', self::PT_CONTEUDO);
        $this->SetXY(self::COL_X[0] + 0.8, $this->cursorY + 0.6);
        $this->Cell(202.4, 2.6, $this->truncar((string) $s['descricaoCodigo'], 170), 0, 0, 'L');
        $this->cursorY += 3.8;

        $this->campoMultilinha('Descrição do Serviço', (string) $s['descricao'], 13.0);
    }

    private function tributacaoMunicipal(): void
    {
        $t = $this->dados['issqn'];

        if (($t['sujeitoAoIssqn'] ?? true) === false) {
            $this->linhaSuprimida('TRIBUTAÇÃO MUNICIPAL (ISSQN) - OPERAÇÃO NÃO SUJEITA AO ISSQN');

            return;
        }

        $this->tituloComCampos('TRIBUTAÇÃO MUNICIPAL (ISSQN)', [
            ['Tipo de Tributação do ISSQN', (string) $t['tributacao']],
            ['Município / Sigla UF / País de Incidência do ISSQN', (string) $t['municipioIncidencia'], 102.0],
        ]);

        $this->linhaCampos([
            ['Regime Especial de Tributação do ISSQN', (string) $t['regimeEspecial'], 51.0],
            ['Tipo de Imunidade do ISSQN', (string) $t['tipoImunidade'], 51.0],
            ['Suspensão da Exigibilidade do ISSQN', (string) $t['suspensaoExigibilidade'], 51.0],
            ['Número Processo Suspensão', (string) $t['numeroProcessoSuspensao'], 51.0],
        ]);

        $this->linhaCampos([
            ['Benefício Municipal', (string) $t['beneficioMunicipal'], 51.0],
            ['Cálculo do BM', (string) $t['calculoBM'], 51.0],
            ['Total Deduções/Reduções', (string) $t['totalDeducoes'], 51.0],
            ['Desconto Incondicionado', (string) $t['descontoIncondicionado'], 51.0],
        ]);

        $this->linhaCampos([
            ['BC ISSQN', (string) $t['baseCalculo'], 51.0],
            ['Alíquota Aplicada', (string) $t['aliquota'], 51.0],
            ['Retenção do ISSQN', (string) $t['retencao'], 51.0],
            ['ISSQN Apurado', (string) $t['valorApurado'], 51.0],
        ]);
    }

    private function tributacaoFederal(): void
    {
        $f = $this->dados['federal'];

        $this->tituloComCampos('TRIBUTAÇÃO FEDERAL (EXCETO CBS)', [
            ['IRRF', (string) $f['irrf']],
            ['Contribuição Previdenciária - Retida', (string) $f['contribuicaoPrevidenciaria']],
            ['Contribuições Sociais - Retidas', (string) $f['contribuicoesSociais']],
        ]);

        // Linha impressa apenas para competências até o fim de 2026
        // (NT 008, nota 6).
        if ($this->competenciaAte2026()) {
            $this->linhaCampos([
                ['PIS - Débito Apuração Própria', (string) $f['pis'], 51.0],
                ['COFINS - Débito Apuração Própria', (string) $f['cofins'], 51.0],
                ['Descrição Contrib. Sociais - Retidas', (string) $f['descricaoContribuicoes'], 102.0],
            ]);
        }
    }

    private function tributacaoIbsCbs(): void
    {
        $t = $this->dados['ibscbs'];

        $indicador = implode(' / ', [
            (string) $t['indicadorOperacao'],
            (string) $t['codigoIbgeIncidencia'],
            (string) $t['municipioIncidencia'],
        ]);

        $this->tituloComCampos('TRIBUTAÇÃO IBS / CBS', [
            ['CST / cClassTrib', $t['cst'] . ' / ' . $t['cClassTrib']],
            ['Indicador de Operação / Código IBGE Incidência / Município Incidência / Sigla UF', $indicador, 102.0],
        ]);

        $this->linhaCampos([
            ['Exclusões e Reduções da Base de Cálculo', (string) $t['exclusoesReducoes'], 51.0],
            ['Base de Cálculo Após Exclusões e Reduções', (string) $t['baseCalculo'], 51.0],
            [
                'Red. Alíquota IBS / Red. Alíquota CBS',
                $t['reducaoAliquotaIbsUf'] . ' / ' . $t['reducaoAliquotaIbsMun'] . ' / ' . $t['reducaoAliquotaCbs'],
                51.0,
            ],
            ['Alíquota - IBS UF / IBS Mun', $t['aliquotaIbsUf'] . ' / ' . $t['aliquotaIbsMun'], 51.0],
        ]);

        $this->linhaCampos([
            ['Alíq. Efetiva Municipal - IBS', (string) $t['aliquotaEfetivaIbsMun'], 51.0],
            ['Valor Apurado Municipal - IBS', (string) $t['valorApuradoIbsMun'], 51.0],
            ['Alíq. Efetiva Estadual - IBS', (string) $t['aliquotaEfetivaIbsUf'], 51.0],
            ['Valor Apurado Estadual - IBS', (string) $t['valorApuradoIbsUf'], 51.0],
        ]);

        $this->linhaCampos([
            ['Valor Total Apurado - IBS', (string) $t['valorTotalIbs'], 51.0],
            ['Alíquota - CBS', (string) $t['aliquotaCbs'], 51.0],
            ['Alíquota Efetiva - CBS', (string) $t['aliquotaEfetivaCbs'], 51.0],
            ['Valor Total Apurado - CBS', (string) $t['valorTotalCbs'], 51.0],
        ]);
    }

    private function valorTotal(): void
    {
        $v = $this->dados['valores'];

        $this->tituloComCampos('VALOR TOTAL DA NFS-e', [
            ['VALOR DA OPERAÇÃO / SERVIÇO', DANFSeDados::formatarValor((float) $v['valorServico'])],
            ['Desconto Incondicionado', (string) $v['descontoIncondicionado']],
            ['Desconto Condicionado', (string) $v['descontoCondicionado']],
        ]);

        // "VALOR LÍQUIDO DA NFS-e + IBS/CBS" leva sombreamento (NT 008, 2.2.3).
        $this->linhaCampos([
            ['Total das Retenções (ISSQN / Federais)', (string) $v['totalRetencoes'], 51.0],
            ['VALOR LÍQUIDO DA NFS-e', DANFSeDados::formatarValor((float) $v['valorLiquido']), 51.0],
            ['Total do IBS/CBS', DANFSeDados::formatarValor((float) $v['totalIbsCbs']), 51.0],
            [
                'VALOR LÍQUIDO DA NFS-e + IBS/CBS',
                DANFSeDados::formatarValor((float) $v['valorLiquidoComIbsCbs']),
                51.0,
                true,
            ],
        ]);
    }

    private function informacoesComplementares(): void
    {
        $this->tituloBloco('INFORMAÇÕES COMPLEMENTARES');

        /** @var array<int, string> $partes */
        $partes = $this->dados['informacoesComplementares'];

        $altura = $this->alturaRestante();
        $this->caixa(self::MARGEM, $this->cursorY, self::LARGURA_UTIL, $altura);

        $this->SetFont($this->fonteConteudo, '', self::PT_CONTEUDO);
        $this->SetXY(self::COL_X[0] + 0.8, $this->cursorY + 0.8);
        $this->MultiCell(202.4, 2.6, implode(' | ', $partes), 0, 'L');

        $this->cursorY += $altura;
    }

    private function canhoto(): void
    {
        if (!$this->exibirCanhoto) {
            return;
        }

        $chave = $this->dados['numero'] . ' / ' . $this->dados['chaveAcesso'];

        $this->linhaCampos([
            ['DATA CIENTIFICAÇÃO:', '', 51.0],
            ['IDENTIFICAÇÃO E ASSINATURA', '', 51.0],
            ['N° NFS-e / CHAVE NFS-e', $chave, 102.0],
        ], maiusculo: true);
    }

    // -------------------------------------------------------------- desenho

    /**
     * Título de bloco seguido dos campos da mesma linha, como no leiaute: o
     * título ocupa a primeira coluna e os campos as demais.
     *
     * @param array<int, array{0: string, 1: string, 2?: float, 3?: bool}> $campos
     */
    private function tituloComCampos(string $titulo, array $campos): void
    {
        $altura = self::ALTURA_LINHA;
        $x = self::COL_X[0];

        // Primeira coluna: título do bloco, com sombreamento
        $this->caixa($x, $this->cursorY, self::COL_LARGURA, $altura, true);
        $this->SetFont($this->fonteTitulos, 'B', self::PT_TITULO_BLOCO);
        $this->SetXY($x + 0.8, $this->cursorY + 1.4);
        $this->Cell(self::COL_LARGURA - 1.6, 3, $titulo, 0, 0, 'L');

        $x += self::COL_LARGURA;
        foreach ($campos as $campo) {
            $largura = $campo[2] ?? self::COL_LARGURA;
            $this->campo($x, $this->cursorY, $largura, $campo[0], $campo[1], sombreado: $campo[3] ?? false);
            $x += $largura;
        }

        $this->cursorY += $altura;
    }

    /**
     * @param array<int, array{0: string, 1: string, 2?: float, 3?: bool}> $campos
     */
    private function linhaCampos(array $campos, bool $maiusculo = false): void
    {
        $x = self::COL_X[0];

        foreach ($campos as $campo) {
            $largura = $campo[2] ?? self::COL_LARGURA;
            $this->campo($x, $this->cursorY, $largura, $campo[0], $campo[1], $maiusculo, $campo[3] ?? false);
            $x += $largura;
        }

        $this->cursorY += self::ALTURA_LINHA;
    }

    private function campo(
        float $x,
        float $y,
        float $largura,
        string $label,
        string $valor,
        bool $maiusculo = false,
        bool $sombreado = false,
    ): void {
        $this->caixa($x, $y, $largura, self::ALTURA_LINHA, $sombreado);

        if ($label !== '') {
            $this->SetFont($this->fonteTitulos, 'B', $maiusculo ? self::PT_TITULO_BLOCO : self::PT_LABEL);
            $this->SetXY($x + 0.8, $y + 0.5);
            $this->Cell($largura - 1.6, 2.4, $this->truncar($label, (int) ($largura * 1.1)), 0, 0, 'L');
        }

        $this->SetFont($this->fonteConteudo, '', self::PT_CONTEUDO);
        $this->SetXY($x + 0.8, $y + 3.2);
        $this->Cell($largura - 1.6, 2.8, $this->truncar($valor, (int) ($largura * 0.95)), 0, 0, 'L');
    }

    private function campoMultilinha(string $label, string $valor, float $altura): void
    {
        $this->caixa(self::MARGEM, $this->cursorY, self::LARGURA_UTIL, $altura);

        $this->SetFont($this->fonteTitulos, 'B', self::PT_LABEL);
        $this->SetXY(self::COL_X[0] + 0.8, $this->cursorY + 0.5);
        $this->Cell(202.4, 2.4, $label, 0, 0, 'L');

        $this->SetFont($this->fonteConteudo, '', self::PT_CONTEUDO);
        $this->SetXY(self::COL_X[0] + 0.8, $this->cursorY + 3.2);
        $this->MultiCell(202.4, 2.8, $this->truncar($valor, 1300), 0, 'L');

        $this->cursorY += $altura;
    }

    /**
     * Linha única para blocos suprimidos (NT 008, item 2.3 e notas 2 a 4).
     */
    private function linhaSuprimida(string $texto): void
    {
        $altura = self::ALTURA_LINHA_SUPRIMIDA;
        $this->caixa(self::MARGEM, $this->cursorY, self::LARGURA_UTIL, $altura);

        $this->SetFont($this->fonteTitulos, 'B', self::PT_TITULO_BLOCO);
        $this->SetXY(self::COL_X[0], $this->cursorY + 0.3);
        $this->Cell(self::LARGURA_UTIL, 2.6, $texto, 0, 0, 'C');

        $this->cursorY += $altura;
    }

    private function tituloBloco(string $titulo): void
    {
        $altura = self::ALTURA_TITULO_BLOCO - 2.5;
        $this->caixa(self::MARGEM, $this->cursorY, self::LARGURA_UTIL, $altura, true);

        $this->SetFont($this->fonteTitulos, 'B', self::PT_TITULO_BLOCO);
        $this->SetXY(self::COL_X[0] + 0.8, $this->cursorY + 0.5);
        $this->Cell(202.4, 2.8, $titulo, 0, 0, 'L');

        $this->cursorY += $altura;
    }

    /**
     * Retângulo com borda de 0,5 pt e, opcionalmente, sombreamento cinza claro
     * (5% de densidade), conforme o item 2.2.3 da NT.
     */
    private function caixa(float $x, float $y, float $largura, float $altura, bool $sombreado = false): void
    {
        $this->SetLineWidth(0.18);

        if ($sombreado) {
            $this->SetFillColor(242, 242, 242);
            $this->Rect($x, $y, $largura, $altura, 'FD');

            return;
        }

        $this->Rect($x, $y, $largura, $altura, 'D');
    }

    /**
     * Borda externa de 1 pt em volta de todo o documento (item 2.2.3).
     */
    private function bordaPagina(): void
    {
        $this->SetLineWidth(0.35);
        $this->Rect(self::MARGEM, self::MARGEM, self::LARGURA_UTIL, $this->cursorY - self::MARGEM, 'D');
    }

    private function aplicarMarcaDagua(): void
    {
        $texto = $this->marcaDagua ?? $this->marcaDaguaPorSituacao();
        if ($texto === null) {
            return;
        }

        $this->StartTransform();
        $this->Rotate(45, 105, 150);
        $this->SetFont($this->fonteTitulos, '', 50);
        $this->SetTextColor(166, 166, 166);
        $this->SetXY(35, 140);
        $this->Cell(140, 20, $texto, 0, 0, 'C');
        $this->StopTransform();
        $this->SetTextColor(0, 0, 0);
    }

    /**
     * Logomarca oficial da NFS-e distribuída com a biblioteca.
     */
    private function logoPadrao(): ?string
    {
        $caminho = dirname(__DIR__, 3) . '/assets/logo-nfse.png';

        return file_exists($caminho) ? $caminho : null;
    }

    private function marcaDaguaPorSituacao(): ?string
    {
        $situacao = (string) ($this->dados['situacao'] ?? '');

        return match (true) {
            str_contains($situacao, 'Cancelada') => 'CANCELADA',
            str_contains($situacao, 'Substituída') => 'SUBSTITUÍDA',
            default => null,
        };
    }

    /**
     * Espaço vertical disponível até o canhoto (ou o fim da página), usado para
     * que as informações complementares absorvam a folga do documento.
     */
    private function alturaRestante(): float
    {
        $limite = 297.0 - self::MARGEM;
        if ($this->exibirCanhoto) {
            $limite -= self::ALTURA_LINHA;
        }

        return max(8.0, $limite - $this->cursorY);
    }

    private function competenciaAte2026(): bool
    {
        $competencia = (string) ($this->dados['competencia'] ?? '');
        if (!preg_match('#/(\d{4})$#', $competencia, $m)) {
            return true;
        }

        return (int) $m[1] <= 2026;
    }

    /**
     * Corta o texto no limite do campo, sinalizando com reticências
     * (NT 008, item 2.1).
     */
    private function truncar(string $texto, int $limite): string
    {
        if ($limite <= 0 || mb_strlen($texto) <= $limite) {
            return $texto;
        }

        return mb_substr($texto, 0, max(1, $limite - 3)) . '...';
    }
}
