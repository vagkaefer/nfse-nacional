# Exemplos de Uso - NFS-e Nacional

## `emitir_nfse.php`

Exemplo único de emissão, consulta e cancelamento, que atende homologação e
produção. Todas as credenciais vêm de **variáveis de ambiente** — nunca deixe
senha de certificado em código.

### Configuração

```bash
cp examples/.env.example examples/.env   # e preencha os valores
# ou exporte direto no shell:
export NFSE_AMBIENTE=2                   # 2=homologação (padrão), 1=produção
export NFSE_CERT_PFX=/caminho/cert.pfx
export NFSE_CERT_SENHA='senha'
export NFSE_COD_MUN=4216909
export NFSE_CNPJ='00.000.000/0001-00'

php examples/emitir_nfse.php
```

## Diferenças entre Homologação e Produção

| Campo | Homologação | Produção |
|-------|-------------|----------|
| **Inscrição Municipal (IM)** | ❌ NÃO enviar | ✅ Pode enviar (se município exigir) |
| **Endereço prestador (tpEmit=1)** | ❌ NÃO enviar | ❌ NÃO enviar |
| **`NFSE_AMBIENTE`** | `2` | `1` |
| **URL da API** | `producaorestrita.nfse.gov.br` | `nfse.gov.br` |
| **Validade fiscal** | ❌ Sem validade | ⚠️ Válida juridicamente |

## Troubleshooting

- **"IM do prestador não deve ser informado"** — remova o campo `'im'` em homologação.
- **"Endereço não deve ser informado"** — remova `'endereco'` do prestador quando `tpEmit=1`.
- **"Data de competência posterior à data de emissão"** — use `(new DateTime('-1 day'))->format('Y-m-d')`.
- **Erro na assinatura** — confira caminho, senha e validade do certificado. Certificados
  antigos (cifra RC2-40) exigem o *legacy provider* do OpenSSL 3.x no sistema.

## Dicas

1. Sempre teste em homologação primeiro — notas de produção são reais.
2. Guarde os XMLs das notas emitidas e implemente logs das operações.
3. Incremente `NFSE_NUMERO_DPS` a cada emissão.
4. Monitore a validade do certificado digital.

## Recursos

- [Documentação Oficial NFS-e](https://www.gov.br/nfse/)
- [API Homologação](https://adn.producaorestrita.nfse.gov.br/docs/index.html)
- [API Produção](https://adn.nfse.gov.br/docs/index.html)
- [Códigos IBGE de Municípios](https://www.ibge.gov.br/explica/codigos-dos-municipios.php)
