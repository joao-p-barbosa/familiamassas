<?php
// Funções pequenas usadas dentro das views (HTML).
declare(strict_types=1);

/** Escapa texto para HTML. TODA saída de dado em view passa por aqui. */
function e(mixed $texto): string
{
    return htmlspecialchars((string) $texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Caminho de um arquivo de /public/assets com "?v=" (data do arquivo), para o navegador pegar a versão nova. */
function asset(string $caminho): string
{
    $arquivo = RAIZ . '/public/assets/' . $caminho;
    return '/assets/' . $caminho . (is_file($arquivo) ? '?v=' . filemtime($arquivo) : '');
}

/** JSON seguro para embutir dentro de <script type="application/json">. */
function json_embutido(mixed $dados): string
{
    return json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

/** Cor da barra do navegador no celular, por tema (igual a --cor-fundo). */
function cor_do_tema(string $tema): string
{
    return ['italia' => '#f1f2f1', 'linho' => '#f1ece2', 'pedra' => '#eceef0', 'grafite' => '#151817'][$tema] ?? '#f1f2f1';
}

/** 3190 → "R$ 31,90" */
function reais(int $centavos): string
{
    return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
}
