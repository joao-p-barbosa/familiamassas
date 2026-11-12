<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ErroHttp;
use App\Core\Requisicao;
use App\Core\Resposta;
use App\Core\Sessao;
use App\Repositories\ConfigRepository;
use App\Repositories\UsuarioRepository;

// Páginas do painel e login. O conteúdo de cada tela vem da API (AdminPedido, AdminCardapio, AdminConfig).
final class PainelController
{
    /** Quem pode abrir cada tela. A ordem aqui é a ordem do menu. */
    public const TELAS = [
        'pedidos'       => ['titulo' => 'Pedidos',       'perfis' => ['admin', 'atendente']],
        'cozinha'       => ['titulo' => 'Cozinha',       'perfis' => ['admin', 'atendente', 'cozinha']],
        'cardapio'      => ['titulo' => 'Cardápio',      'perfis' => ['admin', 'atendente', 'cozinha']],
        'configuracoes' => ['titulo' => 'Configurações', 'perfis' => ['admin']],
        'whatsapp'      => ['titulo' => 'WhatsApp',      'perfis' => ['admin']],
    ];

    public static function telaInicial(string $perfil): string
    {
        return $perfil === 'cozinha' ? '/painel/cozinha' : '/painel/pedidos';
    }

    public function entrada(Requisicao $req): void
    {
        Sessao::iniciar($req);
        $usuario = Sessao::usuario();
        Resposta::redirecionar($usuario ? self::telaInicial($usuario['perfil']) : '/painel/login');
    }

    public function paginaLogin(Requisicao $req): void
    {
        Sessao::iniciar($req);
        if ($usuario = Sessao::usuario()) {
            Resposta::redirecionar(self::telaInicial($usuario['perfil']));
            return;
        }
        $config = new ConfigRepository();
        Resposta::pagina('painel/login', [
            'nomeLoja' => $config->get('nome_loja', 'Nome do Restaurante'),
            'tema' => $config->get('tema', 'italia'),
            'csrf' => Sessao::csrf(),
        ]);
    }

    public function paginaTela(Requisicao $req): void
    {
        Sessao::iniciar($req);
        $usuario = Sessao::usuario();
        if ($usuario === null) {
            Resposta::redirecionar('/painel/login');
            return;
        }
        $tela = $req->parametro('tela');
        if (!isset(self::TELAS[$tela])) {
            Resposta::pagina('nao-encontrado', [], 404);
            return;
        }
        if (!in_array($usuario['perfil'], self::TELAS[$tela]['perfis'], true)) {
            Resposta::redirecionar(self::telaInicial($usuario['perfil']));
            return;
        }
        $config = new ConfigRepository();
        Resposta::pagina('painel/tela', [
            'tela' => $tela,
            'titulo' => self::TELAS[$tela]['titulo'],
            'menu' => array_filter(self::TELAS, static fn (array $t): bool => in_array($usuario['perfil'], $t['perfis'], true)),
            'usuario' => $usuario,
            'nomeLoja' => $config->get('nome_loja', 'Nome do Restaurante'),
            'tema' => $config->get('tema', 'italia'),
            'csrf' => Sessao::csrf(),
            'ajustes' => [
                'polling_segundos' => max(3, $config->inteiro('polling_segundos', 5)),
                'atraso_minutos' => $config->inteiro('atraso_minutos', 20),
                'comanda_largura_mm' => $config->inteiro('comanda_largura_mm', 80),
                'comanda_mostra_preco' => $config->get('comanda_mostra_preco', '0') === '1',
                'nome_loja' => $config->get('nome_loja', 'Nome do Restaurante'),
            ],
        ]);
    }

    public function apiLogin(Requisicao $req): void
    {
        Sessao::iniciar($req);
        Sessao::exigirCsrf($req);
        $dados = $req->json();
        $login = mb_strtolower(trim((string) ($dados['login'] ?? '')));
        $senha = (string) ($dados['senha'] ?? '');
        if ($login === '' || $senha === '') {
            throw ErroHttp::validacao(['login' => 'Informe usuário e senha.']);
        }

        $usuarios = new UsuarioRepository();
        $chaves = ['ip:' . $req->ip(), 'login:' . mb_substr($login, 0, 60)];
        foreach ($chaves as $chave) {
            if ($usuarios->bloqueadoAte($chave) !== null) {
                throw new ErroHttp(429, 'muitas_tentativas', 'Muitas tentativas seguidas. Espere 5 minutos e tente de novo.');
            }
        }

        $usuario = $usuarios->porLogin($login);
        // Mesmo sem usuário, verificamos um hash qualquer para o tempo de resposta não revelar se o login existe.
        $hash = $usuario['senha_hash'] ?? password_hash('sem-usuario', PASSWORD_DEFAULT);
        if (!password_verify($senha, $hash) || $usuario === null) {
            foreach ($chaves as $chave) {
                $usuarios->registrarFalha($chave);
            }
            throw new ErroHttp(401, 'login_invalido', 'Usuário ou senha não conferem.');
        }
        foreach ($chaves as $chave) {
            $usuarios->limparFalhas($chave);
        }
        if (password_needs_rehash($usuario['senha_hash'], PASSWORD_DEFAULT)) {
            $usuarios->trocarSenha((int) $usuario['id'], password_hash($senha, PASSWORD_DEFAULT));
        }
        Sessao::entrar(['id' => (int) $usuario['id'], 'nome' => $usuario['nome'], 'login' => $usuario['login'], 'perfil' => $usuario['perfil']]);
        Resposta::json(['ir_para' => self::telaInicial($usuario['perfil'])]);
    }

    public function apiLogout(Requisicao $req): void
    {
        Sessao::iniciar($req);
        Sessao::exigirCsrf($req);
        Sessao::sair();
        Resposta::json(['ir_para' => '/painel/login']);
    }

    public function apiTrocarSenha(Requisicao $req): void
    {
        $usuario = Sessao::exigir($req, [], true);
        $dados = $req->json();
        $nova = (string) ($dados['nova'] ?? '');
        $registro = (new UsuarioRepository())->porLogin($usuario['login']);
        if ($registro === null || !password_verify((string) ($dados['atual'] ?? ''), $registro['senha_hash'])) {
            throw ErroHttp::validacao(['atual' => 'A senha atual não confere.']);
        }
        if (mb_strlen($nova) < 8) {
            throw ErroHttp::validacao(['nova' => 'A nova senha precisa de pelo menos 8 caracteres.']);
        }
        (new UsuarioRepository())->trocarSenha($usuario['id'], password_hash($nova, PASSWORD_DEFAULT));
        Resposta::json(['ok' => true]);
    }
}
