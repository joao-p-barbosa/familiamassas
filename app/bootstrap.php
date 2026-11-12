<?php
// Ponto de partida do PHP: carrega classes, variáveis de ambiente e trata erros.
declare(strict_types=1);

define('RAIZ', dirname(__DIR__));

// Autoload simples: App\Core\Router  ->  app/Core/Router.php
spl_autoload_register(static function (string $classe): void {
    if (!str_starts_with($classe, 'App\\')) {
        return;
    }
    $arquivo = RAIZ . '/app/' . str_replace('\\', '/', substr($classe, 4)) . '.php';
    if (is_file($arquivo)) {
        require $arquivo;
    }
});

require RAIZ . '/app/Views/ajudantes.php';

App\Core\Env::carregar(RAIZ . '/.env');
date_default_timezone_set('UTC'); // o banco guarda tudo em UTC; a loja converte só na hora de mostrar

$emDev = App\Core\Env::get('APP_ENV', 'producao') === 'dev';
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(static function (Throwable $e) use ($emDev): void {
    error_log('[erro] ' . get_class($e) . ': ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    if ($e instanceof App\Core\ErroHttp) {
        App\Core\Resposta::erro($e->status, $e->codigo, $e->getMessage(), $e->campos);
        return;
    }
    $mensagem = $emDev ? $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' : 'Algo deu errado do nosso lado. Tente de novo em instantes.';
    $caminho = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (str_starts_with($caminho, '/api/')) {
        App\Core\Resposta::erro(500, 'erro_interno', $mensagem);
        return;
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Erro</title><p style="font-family:system-ui;padding:2rem">'
        . htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') . '</p>';
});
