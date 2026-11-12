<?php
declare(strict_types=1);

namespace App\Core;

// Monta as respostas HTTP (JSON da API, páginas HTML e redirecionamentos).
final class Resposta
{
    public static function json(mixed $dados, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string,string> $campos */
    public static function erro(int $status, string $codigo, string $mensagem, array $campos = [], array $extra = []): void
    {
        $erro = ['codigo' => $codigo, 'mensagem' => $mensagem];
        if ($campos !== []) {
            $erro['campos'] = $campos;
        }
        self::json(['erro' => $erro + $extra], $status);
    }

    /**
     * Mostra uma view. Os nomes começam com "__" para não colidir com as variáveis que a view recebe
     * (uma view pode, por exemplo, receber a sua própria variável $dados).
     * @param array<string,mixed> $__variaveis variáveis disponíveis dentro da view
     */
    public static function pagina(string $__view, array $__variaveis = [], int $__status = 200): void
    {
        http_response_code($__status);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        extract($__variaveis, EXTR_SKIP);
        require RAIZ . '/app/Views/' . $__view . '.php';
    }

    public static function redirecionar(string $para): void
    {
        header('Location: ' . $para, true, 302);
    }
}
