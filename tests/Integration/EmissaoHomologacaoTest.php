<?php

declare(strict_types=1);

namespace NFSe\Tests\Integration;

use NFSe\Config\Config;
use NFSe\Models\DPS;
use NFSe\Services\NFSeClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Round-trip real contra o ambiente de homologação (produção restrita).
 *
 * Excluído da execução padrão (grupo "integration"). Para rodar:
 *
 *   NFSE_CERT_PFX=/caminho/cert.pfx NFSE_CERT_SENHA=senha NFSE_COD_MUN=4216909 \
 *     vendor/bin/phpunit --group integration
 */
#[Group('integration')]
final class EmissaoHomologacaoTest extends TestCase
{
    public function testEmissaoEConsultaEmHomologacao(): void
    {
        $pfx = getenv('NFSE_CERT_PFX');
        $senha = getenv('NFSE_CERT_SENHA');
        $codMun = getenv('NFSE_COD_MUN');
        $cnpj = getenv('NFSE_CNPJ');

        if ($pfx === false || $senha === false || $codMun === false || $cnpj === false) {
            $this->markTestSkipped(
                'Defina NFSE_CERT_PFX, NFSE_CERT_SENHA, NFSE_COD_MUN e NFSE_CNPJ para rodar a integração.',
            );
        }

        $config = new Config(Config::AMBIENTE_HOMOLOGACAO, $pfx, $senha, $codMun);
        $cliente = new NFSeClient($config);

        $numero = (string) random_int(1, 999999999);
        $dps = (new DPS())
            ->setTpAmb(Config::AMBIENTE_HOMOLOGACAO)
            ->setVerAplic('nfse-nacional-php-teste')
            ->setSerie('999')
            ->setNDPS($numero)
            ->setDCompet(date('Y-m-d'))
            ->setCLocEmi($codMun)
            ->setPrestador([
                'cnpj' => $cnpj,
                'regTrib' => ['opSimpNac' => 3, 'regApTribSN' => 1, 'regEspTrib' => 0],
            ])
            ->setServico([
                'cLocPrestacao' => $codMun,
                'cTribNac' => '01.06.01',
                'xDescServ' => 'Teste de integração da biblioteca nfse-nacional',
            ])
            ->setValores(['vServ' => 1.0, 'pTotTribSN' => 13.45]);

        $resposta = $cliente->emitirNFSe($dps);

        $this->assertArrayHasKey('chaveAcesso', $resposta, 'Emissão falhou: ' . json_encode($resposta));

        $consulta = $cliente->consultarNFSe((string) $resposta['chaveAcesso']);
        $this->assertNotEmpty($consulta);
    }
}
