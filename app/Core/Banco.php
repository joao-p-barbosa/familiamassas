<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

// Abre a conexão com o banco (SQLite no MVP, Postgres/Neon em produção), cria as tabelas na primeira vez
// e atualiza um banco antigo pelas migrações em data/migracoes/.
final class Banco
{
    /**
     * Versão do esquema que data/schema.sqlite.sql descreve. Sobe junto com o arquivo de migração mais novo
     * (data/migracoes/NNN_nome.php): um banco novo já nasce nesta versão e um banco antigo é atualizado até ela.
     * 1 = esquema da rodada de 12/11 (manhã) · 2 = atendimentos do salão e proteínas separadas.
     */
    public const VERSAO_DO_ESQUEMA = 2;

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
            $pdo->exec('PRAGMA user_version = ' . self::VERSAO_DO_ESQUEMA);
        } else {
            self::migrar($pdo, $arquivo);
        }
        return $pdo;
    }

    /**
     * Atualiza um banco antigo, uma migração por vez, cada uma numa transação (se uma falha, nada dela fica pela metade).
     * Antes de mexer, guarda uma cópia do arquivo em data/backups/. Devolve a lista de versões aplicadas.
     * @param string|null $arquivo caminho do arquivo SQLite, para a cópia de segurança (null = sem cópia)
     * @return list<int>
     */
    public static function migrar(PDO $pdo, ?string $arquivo = null): array
    {
        $versao = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if ($versao === 0) {
            // Banco anterior à numeração de versões. Só é compatível se já tiver o esquema da rodada de 12/11 (versão 1).
            $colunas = array_column($pdo->query('PRAGMA table_info(pedidos)')->fetchAll(), 'name');
            if (!in_array('entrega_lat', $colunas, true)) {
                throw new \RuntimeException('Este banco é de uma versão muito antiga e não pode ser atualizado sozinho. Apague data/restaurante.sqlite para recomeçar com os dados de exemplo.');
            }
            $versao = 1;
        }

        $pendentes = [];
        foreach (glob(RAIZ . '/data/migracoes/*.php') ?: [] as $caminho) {
            if (preg_match('/^(\d+)_/', basename($caminho), $m) === 1 && (int) $m[1] > $versao) {
                $pendentes[(int) $m[1]] = $caminho;
            }
        }
        ksort($pendentes);
        if ($pendentes === []) {
            if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() !== $versao) {
                $pdo->exec('PRAGMA user_version = ' . $versao);
            }
            return [];
        }

        if ($arquivo !== null && is_file($arquivo)) {
            $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');   // junta o que está no arquivo -wal para a cópia sair inteira
            $pasta = dirname($arquivo) . '/backups';
            if (!is_dir($pasta)) {
                mkdir($pasta, 0775, true);
            }
            copy($arquivo, sprintf('%s/%s-antes-da-v%d-%s.sqlite', $pasta, pathinfo($arquivo, PATHINFO_FILENAME), array_key_first($pendentes), date('Ymd-His')));
        }

        $aplicadas = [];
        foreach ($pendentes as $numero => $caminho) {
            $pdo->beginTransaction();
            try {
                (require $caminho)($pdo);
                $pdo->exec('PRAGMA user_version = ' . $numero);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw new \RuntimeException("A atualização do banco para a versão {$numero} falhou e foi desfeita: " . $e->getMessage(), 0, $e);
            }
            $aplicadas[] = $numero;
        }
        return $aplicadas;
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
