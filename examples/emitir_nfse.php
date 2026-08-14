<?php

declare(strict_types=1);

/**
 * Exemplo de emissão de NFS-e (homologação ou produção).
 *
 * Todas as credenciais e dados vêm de variáveis de ambiente — nunca deixe
 * senha de certificado em código. Veja o arquivo .env.example nesta pasta.
 *
 * Execução:
 *   export NFSE_AMBIENTE=2                       # 2=homologação (padrão), 1=produção
 *   export NFSE_CERT_PFX=/caminho/cert.pfx
 *   export NFSE_CERT_SENHA='senha'
 *   export NFSE_COD_MUN=4216909
 *   export NFSE_CNPJ='00.000.000/0001-00'
 *   php examples/emitir_nfse.php
 *
 * Regras que valem para os dois ambientes quando o prestador é o emitente
 * (tpEmit=1): NÃO informar endereço do prestador. Em homologação, também
 * NÃO informar a Inscrição Municipal (IM).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use NFSe\Config\Config;
use NFSe\Models\DPS;
use NFSe\Services\NFSeClient;

function env(string $nome, ?string $padrao = null): string
{
    $valor = getenv($nome);
    if ($valor === false || $valor === '') {
        if ($padrao !== null) {
            return $padrao;
        }
        fwrite(STDERR, "Variável de ambiente obrigatória ausente: {$nome}\n");
        fwrite(STDERR, "Veja examples/.env.example\n");
        exit(1);
    }

    return $valor;
}

$ambiente = (int) env('NFSE_AMBIENTE', (string) Config::AMBIENTE_HOMOLOGACAO);
$rotulo = $ambiente === Config::AMBIENTE_PRODUCAO ? 'PRODUÇÃO' : 'HOMOLOGAÇÃO';

try {
    echo "=== EMISSÃO DE NFS-e — AMBIENTE DE {$rotulo} ===\n\n";

    if ($ambiente === Config::AMBIENTE_PRODUCAO) {
        echo "⚠️  ATENÇÃO: notas emitidas em produção têm validade jurídica e fiscal.\n\n";
    }

    $config = new Config(
        $ambiente,
        env('NFSE_CERT_PFX'),
        env('NFSE_CERT_SENHA'),
        env('NFSE_COD_MUN'),
        env('NFSE_VER_APLIC', 'MeuSistema/1.0.0'),
    );

    $client = new NFSeClient($config);

    $dps = (new DPS())
        ->setTpAmb($ambiente)
        ->setVerAplic($config->getVersaoAplicativo())
        ->setSerie(env('NFSE_SERIE', '900'))
        ->setNDPS(env('NFSE_NUMERO_DPS', '1'))
        ->setDCompet((new DateTime('-1 day', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d'))
        ->setTpEmit(1)
        ->setCLocEmi($config->getCodigoMunicipioIBGE());

    $dps->setPrestador([
        'cnpj' => env('NFSE_CNPJ'),
        // 'im' => '12345',            // Apenas em produção, se o município exigir
        'xNome' => env('NFSE_RAZAO_SOCIAL', 'EMPRESA PRESTADORA LTDA'),
        'fone' => '4999999999',
        'email' => 'contato@empresa.com.br',
        'regTrib' => [
            'opSimpNac' => 3,          // 3=Simples Nacional ME/EPP
            'regApTribSN' => 1,
            'regEspTrib' => 0,
        ],
    ]);

    $dps->setTomador([
        'cnpj' => '11.111.111/0001-11',
        'xNome' => 'EMPRESA TOMADORA LTDA',
        'endereco' => [
            'cMun' => '4204202',
            'CEP' => '89802-112',
            'xLog' => 'Rua Exemplo',
            'nLog' => '123',
            'xBairro' => 'Centro',
        ],
        'fone' => '4999999999',
        'email' => 'tomador@empresa.com.br',
    ]);

    $dps->setServico([
        'cTribNac' => '010601',
        'xDescServ' => 'Consultoria em Tecnologia da Informação',
        'cLocPrestacao' => $config->getCodigoMunicipioIBGE(),
        'xInfComp' => 'Informações adicionais da nota fiscal',
    ]);

    $dps->setValores([
        'vServ' => 1000.00,
        'pTotTribSN' => 6.00,          // Percentual de tributos (Simples Nacional)
    ]);

    echo "Emitindo NFS-e...\n";
    $resultado = $client->emitirNFSe($dps);

    echo "\n=== RESULTADO DA EMISSÃO ===\n";
    print_r($resultado);

    $chaveAcesso = $resultado['chNFSe'] ?? $resultado['chaveAcesso'] ?? null;
    if ($chaveAcesso === null) {
        echo "\n❌ Erro ao emitir NFS-e\n";
        exit(1);
    }

    echo "\n✅ NFS-e emitida com sucesso em {$rotulo}!\n";
    echo "Chave de acesso: {$chaveAcesso}\n";

    echo "\n=== CONSULTANDO NFS-e ===\n";
    print_r($client->consultarNFSe((string) $chaveAcesso));

    // Cancelamento (descomente se necessário):
    // $client->cancelarNFSe((string) $chaveAcesso, 'Motivo do cancelamento', 1);
} catch (Exception $e) {
    echo "\n❌ ERRO: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== FIM ===\n";
