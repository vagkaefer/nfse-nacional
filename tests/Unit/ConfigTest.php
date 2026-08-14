<?php

declare(strict_types=1);

namespace NFSe\Tests\Unit;

use NFSe\Config\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testUrlsDeProducao(): void
    {
        $config = new Config(Config::AMBIENTE_PRODUCAO);

        $this->assertSame('https://sefin.nfse.gov.br/SefinNacional', $config->getUrlBase());
        $this->assertSame('https://adn.nfse.gov.br/cnc', $config->getUrlCNC());
        $this->assertSame('https://adn.nfse.gov.br/danfse', $config->getUrlPDF());
    }

    public function testUrlsDeHomologacao(): void
    {
        $config = new Config(Config::AMBIENTE_HOMOLOGACAO);

        $this->assertSame('https://sefin.producaorestrita.nfse.gov.br/SefinNacional', $config->getUrlBase());
        $this->assertSame('https://adn.producaorestrita.nfse.gov.br/cnc', $config->getUrlCNC());
        $this->assertSame('https://adn.producaorestrita.nfse.gov.br/danfse', $config->getUrlPDF());
    }

    public function testAmbientePadraoEHomologacao(): void
    {
        $config = new Config();

        $this->assertSame(Config::AMBIENTE_HOMOLOGACAO, $config->getAmbiente());
    }

    public function testConstrutorEGetters(): void
    {
        $config = new Config(
            Config::AMBIENTE_PRODUCAO,
            '/caminho/cert.pfx',
            'segredo',
            '4216909',
            '2.0.0',
        );

        $this->assertSame(Config::AMBIENTE_PRODUCAO, $config->getAmbiente());
        $this->assertSame('/caminho/cert.pfx', $config->getCertificadoPfx());
        $this->assertSame('segredo', $config->getCertificadoSenha());
        $this->assertSame('4216909', $config->getCodigoMunicipioIBGE());
        $this->assertSame('2.0.0', $config->getVersaoAplicativo());
    }

    public function testSetters(): void
    {
        $config = new Config();
        $config
            ->setAmbiente(Config::AMBIENTE_PRODUCAO)
            ->setCertificadoPfx('/outro/cert.pfx')
            ->setCertificadoSenha('outra-senha')
            ->setCodigoMunicipioIBGE('4205407')
            ->setVersaoAplicativo('3.1.4');

        $this->assertSame(Config::AMBIENTE_PRODUCAO, $config->getAmbiente());
        $this->assertSame('/outro/cert.pfx', $config->getCertificadoPfx());
        $this->assertSame('outra-senha', $config->getCertificadoSenha());
        $this->assertSame('4205407', $config->getCodigoMunicipioIBGE());
        $this->assertSame('3.1.4', $config->getVersaoAplicativo());
    }
}
