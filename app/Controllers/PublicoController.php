<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ErroHttp;
use App\Core\Requisicao;
use App\Core\Resposta;
use App\Repositories\CardapioRepository;
use App\Repositories\PedidoRepository;
use App\Services\AcompanhamentoDoCliente;
use App\Services\LojaService;
use App\Services\PedidoService;
use App\Services\TokenWhatsApp;

// Lado do cliente: páginas do cardápio e a API pública.
final class PublicoController
{
    /** Cardápio + dados da loja, no formato que o front usa. */
    public static function dadosCardapio(): array
    {
        return ['loja' => (new LojaService())->publica()] + (new CardapioRepository())->publico();
    }

    public function paginaCardapio(Requisicao $req): void
    {
        // Link vindo do WhatsApp: ?t=<token>. Token inválido ou vencido é ignorado (segue como pedido normal).
        $t = $req->consulta('t');
        $telefone = $t !== '' ? (new TokenWhatsApp())->validar($t) : null;
        Resposta::pagina('cardapio', [
            'dados' => self::dadosCardapio(),
            'whatsapp' => $telefone !== null ? ['t' => $t, 'telefone' => $telefone] : null,
            // Pedidos deste navegador (cookie): a Home mostra o acompanhamento mesmo depois de atualizar a página.
            'acompanhamento' => (new AcompanhamentoDoCliente())->paraHome($req),
        ]);
    }

    public function paginaPedido(Requisicao $req): void
    {
        $pedido = (new PedidoRepository())->porCodigo($req->parametro('codigo'));
        if ($pedido === null) {
            Resposta::pagina('nao-encontrado', [], 404);
            return;
        }
        if (!in_array($pedido['status'], PedidoRepository::ENCERRADOS, true)) {
            (new AcompanhamentoDoCliente())->registrar($req, $pedido['codigo']);
        }
        Resposta::pagina('pedido', [
            'loja' => (new LojaService())->publica(),
            'pedido' => (new PedidoService())->publico($pedido),
        ]);
    }

    public function paginaStyleguide(Requisicao $req): void
    {
        Resposta::pagina('styleguide', ['loja' => (new LojaService())->publica()]);
    }

    public function apiCardapio(Requisicao $req): void
    {
        Resposta::json(self::dadosCardapio());
    }

    public function apiCriarPedido(Requisicao $req): void
    {
        $criado = (new PedidoService())->criar($req->json());
        (new AcompanhamentoDoCliente())->registrar($req, $criado['codigo']);
        Resposta::json(['numero' => $criado['id'], 'codigo' => $criado['codigo']], 201);
    }

    /** POST /api/pedidos/{codigo}/dispensar: tira o pedido da Home deste navegador (botão "Dispensar" do pedido encerrado). */
    public function apiDispensar(Requisicao $req): void
    {
        (new AcompanhamentoDoCliente())->remover($req, $req->parametro('codigo'));
        Resposta::json(['ok' => true]);
    }

    public function apiPedido(Requisicao $req): void
    {
        $pedido = (new PedidoRepository())->porCodigo($req->parametro('codigo'));
        if ($pedido === null) {
            throw ErroHttp::naoEncontrado('Pedido não encontrado.');
        }
        Resposta::json((new PedidoService())->publico($pedido));
    }
}
