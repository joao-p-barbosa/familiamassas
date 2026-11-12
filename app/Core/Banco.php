<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

// Abre a conexão com o banco (SQLite no MVP, Postgres/Neon em produção) e cria as tabelas na primeira vez.
final class Banco
{
    private static ?PDO $pdo = null;

    public static function conexao(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $opcoes = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if (Env::get('DB_DRIVER', 'sqlite') === 'pgsql') {
            self::$pdo = new PDO(Env::get('DB_PG_DSN'), Env::get('DB_PG_USER'), Env::get('DB_PG_PASSWORD'), $opcoes);
            return self::$pdo;
        }

        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new \RuntimeException('A extensão pdo_sqlite do PHP não está ligada. Inicie o servidor com "iniciar.bat" ou habilite extension=pdo_sqlite no php.ini.');
        }
        $arquivo = Env::get('DB_SQLITE_PATH', 'data/restaurante.sqlite');
        if (!preg_match('#^([A-Za-z]:)?[\\\\/]#', $arquivo)) {
            $arquivo = RAIZ . '/' . $arquivo;
        }
        $novo = !is_file($arquivo);
        if ($novo && !is_dir(dirname($arquivo))) {
            mkdir(dirname($arquivo), 0775, true);
        }
        $pdo = new PDO('sqlite:' . $arquivo, null, null, $opcoes);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');   // leitura e escrita ao mesmo tempo sem travar
        $pdo->exec('PRAGMA busy_timeout = 4000');
        self::$pdo = $pdo;

        $temTabelas = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'pedidos'")->fetchColumn() > 0;
        if (!$temTabelas) {
            $pdo->exec((string) file_get_contents(RAIZ . '/data/schema.sqlite.sql'));
            (require RAIZ . '/data/seed.php')($pdo);
        }
        return $pdo;
    }

    /** Executa várias escritas como uma só: ou todas valem, ou nenhuma. */
    public static function transacao(callable $trabalho): mixed
    {
        $pdo = self::conexao();
        $pdo->beginTransaction();
        try {
            $resultado = $trabalho($pdo);
            $pdo->commit();
            return $resultado;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
