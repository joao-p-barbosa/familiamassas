<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ErroHttp;
use App\Core\Sessao;
use App\Repositories\UsuarioRepository;

/**
 * Gestão dos usuários do painel (tela Usuários, só o proprietário): criar, mudar nome, perfil e situação, e definir senha.
 * Regras: login único (letras minúsculas, números, ponto, hífen e sublinhado); senha com 8+ caracteres; o sistema nunca
 * fica sem um proprietário ativo; ninguém desativa nem rebaixa a si mesmo (para não se trancar para fora).
 */
final class UsuarioService
{
    public const ROTULOS = ['admin' => 'Proprietário', 'atendente' => 'Atendente'];

    public function __construct(private readonly UsuarioRepository $usuarios = new UsuarioRepository()) {}

    /** @return array{id:int} */
    public function criar(array $dados): array
    {
        $erros = [];
        $nome = self::nome($dados['nome'] ?? '', $erros);
        $login = mb_strtolower(trim((string) ($dados['login'] ?? '')));
        if (preg_match('/^[a-z0-9._-]{3,30}$/', $login) !== 1) {
            $erros['login'] = 'Use de 3 a 30 caracteres: letras minúsculas, números, ponto, hífen ou sublinhado.';
        } elseif ($this->usuarios->loginEmUso($login)) {
            $erros['login'] = 'Já existe um usuário com este login.';
        }
        $perfil = self::perfil($dados['perfil'] ?? '', $erros);
        $senha = (string) ($dados['senha'] ?? '');
        if (mb_strlen($senha) < 8) {
            $erros['senha'] = 'A senha precisa de pelo menos 8 caracteres.';
        }
        if ($erros !== []) {
            throw ErroHttp::validacao($erros);
        }
        return ['id' => $this->usuarios->criar($nome, $login, $perfil, password_hash($senha, PASSWORD_DEFAULT))];
    }

    /** @param array{id:int} $quem quem está mexendo (não pode desativar nem rebaixar a si mesmo) */
    public function atualizar(int $id, array $dados, array $quem): array
    {
        $atual = $this->usuarios->porId($id) ?? throw ErroHttp::naoEncontrado('Usuário não encontrado.');
        $erros = [];
        $nome = self::nome($dados['nome'] ?? $atual['nome'], $erros);
        $perfil = self::perfil($dados['perfil'] ?? $atual['perfil'], $erros);
        $ativo = array_key_exists('ativo', $dados) ? (bool) $dados['ativo'] : $atual['ativo'];
        if ($erros !== []) {
            throw ErroHttp::validacao($erros);
        }
        if ($id === $quem['id'] && (!$ativo || $perfil !== 'admin')) {
            throw new ErroHttp(409, 'proprio_usuario', 'Você não pode desativar nem tirar o perfil de proprietário do seu próprio usuário.');
        }
        $deixaDeSerAdminAtivo = $atual['perfil'] === 'admin' && $atual['ativo'] && ($perfil !== 'admin' || !$ativo);
        if ($deixaDeSerAdminAtivo && $this->usuarios->adminsAtivos() <= 1) {
            throw new ErroHttp(409, 'ultimo_proprietario', 'Este é o único proprietário ativo: crie ou ative outro antes.');
        }
        $this->usuarios->atualizar($id, $nome, $perfil, $ativo);
        return $this->usuarios->porId($id);
    }

    public function definirSenha(int $id, array $dados): void
    {
        $this->usuarios->porId($id) ?? throw ErroHttp::naoEncontrado('Usuário não encontrado.');
        $senha = (string) ($dados['senha'] ?? '');
        if (mb_strlen($senha) < 8) {
            throw ErroHttp::validacao(['senha' => 'A senha precisa de pelo menos 8 caracteres.']);
        }
        $this->usuarios->trocarSenha($id, password_hash($senha, PASSWORD_DEFAULT));
    }

    private static function nome(mixed $valor, array &$erros): string
    {
        $nome = mb_substr(trim(is_string($valor) ? $valor : ''), 0, 80);
        if (mb_strlen($nome) < 2) {
            $erros['nome'] = 'Informe o nome.';
        }
        return $nome;
    }

    private static function perfil(mixed $valor, array &$erros): string
    {
        $perfil = is_string($valor) ? $valor : '';
        if (!in_array($perfil, Sessao::PERFIS, true)) {
            $erros['perfil'] = 'Escolha Proprietário ou Atendente.';
        }
        return $perfil;
    }
}
