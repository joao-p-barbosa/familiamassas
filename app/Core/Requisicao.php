<?php
declare(strict_types=1);

namespace App\Core;

// Tudo que chega do navegador numa requisição, num lugar só.
final class Requisicao
{
    public readonly string $metodo;
    public readonly string $caminho;
    /** @var array<string,string> parâmetros da rota, ex.: {id} */
    public array $parametros = [];
    private ?array $corpo = null;

    public function __construct()
    {
        $metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        // Algumas hospedagens compartilhadas bloqueiam PUT/PATCH/DELETE: aceitamos POST com este cabeçalho.
        $sobrescrita = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? '');
        if ($metodo === 'POST' && in_array($sobrescrita, ['PUT', 'PATCH', 'DELETE'], true)) {
            $metodo = $sobrescrita;
        }
        $this->metodo = $metodo;
        $caminho = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $this->caminho = $caminho === '/' ? '/' : rtrim($caminho, '/');
    }

    /** Corpo JSON da requisição, já como array. */
    public function json(): array
    {
        if ($this->corpo === null) {
            $cru = file_get_contents('php://input') ?: '';
            if (strlen($cru) > 200_000) {
                throw new ErroHttp(413, 'grande_demais', 'O envio ficou grande demais.');
            }
            $dados = $cru === '' ? [] : json_decode($cru, true);
            if (!is_array($dados)) {
                throw new ErroHttp(400, 'json_invalido', 'Não entendemos os dados enviados.');
            }
            $this->corpo = $dados;
        }
        return $this->corpo;
    }

    public function consulta(string $chave, string $padrao = ''): string
    {
        $valor = $_GET[$chave] ?? $padrao;
        return is_string($valor) ? $valor : $padrao;
    }

    public function cabecalho(string $nome): string
    {
        return (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $nome))] ?? '');
    }

    public function parametro(string $nome): string
    {
        return $this->parametros[$nome] ?? '';
    }

    public function cookie(string $nome): string
    {
        $valor = $_COOKIE[$nome] ?? '';
        return is_string($valor) ? $valor : '';
    }

    /**
     * IP de quem está acessando (usado no bloqueio de tentativas de login). Atrás de um proxy (Render) o REMOTE_ADDR é o do
     * proxy, e todo mundo cairia no mesmo contador: com TRUST_PROXY=1 vale o primeiro IP de X-Forwarded-For.
     */
    public function ip(): string
    {
        if (Env::get('TRUST_PROXY') === '1') {
            $primeiro = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
            if (filter_var($primeiro, FILTER_VALIDATE_IP) !== false) {
                return $primeiro;
            }
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function https(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
