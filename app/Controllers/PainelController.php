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
    /**
     * Quem pode abrir cada tela e em que grupo do menu lateral ela fica.
     * admin = proprietário (tudo); atendente = Salão (as contas dos clientes), pedidos e cardápio (só marca esgotado).
     * 'oculta' = a tela existe mas não tem item no menu; 'menu_ativo' = qual item do menu fica marcado quando ela está aberta.
     * O painel é uma SPA (public/assets/js/painel/app.js): esta lista também diz ao roteador do navegador o que cada perfil abre.
     */
    public const TELAS = [
        'pedidos'       => ['titulo' => 'Pedidos',                 'grupo' => 'Operação', 'perfis' => ['admin', 'atendente']],
        'atendimento'   => ['titulo' => 'Produtos do atendimento', 'grupo' => 'Operação', 'perfis' => ['admin', 'atendente'], 'oculta' => true, 'menu_ativo' => 'pedidos'],
        'cardapio'      => ['titulo' => 'Cardápio',                'grupo' => 'Loja',     'perfis' => ['admin', 'atendente']],
        'usuarios'      => ['titulo' => 'Usuários',                'grupo' => 'Loja',     'perfis' => ['admin']],
        'configuracoes' => ['titulo' => 'Configurações',           'grupo' => 'Loja',     'perfis' => ['admin']],
        'whatsapp'      => ['titulo' => 'WhatsApp',                'grupo' => 'Loja',     'perfis' => ['admin']],
    ];

    /** Telas que saíram do painel: quem tinha o endereço salvo cai no quadro de pedidos. */
    private const TELAS_RETIRADAS = ['cozinha', 'salao', 'historico'];

    /** Todo mundo entra no quadro de pedidos (os pedidos do salão ficam na coluna "Salão" dele). */
    public static function telaInicial(string $perfil): string
    {
        return '/painel/pedidos';
    }

    /**
     * Telas que o perfil enxerga no menu, na ordem do menu.
     * @return array<string, array{titulo:string, grupo:string, perfis:list<string>}>
     */
    public static function menuDo(string $perfil): array
    {
        return array_filter(self::TELAS, static fn (array $t): bool => in_array($perfil, $t['perfis'], true) && empty($t['oculta']));
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
        if (in_array($tela, self::TELAS_RETIRADAS, true)) {
            // Cozinha, Salão e Histórico do salão saíram: o endereço antigo leva ao quadro (e "?novo=1" abre o novo pedido do salão).
            Resposta::redirecionar(self::telaInicial($usuario['perfil']) . ($req->consulta('novo') !== '' ? '?novo=1' : ''));
            return;
        }
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
            'telaDoMenu' => self::TELAS[$tela]['menu_ativo'] ?? $tela,
            'titulo' => self::TELAS[$tela]['titulo'],
            'menu' => self::menuDo($usuario['perfil']),
            // Para o roteador do navegador: o que este perfil pode abrir (a tela pedida no endereço já foi conferida aqui)
            // e para onde ir quando abrir algo que não pode.
            'telas' => array_map(static fn (array $t): array => ['titulo' => $t['titulo'], 'menu_ativo' => $t['menu_ativo'] ?? null],
                array_filter(self::TELAS, static fn (array $t): bool => in_array($usuario['perfil'], $t['perfis'], true))),
            'inicio' => self::telaInicial($usuario['perfil']),
            'menuRecolhido' => $req->cookie('painel_menu') === 'recolhido',
            'usuario' => $usuario,
            'nomeLoja' => $config->get('nome_loja', 'Nome do Restaurante'),
            'tema' => $config->get('tema', 'italia'),
            'csrf' => Sessao::csrf(),
            'ajustes' => [
                'polling_segundos' => max(3, $config->inteiro('polling_segundos', 5)),
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
    // Senhas e perfis: tela Usuários (AdminUsuarioController).
}
