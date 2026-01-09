<?php

namespace NFSe\Utils;

use TCPDF;
use Exception;

/**
 * Gerador de DANFSe (Documento Auxiliar da NFS-e)
 * Com layout dinâmico e responsivo
 */
class DANFSeGenerator extends TCPDF
{
    private $dadosNFSe;
    private $logoPath;
    private $currentY = 10; // Posição Y atual (dinâmica)

    // Constantes de layout
    private const MARGIN_LEFT = 10;
    private const MARGIN_RIGHT = 10;
    private const PAGE_WIDTH = 210; // A4
    private const CONTENT_WIDTH = 190; // 210 - 10 - 10

    private const SPACING_SECTION = 3; // Espaço entre seções
    private const SPACING_LINE = 10; // Espaço entre linhas de campos
    private const SECTION_HEADER_HEIGHT = 7;
    private const FIELD_LABEL_HEIGHT = 3;

    public function __construct()
    {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8', false);

        // Configurações do PDF
        $this->SetCreator('CloudGer - Sistema Nacional NFS-e');
        $this->SetAuthor('CloudGer');
        $this->SetTitle('DANFSe');
        $this->SetSubject('Documento Auxiliar da NFS-e');

        // Remove header e footer padrão
        $this->setPrintHeader(false);
        $this->setPrintFooter(false);

        // Margens
        $this->SetMargins(self::MARGIN_LEFT, 10, self::MARGIN_RIGHT);
        $this->SetAutoPageBreak(true, 10);
    }

    /**
     * Gera o DANFSe a partir do XML da NFS-e
     */
    public function gerarPDF(string $xmlNFSe, ?string $logoPath = null): string
    {
        $this->logoPath = $logoPath;

        // Parse do XML
        $xml = simplexml_load_string($xmlNFSe);
        if ($xml === false) {
            throw new Exception("XML da NFS-e inválido");
        }

        // Extrai dados do XML
        $this->dadosNFSe = $this->extrairDadosXML($xml);

        // Adiciona página
        $this->AddPage();

        // Renderiza o documento de forma dinâmica
        $this->currentY = 10;
        $this->renderizarCabecalho();
        $this->renderizarIdentificacao();
        $this->renderizarEmitente();
        $this->renderizarTomador();
        $this->renderizarIntermediario();
        $this->renderizarServico();
        $this->renderizarValores();
        $this->renderizarInformacoesComplementares();

        return $this->Output('', 'S');
    }

    /**
     * Extrai dados do XML para array
     */
    private function extrairDadosXML($xml): array
    {
        $infNFSe = $xml->infNFSe;
        $dps = $infNFSe->DPS->infDPS;

        // Chave de acesso
        $chaveAcesso = str_replace('NFS', '', (string)$infNFSe['Id']);

        // Dados básicos
        $dados = [
            'chaveAcesso' => $chaveAcesso,
            'numero' => (string)$infNFSe->nNFSe,
            'serie' => (string)$dps->serie ?? '',
            'numeroDPS' => (string)$dps->nDPS ?? '',
            'competencia' => $this->formatarData((string)$dps->dCompet ?? ''),
            'dhEmissao' => $this->formatarDataHora((string)$infNFSe->dhProc ?? ''),
            'dhEmissaoDPS' => $this->formatarDataHora((string)$dps->dhEmi ?? ''),
            'ambiente' => (string)$dps->tpAmb ?? '1',
        ];

        // Emitente (prestador)
        $emit = $infNFSe->emit;
        $prest = $dps->prest ?? null;

        $dados['prestador'] = [
            'cnpj' => $this->formatarCNPJ((string)($emit->CNPJ ?? $emit->CPF ?? '')),
            'inscricaoMunicipal' => '-',
            'nome' => (string)$emit->xNome,
            'telefone' => $this->formatarTelefone((string)$emit->fone ?? ''),
            'email' => (string)$emit->email ?? '',
            'endereco' => $this->montarEndereco($emit->enderNac ?? null),
            'simplesNacional' => (string)($prest->regTrib->opSimpNac ?? '2'),
            'regimeApuracao' => (string)($prest->regTrib->regApTribSN ?? ''),
        ];

        // Tomador
        $tom = $dps->toma ?? null;
        if ($tom) {
            $dados['tomador'] = [
                'cnpj' => $this->formatarCNPJ((string)($tom->CNPJ ?? $tom->CPF ?? '')),
                'inscricaoMunicipal' => '-',
                'nome' => (string)$tom->xNome,
                'telefone' => $this->formatarTelefone((string)$tom->fone ?? ''),
                'email' => (string)$tom->email ?? '',
                'endereco' => $this->montarEnderecoTomador($tom->end ?? null),
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

        // Serviço
        $serv = $dps->serv ?? null;
        $dados['servico'] = [
            'codigoTributacao' => $this->formatarCodigoTrib((string)($serv->cServ->cTribNac ?? '')),
            'localPrestacao' => $this->obterNomeMunicipio((string)($serv->locPrest->cLocPrestacao ?? (string)$infNFSe->cLocIncid)),
            'descricao' => (string)($serv->cServ->xDescServ ?? ''),
        ];

        // Valores
        $valoresDPS = $dps->valores ?? null;
        $dados['valores'] = [
            'valorServico' => (float)($valoresDPS->vServPrest->vServ ?? 0),
            'valorLiquido' => (float)($infNFSe->valores->vLiq ?? 0),
            'tribISSQN' => (string)($valoresDPS->trib->tribMun->tribISSQN ?? '1'),
            'retencaoISSQN' => (string)($valoresDPS->trib->tribMun->tpRetISSQN ?? '1'),
            'totTribSN' => (float)($valoresDPS->trib->totTrib->pTotTribSN ?? 0),
        ];

        return $dados;
    }

    /**
     * Renderiza o cabeçalho
     */
    private function renderizarCabecalho(): void
    {
        $startY = $this->currentY;

        // Logo CloudGer (canto superior direito, tamanho controlado)
        if ($this->logoPath && file_exists($this->logoPath)) {
            try {
                $this->Image($this->logoPath, 165, $startY, 35, 0, 'JPG', '', '', false, 300, '', false, false, 0);
            } catch (\Exception $e) {
                // Se falhar, ignora o logo
            }
        }

        // Título
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

        // Ambiente de homologação
        if ($this->dadosNFSe['ambiente'] == '2') {
            $this->SetFont('helvetica', 'B', 9);
            $this->SetTextColor(255, 0, 0);
            $this->SetXY(self::MARGIN_LEFT, $this->currentY);
            $this->Cell(150, 4, 'AMBIENTE DE HOMOLOGAÇÃO - SEM VALIDADE JURÍDICA', 0, 0, 'L');
            $this->currentY += 5;
        }

        $this->SetTextColor(0, 0, 0);
        $this->currentY += self::SPACING_SECTION;
    }

    /**
     * Renderiza seção de identificação com chave e QR Code
     */
    private function renderizarIdentificacao(): void
    {
        $boxHeight = 28;
        $startY = $this->currentY;

        // Box com borda colorida
        $this->SetLineWidth(0.5);
        $this->SetDrawColor(46, 64, 178);
        $this->Rect(self::MARGIN_LEFT, $startY, self::CONTENT_WIDTH, $boxHeight);

        $y = $startY + 2;

        // Município
        $this->SetFont('helvetica', 'B', 10);
        $this->SetTextColor(46, 64, 178);
        $this->SetXY(12, $y);
        $municipio = $this->dadosNFSe['prestador']['endereco']['municipio'] ?? 'Município';
        $this->Cell(115, 4, 'Município de ' . $municipio, 0, 0);
        $y += 5;

        // Chave de acesso
        $this->SetFont('helvetica', 'B', 7);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY(12, $y);
        $this->Cell(115, 3, 'Chave de Acesso da NFS-e', 0, 0);
        $y += 3.5;

        $this->SetFont('courier', '', 8);
        $this->SetXY(12, $y);
        $this->Cell(115, 3, $this->dadosNFSe['chaveAcesso'], 0, 0);
        $y += 4;

        // Texto de autenticidade
        $this->SetFont('helvetica', '', 6);
        $this->SetXY(12, $y);
        $this->MultiCell(115, 2, "Consulte a autenticidade no portal nacional da NFS-e\ncom o código QR ao lado ou pela chave de acesso", 0, 'L');

        // QR Code
        $style = [
            'border' => false,
            'padding' => 0,
            'fgcolor' => [0, 0, 0],
            'bgcolor' => [255, 255, 255]
        ];
        $urlConsulta = 'https://www.nfse.gov.br/ConsultaNFSe/' . $this->dadosNFSe['chaveAcesso'];
        $this->write2DBarcode($urlConsulta, 'QRCODE,H', 132, $startY + 2, 22, 22, $style, 'N');

        // Dados da nota (direita)
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
     * Renderiza seção do emitente
     */
    private function renderizarEmitente(): void
    {
        $this->renderizarTituloSecao('EMITENTE DA NFS-e (Prestador do Serviço)');

        $prest = $this->dadosNFSe['prestador'];
        $y = $this->currentY;

        // Linha 1
        $this->renderizarCampo('Nome / Razão Social', $prest['nome'], 12, $y, 123);
        $this->renderizarCampo('CNPJ / CPF', $prest['cnpj'], 140, $y, 58);
        $y += self::SPACING_LINE;

        // Linha 2
        $this->renderizarCampo('Endereço', $prest['endereco']['logradouro'], 12, $y, 123);
        $this->renderizarCampo('Município', $prest['endereco']['municipio'], 140, $y, 58);
        $y += self::SPACING_LINE;

        // Linha 3
        $this->renderizarCampo('E-mail', $prest['email'], 12, $y, 88);
        $this->renderizarCampo('Telefone', $prest['telefone'], 105, $y, 43);
        $this->renderizarCampo('CEP', $prest['endereco']['cep'], 153, $y, 45);
        $y += self::SPACING_LINE;

        // Linha 4
        $simplesTexto = $prest['simplesNacional'] == '3' ? 'Optante pelo Simples Nacional' : 'Não optante pelo Simples';
        $this->renderizarCampo('Regime Tributário', $simplesTexto, 12, $y, 186);

        $this->currentY = $y + self::SPACING_LINE + self::SPACING_SECTION;
    }

    /**
     * Renderiza seção do tomador
     */
    private function renderizarTomador(): void
    {
        $this->renderizarTituloSecao('TOMADOR DO SERVIÇO');

        $tom = $this->dadosNFSe['tomador'];
        $y = $this->currentY;

        // Linha 1
        $this->renderizarCampo('Nome / Razão Social', $tom['nome'], 12, $y, 123);
        $this->renderizarCampo('CNPJ / CPF', $tom['cnpj'], 140, $y, 58);
        $y += self::SPACING_LINE;

        // Linha 2
        $this->renderizarCampo('Endereço', $tom['endereco']['logradouro'], 12, $y, 123);
        $this->renderizarCampo('Município', $tom['endereco']['municipio'], 140, $y, 58);
        $y += self::SPACING_LINE;

        // Linha 3
        $this->renderizarCampo('E-mail', $tom['email'], 12, $y, 88);
        $this->renderizarCampo('Telefone', $tom['telefone'], 105, $y, 43);
        $this->renderizarCampo('CEP', $tom['endereco']['cep'], 153, $y, 45);

        $this->currentY = $y + self::SPACING_LINE + self::SPACING_SECTION;
    }

    /**
     * Renderiza seção do intermediário
     */
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

    /**
     * Renderiza seção do serviço prestado
     */
    private function renderizarServico(): void
    {
        $this->renderizarTituloSecao('SERVIÇO PRESTADO');

        $serv = $this->dadosNFSe['servico'];
        $y = $this->currentY;

        // Linha 1
        $this->renderizarCampo('Código de Tributação Nacional', $serv['codigoTributacao'], 12, $y, 90);
        $this->renderizarCampo('Local da Prestação', $serv['localPrestacao'], 107, $y, 91);
        $y += self::SPACING_LINE;

        // Linha 2 - Descrição (pode ser longa, calcula altura dinamicamente)
        $descricaoAltura = $this->renderizarCampoMultiline('Descrição do Serviço', $serv['descricao'], 12, $y, 186);

        $this->currentY = $y + $descricaoAltura + self::SPACING_SECTION;
    }

    /**
     * Renderiza valores
     */
    private function renderizarValores(): void
    {
        $this->renderizarTituloSecao('VALORES DA NFS-e');

        $val = $this->dadosNFSe['valores'];
        $y = $this->currentY;
        $boxHeight = 20;

        // Box com valores em destaque
        $this->SetFillColor(240, 248, 255);
        $this->Rect(self::MARGIN_LEFT, $y, self::CONTENT_WIDTH, $boxHeight, 'F');

        // Valor do Serviço
        $this->SetFont('helvetica', 'B', 9);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY(15, $y + 3);
        $this->Cell(50, 4, 'Valor do Serviço:', 0, 0, 'L');
        $this->SetFont('helvetica', '', 10);
        $this->SetXY(65, $y + 3);
        $this->Cell(45, 4, $this->formatarValor($val['valorServico']), 0, 0, 'R');

        // Retenção ISSQN
        $this->SetFont('helvetica', 'B', 9);
        $this->SetXY(15, $y + 9);
        $this->Cell(50, 4, 'Retenção do ISSQN:', 0, 0, 'L');
        $this->SetFont('helvetica', '', 9);
        $retTexto = $val['retencaoISSQN'] == '1' ? 'Não Retido' : 'Retido';
        $this->SetXY(65, $y + 9);
        $this->Cell(45, 4, $retTexto, 0, 0, 'R');

        // Valor Líquido (destaque)
        $this->SetFont('helvetica', 'B', 9);
        $this->SetTextColor(46, 64, 178);
        $this->SetXY(120, $y + 3);
        $this->Cell(48, 5, 'Valor Líquido da NFS-e:', 0, 0, 'R');
        $this->SetFont('helvetica', 'B', 13);
        $this->SetXY(170, $y + 3);
        $this->Cell(25, 5, $this->formatarValor($val['valorLiquido']), 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);

        // Tributos aproximados
        if ($val['totTribSN'] > 0) {
            $this->SetFont('helvetica', '', 7);
            $this->SetXY(120, $y + 11);
            $tribAprox = $val['valorServico'] * $val['totTribSN'] / 100;
            $this->Cell(75, 3, sprintf('Tributos aprox.: R$ %.2f (%.2f%%)', $tribAprox, $val['totTribSN']), 0, 0, 'R');
        }

        $this->currentY = $y + $boxHeight + self::SPACING_SECTION;
    }

    /**
     * Renderiza informações complementares
     */
    private function renderizarInformacoesComplementares(): void
    {
        $this->renderizarTituloSecao('INFORMAÇÕES COMPLEMENTARES');

        $y = $this->currentY;

        $this->SetFont('helvetica', 'I', 7);
        $this->SetTextColor(60, 60, 60);
        $this->SetXY(12, $y);
        $this->MultiCell(186, 3,
            "Este documento foi gerado automaticamente pelo sistema CloudGer.\n" .
            "Para verificar a autenticidade desta NFS-e, acesse o Portal Nacional da NFS-e.",
            0, 'L'
        );
        $this->SetTextColor(0, 0, 0);

        $this->currentY = $y + 8;
    }

    /**
     * Renderiza título de seção (barra azul)
     */
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

    /**
     * Renderiza um campo (label + valor)
     */
    private function renderizarCampo(string $label, string $valor, float $x, float $y, float $width): void
    {
        // Label
        $this->SetFont('helvetica', 'B', 7);
        $this->SetTextColor(100, 100, 100);
        $this->SetXY($x, $y);
        $this->Cell($width, self::FIELD_LABEL_HEIGHT, $label, 0, 0, 'L');

        // Valor
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY($x, $y + self::FIELD_LABEL_HEIGHT + 0.5);

        // Trunca texto se muito longo
        $valorTruncado = strlen($valor) > 80 ? substr($valor, 0, 77) . '...' : $valor;
        $this->Cell($width, 5, $valorTruncado, 0, 0, 'L');
    }

    /**
     * Renderiza campo multiline (retorna altura usada)
     */
    private function renderizarCampoMultiline(string $label, string $valor, float $x, float $y, float $width): float
    {
        // Label
        $this->SetFont('helvetica', 'B', 7);
        $this->SetTextColor(100, 100, 100);
        $this->SetXY($x, $y);
        $this->Cell($width, self::FIELD_LABEL_HEIGHT, $label, 0, 0, 'L');

        // Valor (multicell calcula altura automaticamente)
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(0, 0, 0);
        $this->SetXY($x, $y + self::FIELD_LABEL_HEIGHT + 0.5);

        $startY = $this->GetY();
        $this->MultiCell($width, 4, $valor, 0, 'L');
        $endY = $this->GetY();

        return ($endY - $y) + 2; // Altura total com margem
    }

    /**
     * Formata CNPJ/CPF
     */
    private function formatarCNPJ(string $numero): string
    {
        $numero = preg_replace('/\D/', '', $numero);
        if (strlen($numero) == 14) {
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $numero);
        } elseif (strlen($numero) == 11) {
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $numero);
        }
        return $numero ?: '-';
    }

    /**
     * Formata telefone
     */
    private function formatarTelefone(string $telefone): string
    {
        $telefone = preg_replace('/\D/', '', $telefone);
        if (strlen($telefone) == 11) {
            return preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $telefone);
        } elseif (strlen($telefone) == 10) {
            return preg_replace('/(\d{2})(\d{4})(\d{4})/', '($1) $2-$3', $telefone);
        }
        return $telefone ?: '-';
    }

    /**
     * Formata data
     */
    private function formatarData(string $data): string
    {
        if (empty($data)) return '-';
        $dt = \DateTime::createFromFormat('Y-m-d', $data);
        return $dt ? $dt->format('d/m/Y') : $data;
    }

    /**
     * Formata data e hora
     */
    private function formatarDataHora(string $dataHora): string
    {
        if (empty($dataHora)) return '-';
        $dt = \DateTime::createFromFormat(\DateTime::ATOM, $dataHora);
        if (!$dt) {
            $dt = \DateTime::createFromFormat('Y-m-d\TH:i:sP', $dataHora);
        }
        return $dt ? $dt->format('d/m/Y H:i:s') : $dataHora;
    }

    /**
     * Formata valor monetário
     */
    private function formatarValor(float $valor): string
    {
        if ($valor == 0) return 'R$ 0,00';
        return 'R$ ' . number_format($valor, 2, ',', '.');
    }

    /**
     * Formata código de tributação
     */
    private function formatarCodigoTrib(string $codigo): string
    {
        if (strlen($codigo) == 6) {
            return substr($codigo, 0, 2) . '.' . substr($codigo, 2, 2) . '.' . substr($codigo, 4, 2);
        }
        return $codigo;
    }

    /**
     * Monta endereço completo
     */
    private function montarEndereco($end): array
    {
        if (!$end) {
            return [
                'logradouro' => '-',
                'municipio' => '-',
                'cep' => '-',
            ];
        }

        $logradouro = (string)$end->xLgr . ', ' . (string)$end->nro;
        if (!empty((string)$end->xBairro)) {
            $logradouro .= ', ' . (string)$end->xBairro;
        }

        $municipio = $this->obterNomeMunicipio((string)$end->cMun) . ' - ' . (string)$end->UF;
        $cep = $this->formatarCEP((string)$end->CEP);

        return [
            'logradouro' => $logradouro,
            'municipio' => $municipio,
            'cep' => $cep,
        ];
    }

    /**
     * Monta endereço do tomador
     */
    private function montarEnderecoTomador($end): array
    {
        if (!$end) {
            return ['logradouro' => '-', 'municipio' => '-', 'cep' => '-'];
        }

        $logradouro = (string)$end->xLgr . ', ' . (string)$end->nro;
        if (!empty((string)$end->xBairro)) {
            $logradouro .= ', ' . (string)$end->xBairro;
        }

        $municipio = $this->obterNomeMunicipio((string)$end->endNac->cMun);
        $cep = $this->formatarCEP((string)$end->endNac->CEP);

        return [
            'logradouro' => $logradouro,
            'municipio' => $municipio,
            'cep' => $cep,
        ];
    }

    /**
     * Formata CEP
     */
    private function formatarCEP(string $cep): string
    {
        $cep = preg_replace('/\D/', '', $cep);
        if (strlen($cep) == 8) {
            return preg_replace('/(\d{5})(\d{3})/', '$1-$2', $cep);
        }
        return $cep ?: '-';
    }

    /**
     * Obtém nome do município pelo código IBGE
     */
    private function obterNomeMunicipio(string $codigo): string
    {
        static $municipios = [
            '4216909' => 'São Lourenço do Oeste - SC',
            '4208302' => 'Itapema - SC',
        ];

        return $municipios[$codigo] ?? "Município $codigo";
    }
}
