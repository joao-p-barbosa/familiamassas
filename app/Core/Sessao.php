<?php
declare(strict_types=1);

namespace App\Core;

use App\Repositories\UsuarioRepository;

// Sessão do painel: quem está logado, proteção CSRF e checagem de perfil.
final class Sessao
{
    private static bool $iniciada = false;

    public static function iniciar(Requisicao $req): void
    {
        if (self::$iniciada) {
            return;
        }
        if (Banco::driver() === 'pgsql') {
            // Na hospedagem o disco do contêiner é temporário: as sessões ficam no banco e sobrevivem a reinícios.
            session_set_save_handler(new SessaoNoBanco(), true);
        } else {
            // Sessões ficam numa pasta nossa, fora de /public: em hospedagem compartilhada a pasta temporária
            // é dividida com outros sites, que podem apagar sessões mais cedo.
            $pasta = RAIZ . '/data/sessoes';
            if (!is_dir($pasta)) {
                mkdir($pasta, 0700, true);
            }
            session_save_path($pasta);
        }
        ini_set('session.gc_maxlifetime', '43200');   // validade de 12 h sem uso
        ini_set('session.use_strict_mode', '1');
        session_name('painel');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $req->https(),
            'httponly' => true,       // o JavaScript não lê o cookie
            'samesite' => 'Lax',
        ]);
        session_start();
        self::$iniciada = true;
    }

    /** Perfis que existem. admin = proprietário. Sessão de um perfil que deixou de existir (ex.: cozinha) não vale mais. */
    public const PERFIS = ['admin', 'atendente'];

    /**
     * Quem está logado, conferido no banco a cada requisição: usuário desativado sai na hora, e nome ou perfil
     * trocados na tela Usuários valem já no próximo clique, sem precisar entrar de novo.
     * @return array{id:int, nome:string, login:string, perfil:string}|null
     */
    public static function usuario(): ?array
    {
        $usuario = $_SESSION['usuario'] ?? null;
        if (!is_array($usuario) || !isset($usuario['id'])) {
            return null;
        }
        $atual = (new UsuarioRepository())->porId((int) $usuario['id']);
        if ($atual === null || !$atual['ativo'] || !in_array($atual['perfil'], self::PERFIS, true)) {
            unset($_SESSION['usuario']);
            return null;
        }
        $usuario = ['id' => $atual['id'], 'nome' => $atual['nome'], 'login' => $atual['login'], 'perfil' => $atual['perfil']];
        $_SESSION['usuario'] = $usuario;
        return $usuario;
    }

    /** @param array{id:int, nome:string, login:string, perfil:string} $usuario */
    public static function entrar(array $usuario): void
    {
        session_regenerate_id(true);   // evita reaproveitar uma sessão antiga depois do login
        $_SESSION['usuario'] = $usuario;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public static function sair(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function csrf(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    /** Toda escrita no painel precisa mandar o token no cabeçalho X-CSRF-Token. */
    public static function exigirCsrf(Requisicao $req): void
    {
        if (!hash_equals(self::csrf(), $req->cabecalho('X-CSRF-Token'))) {
            throw new ErroHttp(403, 'csrf', 'Sua sessão expirou. Recarregue a página e tente de novo.');
        }
    }

    /**
     * Garante login (e perfil, se informado) numa rota da API.
     * @param list<string> $perfis
     * @return array{id:int, nome:string, login:string, perfil:string}
     */
    public static function exigir(Requisicao $req, array $perfis = [], bool $escrita = false): array
    {
        self::iniciar($req);
        $usuario = self::usuario();
        if ($usuario === null) {
            throw new ErroHttp(401, 'nao_autenticado', 'Entre no painel para continuar.');
        }
        if ($perfis !== [] && !in_array($usuario['perfil'], $perfis, true)) {
            throw new ErroHttp(403, 'sem_permissao', 'Seu perfil não tem acesso a isso.');
        }
        if ($escrita) {
            self::exigirCsrf($req);
        }
        return $usuario;
    }
}
