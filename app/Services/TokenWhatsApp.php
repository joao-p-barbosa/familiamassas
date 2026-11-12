<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Env;

/**
 * Token do link que o bot manda no WhatsApp: https://dominio/?t=<token>
 * Guarda o telefone e a validade (2 h) e é assinado com HMAC usando a APP_KEY.
 * Quem não tem a chave não consegue inventar nem alterar um token.
 */
final class TokenWhatsApp
{
    public const VALIDADE_SEGUNDOS = 7200;

    public function gerar(string $telefone, ?int $agora = null): string
    {
        $dados = self::b64(preg_replace('/\D/', '', $telefone) . '.' . (($agora ?? time()) + self::VALIDADE_SEGUNDOS));
        return $dados . '.' . self::b64($this->assinar($dados));
    }

    /** Devolve o telefone se o token for verdadeiro e ainda valer; senão, null. */
    public function validar(string $token, ?int $agora = null): ?string
    {
        if ($token === '' || strlen($token) > 200 || substr_count($token, '.') !== 1) {
            return null;
        }
        [$dados, $assinatura] = explode('.', $token);
        if (!hash_equals(self::b64($this->assinar($dados)), $assinatura)) {
            return null;
        }
        $partes = explode('.', (string) base64_decode(strtr($dados, '-_', '+/'), true));
        if (count($partes) !== 2 || !ctype_digit($partes[0]) || !ctype_digit($partes[1])) {
            return null;
        }
        return (int) $partes[1] >= ($agora ?? time()) ? $partes[0] : null;
    }

    private function assinar(string $dados): string
    {
        $chave = Env::get('APP_KEY');
        if (strlen($chave) < 32) {
            throw new \RuntimeException('APP_KEY ausente ou curta demais no .env.');
        }
        return hash_hmac('sha256', $dados, $chave, true);
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
