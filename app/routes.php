<?php
// Mapa de endereços do sistema: cada linha liga um endereço a uma função de um Controller.
declare(strict_types=1);

use App\Controllers\AdminCardapioController;
use App\Controllers\AdminAssistenteController;
use App\Controllers\AdminAtendimentoController;
use App\Controllers\AdminConfigController;
use App\Controllers\AdminPedidoController;
use App\Controllers\AdminUsuarioController;
use App\Controllers\FaturamentoController;
use App\Controllers\IntegracoesController;
use App\Controllers\PainelController;
use App\Controllers\PublicoController;
use App\Controllers\WhatsAppController;
use App\Core\Roteador;

return static function (Roteador $r): void {
    $publico = new PublicoController();
    $painel = new PainelController();
    $pedidos = new AdminPedidoController();
    $atendimentos = new AdminAtendimentoController();
    $config = new AdminConfigController();
    $assistente = new AdminAssistenteController();
    $cardapio = static fn (): AdminCardapioController => new AdminCardapioController();
    $whatsapp = new WhatsAppController();
    $faturamento = static fn (): FaturamentoController => new FaturamentoController();

    // ---------- Páginas do cliente ----------
    $r->get('/', $publico->paginaCardapio(...));
    $r->get('/pedido/{codigo}', $publico->paginaPedido(...));
    $r->get('/styleguide', $publico->paginaStyleguide(...));
    $r->get('/uploads/{nome}', $publico->foto(...));

    // ---------- API pública ----------
    $r->get('/api/cardapio', $publico->apiCardapio(...));
    $r->post('/api/pedidos', $publico->apiCriarPedido(...));
    $r->get('/api/pedidos/{codigo}', $publico->apiPedido(...));
    $r->post('/api/pedidos/{codigo}/dispensar', $publico->apiDispensar(...));

    // ---------- Páginas do painel ----------
    $r->get('/painel', $painel->entrada(...));
    $r->get('/painel/login', $painel->paginaLogin(...));
    $r->get('/painel/{tela}', $painel->paginaTela(...));

    // ---------- API do painel (sessão + CSRF) ----------
    $r->post('/api/admin/login', $painel->apiLogin(...));
    $r->post('/api/admin/logout', $painel->apiLogout(...));

    $r->get('/api/admin/pedidos', $pedidos->listar(...));
    $r->get('/api/admin/pedidos/{id}', $pedidos->detalhe(...));
    $r->patch('/api/admin/pedidos/{id}/status', $pedidos->mudarStatus(...));
    $r->post('/api/admin/pedidos/{id}/impressao', $pedidos->impressao(...));

    // Salão: atendimentos (a conta de cada cliente), os pedidos de cada um e o fechamento da conta
    $r->get('/api/admin/atendimentos', $atendimentos->listar(...));
    $r->post('/api/admin/atendimentos', $atendimentos->abrir(...));
    $r->get('/api/admin/atendimentos/{id}', $atendimentos->detalhe(...));
    $r->post('/api/admin/atendimentos/{id}/pedidos', $atendimentos->adicionarPedido(...));
    $r->post('/api/admin/atendimentos/{id}/fechar', $atendimentos->fechar(...));
    $r->post('/api/admin/atendimentos/{id}/servir', $atendimentos->servir(...));
    $r->get('/api/admin/historico', $atendimentos->historico(...));

    $r->get('/api/admin/cardapio', static fn ($req) => $cardapio()->listar($req));
    $r->post('/api/admin/upload', static fn ($req) => $cardapio()->upload($req));
    $r->put('/api/admin/cardapio-precos', static fn ($req) => $cardapio()->precos($req));
    $r->post('/api/admin/cardapio/{recurso}', static fn ($req) => $cardapio()->criar($req));
    $r->put('/api/admin/cardapio/{recurso}/{id}', static fn ($req) => $cardapio()->atualizar($req));
    $r->patch('/api/admin/cardapio/{recurso}/{id}/estado', static fn ($req) => $cardapio()->estado($req));
    $r->delete('/api/admin/cardapio/{recurso}/{id}', static fn ($req) => $cardapio()->apagar($req));

    // Faturamento (D101): Vendas, cancelamento de venda e Fechamento do dia
    $r->get('/api/admin/vendas', static fn ($req) => $faturamento()->vendas($req));
    $r->post('/api/admin/vendas/cancelar', static fn ($req) => $faturamento()->cancelar($req));
    $r->get('/api/admin/fechamento', static fn ($req) => $faturamento()->calendario($req));
    $r->get('/api/admin/fechamento/{data}', static fn ($req) => $faturamento()->dia($req));
    $r->post('/api/admin/fechamento/{data}', static fn ($req) => $faturamento()->fechar($req));
    $r->post('/api/admin/fechamento/{id}/reabrir', static fn ($req) => $faturamento()->reabrir($req));

    $r->get('/api/admin/configuracoes', $config->ler(...));
    $r->put('/api/admin/configuracoes', $config->salvar(...));
    // Assistente com IA (D70)
    $r->get('/api/admin/assistente', $assistente->ler(...));
    $r->put('/api/admin/assistente', $assistente->salvar(...));
    $r->get('/api/admin/assistente/saldo', $assistente->saldo(...));
    $r->get('/api/admin/assistente/prompt', $assistente->prompt(...));
    $r->post('/api/admin/assistente/testar', $assistente->testar(...));
    $r->post('/api/admin/assistente/recargas', $assistente->recarregar(...));
    $r->delete('/api/admin/assistente/recargas/{id}', $assistente->apagarRecarga(...));
    $r->post('/api/admin/whatsapp/link', $config->linkDeTeste(...));

    // Usuários do painel (só o proprietário)
    $usuarios = new AdminUsuarioController();
    $r->get('/api/admin/usuarios', $usuarios->listar(...));
    $r->post('/api/admin/usuarios', $usuarios->criar(...));
    $r->put('/api/admin/usuarios/{id}', $usuarios->atualizar(...));
    $r->delete('/api/admin/usuarios/{id}', $usuarios->apagar(...));
    $r->post('/api/admin/usuarios/{id}/senha', $usuarios->senha(...));

    // ---------- WhatsApp (Cloud API) ----------
    // Webhook da Meta: sem sessão; vale a assinatura X-Hub-Signature-256 (POST) e o verify token (GET).
    $r->get('/webhook/whatsapp.php', $whatsapp->verificar(...));
    $r->post('/webhook/whatsapp.php', $whatsapp->receber(...));
    $r->get('/cron/whatsapp', $whatsapp->cron(...));
    $r->post('/api/conector/sinal', $whatsapp->conectorSinal(...));          // canal QR: só o Node (segredo no cabeçalho)
    $r->post('/api/conector/mensagens', $whatsapp->conectorMensagens(...));
    $r->post('/api/conector/equipe', $whatsapp->conectorEquipe(...));          // alguém da loja respondeu pelo celular (D106)
    $r->post('/api/admin/whatsapp/fila/liberar', $whatsapp->liberarFila(...));
    $r->post('/api/admin/whatsapp/fila/descartar', $whatsapp->descartarFila(...));
    $r->get('/api/admin/whatsapp', $whatsapp->situacao(...));
    $r->post('/api/admin/whatsapp/simular', $whatsapp->simular(...));
    $r->get('/api/admin/whatsapp/conversa', $whatsapp->conversa(...));
    $r->post('/api/admin/whatsapp/conversas/{telefone}/responder', $whatsapp->responder(...));
    $r->post('/api/admin/whatsapp/conversas/{telefone}/bot', $whatsapp->devolverAoBot(...));

    // ---------- iFood (D76 a D78) ----------
    $integracoes = new IntegracoesController();
    $r->get('/api/admin/ifood', $integracoes->ifood(...));
    $r->get('/api/admin/pedidos/{id}/ifood/motivos', $integracoes->ifoodMotivos(...));
    $r->put('/api/admin/ifood/credenciais', $integracoes->ifoodCredenciais(...));
    $r->delete('/api/admin/ifood/credenciais', $integracoes->ifoodRemoverCredenciais(...));
    $r->post('/api/admin/ifood/conectar', $integracoes->ifoodConectar(...));
    $r->post('/api/admin/ifood/autorizar', $integracoes->ifoodAutorizar(...));
    $r->post('/api/admin/ifood/desconectar', $integracoes->ifoodDesconectar(...));
    $r->put('/api/admin/ifood/ajustes', $integracoes->ifoodAjustes(...));
    $r->post('/api/admin/ifood/simular', $integracoes->ifoodSimular(...));
    $r->post('/api/admin/ifood/sincronizar', $integracoes->ifoodSincronizar(...));
    $r->get('/cron/ifood', $integracoes->ifoodCron(...));

    // ---------- Impressora e agente de impressão (D79 a D81) ----------
    $r->get('/api/admin/impressora', $integracoes->impressora(...));
    $r->put('/api/admin/impressora', $integracoes->impressoraSalvar(...));
    $r->post('/api/admin/impressora/codigo', $integracoes->impressoraCodigo(...));
    $r->post('/api/admin/impressora/instalador', $integracoes->impressoraInstalador(...));
    $r->post('/api/admin/impressora/confirmar', $integracoes->impressoraConfirmar(...));
    $r->post('/api/admin/impressora/revogar', $integracoes->impressoraRevogar(...));
    $r->post('/api/admin/impressora/teste', $integracoes->impressoraTeste(...));
    $r->get('/api/admin/impressora/agente/{arquivo}', $integracoes->impressoraArquivo(...));
    $r->post('/api/impressora/ciclo', $integracoes->agenteCiclo(...));          // o agente do computador da loja (código no cabeçalho)
    $r->post('/api/impressora/resultado', $integracoes->agenteResultado(...));
};
