<?php

/**
 * Exemplo de Emissão de NFS-e em AMBIENTE DE PRODUÇÃO
 *
 * IMPORTANTE: Este é o ambiente REAL de emissão de notas fiscais.
 * As notas emitidas aqui têm validade jurídica e fiscal.
 *
 * Diferenças em relação à homologação:
 * - PODE informar Inscrição Municipal (IM) do prestador
 * - PODE informar endereço do prestador (mas NÃO deve quando tpEmit=1)
 * - Use sempre o ambiente Config::AMBIENTE_PRODUCAO
 */

// Carrega o autoloader do Composer
require_once __DIR__ . '/../vendor/autoload.php';

use NFSe\Config\Config;
use NFSe\Models\DPS;
use NFSe\Services\NFSeClient;

try {
    // 1. CONFIGURAÇÃO - PRODUÇÃO
    echo "=== EMISSÃO EM AMBIENTE DE PRODUÇÃO ===\n\n";
    echo "⚠️  ATENÇÃO: As notas emitidas aqui são REAIS!\n\n";

    $config = new Config(
        Config::AMBIENTE_PRODUCAO,                         // AMBIENTE DE PRODUÇÃO
        __DIR__ . '/../certs/certificado.pfx',             // Caminho do certificado digital
        'senha_do_certificado',                            // Senha do certificado
        '4216909',                                          // Código município IBGE (7 dígitos)
        'MeuSistema/1.0.0'                                 // Identificação do sistema
    );

    // 2. CRIAR CLIENTE
    $client = new NFSeClient($config);

    // 3. CRIAR DPS (Declaração de Prestação de Serviço)
    $dps = new DPS();

    // Configurações básicas - PRODUÇÃO
    $dps->setTpAmb(Config::AMBIENTE_PRODUCAO)              // IMPORTANTE: Usar PRODUÇÃO
        ->setVerAplic('MeuSistema/1.0.0')
        ->setSerie('900')                                   // Série conforme município
        ->setNDPS('1')                                      // Número sequencial da DPS
        ->setDCompet((new DateTime('-1 day', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d'))
        ->setTpEmit(1)                                      // 1=Prestador (quem emite)
        ->setCLocEmi('4216909');                            // Código IBGE do município emissor

    // 4. DADOS DO PRESTADOR - PRODUÇÃO
    // ATENÇÃO: Mesmo em produção, quando o prestador é o emitente (tpEmit=1):
    // - PODE informar 'im' (Inscrição Municipal) - se município exigir
    // - NÃO deve informar 'endereco' (regra do Sistema Nacional)
    $dps->setPrestador([
        'cnpj' => '00.000.000/0001-00',                     // CNPJ do prestador
        'im' => '12345',                                    // ✅ Pode informar em produção (se exigido)
        'xNome' => 'EMPRESA PRESTADORA LTDA',
        // 'endereco' => [...],                             // ❌ NÃO informar quando tpEmit=1
        'fone' => '4999999999',
        'email' => 'contato@empresa.com.br',
        'regTrib' => [
            'opSimpNac' => 3,                               // 1=Não Optante, 2=MEI, 3=ME/EPP
            'regApTribSN' => 1,                             // Regime de apuração
            'regEspTrib' => 0                               // 0=Nenhum regime especial
        ]
    ]);

    // 5. DADOS DO TOMADOR (quem contrata o serviço)
    $dps->setTomador([
        'cnpj' => '11.111.111/0001-11',                     // Pode ser CNPJ ou CPF
        'xNome' => 'EMPRESA TOMADORA LTDA',
        'endereco' => [
            'cMun' => '4204202',                            // Código IBGE do município
            'CEP' => '89802-112',
            'xLog' => 'Rua Exemplo',
            'nLog' => '123',
            'xBairro' => 'Centro'
        ],
        'fone' => '4999999999',
        'email' => 'tomador@empresa.com.br'
    ]);

    // 6. DADOS DO SERVIÇO
    $dps->setServico([
        'cTribNac' => '010601',                             // Código tributação nacional
        'xDescServ' => 'Consultoria em Tecnologia da Informação',
        'cLocPrestacao' => '4216909',                       // Local da prestação
        'xInfComp' => 'Pagamento via PIX - Chave CNPJ: 00.000.000/0001-00'
    ]);

    // 7. VALORES - Cálculo completo
    $valorServico = 1000.00;
    $descontoIncondicionado = 0.00;
    $descontoCondicionado = 0.00;
    $deducaoReducao = 0.00;

    $baseCalculo = $valorServico - $descontoIncondicionado - $descontoCondicionado - $deducaoReducao;
    $aliquota = 5.00;                                       // Alíquota do município
    $valorISSQN = ($baseCalculo * $aliquota) / 100;

    $dps->setValores([
        'vServ' => $valorServico,                           // Valor do serviço
        'vDescIncond' => $descontoIncondicionado,           // Desconto incondicionado
        'vDescCond' => $descontoCondicionado,               // Desconto condicionado
        'vDedRed' => $deducaoReducao,                       // Dedução/Redução
        'vBaseCalc' => $baseCalculo,                        // Base de cálculo
        'pAliq' => $aliquota,                               // Alíquota
        'vISSQN' => $valorISSQN,                            // Valor do ISSQN
        'pTotTribSN' => 6.00,                               // Percentual tributos Simples Nacional
        // Retenções (se aplicável)
        'vRetPIS' => 0.00,
        'vRetCOFINS' => 0.00,
        'vRetCSLL' => 0.00,
        'vRetIRRF' => 0.00
    ]);

    // 8. EMITIR NFS-e
    echo "Emitindo NFS-e em PRODUÇÃO...\n";
    echo "⏳ Aguarde o processamento...\n\n";

    $resultado = $client->emitirNFSe($dps);

    echo "\n=== RESULTADO DA EMISSÃO ===\n";
    print_r($resultado);

    // 9. PROCESSAR RESULTADO
    if (isset($resultado['chNFSe']) || isset($resultado['chaveAcesso'])) {
        $chaveAcesso = $resultado['chNFSe'] ?? $resultado['chaveAcesso'];

        echo "\n✅ NFS-e emitida com SUCESSO em PRODUÇÃO!\n";
        echo "Chave de acesso: {$chaveAcesso}\n";
        echo "🔗 Esta nota fiscal tem validade jurídica e fiscal.\n";

        // 10. CONSULTAR NFS-e
        echo "\n=== CONSULTANDO NFS-E ===\n";
        $nfse = $client->consultarNFSe($chaveAcesso);

        if (isset($nfse['nNFSe'])) {
            echo "Número da NFS-e: " . $nfse['nNFSe'] . "\n";
        }
        if (isset($nfse['dhProc'])) {
            echo "Data de processamento: " . $nfse['dhProc'] . "\n";
        }

        // 11. SALVAR XML (opcional)
        if (isset($nfse['xml'])) {
            $nomeArquivo = "nfse_{$chaveAcesso}.xml";
            file_put_contents(__DIR__ . "/../" . $nomeArquivo, $nfse['xml']);
            echo "📄 XML salvo: {$nomeArquivo}\n";
        }

        // 12. CANCELAR NFS-e (CUIDADO!)
        // ⚠️  DESCOMENTE APENAS SE REALMENTE PRECISAR CANCELAR!
        // Cancelamento em produção é irreversível
        /*
        echo "\n=== CANCELANDO NFS-E ===\n";
        echo "⚠️  ATENÇÃO: Você está prestes a CANCELAR uma nota REAL!\n";

        // Descomente a linha abaixo para confirmar o cancelamento
        // $confirmarCancelamento = true;

        if (isset($confirmarCancelamento) && $confirmarCancelamento === true) {
            $cancelamento = $client->cancelarNFSe(
                $chaveAcesso,
                'Motivo válido para o cancelamento'
            );
            print_r($cancelamento);
            echo "\n❌ NFS-e cancelada em PRODUÇÃO\n";
        }
        */

    } else {
        echo "\n❌ Erro ao emitir NFS-e\n";
    }

} catch (Exception $e) {
    echo "\n❌ ERRO: " . $e->getMessage() . "\n";
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";

    // Log do erro (implementar conforme sua necessidade)
    error_log("Erro NFS-e Produção: " . $e->getMessage());
}

echo "\n=== FIM ===\n";
