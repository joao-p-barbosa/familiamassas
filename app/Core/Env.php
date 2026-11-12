<?php
declare(strict_types=1);

namespace App\Core;

// Lê o arquivo .env (que fica fora da pasta pública) e guarda os valores em memória.
final class Env
{
    /** @var array<string,string> */
    private static array $valores = [];

    public static function carregar(string $arquivo): void
    {
        if (!is_file($arquivo)) {
            return;
        }
        foreach (file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linha) {
            $linha = trim($linha);
            if ($linha === '' || $linha[0] === '#' || !str_contains($linha, '=')) {
                continue;
            }
            [$chave, $valor] = explode('=', $linha, 2);
            self::$valores[trim($chave)] = trim(trim($valor), "\"'");
        }
    }

    /** Troca um valor em memória (usado pelos testes para apontar para um banco temporário). */
    public static function definir(string $chave, string $valor): void
    {
        self::$valores[$chave] = $valor;
    }

    public static function get(string $chave, string $padrao = ''): string
    {
        $valor = self::$valores[$chave] ?? '';
        return $valor !== '' ? $valor : $padrao;
    }
}
