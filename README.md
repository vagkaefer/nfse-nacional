# NFS-e Nacional - Biblioteca PHP

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-blue)](https://www.php.net)

Biblioteca PHP para integração com o **Sistema Nacional de Nota Fiscal de Serviço Eletrônica (NFS-e)** do governo brasileiro.

## Características

- ✅ Emissão de NFS-e
- ✅ Consulta de NFS-e
- ✅ Cancelamento de NFS-e
- ✅ Suporte a ambientes de Produção e Homologação
- ✅ Assinatura digital com certificado A1 (PFX)
- ✅ Suporte ao Simples Nacional
- ✅ Validação completa de XML conforme schema do governo

## Requisitos

- PHP >= 8.2
- Extensões PHP: OpenSSL, DOM, cURL, JSON
- Certificado Digital A1 (formato .pfx)

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

// Configurar o cliente
$config = new Config(
    Config::AMBIENTE_PRODUCAO,                    // ou Config::AMBIENTE_HOMOLOGACAO
    '/caminho/para/certificado.pfx',              // Caminho do certificado A1
    'senha_do_certificado',                       // Senha do certificado
    '4216909',                                    // Código IBGE do município emissor
    'MeuSistema/1.0.0'                           // Identificação da aplicação
);

$client = new NFSeClient($config);
```

### 2. Emitir NFS-e

```php
// Criar DPS (Declaração de Prestação de Serviço)
$dps = new DPS();

// Configurações básicas
$dps->setTpAmb(Config::AMBIENTE_PRODUCAO)
    ->setVerAplic('MeuSistema/1.0.0')
    ->setSerie('900')
    ->setNDPS('1')
    ->setDCompet((new DateTime('-1 day'))->format('Y-m-d'))
    ->setTpEmit(1)                                // 1=Prestador
    ->setCLocEmi('4216909');                      // Código IBGE do município

// Dados do Prestador (quem emite a nota)
$dps->setPrestador([
    'cnpj' => '00.000.000/0001-00',
    'xNome' => 'RAZAO SOCIAL DO PRESTADOR',
    'fone' => '4999999999',
    'email' => 'contato@empresa.com.br',
    'regTrib' => [
        'opSimpNac' => 3,                         // 3=Simples Nacional ME/EPP
        'regApTribSN' => 1,                       // Regime de apuração
        'regEspTrib' => 0                         // 0=Nenhum regime especial
    ]
]);

// Dados do Tomador (quem contrata o serviço)
$dps->setTomador([
    'cnpj' => '11.111.111/0001-11',
    'xNome' => 'NOME DO TOMADOR',
    'endereco' => [
        'cMun' => '4204202',                      // Código IBGE do município
        'CEP' => '89802-112',
        'xLog' => 'Rua Exemplo',
        'nLog' => '123',
        'xBairro' => 'Centro'
    ],
    'fone' => '4999999999',
    'email' => 'tomador@email.com.br'
]);

// Dados do Serviço
$dps->setServico([
    'cTribNac' => '010601',                       // Código de tributação nacional
    'xDescServ' => 'Consultoria em TI',
    'cLocPrestacao' => '4216909',                 // Local da prestação
    'xInfComp' => 'Informações adicionais aqui'
]);

// Valores
$valorServico = 1000.00;
$dps->setValores([
    'vServ' => $valorServico,
    'pTotTribSN' => 6.00,                         // Percentual de tributos (Simples Nacional)
]);

// Emitir NFS-e
try {
    $resultado = $client->emitirNFSe($dps);

    if (isset($resultado['chNFSe'])) {
        echo "NFS-e emitida com sucesso!\n";
        echo "Chave: " . $resultado['chNFSe'] . "\n";
    }
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
```

### 3. Consultar NFS-e

```php
$chaveAcesso = '42169092230797305000137000000000000321019889323870';

try {
    $nfse = $client->consultarNFSe($chaveAcesso);
    print_r($nfse);
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
```

### 4. Cancelar NFS-e

```php
$chaveAcesso = '42169092230797305000137000000000000321019889323870';
$motivo = 'Nota emitida por engano';

try {
    $resultado = $client->cancelarNFSe($chaveAcesso, $motivo);
    echo "NFS-e cancelada com sucesso!\n";
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
```

<!-- ### 5. Listar NFS-e por Período

```php
try {
    $lista = $client->listarNFSe(
        '2024-01-01',  // Data inicial
        '2024-01-31',  // Data final
        1,             // Página
        50             // Itens por página
    );

    foreach ($lista as $nfse) {
        echo "NFS-e: " . $nfse['chNFSe'] . "\n";
    }
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
``` -->

## Ambientes

### Homologação
```php
$config = new Config(
    Config::AMBIENTE_HOMOLOGACAO,
    '/caminho/certificado.pfx',
    'senha',
    '4216909',
    'MeuApp/1.0'
);
```

### Produção
```php
$config = new Config(
    Config::AMBIENTE_PRODUCAO,
    '/caminho/certificado.pfx',
    'senha',
    '4216909',
    'MeuApp/1.0'
);
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
nfse-nacional/
├── src/
│   └── NFSe/
│       ├── Config/
│       │   └── Config.php
│       ├── Models/
│       │   └── DPS.php
│       ├── Services/
│       │   └── NFSeClient.php
│       └── Utils/
│           └── AssinaturaDigital.php
├── examples/
│   └── emitir_nfse.php
├── composer.json
├── README.md
└── LICENSE
```

## Exemplos

Veja a pasta `examples/` para exemplos de uso.

## Observações Importantes

1. **Certificado Digital**: Você precisa de um certificado A1 válido no formato .pfx
2. **Códigos IBGE**: Use os códigos corretos do município (7 dígitos)
3. **Série**: Em produção, utilize a série fornecida pela prefeitura (geralmente "900")
4. **Data de Competência**: Não pode ser posterior à data de emissão
5. **Endereço do Prestador**: Quando o prestador é o emitente (tpEmit=1), o endereço não deve ser informado
6. **Inscrição Municipal**: Pode não ser obrigatória em alguns municípios

## Documentação Oficial

- [Portal NFS-e Nacional](https://www.gov.br/nfse/)
- [Documentação Técnica SEFIN](https://www.gov.br/nfse/pt-br/documentacao)
- [ADN API Docs (Produção)](https://adn.nfse.gov.br/docs/index.html)
- [ADN API Docs (Homologação)](https://adn.producaorestrita.nfse.gov.br/docs/index.html)

## Tratamento de Erros

```php
try {
    $resultado = $client->emitirNFSe($dps);
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage();
    // Tratar erro conforme necessidade
}
```

### Erros Comuns

- Certificado inválido ou expirado
- Senha incorreta do certificado
- XML inválido (não conforme schema)
- Dados obrigatórios ausentes
- Município não conveniado
- NFS-e já cancelada
- Data de competência posterior à data de emissão
- Endereço do prestador informado quando não deveria

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
