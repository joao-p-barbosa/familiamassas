<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Requisicao;
use App\Core\Resposta;
use App\Core\Sessao;
use App\Repositories\UsuarioRepository;
use App\Services\UsuarioService;

// Usuários do painel (só o proprietário). Senhas nunca saem do servidor: só são definidas.
final class AdminUsuarioController
{
    /** GET /api/admin/usuarios */
    public function listar(Requisicao $req): void
    {
        $eu = Sessao::exigir($req, ['admin']);
        Resposta::json(['usuarios' => (new UsuarioRepository())->listar(), 'eu' => $eu['id']]);
    }

    /** POST /api/admin/usuarios  {nome, login, perfil, senha} */
    public function criar(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        $criado = (new UsuarioService())->criar($req->json());
        Resposta::json((new UsuarioRepository())->porId($criado['id']), 201);
    }

    /** PUT /api/admin/usuarios/{id}  {nome?, perfil?, ativo?} */
    public function atualizar(Requisicao $req): void
    {
        $eu = Sessao::exigir($req, ['admin'], true);
        Resposta::json((new UsuarioService())->atualizar((int) $req->parametro('id'), $req->json(), $eu));
    }

    /** POST /api/admin/usuarios/{id}/senha  {senha}: define a senha (a pessoa troca depois com o proprietário, se quiser). */
    public function senha(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        (new UsuarioService())->definirSenha((int) $req->parametro('id'), $req->json());
        Resposta::json(['ok' => true]);
    }
}
