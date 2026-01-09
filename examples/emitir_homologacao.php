<?php

/**
 * Exemplo de Emissão de NFS-e em AMBIENTE DE HOMOLOGAÇÃO
 *
 * IMPORTANTE: Ambiente de homologação tem regras específicas:
 * - NÃO deve informar Inscrição Municipal (IM) do prestador
 * - NÃO deve informar endereço do prestador quando ele é o emitente (tpEmit=1)
 * - Use sempre o ambiente Config::AMBIENTE_HOMOLOGACAO
 */

// Carrega o autoloader do Composer
require_once __DIR__ . '/../vendor/autoload.php';

use NFSe\Config\Config;
use NFSe\Models\DPS;
use NFSe\Services\NFSeClient;

try {
    // 1. CONFIGURAÇÃO - HOMOLOGAÇÃO
    echo "=== EMISSÃO EM AMBIENTE DE HOMOLOGAÇÃO ===\n\n";

    $config = new Config(
        Config::AMBIENTE_HOMOLOGACAO,                      // AMBIENTE DE HOMOLOGAÇÃO
        __DIR__ . '/../certs/certificado.pfx',             // Caminho do certificado digital
        'senha_do_certificado',                            // Senha do certificado
        '4216909',                                          // Código município IBGE (7 dígitos)
        'MeuSistema/1.0.0'                                 // Identificação do sistema
    );

    // 2. CRIAR CLIENTE
    $client = new NFSeClient($config);

    // 3. CRIAR DPS (Declaração de Prestação de Serviço)
    $dps = new DPS();

    // Configurações básicas - HOMOLOGAÇÃO
    $dps->setTpAmb(Config::AMBIENTE_HOMOLOGACAO)           // IMPORTANTE: Usar HOMOLOGAÇÃO
        ->setVerAplic('MeuSistema/1.0.0')
        ->setSerie('900')                                   // Série conforme município
        ->setNDPS('1')                                      // Número sequencial da DPS
        ->setDCompet((new DateTime('-1 day', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d'))
        ->setTpEmit(1)                                      // 1=Prestador (quem emite)
        ->setCLocEmi('4216909');                            // Código IBGE do município emissor

    // 4. DADOS DO PRESTADOR - HOMOLOGAÇÃO
    // ATENÇÃO: Em homologação, quando o prestador é o emitente (tpEmit=1):
    // - NÃO informar 'im' (Inscrição Municipal)
    // - NÃO informar 'endereco'
    $dps->setPrestador([
        'cnpj' => '00.000.000/0001-00',                     // CNPJ do prestador
        // 'im' => '12345',                                 // ❌ NÃO informar em homologação
        'xNome' => 'EMPRESA PRESTADORA LTDA',
        // 'endereco' => [...],                             // ❌ NÃO informar quando tpEmit=1
        'fone' => '4999999999',
        'email' => 'contato@empresa.com.br',
        'regTrib' => [
            'opSimpNac' => 3,                               // 3=Simples Nacional ME/EPP
            'regApTribSN' => 1,                             // Regime de apuração
            'regEspTrib' => 0                               // 0=Nenhum regime especial
        ]
    ]);

    // 5. DADOS DO TOMADOR (quem contrata o serviço)
    $dps->setTomador([
        'cnpj' => '11.111.111/0001-11',
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
        'xInfComp' => 'Informações adicionais da nota fiscal'
    ]);

    // 7. VALORES
    $valorServico = 1000.00;

    $dps->setValores([
        'vServ' => $valorServico,                           // Valor do serviço
        'pTotTribSN' => 6.00,                               // Percentual tributos Simples Nacional
    ]);

    // 8. EMITIR NFS-e
    echo "Emitindo NFS-e em HOMOLOGAÇÃO...\n";
    $resultado = $client->emitirNFSe($dps);

    echo "\n=== RESULTADO DA EMISSÃO ===\n";
    print_r($resultado);

    // 9. PROCESSAR RESULTADO
    if (isset($resultado['chNFSe']) || isset($resultado['chaveAcesso'])) {
        $chaveAcesso = $resultado['chNFSe'] ?? $resultado['chaveAcesso'];

        echo "\n✅ NFS-e emitida com SUCESSO em HOMOLOGAÇÃO!\n";
        echo "Chave de acesso: {$chaveAcesso}\n";

        // 10. CONSULTAR NFS-e (opcional)
        echo "\n=== CONSULTANDO NFS-E ===\n";
        $nfse = $client->consultarNFSe($chaveAcesso);
        print_r($nfse);

        // 11. CANCELAR NFS-e (opcional - descomentar se necessário)
        /*
        echo "\n=== CANCELANDO NFS-E ===\n";
        $cancelamento = $client->cancelarNFSe(
            $chaveAcesso,
            'Teste de cancelamento em homologação'
        );
        print_r($cancelamento);
        */
    } else {
        echo "\n❌ Erro ao emitir NFS-e\n";
    }

} catch (Exception $e) {
    echo "\n❌ ERRO: " . $e->getMessage() . "\n";
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== FIM ===\n";
