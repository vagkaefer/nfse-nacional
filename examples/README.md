# Exemplos de Uso - NFS-e Nacional

Esta pasta contém exemplos práticos de uso da biblioteca para diferentes cenários.

## 📋 Exemplos Disponíveis

### 1. `emitir_homologacao.php`
**Emissão em ambiente de HOMOLOGAÇÃO (testes)**

Características do ambiente de homologação:
- ✅ Ideal para testes e desenvolvimento
- ✅ Notas emitidas NÃO têm validade jurídica
- ❌ **NÃO deve** informar Inscrição Municipal (IM) do prestador
- ❌ **NÃO deve** informar endereço do prestador quando ele é o emitente (tpEmit=1)
- ✅ Use `Config::AMBIENTE_HOMOLOGACAO`

**Quando usar:**
- Durante o desenvolvimento
- Para testar integrações
- Para validar dados antes de ir para produção

**Como executar:**
```bash
php examples/emitir_homologacao.php
```

---

### 2. `emitir_producao.php`
**Emissão em ambiente de PRODUÇÃO (real)**

Características do ambiente de produção:
- ⚠️  Notas emitidas têm **validade jurídica e fiscal**
- ✅ Pode informar Inscrição Municipal (se o município exigir)
- ❌ **NÃO deve** informar endereço do prestador quando ele é o emitente (tpEmit=1)
- ✅ Use `Config::AMBIENTE_PRODUCAO`

**Quando usar:**
- Após testar em homologação
- Para emissão real de notas fiscais
- Em ambiente de produção

**Como executar:**
```bash
php examples/emitir_producao.php
```

---

### 3. `emitir_nfse.php`
**Exemplo completo com todas as operações**

Demonstra:
- Emissão de NFS-e
- Consulta de NFS-e
- Cancelamento de NFS-e (comentado)

---

## 🔧 Configuração dos Exemplos

Antes de executar os exemplos, você precisa:

### 1. Ajustar o certificado digital
```php
__DIR__ . '/../certs/certificado.pfx'  // Caminho do seu certificado
'senha_do_certificado'                 // Senha do certificado
```

### 2. Configurar os dados da sua empresa
```php
'cnpj' => '00.000.000/0001-00',       // Seu CNPJ
'xNome' => 'SUA EMPRESA LTDA',         // Razão social
'im' => '12345',                       // Inscrição Municipal (produção)
'cLocEmi' => '4216909',                // Código IBGE do município
```

### 3. Ajustar o número sequencial
```php
->setNDPS('1')  // Incrementar a cada nova emissão
```

---

## 📊 Diferenças entre Homologação e Produção

| Campo | Homologação | Produção |
|-------|-------------|----------|
| **Inscrição Municipal (IM)** | ❌ NÃO enviar | ✅ Pode enviar (se município exigir) |
| **Endereço Prestador (quando tpEmit=1)** | ❌ NÃO enviar | ❌ NÃO enviar |
| **Ambiente Config** | `AMBIENTE_HOMOLOGACAO` | `AMBIENTE_PRODUCAO` |
| **Ambiente DPS** | `setTpAmb(Config::AMBIENTE_HOMOLOGACAO)` | `setTpAmb(Config::AMBIENTE_PRODUCAO)` |
| **URL da API** | `producaorestrita.nfse.gov.br` | `nfse.gov.br` |
| **Validade Fiscal** | ❌ Sem validade | ✅ Válida juridicamente |

---

## ⚠️  Observações Importantes

### Homologação
- Use para **todos os testes** antes de ir para produção
- Não tem consequências fiscais
- Permite testar diferentes cenários
- Dados podem ser fictícios

### Produção
- ⚠️  **CUIDADO**: Notas emitidas são REAIS
- Tem validade jurídica e fiscal
- Erros podem gerar problemas fiscais
- Use dados reais e corretos
- Teste SEMPRE em homologação primeiro

---

## 🚀 Fluxo Recomendado

```
1. Desenvolvimento
   └── Use emitir_homologacao.php

2. Testes
   └── Execute múltiplos testes em homologação
   └── Valide todos os cenários

3. Validação
   └── Confirme que tudo funciona
   └── Verifique XMLs gerados

4. Produção
   └── Configure dados reais
   └── Execute emitir_producao.php
   └── Monitore resultados
```

---

## 📝 Campos Obrigatórios vs Opcionais

### Sempre Obrigatórios
- CNPJ/CPF do prestador
- Nome do prestador
- Regime tributário
- Dados do tomador
- Dados do serviço
- Valores

### Opcionais (depende do município/situação)
- Inscrição Municipal (IM)
- Endereço do prestador
- Telefone
- E-mail
- Informações complementares

---

## 🐛 Troubleshooting

### Erro: "IM do prestador não deve ser informado"
**Solução:** Remova o campo `'im'` em ambiente de homologação

### Erro: "Endereço não deve ser informado"
**Solução:** Remova o campo `'endereco'` quando `tpEmit=1`

### Erro: "Data de competência posterior à data de emissão"
**Solução:** Use data anterior: `(new DateTime('-1 day'))->format('Y-m-d')`

### Erro: "Arquivo enviado com erro na assinatura"
**Solução:**
- Verifique o caminho do certificado
- Confirme a senha do certificado
- Certifique-se que o certificado está válido

---

## 📚 Recursos Adicionais

- [Documentação Oficial NFS-e](https://www.gov.br/nfse/)
- [API Homologação](https://adn.producaorestrita.nfse.gov.br/docs/index.html)
- [API Produção](https://adn.nfse.gov.br/docs/index.html)
- [Códigos IBGE de Municípios](https://www.ibge.gov.br/explica/codigos-dos-municipios.php)

---

## 💡 Dicas

1. **Sempre teste em homologação primeiro**
2. **Guarde os XMLs das notas emitidas**
3. **Implemente logs de todas as operações**
4. **Valide dados antes de enviar**
5. **Tenha um backup do certificado digital**
6. **Monitore a data de validade do certificado**

---

## 🆘 Suporte

Se encontrar problemas:
1. Verifique os exemplos nesta pasta
2. Consulte o README.md principal
3. Revise a documentação oficial
4. Abra uma issue no GitHub
