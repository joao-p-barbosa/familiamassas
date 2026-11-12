<?php
// Mapa de endereços do sistema: cada linha liga um endereço a uma função de um Controller.
declare(strict_types=1);

use App\Controllers\AdminCardapioController;
use App\Controllers\AdminConfigController;
use App\Controllers\AdminPedidoController;
use App\Controllers\PainelController;
use App\Controllers\PublicoController;
use App\Core\Roteador;

return static function (Roteador $r): void {
    $publico = new PublicoController();
    $painel = new PainelController();
    $pedidos = new AdminPedidoController();
    $config = new AdminConfigController();
    $cardapio = static fn (): AdminCardapioController => new AdminCardapioController();

    // ---------- Páginas do cliente ----------
    $r->get('/', $publico->paginaCardapio(...));
    $r->get('/pedido/{codigo}', $publico->paginaPedido(...));
    $r->get('/styleguide', $publico->paginaStyleguide(...));

    // ---------- API pública ----------
    $r->get('/api/cardapio', $publico->apiCardapio(...));
    $r->post('/api/pedidos', $publico->apiCriarPedido(...));
    $r->get('/api/pedidos/{codigo}', $publico->apiPedido(...));

    // ---------- Páginas do painel ----------
    $r->get('/painel', $painel->entrada(...));
    $r->get('/painel/login', $painel->paginaLogin(...));
    $r->get('/painel/{tela}', $painel->paginaTela(...));

    // ---------- API do painel (sessão + CSRF) ----------
    $r->post('/api/admin/login', $painel->apiLogin(...));
    $r->post('/api/admin/logout', $painel->apiLogout(...));
    $r->post('/api/admin/senha', $painel->apiTrocarSenha(...));

    $r->get('/api/admin/pedidos', $pedidos->listar(...));
    $r->get('/api/admin/pedidos/{id}', $pedidos->detalhe(...));
    $r->patch('/api/admin/pedidos/{id}/status', $pedidos->mudarStatus(...));
    $r->post('/api/admin/pedidos/{id}/impressao', $pedidos->impressao(...));

    $r->get('/api/admin/cardapio', static fn ($req) => $cardapio()->listar($req));
    $r->post('/api/admin/upload', static fn ($req) => $cardapio()->upload($req));
    $r->post('/api/admin/cardapio/{recurso}', static fn ($req) => $cardapio()->criar($req));
    $r->put('/api/admin/cardapio/{recurso}/{id}', static fn ($req) => $cardapio()->atualizar($req));
    $r->patch('/api/admin/cardapio/{recurso}/{id}/estado', static fn ($req) => $cardapio()->estado($req));
    $r->delete('/api/admin/cardapio/{recurso}/{id}', static fn ($req) => $cardapio()->apagar($req));

    $r->get('/api/admin/configuracoes', $config->ler(...));
    $r->put('/api/admin/configuracoes', $config->salvar(...));
    $r->get('/api/admin/whatsapp', $config->whatsapp(...));
    $r->post('/api/admin/whatsapp/link', $config->linkDeTeste(...));
};
