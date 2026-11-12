<?php
declare(strict_types=1);

namespace App\Core;

use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Guarda as sessões do painel na tabela `sessoes` (só no Postgres). Na hospedagem gratuita o disco do contêiner some quando o
 * serviço reinicia ou dorme, e com as sessões em arquivo todo mundo seria deslogado a cada vez; no banco elas sobrevivem.
 * Validade de 12 h sem uso (a mesma das sessões em arquivo).
 */
final class SessaoNoBanco implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private const VALIDADE_S = 43200;

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $consulta = Banco::conexao()->prepare('SELECT dados FROM sessoes WHERE id = ? AND atualizado_em >= ?');
        $consulta->execute([$id, gmdate('Y-m-d H:i:s', time() - self::VALIDADE_S)]);
        $dados = $consulta->fetchColumn();
        return is_string($dados) ? $dados : '';
    }

    public function write(string $id, string $data): bool
    {
        Banco::conexao()->prepare(
            'INSERT INTO sessoes (id, dados, atualizado_em) VALUES (?, ?, ?)
             ON CONFLICT (id) DO UPDATE SET dados = EXCLUDED.dados, atualizado_em = EXCLUDED.atualizado_em',
        )->execute([$id, $data, Relogio::agora()]);
        return true;
    }

    public function destroy(string $id): bool
    {
        Banco::conexao()->prepare('DELETE FROM sessoes WHERE id = ?')->execute([$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $consulta = Banco::conexao()->prepare('DELETE FROM sessoes WHERE atualizado_em < ?');
        $consulta->execute([gmdate('Y-m-d H:i:s', time() - self::VALIDADE_S)]);
        return $consulta->rowCount();
    }

    /** use_strict_mode: só vale um id de sessão que existe (o servidor não aceita um id inventado pelo navegador). */
    public function validateId(string $id): bool
    {
        $consulta = Banco::conexao()->prepare('SELECT COUNT(*) FROM sessoes WHERE id = ? AND atualizado_em >= ?');
        $consulta->execute([$id, gmdate('Y-m-d H:i:s', time() - self::VALIDADE_S)]);
        return (int) $consulta->fetchColumn() > 0;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }
}
