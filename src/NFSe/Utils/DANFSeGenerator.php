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
 * posições verticais ("sup") do item 2.4.5 da NT são dadas em centímetros e
 * usadas aqui como coordenadas absolutas em milímetros (GRADE) — os "sup" da
 * NT não são a soma das alturas das linhas, então um cursor acumulativo nunca
 * fecharia a grade. Cada linha ocupa o espaço até o "sup" seguinte.
 *
 * Blocos suprimidos (NT, item 2.3) viram uma faixa única e a altura
 * economizada é absorvida pelo quadro "Descrição do Serviço" (supressões
 * acima dele) ou pelas "Informações Complementares" (supressões abaixo),
 * como faz o portal nacional. O canhoto é fixo no pé do formulário.
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

    // Colunas do leiaute (NT 008, item 2.4.5): X em 0,30 / 5,41 / 10,51 /
    // 15,62 cm; o último item é a borda direita do formulário.
    private const COL_X = [3.0, 54.1, 105.1, 156.2, 207.0];

    /**
     * Posições verticais ("sup") do item 2.4.5 da NT, em milímetros. A altura
     * de cada linha é a distância até a linha seguinte da grade.
     */
    private const GRADE = [
        'cabecalho' => 3.0,
        'chave' => 14.8,
        'id.1' => 22.7,
        'id.2' => 29.6,
        'id.3' => 36.5,
        'prest.titulo' => 43.4,
        'prest.nome' => 49.8,
        'prest.endereco' => 56.2,
        'prest.regime' => 62.8,
        'toma.titulo' => 69.2,
        'toma.nome' => 75.6,
        'toma.endereco' => 82.2,
        'dest.titulo' => 88.6,
        'dest.nome' => 95.0,
        'dest.endereco' => 101.6,
        'interm.titulo' => 108.0,
        'interm.nome' => 114.4,
        'interm.endereco' => 120.9,
        'serv.titulo' => 127.4,
        'serv.descCodigo' => 133.9,
        'serv.descricao' => 137.9,
        'issqn.titulo' => 144.3,
        'issqn.regime' => 150.8,
        'issqn.beneficio' => 157.3,
        'issqn.bc' => 163.7,
        'fed.titulo' => 170.2,
        'fed.pis' => 176.7,
        'ibs.titulo' => 183.2,
        'ibs.exclusoes' => 189.6,
        'ibs.efetiva' => 196.1,
        'ibs.total' => 202.6,
        'valor.titulo' => 209.0,
        'valor.liquido' => 215.9,
        'infcompl.titulo' => 222.7,
        'infcompl.conteudo' => 226.8,
        'canhoto' => 281.0,
        'fim' => 287.7,
    ];

    // QR Code (NT 008, item 2.4.3): 1,52 cm em X 17,48 / Y 1,67; texto de
    // autenticidade no quadro complementar de 4,72 cm em X 15,80 / Y 3,36.
    private const QR_TAMANHO = 15.2;
    private const QR_X = 174.8;
    private const QR_Y = 16.7;
    private const QR_TEXTO_X = 158.0;
    private const QR_TEXTO_Y = 33.6;
    private const QR_TEXTO_LARGURA = 47.2;

    private const ALTURA_LINHA_SUPRIMIDA = 3.2;

    // Espessuras (NT 008, item 2.2.3): divisórias 0,5 pt; borda da página 1 pt
    private const LINHA_DIVISORIA = 0.176;
    private const LINHA_BORDA = 0.353;

    // Fontes (NT 008, item 2.4): Arial para títulos, Microsoft Sans Serif para
    // conteúdo. O TCPDF não embarca essas famílias; helvetica é o substituto
    // métrico equivalente.
    private const PT_TITULO_BLOCO = 7;
    private const PT_LABEL = 6;
    private const PT_CONTEUDO = 7;

    // Limites de caracteres por campo (NT 008, item 2.4.5): acima deles o
    // texto é cortado com reticências.
    private const LIM_COLUNA = 37;
    private const LIM_COLUNA_DUPLA = 77;
    private const LIM_DESC_CODIGO = 167;
    private const LIM_DESC_SERVICO = 1297;
    private const LIM_INF_COMPL = 1997;

    /** @var array<string, mixed> */
    private array $dados = [];

    /**
     * Altura acumulada economizada por supressões (positivo = blocos sobem).
     */
    private float $desloc = 0.0;

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
        $this->SetDrawColor(0, 0, 0);
        $this->SetTextColor(0, 0, 0);
        $this->setCellPaddings(0, 0, 0, 0);

        $this->desloc = 0.0;

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

    // ------------------------------------------------------------- coordenadas

    /**
     * Posição vertical efetiva da linha, já descontadas as supressões.
     */
    private function y(string $linha): float
    {
        return self::GRADE[$linha] - $this->desloc;
    }

    /**
     * Distância vertical entre duas linhas da grade (altura de blocos/linhas).
     */
    private function altura(string $de, string $ate): float
    {
        return self::GRADE[$ate] - self::GRADE[$de];
    }

    /**
     * Largura entre duas colunas da grade (a coluna 4 é a borda direita).
     */
    private function largura(int $de, int $ate): float
    {
        return self::COL_X[$ate] - self::COL_X[$de];
    }

    // ---------------------------------------------------------------- blocos

    private function cabecalho(): void
    {
        $y = self::GRADE['cabecalho'];
        $altura = $this->altura('cabecalho', 'chave');

        // Quadro único, sem divisórias internas nem sombreamento, como no
        // DANFSe do portal nacional — o fundo branco também deixa a logomarca
        // (PNG sem canal alfa) integrada ao cabeçalho.
        $this->caixa(self::COL_X[0], $y, self::LARGURA_UTIL, $altura);

        if ($this->logoPath !== null && file_exists($this->logoPath)) {
            // Logomarca da NFS-e (NT 008, item 2.4.3): 4,00 x 0,85 cm em
            // X 0,49 / Y 0,44. PNGs com canal alfa exigiriam GD/Imagick, que a
            // lib não impõe como dependência — por isso a imagem embarcada já
            // vem sem transparência.
            try {
                $this->Image($this->logoPath, 4.9, 4.4, 40.0, 8.5, '', '', '', true, 300, '', false, false, 0, true);
            } catch (\Throwable) {
                // Logo é opcional: falha ao carregar não impede o documento
            }
        }

        $homologacao = ($this->dados['ambiente'] ?? '1') === '2';

        $tituloX = self::COL_X[1];
        $tituloLargura = $this->largura(1, 3);

        $this->SetFont($this->fonteTitulos, 'B', 9);
        $this->SetXY($tituloX, $y + ($homologacao ? 0.8 : 2.0));
        $this->Cell($tituloLargura, 4, 'DANFSe v2.0', 0, 0, 'C');

        $this->SetXY($tituloX, $y + ($homologacao ? 4.3 : 5.5));
        $this->Cell($tituloLargura, 4, 'Documento Auxiliar da NFS-e', 0, 0, 'C');

        if ($homologacao) {
            // Vermelho sólido M100/Y100 (NT 008, item 2.4.3)
            $this->SetTextColor(255, 0, 0);
            $this->SetXY($tituloX, $y + 7.8);
            $this->Cell($tituloLargura, 4, 'NFS-e SEM VALIDADE JURÍDICA', 0, 0, 'C');
            $this->SetTextColor(0, 0, 0);
        }

        $municipio = (string) ($this->dados['municipioEmissorComUf'] ?? DANFSeDados::TRACO);
        $this->SetFont($this->fonteConteudo, '', 8);
        $this->SetXY(self::COL_X[3] + 0.8, $y + 0.6);
        $this->MultiCell($this->largura(3, 4) - 1.6, 3.2, 'Município: ' . $municipio, 0, 'L');

        $this->SetFont($this->fonteConteudo, '', 6);
        $this->SetXY(self::COL_X[3] + 0.8, $y + 6.9);
        $this->Cell($this->largura(3, 4) - 1.6, 2.4, 'Ambiente Gerador: ' . ($this->dados['ambienteGerador'] ?? ''), 0, 0, 'L');
        $this->SetXY(self::COL_X[3] + 0.8, $y + 9.3);
        $this->Cell($this->largura(3, 4) - 1.6, 2.4, 'Tipo de Ambiente: ' . ($this->dados['ambiente'] ?? ''), 0, 0, 'L');
    }

    /**
     * Bloco "DADOS DA NFS-e": chave de acesso, numeração e QR Code à direita.
     */
    private function identificacao(): void
    {
        // Quadro do QR Code e do texto de autenticidade, à direita dos campos
        $this->caixa(self::COL_X[3], self::GRADE['chave'], $this->largura(3, 4), $this->altura('chave', 'prest.titulo'));

        // Chave de acesso em bloco único de 50 dígitos (NT 008, item 2.1.1)
        $this->campo(
            self::COL_X[0],
            self::GRADE['chave'],
            $this->largura(0, 3),
            $this->altura('chave', 'id.1'),
            'CHAVE DE ACESSO DA NFS-e',
            (string) $this->dados['chaveAcesso'],
            limite: 50,
            maiusculo: true,
        );

        $this->linhaCampos(self::GRADE['id.1'], $this->altura('id.1', 'id.2'), [
            ['NÚMERO DA NFS-e', (string) $this->dados['numero'], $this->largura(0, 1)],
            ['COMPETÊNCIA DA NFS-e', (string) $this->dados['competencia'], $this->largura(1, 2)],
            ['DATA E HORA DA EMISSÃO DA NFS-e', (string) $this->dados['dhEmissao'], $this->largura(2, 3)],
        ], maiusculo: true);

        $this->linhaCampos(self::GRADE['id.2'], $this->altura('id.2', 'id.3'), [
            ['NÚMERO DA DPS', (string) $this->dados['numeroDPS'], $this->largura(0, 1)],
            ['SÉRIE DA DPS', (string) $this->dados['serie'], $this->largura(1, 2)],
            ['DATA E HORA DA EMISSÃO DA DPS', (string) $this->dados['dhEmissaoDPS'], $this->largura(2, 3)],
        ], maiusculo: true);

        $this->linhaCampos(self::GRADE['id.3'], $this->altura('id.3', 'prest.titulo'), [
            ['EMITENTE DA NFS-e', (string) $this->dados['emitente'], $this->largura(0, 1), true],
            ['SITUAÇÃO DA NFS-e', (string) $this->dados['situacao'], $this->largura(1, 2)],
            ['FINALIDADE', (string) $this->dados['finalidade'], $this->largura(2, 3)],
        ], maiusculo: true);

        $this->qrCode();
    }

    private function qrCode(): void
    {
        $estilo = [
            'border' => false,
            'padding' => 0,
            'fgcolor' => [0, 0, 0],
            'bgcolor' => [255, 255, 255],
        ];

        $this->write2DBarcode(
            self::URL_CONSULTA . $this->dados['chaveAcesso'],
            'QRCODE,M',
            self::QR_X,
            self::QR_Y,
            self::QR_TAMANHO,
            self::QR_TAMANHO,
            $estilo,
            'N',
        );

        // Texto de autenticidade sob o QR (NT 008, item 2.4.3): 3 linhas de
        // 6 pt no quadro complementar.
        $this->SetFont($this->fonteConteudo, '', 6);
        $this->SetXY(self::QR_TEXTO_X, self::QR_TEXTO_Y);
        $this->MultiCell(self::QR_TEXTO_LARGURA, 2.27, self::TEXTO_QR, 0, 'L');
    }

    private function prestador(): void
    {
        $p = $this->dados['prestador'];

        $this->tituloComCampos('prest.titulo', 'prest.nome', 'PRESTADOR / FORNECEDOR', [
            ['CNPJ / CPF / NIF', (string) $p['cnpj'], $this->largura(1, 2)],
            ['Indicador Municipal (Inscrição)', (string) $p['inscricaoMunicipal'], $this->largura(2, 3)],
            ['Telefone', (string) $p['telefone'], $this->largura(3, 4)],
        ]);

        $this->linhaCampos($this->y('prest.nome'), $this->altura('prest.nome', 'prest.endereco'), [
            ['Nome / Nome Empresarial', (string) $p['nome'], $this->largura(0, 2), false, self::LIM_COLUNA_DUPLA],
            ['Município / Sigla UF', (string) $p['endereco']['municipio'], $this->largura(2, 3)],
            ['Código IBGE / CEP', $p['endereco']['codigoIbge'] . ' / ' . $p['endereco']['cep'], $this->largura(3, 4)],
        ]);

        $this->linhaCampos($this->y('prest.endereco'), $this->altura('prest.endereco', 'prest.regime'), [
            ['Endereço', (string) $p['endereco']['logradouro'], $this->largura(0, 2), false, self::LIM_COLUNA_DUPLA],
            ['E-mail', (string) $p['email'], $this->largura(2, 4), false, self::LIM_COLUNA_DUPLA],
        ]);

        $this->linhaCampos($this->y('prest.regime'), $this->altura('prest.regime', 'toma.titulo'), [
            ['Simples Nacional na Data de Competência', (string) $p['simplesNacional'], $this->largura(0, 2), false, self::LIM_COLUNA],
            ['Regime de Apuração Tributária pelo SN', (string) $p['regimeApuracao'], $this->largura(2, 4), false, self::LIM_COLUNA_DUPLA],
        ]);
    }

    private function tomador(): void
    {
        $t = $this->dados['tomador'] ?? null;

        if ($t === null) {
            $this->blocoSuprimido('toma.titulo', 'dest.titulo', 'TOMADOR/ADQUIRENTE DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e');

            return;
        }

        $this->tituloComCampos('toma.titulo', 'toma.nome', 'TOMADOR / ADQUIRENTE', [
            ['CNPJ / CPF / NIF', (string) $t['cnpj'], $this->largura(1, 2)],
            ['Indicador Municipal (Inscrição)', (string) $t['inscricaoMunicipal'], $this->largura(2, 3)],
            ['Telefone', (string) $t['telefone'], $this->largura(3, 4)],
        ]);

        $this->linhaCampos($this->y('toma.nome'), $this->altura('toma.nome', 'toma.endereco'), [
            ['Nome / Nome Empresarial', (string) $t['nome'], $this->largura(0, 2), false, self::LIM_COLUNA_DUPLA],
            ['Município / Sigla UF', (string) $t['endereco']['municipio'], $this->largura(2, 3)],
            ['Código IBGE / CEP', $t['endereco']['codigoIbge'] . ' / ' . $t['endereco']['cep'], $this->largura(3, 4)],
        ]);

        $this->linhaCampos($this->y('toma.endereco'), $this->altura('toma.endereco', 'dest.titulo'), [
            ['Endereço', (string) $t['endereco']['logradouro'], $this->largura(0, 2), false, self::LIM_COLUNA_DUPLA],
            ['E-mail', (string) $t['email'], $this->largura(2, 4), false, self::LIM_COLUNA_DUPLA],
        ]);
    }

    private function destinatario(): void
    {
        $d = $this->dados['destinatario'] ?? null;

        if ($d === null) {
            $this->blocoSuprimido('dest.titulo', 'interm.titulo', 'DESTINATÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e');

            return;
        }

        if (($d['ehProprioTomador'] ?? false) === true) {
            $this->blocoSuprimido('dest.titulo', 'interm.titulo', 'O DESTINATÁRIO É O PRÓPRIO TOMADOR/ADQUIRENTE DA OPERAÇÃO');

            return;
        }

        $this->tituloComCampos('dest.titulo', 'dest.nome', 'DESTINATÁRIO DA OPERAÇÃO', [
            ['CNPJ / CPF / NIF', (string) $d['cnpj'], $this->largura(1, 2)],
            ['', '', $this->largura(2, 3)],
            ['Telefone', (string) $d['telefone'], $this->largura(3, 4)],
        ]);

        $this->linhaCampos($this->y('dest.nome'), $this->altura('dest.nome', 'dest.endereco'), [
            ['Nome / Nome Empresarial', (string) $d['nome'], $this->largura(0, 2), false, self::LIM_COLUNA_DUPLA],
            ['Município / Sigla UF', (string) $d['endereco']['municipio'], $this->largura(2, 3)],
            ['Código IBGE / CEP', $d['endereco']['codigoIbge'] . ' / ' . $d['endereco']['cep'], $this->largura(3, 4)],
        ]);

        $this->linhaCampos($this->y('dest.endereco'), $this->altura('dest.endereco', 'interm.titulo'), [
            ['Endereço', (string) $d['endereco']['logradouro'], $this->largura(0, 2), false, self::LIM_COLUNA_DUPLA],
            ['E-mail', (string) $d['email'], $this->largura(2, 4), false, self::LIM_COLUNA_DUPLA],
        ]);
    }

    private function intermediario(): void
    {
        $i = $this->dados['intermediario'] ?? null;

        if ($i === null) {
            $this->blocoSuprimido('interm.titulo', 'serv.titulo', 'INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e');

            return;
        }

        $this->tituloComCampos('interm.titulo', 'interm.nome', 'INTERMEDIÁRIO DA OPERAÇÃO', [
            ['CNPJ / CPF / NIF', (string) $i['cnpj'], $this->largura(1, 2)],
            ['Indicador Municipal (Inscrição)', (string) $i['inscricaoMunicipal'], $this->largura(2, 3)],
            ['Telefone', (string) $i['telefone'], $this->largura(3, 4)],
        ]);

        $this->linhaCampos($this->y('interm.nome'), $this->altura('interm.nome', 'interm.endereco'), [
            ['Nome / Nome Empresarial', (string) $i['nome'], $this->largura(0, 2), false, self::LIM_COLUNA_DUPLA],
            ['Município / Sigla UF', (string) $i['endereco']['municipio'], $this->largura(2, 3)],
            ['Código IBGE / CEP', $i['endereco']['codigoIbge'] . ' / ' . $i['endereco']['cep'], $this->largura(3, 4)],
        ]);

        $this->linhaCampos($this->y('interm.endereco'), $this->altura('interm.endereco', 'serv.titulo'), [
            ['Endereço', (string) $i['endereco']['logradouro'], $this->largura(0, 2), false, self::LIM_COLUNA_DUPLA],
            ['E-mail', (string) $i['email'], $this->largura(2, 4), false, self::LIM_COLUNA_DUPLA],
        ]);
    }

    private function servico(): void
    {
        $s = $this->dados['servico'];

        $this->tituloComCampos('serv.titulo', 'serv.descCodigo', 'SERVIÇO PRESTADO', [
            ['Código de Tributação Nacional/Municipal', (string) $s['codigoTributacao'], $this->largura(1, 2)],
            ['Código da NBS', (string) $s['codigoNBS'], $this->largura(2, 3)],
            ['Local da Prestação / Sigla UF / País', (string) $s['localPrestacao'], $this->largura(3, 4), false, 42],
        ]);

        // A descrição do código não tem label no DANFSe (NT 008, item 2.4.5).
        $yDescCodigo = $this->y('serv.descCodigo');
        $this->caixa(self::COL_X[0], $yDescCodigo, self::LARGURA_UTIL, $this->altura('serv.descCodigo', 'serv.descricao'));
        $this->SetFont($this->fonteConteudo, '', self::PT_CONTEUDO);
        $this->SetXY(self::COL_X[0] + 0.8, $yDescCodigo + 0.7);
        $this->Cell(self::LARGURA_UTIL - 1.6, 2.6, $this->truncar((string) $s['descricaoCodigo'], self::LIM_DESC_CODIGO), 0, 0, 'L');

        // A altura economizada pelos blocos suprimidos acima é incorporada ao
        // quadro "Descrição do Serviço" (NT 008, item 2.3): os blocos abaixo
        // voltam às posições originais da grade.
        $altura = $this->altura('serv.descricao', 'issqn.titulo') + $this->desloc;
        $y = $this->y('serv.descricao');
        $this->desloc = 0.0;

        $this->caixa(self::COL_X[0], $y, self::LARGURA_UTIL, $altura);

        $this->SetFont($this->fonteTitulos, 'B', self::PT_LABEL);
        $this->SetXY(self::COL_X[0] + 0.8, $y + 0.5);
        $this->Cell(self::LARGURA_UTIL - 1.6, 2.4, 'Descrição do Serviço', 0, 0, 'L');

        $texto = $this->truncar((string) $s['descricao'], self::LIM_DESC_SERVICO);
        $this->SetFont($this->fonteConteudo, '', self::PT_CONTEUDO);
        $texto = $this->ajustarAoQuadro($texto, self::LARGURA_UTIL - 1.6, $altura - 3.4);
        $this->SetXY(self::COL_X[0] + 0.8, $y + 3.2);
        $this->MultiCell(self::LARGURA_UTIL - 1.6, 2.8, $texto, 0, 'L');
    }

    private function tributacaoMunicipal(): void
    {
        $t = $this->dados['issqn'];

        if (($t['sujeitoAoIssqn'] ?? true) === false) {
            $this->blocoSuprimido('issqn.titulo', 'fed.titulo', 'TRIBUTAÇÃO MUNICIPAL (ISSQN) - OPERAÇÃO NÃO SUJEITA AO ISSQN');

            return;
        }

        $this->tituloComCampos('issqn.titulo', 'issqn.regime', 'TRIBUTAÇÃO MUNICIPAL (ISSQN)', [
            ['Tipo de Tributação do ISSQN', (string) $t['tributacao'], $this->largura(1, 2)],
            ['Município / Sigla UF / País de Incidência do ISSQN', (string) $t['municipioIncidencia'], $this->largura(2, 4), false, 42],
        ]);

        // Linhas opcionais (NT 008, nota 5): suprimidas quando todos os campos
        // da linha estão sem dados no XML.
        if (($t['linhaRegimeVazia'] ?? false) === true) {
            $this->desloc += $this->altura('issqn.regime', 'issqn.beneficio');
        } else {
            $this->linhaCampos($this->y('issqn.regime'), $this->altura('issqn.regime', 'issqn.beneficio'), [
                ['Regime Especial de Tributação do ISSQN', (string) $t['regimeEspecial'], $this->largura(0, 1)],
                ['Tipo de Imunidade do ISSQN', (string) $t['tipoImunidade'], $this->largura(1, 2)],
                ['Suspensão da Exigibilidade do ISSQN', (string) $t['suspensaoExigibilidade'], $this->largura(2, 3)],
                ['Número Processo Suspensão', (string) $t['numeroProcessoSuspensao'], $this->largura(3, 4)],
            ]);
        }

        if (($t['linhaBeneficioVazia'] ?? false) === true) {
            $this->desloc += $this->altura('issqn.beneficio', 'issqn.bc');
        } else {
            $this->linhaCampos($this->y('issqn.beneficio'), $this->altura('issqn.beneficio', 'issqn.bc'), [
                ['Benefício Municipal', (string) $t['beneficioMunicipal'], $this->largura(0, 1)],
                ['Cálculo do BM', (string) $t['calculoBM'], $this->largura(1, 2)],
                ['Total Deduções/Reduções', (string) $t['totalDeducoes'], $this->largura(2, 3)],
                ['Desconto Incondicionado', (string) $t['descontoIncondicionado'], $this->largura(3, 4)],
            ]);
        }

        $this->linhaCampos($this->y('issqn.bc'), $this->altura('issqn.bc', 'fed.titulo'), [
            ['BC ISSQN', (string) $t['baseCalculo'], $this->largura(0, 1)],
            ['Alíquota Aplicada', (string) $t['aliquota'], $this->largura(1, 2)],
            ['Retenção do ISSQN', (string) $t['retencao'], $this->largura(2, 3)],
            ['ISSQN Apurado', (string) $t['valorApurado'], $this->largura(3, 4)],
        ]);
    }

    private function tributacaoFederal(): void
    {
        $f = $this->dados['federal'];

        $this->tituloComCampos('fed.titulo', 'fed.pis', 'TRIBUTAÇÃO FEDERAL (EXCETO CBS)', [
            ['IRRF', (string) $f['irrf'], $this->largura(1, 2)],
            ['Contribuição Previdenciária - Retida', (string) $f['contribuicaoPrevidenciaria'], $this->largura(2, 3)],
            ['Contribuições Sociais - Retidas', (string) $f['contribuicoesSociais'], $this->largura(3, 4)],
        ]);

        // Linha impressa apenas para competências até o fim de 2026
        // (NT 008, nota 6).
        if ($this->competenciaAte2026()) {
            $this->linhaCampos($this->y('fed.pis'), $this->altura('fed.pis', 'ibs.titulo'), [
                ['PIS - Débito Apuração Própria', (string) $f['pis'], $this->largura(0, 1)],
                ['COFINS - Débito Apuração Própria', (string) $f['cofins'], $this->largura(1, 2)],
                ['Descrição Contrib. Sociais - Retidas', (string) $f['descricaoContribuicoes'], $this->largura(2, 4)],
            ]);
        } else {
            $this->desloc += $this->altura('fed.pis', 'ibs.titulo');
        }
    }

    private function tributacaoIbsCbs(): void
    {
        $t = $this->dados['ibscbs'];

        // Quatro posições concatenadas (NT 008, item 2.4.5): indicador, código
        // IBGE, município e UF — o município do mapa já vem como "Nome / UF".
        $municipioIncidencia = (string) $t['municipioIncidencia'];
        if (!str_contains($municipioIncidencia, ' / ')) {
            $municipioIncidencia .= ' / ' . DANFSeDados::TRACO;
        }

        $indicador = implode(' / ', [
            (string) $t['indicadorOperacao'],
            (string) $t['codigoIbgeIncidencia'],
            $municipioIncidencia,
        ]);

        $this->tituloComCampos('ibs.titulo', 'ibs.exclusoes', 'TRIBUTAÇÃO IBS / CBS', [
            ['CST / cClassTrib', $t['cst'] . ' / ' . $t['cClassTrib'], $this->largura(1, 2)],
            ['Indicador de Operação / Código IBGE Incidência / Município Incidência / Sigla UF', $indicador, $this->largura(2, 4), false, 56],
        ]);

        $this->linhaCampos($this->y('ibs.exclusoes'), $this->altura('ibs.exclusoes', 'ibs.efetiva'), [
            ['Exclusões e Reduções da Base de Cálculo', (string) $t['exclusoesReducoes'], $this->largura(0, 1)],
            ['Base de Cálculo Após Exclusões e Reduções', (string) $t['baseCalculo'], $this->largura(1, 2)],
            [
                'Red. Alíquota IBS / Red. Alíquota CBS',
                $t['reducaoAliquotaIbsUf'] . ' / ' . $t['reducaoAliquotaIbsMun'] . ' / ' . $t['reducaoAliquotaCbs'],
                $this->largura(2, 3),
            ],
            ['Alíquota - IBS UF / IBS Mun', $t['aliquotaIbsUf'] . ' / ' . $t['aliquotaIbsMun'], $this->largura(3, 4)],
        ]);

        $this->linhaCampos($this->y('ibs.efetiva'), $this->altura('ibs.efetiva', 'ibs.total'), [
            ['Alíq. Efetiva Municipal - IBS', (string) $t['aliquotaEfetivaIbsMun'], $this->largura(0, 1)],
            ['Valor Apurado Municipal - IBS', (string) $t['valorApuradoIbsMun'], $this->largura(1, 2)],
            ['Alíq. Efetiva Estadual - IBS', (string) $t['aliquotaEfetivaIbsUf'], $this->largura(2, 3)],
            ['Valor Apurado Estadual - IBS', (string) $t['valorApuradoIbsUf'], $this->largura(3, 4)],
        ]);

        $this->linhaCampos($this->y('ibs.total'), $this->altura('ibs.total', 'valor.titulo'), [
            ['Valor Total Apurado - IBS', (string) $t['valorTotalIbs'], $this->largura(0, 1)],
            ['Alíquota - CBS', (string) $t['aliquotaCbs'], $this->largura(1, 2)],
            ['Alíquota Efetiva - CBS', (string) $t['aliquotaEfetivaCbs'], $this->largura(2, 3)],
            ['Valor Total Apurado - CBS', (string) $t['valorTotalCbs'], $this->largura(3, 4)],
        ]);
    }

    private function valorTotal(): void
    {
        $v = $this->dados['valores'];

        $this->tituloComCampos('valor.titulo', 'valor.liquido', 'VALOR TOTAL DA NFS-e', [
            ['VALOR DA OPERAÇÃO / SERVIÇO', (string) $v['valorServico'], $this->largura(1, 2)],
            ['Desconto Incondicionado', (string) $v['descontoIncondicionado'], $this->largura(2, 3)],
            ['Desconto Condicionado', (string) $v['descontoCondicionado'], $this->largura(3, 4)],
        ]);

        // "VALOR LÍQUIDO DA NFS-e + IBS/CBS" leva sombreamento (NT 008, 2.2.3).
        $this->linhaCampos($this->y('valor.liquido'), $this->altura('valor.liquido', 'infcompl.titulo'), [
            ['Total das Retenções (ISSQN / Federais)', (string) $v['totalRetencoes'], $this->largura(0, 1)],
            ['VALOR LÍQUIDO DA NFS-e', (string) $v['valorLiquido'], $this->largura(1, 2)],
            ['Total do IBS/CBS', (string) $v['totalIbsCbs'], $this->largura(2, 3)],
            ['VALOR LÍQUIDO DA NFS-e + IBS/CBS', (string) $v['valorLiquidoComIbsCbs'], $this->largura(3, 4), true],
        ]);
    }

    private function informacoesComplementares(): void
    {
        $yTitulo = $this->y('infcompl.titulo');
        $alturaTitulo = $this->altura('infcompl.titulo', 'infcompl.conteudo');

        $this->caixa(self::COL_X[0], $yTitulo, self::LARGURA_UTIL, $alturaTitulo, true);
        $this->SetFont($this->fonteTitulos, 'B', self::PT_TITULO_BLOCO);
        $this->SetXY(self::COL_X[0] + 0.8, $yTitulo + 0.6);
        $this->Cell(self::LARGURA_UTIL - 1.6, 2.8, 'INFORMAÇÕES COMPLEMENTARES', 0, 0, 'L');

        // O quadro absorve toda a folga restante: vai do fim do título até o
        // canhoto (fixo) ou até o fim do formulário (NT 008, itens 2.3 e 2.3.3).
        $y = $this->y('infcompl.conteudo');
        $limite = $this->exibirCanhoto ? self::GRADE['canhoto'] : self::GRADE['fim'];
        $altura = $limite - $y;

        $this->caixa(self::COL_X[0], $y, self::LARGURA_UTIL, $altura);

        /** @var array<int, string> $partes */
        $partes = $this->dados['informacoesComplementares'];
        $texto = implode(' | ', $partes);

        // A linha dos totais aproximados é fixa e vem por último (nota 10)
        $linhaTotais = (string) ($this->dados['linhaTotaisAproximados'] ?? '');
        $texto = $texto === '' ? $linhaTotais : $texto . "\n" . $linhaTotais;

        $texto = $this->truncar($texto, self::LIM_INF_COMPL);

        $this->SetFont($this->fonteConteudo, '', self::PT_CONTEUDO);
        $texto = $this->ajustarAoQuadro($texto, self::LARGURA_UTIL - 1.6, $altura - 1.2);
        $this->SetXY(self::COL_X[0] + 0.8, $y + 0.8);
        $this->MultiCell(self::LARGURA_UTIL - 1.6, 2.8, $texto, 0, 'L');
    }

    private function canhoto(): void
    {
        if (!$this->exibirCanhoto) {
            return;
        }

        $chave = $this->dados['numero'] . ' / ' . $this->dados['chaveAcesso'];

        // Bloco opcional, fixo no pé do formulário (NT 008, item 2.4.5)
        $this->linhaCampos(self::GRADE['canhoto'], $this->altura('canhoto', 'fim'), [
            ['DATA CIENTIFICAÇÃO:', '', $this->largura(0, 1)],
            ['IDENTIFICAÇÃO E ASSINATURA', '', $this->largura(1, 2)],
            ['N° NFS-e / CHAVE NFS-e', $chave, $this->largura(2, 4), false, 66],
        ], maiusculo: true);
    }

    // -------------------------------------------------------------- desenho

    /**
     * Título de bloco seguido dos campos da mesma linha, como no leiaute: o
     * título ocupa a primeira coluna e os campos as demais.
     *
     * @param array<int, array{0: string, 1: string, 2: float, 3?: bool, 4?: int}> $campos
     */
    private function tituloComCampos(string $linha, string $proximaLinha, string $titulo, array $campos): void
    {
        $y = $this->y($linha);
        $altura = $this->altura($linha, $proximaLinha);
        $x = self::COL_X[0];

        // Primeira coluna: título do bloco, com sombreamento
        $this->caixa($x, $y, $this->largura(0, 1), $altura, true);
        $this->SetFont($this->fonteTitulos, 'B', self::PT_TITULO_BLOCO);
        $this->SetXY($x + 0.8, $y + 1.4);
        $this->Cell($this->largura(0, 1) - 1.6, 3, $titulo, 0, 0, 'L');

        $x += $this->largura(0, 1);
        foreach ($campos as $campo) {
            $largura = $campo[2];
            $this->campo(
                $x,
                $y,
                $largura,
                $altura,
                $campo[0],
                $campo[1],
                limite: $campo[4] ?? $this->limitePadrao($largura),
                sombreado: $campo[3] ?? false,
            );
            $x += $largura;
        }
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: float, 3?: bool, 4?: int}> $campos
     */
    private function linhaCampos(float $y, float $altura, array $campos, bool $maiusculo = false): void
    {
        $x = self::COL_X[0];

        foreach ($campos as $campo) {
            $largura = $campo[2];
            $this->campo(
                $x,
                $y,
                $largura,
                $altura,
                $campo[0],
                $campo[1],
                limite: $campo[4] ?? $this->limitePadrao($largura),
                maiusculo: $maiusculo,
                sombreado: $campo[3] ?? false,
            );
            $x += $largura;
        }
    }

    /**
     * Limite de caracteres da NT para colunas simples (37) e duplas (77).
     */
    private function limitePadrao(float $largura): int
    {
        return $largura > 100.0 ? self::LIM_COLUNA_DUPLA : self::LIM_COLUNA;
    }

    private function campo(
        float $x,
        float $y,
        float $largura,
        float $altura,
        string $label,
        string $valor,
        int $limite = self::LIM_COLUNA,
        bool $maiusculo = false,
        bool $sombreado = false,
    ): void {
        $this->caixa($x, $y, $largura, $altura, $sombreado);

        if ($label !== '') {
            $this->SetFont($this->fonteTitulos, 'B', $maiusculo ? self::PT_TITULO_BLOCO : self::PT_LABEL);
            $this->SetXY($x + 0.8, $y + 0.5);
            $this->Cell($largura - 1.6, 2.4, $label, 0, 0, 'L');
        }

        $this->SetFont($this->fonteConteudo, '', self::PT_CONTEUDO);
        $this->SetXY($x + 0.8, $y + 3.2);
        $this->Cell($largura - 1.6, 2.8, $this->truncar($valor, $limite), 0, 0, 'L');
    }

    /**
     * Faixa única para blocos suprimidos (NT 008, item 2.3 e notas 2 a 4). A
     * altura economizada desloca os blocos seguintes para cima até ser
     * absorvida pela Descrição do Serviço ou pelas Informações Complementares.
     */
    private function blocoSuprimido(string $linha, string $proximaLinha, string $texto): void
    {
        $y = $this->y($linha);
        $altura = self::ALTURA_LINHA_SUPRIMIDA;

        $this->caixa(self::COL_X[0], $y, self::LARGURA_UTIL, $altura);

        $this->SetFont($this->fonteTitulos, 'B', self::PT_TITULO_BLOCO);
        $this->SetXY(self::COL_X[0], $y + 0.3);
        $this->Cell(self::LARGURA_UTIL, 2.6, $texto, 0, 0, 'C');

        $this->desloc += $this->altura($linha, $proximaLinha) - $altura;
    }

    /**
     * Retângulo com borda de 0,5 pt e, opcionalmente, sombreamento cinza claro
     * (5% de densidade), conforme o item 2.2.3 da NT.
     */
    private function caixa(float $x, float $y, float $largura, float $altura, bool $sombreado = false): void
    {
        $this->SetLineWidth(self::LINHA_DIVISORIA);

        if ($sombreado) {
            $this->SetFillColor(242, 242, 242);
            $this->Rect($x, $y, $largura, $altura, 'FD');

            return;
        }

        $this->Rect($x, $y, $largura, $altura, 'D');
    }

    /**
     * Borda externa de 1 pt em volta do formulário completo (item 2.2.3).
     */
    private function bordaPagina(): void
    {
        $this->SetLineWidth(self::LINHA_BORDA);
        $this->Rect(
            self::MARGEM,
            self::GRADE['cabecalho'],
            self::LARGURA_UTIL,
            self::GRADE['fim'] - self::GRADE['cabecalho'],
            'D',
        );
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
     * (NT 008, item 2.4.5): o tamanho máximo do campo já reserva os três
     * caracteres das reticências além do limite (ex.: 37 + "..." = 40).
     */
    private function truncar(string $texto, int $limite): string
    {
        if ($limite <= 0 || mb_strlen($texto) <= $limite) {
            return $texto;
        }

        return mb_substr($texto, 0, $limite) . '...';
    }

    /**
     * Garante que um texto multilinha caiba na altura do quadro (página única
     * é obrigatória): remove linhas excedentes e sinaliza com reticências.
     */
    private function ajustarAoQuadro(string $texto, float $largura, float $alturaDisponivel): string
    {
        $maxLinhas = max(1, (int) floor($alturaDisponivel / 2.8));

        if ($this->getNumLines($texto, $largura) <= $maxLinhas) {
            return $texto;
        }

        while (mb_strlen($texto) > 3 && $this->getNumLines($texto . '...', $largura) > $maxLinhas) {
            $texto = mb_substr($texto, 0, (int) (mb_strlen($texto) * 0.9));
        }

        return rtrim($texto) . '...';
    }
}
