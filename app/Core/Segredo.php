<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Guarda um segredo (ex.: o Client Secret do iFood) cifrado no banco: AES-256-GCM com uma chave derivada da APP_KEY, que só
 * existe na variável de ambiente do servidor. Quem lê o banco sozinho (backup, cópia, acesso ao Neon) não consegue ler o segredo.
 * Se a APP_KEY mudar, o segredo antigo deixa de abrir (decifrar devolve null) e o dono digita de novo.
 */
final class Segredo
{
    private const PREFIXO = 'v1:';

    public static function disponivel(): bool
    {
        return Env::get('APP_KEY') !== '' && function_exists('openssl_encrypt');
    }

    private static function chave(): string
    {
        return hash('sha256', 'familiamassas|segredo|' . Env::get('APP_KEY'), true);
    }

    public static function cifrar(string $texto): string
    {
        if (!self::disponivel()) {
            throw new \RuntimeException('APP_KEY ausente: não dá para cifrar o segredo.');
        }
        $iv = random_bytes(12);
        $cifrado = openssl_encrypt($texto, 'aes-256-gcm', self::chave(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cifrado === false) {
            throw new \RuntimeException('Não foi possível cifrar o segredo.');
        }
        return self::PREFIXO . base64_encode($iv . $tag . $cifrado);
    }

    /** O texto original, ou null se o valor está vazio, adulterado ou foi cifrado com outra APP_KEY. */
    public static function decifrar(string $valor): ?string
    {
        if (!str_starts_with($valor, self::PREFIXO) || !self::disponivel()) {
            return null;
        }
        $bytes = base64_decode(substr($valor, strlen(self::PREFIXO)), true);
        if ($bytes === false || strlen($bytes) < 29) {
            return null;
        }
        $texto = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', self::chave(), OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16));
        return $texto === false ? null : $texto;
    }
}
