<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

// Abre a conexão com o banco (SQLite no computador do desenvolvedor, Postgres/Neon na hospedagem), cria as tabelas na
// primeira vez e atualiza um banco antigo pelas migrações em data/migracoes/.
//
// Qual banco: DB_DRIVER=sqlite | pgsql. Sem DB_DRIVER, a presença de DATABASE_URL (a connection string do Neon) liga o
// Postgres; sem ela, vale o SQLite (data/restaurante.sqlite). Os dois guardam as mesmas tabelas: data/schema.sqlite.sql e
// data/schema.pgsql.sql (tests/regras.php confere que não se distanciam).
final class Banco
{
    /**
     * Versão do esquema que os arquivos data/schema.*.sql descrevem. Sobe junto com o arquivo de migração mais novo
     * (data/migracoes/NNN_nome.php): um banco novo já nasce nesta versão e um banco antigo é atualizado até ela.
     * 1 = esquema da rodada de 12/11 (manhã) · 2 = atendimentos do salão e proteínas separadas.
     * Migração nova deve funcionar nos dois bancos (o PDO que ela recebe diz qual é: ATTR_DRIVER_NAME).
     */
    public const VERSAO_DO_ESQUEMA = 2;

    /** Chave do bloqueio do Postgres que serializa a criação e a migração do banco (pg_advisory_xact_lock). */
    private const TRAVA_DO_ESQUEMA = 726451;

    private static ?PDO $pdo = null;

    public static function driver(): string
    {
        $configurado = Env::get('DB_DRIVER');
        if ($configurado === '') {
            return Env::get('DATABASE_URL') !== '' ? 'pgsql' : 'sqlite';
        }
        return $configurado === 'pgsql' ? 'pgsql' : 'sqlite';
    }

    public static function ehPostgres(PDO $pdo): bool
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }

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
        if (self::driver() === 'pgsql') {
            $pdo = self::conectarPostgres($opcoes);
            self::$pdo = $pdo;
            self::prepararEsquema($pdo, null);
            return $pdo;
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
        $pdo->exec('PRAGMA busy_timeout = 8000');   // primeiro de tudo: quem chega junto espera a vez em vez de falhar
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');   // leitura e escrita ao mesmo tempo sem travar
        self::$pdo = $pdo;

        self::prepararEsquema($pdo, $arquivo);
        return $pdo;
    }

    /** @param array<int,mixed> $opcoes */
    private static function conectarPostgres(array $opcoes): PDO
    {
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            throw new \RuntimeException('A extensão pdo_pgsql do PHP não está ligada.');
        }
        [$dsn, $usuario, $senha] = self::dadosDoPostgres();
        $pdo = new PDO($dsn, $usuario, $senha, $opcoes);
        // Só os testes usam DB_PG_SCHEMA: cada execução cria um schema próprio e apaga no fim, sem tocar nos dados de verdade.
        $schema = Env::get('DB_PG_SCHEMA');
        if ($schema !== '') {
            $pdo->exec('SET search_path TO "' . str_replace('"', '""', $schema) . '"');
        }
        return $pdo;
    }

    /**
     * DATABASE_URL (postgresql://usuario:senha@host/banco?sslmode=require, a "connection string" que o Neon mostra) ou,
     * como alternativa, DB_PG_DSN + DB_PG_USER + DB_PG_PASSWORD.
     * @return array{0:string, 1:string, 2:string} DSN do PDO, usuário e senha
     */
    public static function dadosDoPostgres(): array
    {
        $url = Env::get('DATABASE_URL');
        if ($url === '') {
            return [Env::get('DB_PG_DSN'), Env::get('DB_PG_USER'), Env::get('DB_PG_PASSWORD')];
        }
        $partes = parse_url($url);
        if ($partes === false || empty($partes['host']) || empty($partes['path'])) {
            throw new \RuntimeException('DATABASE_URL inválida: esperado postgresql://usuario:senha@host/banco?sslmode=require');
        }
        parse_str($partes['query'] ?? '', $consulta);
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $partes['host'], $partes['port'] ?? 5432, ltrim($partes['path'], '/'), $consulta['sslmode'] ?? 'require');
        return [$dsn, rawurldecode($partes['user'] ?? ''), rawurldecode($partes['pass'] ?? '')];
    }

    private static function temTabela(PDO $pdo, string $nome): bool
    {
        if (self::ehPostgres($pdo)) {
            $consulta = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?');
        } else {
            $consulta = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
        }
        $consulta->execute([$nome]);
        return (int) $consulta->fetchColumn() > 0;
    }

    public static function versaoDoBanco(PDO $pdo): int
    {
        if (self::ehPostgres($pdo)) {
            return self::temTabela($pdo, 'esquema_versao') ? (int) $pdo->query('SELECT versao FROM esquema_versao WHERE id = 1')->fetchColumn() : 0;
        }
        return (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    }

    private static function gravarVersao(PDO $pdo, int $versao): void
    {
        if (self::ehPostgres($pdo)) {
            $pdo->exec("INSERT INTO esquema_versao (id, versao) VALUES (1, {$versao}) ON CONFLICT (id) DO UPDATE SET versao = EXCLUDED.versao");
            return;
        }
        $pdo->exec('PRAGMA user_version = ' . $versao);
    }

    /** Abre uma transação que só uma requisição de cada vez consegue: as outras esperam. */
    private static function iniciarExclusivo(PDO $pdo): void
    {
        if (self::ehPostgres($pdo)) {
            $pdo->exec('BEGIN');
            $pdo->query('SELECT pg_advisory_xact_lock(' . self::TRAVA_DO_ESQUEMA . ')')->fetchAll();   // solta sozinho no COMMIT/ROLLBACK
            return;
        }
        $pdo->exec('BEGIN IMMEDIATE');
    }

    private static function desfazer(PDO $pdo): void
    {
        try {
            $pdo->exec('ROLLBACK');
        } catch (\Throwable) {
            // a transação já tinha acabado
        }
    }

    /**
     * Deixa o banco no esquema atual: cria as tabelas e os dados de exemplo (banco novo) ou aplica as migrações (banco antigo).
     * Na hospedagem o servidor atende várias requisições ao mesmo tempo e as primeiras chegam juntas: o trabalho acontece sob
     * um bloqueio exclusivo, pedido ANTES de olhar o estado do banco. Quem esperou encontra tudo pronto e não repete nada.
     */
    private static function prepararEsquema(PDO $pdo, ?string $arquivo): void
    {
        // O caso de quase toda requisição: o banco já está na versão certa. Nada a fazer e nada a trancar.
        if (self::temTabela($pdo, 'pedidos') && self::versaoDoBanco($pdo) === self::VERSAO_DO_ESQUEMA) {
            return;
        }

        self::iniciarExclusivo($pdo);
        try {
            if (!self::temTabela($pdo, 'pedidos')) {
                $esquema = self::ehPostgres($pdo) ? 'schema.pgsql.sql' : 'schema.sqlite.sql';
                $pdo->exec((string) file_get_contents(RAIZ . '/data/' . $esquema));
                (require RAIZ . '/data/seed.php')($pdo);
                self::gravarVersao($pdo, self::VERSAO_DO_ESQUEMA);
                $pdo->exec('COMMIT');
                return;
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            self::desfazer($pdo);
            throw $e;
        }
        self::migrar($pdo, $arquivo);
    }

    /**
     * Atualiza um banco antigo, uma migração por vez, cada uma numa transação (se uma falha, nada dela fica pela metade).
     * No SQLite, antes de mexer, guarda uma cópia do arquivo em data/backups/ (no Postgres/Neon a proteção é o histórico
     * do próprio serviço). Devolve a lista de versões aplicadas.
     * @param string|null $arquivo caminho do arquivo SQLite, para a cópia de segurança (null = sem cópia)
     * @return list<int>
     */
    public static function migrar(PDO $pdo, ?string $arquivo = null): array
    {
        $versao = self::versaoDoBanco($pdo);
        if ($versao === 0) {
            if (self::ehPostgres($pdo)) {
                throw new \RuntimeException('Este banco Postgres não tem a tabela esquema_versao: não dá para saber em que versão ele está.');
            }
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
            if (self::versaoDoBanco($pdo) !== $versao) {
                self::gravarVersao($pdo, $versao);
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
            self::iniciarExclusivo($pdo);   // uma requisição de cada vez; as outras esperam e veem a versão já atualizada
            try {
                if (self::versaoDoBanco($pdo) >= $numero) {
                    $pdo->exec('ROLLBACK');   // outra requisição aplicou esta migração enquanto esta esperava
                    continue;
                }
                (require $caminho)($pdo);
                self::gravarVersao($pdo, $numero);
                $pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                self::desfazer($pdo);
                throw new \RuntimeException("A atualização do banco para a versão {$numero} falhou e foi desfeita: " . $e->getMessage(), 0, $e);
            }
            $aplicadas[] = $numero;
        }
        return $aplicadas;
    }

    /**
     * INSERT que devolve o id criado. No SQLite é o lastInsertId; no Postgres a mesma consulta termina em RETURNING id
     * (lastInsertId lá depende do nome da sequência). `$comId = false` para tabelas sem coluna "id" (chave composta ou texto).
     * @param array<int,mixed> $valores
     */
    public static function inserir(PDO $pdo, string $sql, array $valores = [], bool $comId = true): int
    {
        $postgres = self::ehPostgres($pdo);
        $consulta = $pdo->prepare($postgres && $comId ? $sql . ' RETURNING id' : $sql);
        $consulta->execute($valores);
        if (!$comId) {
            return 0;
        }
        return $postgres ? (int) $consulta->fetchColumn() : (int) $pdo->lastInsertId();
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
