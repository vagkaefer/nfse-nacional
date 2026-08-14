<?php

declare(strict_types=1);

namespace NFSe\Models\Dps;

use DOMDocument;
use DOMElement;

/**
 * Helpers de montagem de XML compartilhados pelos builders da DPS.
 */
final class Xml
{
    /**
     * Acrescenta um elemento simples ao pai quando a chave existe e não está vazia.
     *
     * @param array<string, mixed> $dados
     */
    public static function opcional(DOMDocument $dom, DOMElement $pai, array $dados, string $chave, ?string $tag = null): void
    {
        if (!isset($dados[$chave]) || $dados[$chave] === '' || $dados[$chave] === []) {
            return;
        }

        $pai->appendChild($dom->createElement($tag ?? $chave, (string) $dados[$chave]));
    }

    /**
     * Acrescenta um elemento monetário/percentual, formatado com 2 casas decimais.
     *
     * @param array<string, mixed> $dados
     */
    public static function opcionalValor(DOMDocument $dom, DOMElement $pai, array $dados, string $chave, ?string $tag = null): void
    {
        if (!isset($dados[$chave]) || $dados[$chave] === '') {
            return;
        }

        $pai->appendChild($dom->createElement($tag ?? $chave, self::valor($dados[$chave])));
    }

    public static function valor(mixed $valor): string
    {
        return number_format((float) $valor, 2, '.', '');
    }

    /**
     * Cria um elemento filho e o anexa ao pai, devolvendo o filho.
     */
    public static function filho(DOMDocument $dom, DOMElement $pai, string $tag): DOMElement
    {
        $element = $dom->createElement($tag);
        $pai->appendChild($element);

        return $element;
    }

    /**
     * Remove apenas a pontuação de máscara, preservando caracteres alfanuméricos.
     *
     * O CNPJ passa a admitir letras a partir de julho/2026 (NT 009), então não é
     * mais possível descartar tudo que não for dígito.
     */
    public static function documento(string $valor): string
    {
        return (string) preg_replace('/[^A-Za-z0-9]/', '', $valor);
    }

    public static function digitos(string $valor): string
    {
        return (string) preg_replace('/\D/', '', $valor);
    }

    /**
     * Monta o bloco de escolha de identificação de pessoa (CNPJ | CPF | NIF | cNaoNIF).
     *
     * @param array<string, mixed> $dados
     */
    public static function identificacao(DOMDocument $dom, DOMElement $pai, array $dados): void
    {
        if (isset($dados['cnpj'])) {
            $pai->appendChild($dom->createElement('CNPJ', self::documento((string) $dados['cnpj'])));

            return;
        }

        if (isset($dados['cpf'])) {
            $pai->appendChild($dom->createElement('CPF', self::digitos((string) $dados['cpf'])));

            return;
        }

        if (isset($dados['nif'])) {
            $pai->appendChild($dom->createElement('NIF', (string) $dados['nif']));

            return;
        }

        if (isset($dados['cNaoNIF'])) {
            $pai->appendChild($dom->createElement('cNaoNIF', (string) $dados['cNaoNIF']));
        }
    }
}
