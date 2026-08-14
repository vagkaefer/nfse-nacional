# NFS-e Nacional - Biblioteca PHP

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-blue)](https://www.php.net)

Biblioteca PHP para integração com o **Sistema Nacional de Nota Fiscal de Serviço Eletrônica (NFS-e)** do governo brasileiro.

## Características

- ✅ Emissão de NFS-e (DPS assinada com XMLDSig)
- ✅ Consulta de NFS-e e de DPS
- ✅ Cancelamento de NFS-e (evento e101101)
- ✅ Download do XML e do PDF (DANFSe) da NFS-e
- ✅ Geração local de DANFSe como fallback (quando o endpoint oficial está em rate limit)
- ✅ Listagem de NFS-e por faixa de números de DPS
- ✅ Validação criptográfica de assinaturas XMLDSig
- ✅ Suporte a ambientes de Produção e Homologação
- ✅ Certificado A1 (PFX) com autenticação mTLS
- ✅ Suporte ao Simples Nacional

## Requisitos

- PHP >= 8.2
- Extensões PHP: OpenSSL, DOM, cURL, JSON
- Certificado Digital A1 (formato .pfx)
- **Certificados antigos** cifrados com RC2-40-CBC exigem o binário `openssl`
  com o *legacy provider* do OpenSSL 3.x disponível no sistema (a biblioteca
  faz o fallback automaticamente; certificados modernos são lidos em memória,
  sem depender do binário)

## Instalação

```bash
composer require vagnerkaefer/nfse-nacional
```

## Uso Básico

### 1. Configuração

```php
use NFSe\Config\Config;
use NFSe\Services\NFSeClient;
use NFSe\Models\DPS;

$config = new Config(
    Config::AMBIENTE_PRODUCAO,                    // ou Config::AMBIENTE_HOMOLOGACAO
    '/caminho/para/certificado.pfx',              // Caminho do certificado A1
    getenv('NFSE_CERT_SENHA'),                    // Senha do certificado (use env var!)
    '4216909',                                    // Código IBGE do município emissor
    'MeuSistema/1.0.0',                           // Identificação da aplicação
);

$client = new NFSeClient($config);
```

### 2. Emitir NFS-e

```php
$dps = (new DPS())
    ->setTpAmb(Config::AMBIENTE_PRODUCAO)
    ->setVerAplic('MeuSistema/1.0.0')
    ->setSerie('900')
    ->setNDPS('1')
    ->setDCompet((new DateTime('-1 day'))->format('Y-m-d'))
    ->setTpEmit(1)                                // 1=Prestador
    ->setCLocEmi('4216909');

$dps->setPrestador([
    'cnpj' => '00.000.000/0001-00',
    'xNome' => 'RAZAO SOCIAL DO PRESTADOR',
    'regTrib' => [
        'opSimpNac' => 3,                         // 3=Simples Nacional ME/EPP
        'regApTribSN' => 1,
        'regEspTrib' => 0,
    ],
]);

$dps->setTomador([
    'cnpj' => '11.111.111/0001-11',
    'xNome' => 'NOME DO TOMADOR',
    'endereco' => [
        'cMun' => '4204202',
        'CEP' => '89802-112',
        'xLog' => 'Rua Exemplo',
        'nLog' => '123',
        'xBairro' => 'Centro',
    ],
]);

$dps->setServico([
    'cTribNac' => '010601',
    'xDescServ' => 'Consultoria em TI',
    'cLocPrestacao' => '4216909',
]);

$dps->setValores([
    'vServ' => 1000.00,
    'pTotTribSN' => 6.00,                         // Percentual de tributos (Simples Nacional)
]);

$resultado = $client->emitirNFSe($dps);
$chaveAcesso = $resultado['chNFSe'] ?? $resultado['chaveAcesso'];
```

### 3. Consultar, baixar e cancelar

```php
// Consulta pela chave de acesso (50 dígitos)
$nfse = $client->consultarNFSe($chaveAcesso);

// Consulta DPS pelo ID (para descobrir a chave de acesso)
$dados = $client->consultarDPS($idDPS);

// Download do XML (opcionalmente salvando em arquivo)
$xml = $client->baixarXML($chaveAcesso, '/tmp/nfse.xml');

// Download do PDF. Se o endpoint oficial falhar (rate limit 429 etc.),
// o DANFSe é gerado localmente a partir do XML.
$pdf = $client->baixarPDF($chaveAcesso, '/tmp/danfse.pdf');

// Cancelamento (código do motivo: 1=Erro na emissão, 2=Serviço não prestado, 9=Outros)
$client->cancelarNFSe($chaveAcesso, 'Nota emitida por engano', 1);

// Eventos da NFS-e
$eventos = $client->consultarEventos($chaveAcesso);

// Listagem por faixa de números de DPS (1 requisição HTTP por número!)
$lista = $client->listarNFSePorFaixa('4216909', '00000000000100', '900', 1, 50);
```

### Tratamento de erros

Todas as falhas lançam exceções de `NFSe\Exception\`:

```php
use NFSe\Exception\ApiException;        // Erro HTTP da API (tem getStatusCode()/getBody())
use NFSe\Exception\CertificadoException; // Problema com o certificado
use NFSe\Exception\AssinaturaException;  // Problema na assinatura XML
use NFSe\Exception\NFSeException;        // Base de todas

try {
    $resultado = $client->emitirNFSe($dps);
} catch (ApiException $e) {
    echo "API retornou HTTP {$e->getStatusCode()}: {$e->getBody()}";
} catch (NFSeException $e) {
    echo "Erro: " . $e->getMessage();
}
```

## Códigos Importantes

### Tipo de Emitente (tpEmit)
- `1` - Prestador
- `2` - Tomador
- `3` - Intermediário

### Regime Tributário (opSimpNac)
- `1` - Não Optante pelo Simples Nacional
- `2` - MEI
- `3` - ME/EPP (Simples Nacional)

### Tributação do ISSQN (tribISSQN)
- `1` - Tributável
- `2` - Isento
- `3` - Imune
- `4` - Exigibilidade Suspensa
- `5` - Não Tributável

### Retenção do ISSQN (tpRetISSQN)
- `1` - Não retido
- `2` - Retido pelo tomador
- `3` - Retido pelo intermediário

## Estrutura do Projeto

```
src/NFSe/
├── Certificate/
│   └── Certificado.php          # Carga do PFX, PEMs temporários, CNPJ do CN
├── Config/
│   └── Config.php               # Ambientes, URLs, User-Agent, opções do DANFSe
├── Exception/
│   ├── NFSeException.php        # Base
│   ├── ApiException.php         # Erros HTTP da API
│   ├── CertificadoException.php
│   └── AssinaturaException.php
├── Http/
│   ├── HttpClient.php           # Transporte cURL + mTLS
│   ├── HttpResponse.php
│   └── ResponseParser.php       # JSON/XML → array, mapeamento de erros
├── Models/
│   ├── DPS.php                  # XML da DPS
│   └── PedidoRegistroEvento.php # XML de eventos (cancelamento)
├── Services/
│   └── NFSeClient.php           # Verbos da API
└── Utils/
    ├── AssinaturaDigital.php    # XMLDSig: assinar e validar
    ├── DANFSeDados.php          # Extração XML → dados do DANFSe
    ├── DANFSeGenerator.php      # Renderização do PDF (TCPDF)
    └── Ids.php                  # IDs de DPS e de eventos
```

## Exemplos

Veja a pasta [`examples/`](examples/) — as credenciais vêm de variáveis de
ambiente (há um `.env.example`).

## Observações Importantes

1. **Certificado Digital**: certificado A1 válido no formato .pfx. Nunca
   commite o certificado nem a senha — use variáveis de ambiente.
2. **Códigos IBGE**: use os códigos corretos do município (7 dígitos).
3. **Série**: em produção, utilize a série fornecida pela prefeitura (geralmente "900").
4. **Data de competência**: não pode ser posterior à data de emissão.
5. **Endereço do prestador**: quando o prestador é o emitente (tpEmit=1), não informe.
6. **Rate limit do PDF**: o endpoint oficial de DANFSe retorna 429 com facilidade;
   para downloads em lote, use `baixarPDF($chave, sleepSeconds: 20)`.

## Desenvolvimento

```bash
composer install
composer test        # PHPUnit (testes de unidade)
composer phpstan     # Análise estática (nível 6, sem baseline)
composer cs-check    # Code style (PER-CS 2.0) — cs-fix para aplicar
```

O CI (GitHub Actions) roda a suíte em PHP 8.2, 8.3 e 8.4. O `composer.lock`
não é versionado (política comum para bibliotecas): o CI instala dependências
frescas dentro dos ranges do `composer.json`, exercitando o mesmo cenário dos
consumidores.

Há também um teste de integração real contra a homologação, excluído do CI:

```bash
NFSE_CERT_PFX=... NFSE_CERT_SENHA=... NFSE_COD_MUN=... NFSE_CNPJ=... \
  vendor/bin/phpunit --group integration
```

## Migração v1 → v2

A v2 reorganizou a biblioteca. Principais mudanças de compatibilidade:

- Exceções agora são tipadas (`NFSe\Exception\*`) em vez de `\Exception` genérica.
- `AssinaturaDigital` recebe um `NFSe\Certificate\Certificado` no construtor
  (antes recebia caminho e senha do PFX).
- `NFSeClient::gerarIdDPS()` foi removido — use `NFSe\Utils\Ids::dps()`.
- `baixarPDF()` não dorme mais 20 s por padrão (`$sleepSeconds` agora é `0`)
  e o fallback local de DANFSe foi reativado.
- `listarNFSePorFaixa()` propaga erros de rede/HTTP (antes eram silenciosamente
  ignorados); apenas 404 é tratado como "não encontrada".
- O ID do evento de cancelamento é determinístico (`PRE` + chave + `101101`),
  conforme o XSD oficial (antes usava timestamp, com risco de colisão).
- User-Agent e metadados do DANFSe são configuráveis via `Config`
  (`setUserAgent()`, `setDanfseOptions()`).

## Documentação Oficial

- [Portal NFS-e Nacional](https://www.gov.br/nfse/)
- [Documentação Técnica SEFIN](https://www.gov.br/nfse/pt-br/documentacao)
- [ADN API Docs (Produção)](https://adn.nfse.gov.br/docs/index.html)
- [ADN API Docs (Homologação)](https://adn.producaorestrita.nfse.gov.br/docs/index.html)

## Licença

MIT License - veja o arquivo [LICENSE](LICENSE) para mais detalhes.

## Suporte

Para reportar problemas ou sugerir melhorias, abra uma issue no GitHub.

## Contribuindo

Contribuições são bem-vindas! Por favor:

1. Fork o projeto
2. Crie uma branch para sua feature (`git checkout -b feature/MinhaFeature`)
3. Commit suas mudanças (`git commit -m 'Adiciona MinhaFeature'`)
4. Push para a branch (`git push origin feature/MinhaFeature`)
5. Abra um Pull Request

## Autor

Vagner Kaefer - [vagner@kaefer.eng.br](mailto:vagner@kaefer.eng.br)

## Agradecimentos

- Governo Federal pela documentação e infraestrutura do Sistema Nacional de NFS-e, por não lançar uma portaria em Dezembro, obrigando o uso do ambiente nacional já em Janeiro, época boa de implantar mudanças, evitando que todas as empresas do país tenham suas integrações quebradas do dia pra noite, e não posso deixar de citar, obrigado por fornecer o serviço com eficiência e sem erros em Janeiro (Contem Ironia (e bastante))
