<?php
// Porta de entrada única do site (front controller). Todo endereço que não é arquivo passa por aqui.
declare(strict_types=1);

// No servidor embutido do PHP (desenvolvimento), arquivos reais (CSS, JS, imagens) são entregues direto.
if (PHP_SAPI === 'cli-server') {
    $arquivo = __DIR__ . (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
    if (is_file($arquivo) && pathinfo($arquivo, PATHINFO_EXTENSION) !== 'php') {
        return false;
    }
}

require dirname(__DIR__) . '/app/bootstrap.php';

$roteador = new App\Core\Roteador();
(require RAIZ . '/app/routes.php')($roteador);
$roteador->despachar(new App\Core\Requisicao());
