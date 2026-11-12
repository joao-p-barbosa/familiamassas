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
