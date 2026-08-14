<?php

declare(strict_types=1);

namespace NFSe\Utils;

use TCPDF;

/**
 * Gerador local de DANFSe (Documento Auxiliar da NFS-e).
 *
 * Usado como fallback quando o endpoint oficial de PDF está indisponível
 * (rate limit) ou como alternativa offline.
 *
 * Opções aceitas no construtor:
 *  - creator: string  Criador registrado nos metadados do PDF
 *  - author: string   Autor registrado nos metadados do PDF
 *  - footerText: string Texto da seção de informações complementares
 *  - municipios: array<string, string> Mapa codIBGE => "Nome - UF" para
 *    exibir nomes de municípios (sem o mapa, exibe o código)
 */
class DANFSeGenerator extends TCPDF
{
    private const CREATOR_PADRAO = 'nfse-nacional-php';
    private const FOOTER_PADRAO = 'Para verificar a autenticidade desta NFS-e, acesse o Portal Nacional da NFS-e.';

    /** @var array<string, mixed> */
    private array $dadosNFSe = [];
    private ?string $logoPath = null;
    private float $currentY = 10;

    private readonly string $footerText;

    /** @var array<string, string> */
    private readonly array $municipios;

    // Layout (A4, mm)
    private const MARGIN_LEFT = 10;
    private const MARGIN_RIGHT = 10;
    private const CONTENT_WIDTH = 190;

    private const SPACING_SECTION = 3;
    private const SPACING_LINE = 10;
    private const SECTION_HEADER_HEIGHT = 7;
    private const FIELD_LABEL_HEIGHT = 3;

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

        $this->footerText = (string) ($opcoes['footerText'] ?? self::FOOTER_PADRAO);
        $this->municipios = (array) ($opcoes['municipios'] ?? []);

        $this->setPrintHeader(false);
        $this->setPrintFooter(false);

        $this->SetMargins(self::MARGIN_LEFT, 10, self::MARGIN_RIGHT);
        $this->SetAutoPageBreak(true, 10);
    }

    /**
     * Gera o DANFSe a partir do XML da NFS-e e retorna o PDF em binário.
     */
    public function gerarPDF(string $xmlNFSe, ?string $logoPath = null): string
    {
        $this->logoPath = $logoPath;
        $this->dadosNFSe = DANFSeDados::extrair($xmlNFSe, $this->municipios);

        $this->AddPage();

        $this->currentY = 10;
        $this->renderizarCabecalho();
        $this->renderizarIdentificacao();
        $this->renderizarParte(
            'EMITENTE DA NFS-e (Prestador do Serviço)',
            $this->dadosNFSe['prestador'],
            incluirRegime: true,
        );
        $this->renderizarParte('TOMADOR DO SERVIÇO', $this->dadosNFSe['tomador'], incluirRegime: false);
        $this->renderizarIntermediario();
        $this->renderizarServico();
        $this->renderizarValores();
        $this->renderizarInformacoesComplementares();

        return $this->Output('', 'S');
    }

    private function renderizarCabecalho(): void
    {
        $startY = $this->currentY;

        if ($this->logoPath !== null && file_exists($this->logoPath)) {
            try {
                $this->Image($this->logoPath, 165, $startY, 35, 0, 'JPG', '', '', false, 300, '', false, false, 0);
            } catch (\Exception) {
                // Logo é opcional: falha ao carregar não impede o documento
            }
        }

        $this->SetFont('helvetica', 'B', 12);
        $this->SetTextColor(46, 64, 178);
        $this->SetXY(self::MARGIN_LEFT, $startY);
        $this->Cell(150, 5, 'DANFSe - Documento Auxiliar da NFS-e', 0, 0, 'L');

        $this->currentY += 6;

        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(80, 80, 80);
        $this->SetXY(self::MARGIN_LEFT, $this->currentY);
        $this->Cell(150, 4, 'Sistema Nacional de NFS-e', 0, 0, 'L');

        $this->currentY += 5;

        if ($this->dadosNFSe['ambiente'] === '2') {
            $this->SetFont('helvetica', 'B', 9);
            $this->SetTextColor(255, 0, 0);
            $this->SetXY(self::MARGIN_LEFT, $this->currentY);
            $this->Cell(150, 4, 'AMBIENTE DE HOMOLOGAÇÃO - SEM VALIDADE JURÍDICA', 0, 0, 'L');
            $this->currentY += 5;
        }

        $this->SetTextColor(0, 0, 0);
        $this->currentY += self::SPACING_SECTION;
    }

    private function renderizarIdentificacao(): void
    {
        $boxHeight = 28;
        $startY = $this->currentY;

        $this->SetLineWidth(0.5);
        $this->SetDrawColor(46, 64, 178);
        $this->Rect(self::MARGIN_LEFT, $startY, self::CONTENT_WIDTH, $boxHeight);

        $y = $startY + 2;

        $this->SetFont('helvetica', 'B', 10);
        $this->SetTextColor(46, 64, 178);
        $this->SetXY(12, $y);
        $municipio = $this->dadosNFSe['prestador']['endereco']['municipio'] ?? 'Município';
        $this->Cell(115, 4, 'Município de ' . $municipio, 0, 0);
        $y += 5;

        $this->SetFont('helvetica', 'B', 7);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY(12, $y);
        $this->Cell(115, 3, 'Chave de Acesso da NFS-e', 0, 0);
        $y += 3.5;

        $this->SetFont('courier', '', 8);
        $this->SetXY(12, $y);
        $this->Cell(115, 3, $this->dadosNFSe['chaveAcesso'], 0, 0);
        $y += 4;

        $this->SetFont('helvetica', '', 6);
        $this->SetXY(12, $y);
        $this->MultiCell(115, 2, "Consulte a autenticidade no portal nacional da NFS-e\ncom o código QR ao lado ou pela chave de acesso", 0, 'L');

        $style = [
            'border' => false,
            'padding' => 0,
            'fgcolor' => [0, 0, 0],
            'bgcolor' => [255, 255, 255],
        ];
        $urlConsulta = 'https://www.nfse.gov.br/ConsultaNFSe/' . $this->dadosNFSe['chaveAcesso'];
        $this->write2DBarcode($urlConsulta, 'QRCODE,H', 132, $startY + 2, 22, 22, $style, 'N');

        $yDireita = $startY + 2;

        $this->SetFont('helvetica', 'B', 7);
        $this->SetTextColor(100, 100, 100);
        $this->SetXY(159, $yDireita);
        $this->Cell(38, 3, 'Número da NFS-e', 0, 0, 'L');
        $yDireita += 3;

        $this->SetFont('helvetica', 'B', 11);
        $this->SetTextColor(46, 64, 178);
        $this->SetXY(159, $yDireita);
        $this->Cell(38, 4, $this->dadosNFSe['numero'], 0, 0, 'L');
        $yDireita += 5;

        $this->SetFont('helvetica', 'B', 7);
        $this->SetTextColor(100, 100, 100);
        $this->SetXY(159, $yDireita);
        $this->Cell(38, 3, 'Competência', 0, 0, 'L');
        $yDireita += 3;

        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY(159, $yDireita);
        $this->Cell(38, 3, $this->dadosNFSe['competencia'], 0, 0, 'L');
        $yDireita += 4;

        $this->SetFont('helvetica', 'B', 7);
        $this->SetTextColor(100, 100, 100);
        $this->SetXY(159, $yDireita);
        $this->Cell(38, 3, 'Data Emissão', 0, 0, 'L');
        $yDireita += 3;

        $this->SetFont('helvetica', '', 7);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY(159, $yDireita);
        $this->Cell(38, 3, $this->dadosNFSe['dhEmissao'], 0, 0, 'L');

        $this->SetTextColor(0, 0, 0);
        $this->currentY = $startY + $boxHeight + self::SPACING_SECTION;
    }

    /**
     * Renderiza um bloco de pessoa (emitente ou tomador).
     *
     * @param array<string, mixed> $dados
     */
    private function renderizarParte(string $titulo, array $dados, bool $incluirRegime): void
    {
        $this->renderizarTituloSecao($titulo);

        $y = $this->currentY;

        $this->renderizarCampo('Nome / Razão Social', (string) $dados['nome'], 12, $y, 123);
        $this->renderizarCampo('CNPJ / CPF', (string) $dados['cnpj'], 140, $y, 58);
        $y += self::SPACING_LINE;

        $this->renderizarCampo('Endereço', (string) $dados['endereco']['logradouro'], 12, $y, 123);
        $this->renderizarCampo('Município', (string) $dados['endereco']['municipio'], 140, $y, 58);
        $y += self::SPACING_LINE;

        $this->renderizarCampo('E-mail', (string) $dados['email'], 12, $y, 88);
        $this->renderizarCampo('Telefone', (string) $dados['telefone'], 105, $y, 43);
        $this->renderizarCampo('CEP', (string) $dados['endereco']['cep'], 153, $y, 45);
        $y += self::SPACING_LINE;

        if ($incluirRegime) {
            $simplesTexto = ($dados['simplesNacional'] ?? '') === '3'
                ? 'Optante pelo Simples Nacional'
                : 'Não optante pelo Simples';
            $this->renderizarCampo('Regime Tributário', $simplesTexto, 12, $y, 186);
            $y += self::SPACING_LINE;
        }

        $this->currentY = $y + self::SPACING_SECTION;
    }

    private function renderizarIntermediario(): void
    {
        $this->renderizarTituloSecao('INTERMEDIÁRIO DO SERVIÇO');

        $y = $this->currentY;

        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(100, 100, 100);
        $this->SetXY(12, $y);
        $this->Cell(186, 4, 'NÃO IDENTIFICADO NA NFS-e', 0, 0, 'L');
        $this->SetTextColor(0, 0, 0);

        $this->currentY = $y + 6 + self::SPACING_SECTION;
    }

    private function renderizarServico(): void
    {
        $this->renderizarTituloSecao('SERVIÇO PRESTADO');

        $serv = $this->dadosNFSe['servico'];
        $y = $this->currentY;

        $this->renderizarCampo('Código de Tributação Nacional', (string) $serv['codigoTributacao'], 12, $y, 90);
        $this->renderizarCampo('Local da Prestação', (string) $serv['localPrestacao'], 107, $y, 91);
        $y += self::SPACING_LINE;

        $descricaoAltura = $this->renderizarCampoMultiline('Descrição do Serviço', (string) $serv['descricao'], 12, $y, 186);

        $this->currentY = $y + $descricaoAltura + self::SPACING_SECTION;
    }

    private function renderizarValores(): void
    {
        $this->renderizarTituloSecao('VALORES DA NFS-e');

        $val = $this->dadosNFSe['valores'];
        $y = $this->currentY;
        $boxHeight = 20;

        $this->SetFillColor(240, 248, 255);
        $this->Rect(self::MARGIN_LEFT, $y, self::CONTENT_WIDTH, $boxHeight, 'F');

        $this->SetFont('helvetica', 'B', 9);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY(15, $y + 3);
        $this->Cell(50, 4, 'Valor do Serviço:', 0, 0, 'L');
        $this->SetFont('helvetica', '', 10);
        $this->SetXY(65, $y + 3);
        $this->Cell(45, 4, $this->formatarValor((float) $val['valorServico']), 0, 0, 'R');

        $this->SetFont('helvetica', 'B', 9);
        $this->SetXY(15, $y + 9);
        $this->Cell(50, 4, 'Retenção do ISSQN:', 0, 0, 'L');
        $this->SetFont('helvetica', '', 9);
        $retTexto = $val['retencaoISSQN'] === '1' ? 'Não Retido' : 'Retido';
        $this->SetXY(65, $y + 9);
        $this->Cell(45, 4, $retTexto, 0, 0, 'R');

        $this->SetFont('helvetica', 'B', 9);
        $this->SetTextColor(46, 64, 178);
        $this->SetXY(120, $y + 3);
        $this->Cell(48, 5, 'Valor Líquido da NFS-e:', 0, 0, 'R');
        $this->SetFont('helvetica', 'B', 13);
        $this->SetXY(170, $y + 3);
        $this->Cell(25, 5, $this->formatarValor((float) $val['valorLiquido']), 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);

        if ($val['totTribSN'] > 0) {
            $this->SetFont('helvetica', '', 7);
            $this->SetXY(120, $y + 11);
            $tribAprox = $val['valorServico'] * $val['totTribSN'] / 100;
            $this->Cell(75, 3, sprintf('Tributos aprox.: R$ %.2f (%.2f%%)', $tribAprox, $val['totTribSN']), 0, 0, 'R');
        }

        $this->currentY = $y + $boxHeight + self::SPACING_SECTION;
    }

    private function renderizarInformacoesComplementares(): void
    {
        $this->renderizarTituloSecao('INFORMAÇÕES COMPLEMENTARES');

        $y = $this->currentY;

        $this->SetFont('helvetica', 'I', 7);
        $this->SetTextColor(60, 60, 60);
        $this->SetXY(12, $y);
        $this->MultiCell(186, 3, $this->footerText, 0, 'L');
        $this->SetTextColor(0, 0, 0);

        $this->currentY = $y + 8;
    }

    private function renderizarTituloSecao(string $titulo): void
    {
        $this->SetFillColor(46, 64, 178);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('helvetica', 'B', 9);
        $this->SetXY(self::MARGIN_LEFT, $this->currentY);
        $this->Cell(self::CONTENT_WIDTH, self::SECTION_HEADER_HEIGHT, $titulo, 0, 0, 'L', true);
        $this->SetTextColor(0, 0, 0);

        $this->currentY += self::SECTION_HEADER_HEIGHT + 1;
    }

    private function renderizarCampo(string $label, string $valor, float $x, float $y, float $width): void
    {
        $this->SetFont('helvetica', 'B', 7);
        $this->SetTextColor(100, 100, 100);
        $this->SetXY($x, $y);
        $this->Cell($width, self::FIELD_LABEL_HEIGHT, $label, 0, 0, 'L');

        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY($x, $y + self::FIELD_LABEL_HEIGHT + 0.5);

        $valorTruncado = strlen($valor) > 80 ? substr($valor, 0, 77) . '...' : $valor;
        $this->Cell($width, 5, $valorTruncado, 0, 0, 'L');
    }

    private function renderizarCampoMultiline(string $label, string $valor, float $x, float $y, float $width): float
    {
        $this->SetFont('helvetica', 'B', 7);
        $this->SetTextColor(100, 100, 100);
        $this->SetXY($x, $y);
        $this->Cell($width, self::FIELD_LABEL_HEIGHT, $label, 0, 0, 'L');

        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY($x, $y + self::FIELD_LABEL_HEIGHT + 0.5);

        $this->MultiCell($width, 4, $valor, 0, 'L');
        $endY = $this->GetY();

        return ($endY - $y) + 2;
    }

    private function formatarValor(float $valor): string
    {
        if ($valor === 0.0) {
            return 'R$ 0,00';
        }

        return 'R$ ' . number_format($valor, 2, ',', '.');
    }
}
