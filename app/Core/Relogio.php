<?php
declare(strict_types=1);

namespace App\Core;

// Datas: o banco guarda texto "AAAA-MM-DD HH:MM:SS" em UTC (funciona igual em SQLite e Postgres).
final class Relogio
{
    public static function agora(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** Converte a data do banco para ISO 8601 (o formato que o JavaScript entende). */
    public static function iso(?string $doBanco): ?string
    {
        return $doBanco === null || $doBanco === '' ? null : str_replace(' ', 'T', substr($doBanco, 0, 19)) . 'Z';
    }

    /** Converte ISO 8601 (vindo do navegador) para o formato do banco. Devolve null se for inválido. */
    public static function doIso(string $iso): ?string
    {
        $tempo = strtotime($iso);
        return $tempo === false ? null : gmdate('Y-m-d H:i:s', $tempo);
    }

    public static function fusoDaLoja(): \DateTimeZone
    {
        try {
            return new \DateTimeZone(Env::get('APP_TIMEZONE', 'America/Sao_Paulo'));
        } catch (\Exception) {
            return new \DateTimeZone('America/Sao_Paulo');
        }
    }

    /** Início do dia de hoje no fuso da loja, devolvido em UTC (para "pedidos de hoje"). */
    public static function inicioDoDiaDaLoja(): string
    {
        $hoje = new \DateTimeImmutable('today', self::fusoDaLoja());
        return $hoje->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
