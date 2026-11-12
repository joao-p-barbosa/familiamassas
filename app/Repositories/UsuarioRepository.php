<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Banco;
use App\Core\Relogio;

// Usuários do painel e controle de tentativas de login.
final class UsuarioRepository
{
    public function porLogin(string $login): ?array
    {
        $sql = Banco::conexao()->prepare('SELECT id, nome, login, senha_hash, perfil FROM usuarios WHERE login = ? AND ativo = 1');
        $sql->execute([$login]);
        return $sql->fetch() ?: null;
    }

    public function trocarSenha(int $id, string $hash): void
    {
        Banco::conexao()->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')->execute([$hash, $id]);
    }

    // ---------- Gestão de usuários (tela Usuários, só o proprietário) ----------

    /** @return list<array{id:int, nome:string, login:string, perfil:string, ativo:bool, criado_em:?string}> */
    public function listar(): array
    {
        return array_map($this->converter(...), Banco::conexao()->query('SELECT id, nome, login, perfil, ativo, criado_em FROM usuarios ORDER BY ativo DESC, nome')->fetchAll());
    }

    /** @return array{id:int, nome:string, login:string, perfil:string, ativo:bool, criado_em:?string}|null */
    public function porId(int $id): ?array
    {
        $sql = Banco::conexao()->prepare('SELECT id, nome, login, perfil, ativo, criado_em FROM usuarios WHERE id = ?');
        $sql->execute([$id]);
        $linha = $sql->fetch();
        return $linha ? $this->converter($linha) : null;
    }

    public function loginEmUso(string $login, ?int $excetoId = null): bool
    {
        $sql = Banco::conexao()->prepare('SELECT COUNT(*) FROM usuarios WHERE login = ? AND id <> ?');
        $sql->execute([$login, $excetoId ?? 0]);
        return (int) $sql->fetchColumn() > 0;
    }

    /** Proprietários ativos (o sistema nunca fica sem nenhum). */
    public function adminsAtivos(): int
    {
        return (int) Banco::conexao()->query("SELECT COUNT(*) FROM usuarios WHERE perfil = 'admin' AND ativo = 1")->fetchColumn();
    }

    public function criar(string $nome, string $login, string $perfil, string $hash): int
    {
        return Banco::inserir(Banco::conexao(), 'INSERT INTO usuarios (nome, login, senha_hash, perfil, ativo, criado_em) VALUES (?, ?, ?, ?, 1, ?)',
            [$nome, $login, $hash, $perfil, Relogio::agora()]);
    }

    public function atualizar(int $id, string $nome, string $perfil, bool $ativo): void
    {
        Banco::conexao()->prepare('UPDATE usuarios SET nome = ?, perfil = ?, ativo = ? WHERE id = ?')->execute([$nome, $perfil, $ativo ? 1 : 0, $id]);
    }

    private function converter(array $linha): array
    {
        return [
            'id' => (int) $linha['id'],
            'nome' => (string) $linha['nome'],
            'login' => (string) $linha['login'],
            'perfil' => (string) $linha['perfil'],
            'ativo' => (int) $linha['ativo'] === 1,
            'criado_em' => Relogio::iso($linha['criado_em'] ?? null),
        ];
    }

    /** Devolve até quando a chave está bloqueada (ou null se pode tentar). */
    public function bloqueadoAte(string $chave): ?string
    {
        $sql = Banco::conexao()->prepare('SELECT bloqueado_ate FROM tentativas_login WHERE chave = ?');
        $sql->execute([$chave]);
        $ate = $sql->fetchColumn();
        return is_string($ate) && $ate > Relogio::agora() ? $ate : null;
    }

    /** Conta uma tentativa errada. A partir da 5ª, bloqueia por 5 minutos. */
    public function registrarFalha(string $chave): void
    {
        $pdo = Banco::conexao();
        $agora = Relogio::agora();
        $sql = $pdo->prepare('SELECT tentativas, atualizado_em FROM tentativas_login WHERE chave = ?');
        $sql->execute([$chave]);
        $linha = $sql->fetch();
        $recente = $linha && strtotime($linha['atualizado_em'] . ' UTC') > time() - 900;
        $tentativas = $recente ? (int) $linha['tentativas'] + 1 : 1;
        $bloqueio = $tentativas >= 5 ? gmdate('Y-m-d H:i:s', time() + 300) : null;
        if ($linha) {
            $pdo->prepare('UPDATE tentativas_login SET tentativas = ?, bloqueado_ate = ?, atualizado_em = ? WHERE chave = ?')
                ->execute([$tentativas, $bloqueio, $agora, $chave]);
        } else {
            $pdo->prepare('INSERT INTO tentativas_login (chave, tentativas, bloqueado_ate, atualizado_em) VALUES (?, ?, ?, ?)')
                ->execute([$chave, $tentativas, $bloqueio, $agora]);
        }
    }

    public function limparFalhas(string $chave): void
    {
        Banco::conexao()->prepare('DELETE FROM tentativas_login WHERE chave = ?')->execute([$chave]);
    }
}
