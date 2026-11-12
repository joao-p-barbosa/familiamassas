<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Banco;
use App\Core\ErroHttp;
use App\Core\Requisicao;
use App\Core\Resposta;
use App\Repositories\CardapioRepository;
use App\Repositories\ImagemRepository;
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

    /**
     * GET /uploads/{nome}: foto do cardápio guardada no banco (Postgres). No SQLite as fotos são arquivos em public/uploads e o
     * servidor web as entrega sem passar por aqui; só chega neste método quem pede uma foto que não existe (ou, no Postgres, todas).
     */
    public function foto(Requisicao $req): void
    {
        $foto = self::fotoGuardada($req->parametro('nome'));
        if ($foto === null) {
            Resposta::pagina('nao-encontrado', [], 404);
            return;
        }
        header('Content-Type: ' . $foto['tipo']);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=31536000, immutable');   // o nome é sorteado: nunca muda de conteúdo
        header('Content-Length: ' . strlen($foto['dados']));
        echo $foto['dados'];
    }

    /**
     * A foto guardada no banco com este nome, ou null (nome fora do padrão sorteado pelo upload, foto que não existe, ou
     * banco que não guarda fotos, o SQLite). O padrão rígido também barra "../" e qualquer coisa estranha.
     * @return array{tipo:string, dados:string}|null
     */
    public static function fotoGuardada(string $nome): ?array
    {
        if (preg_match('/^[a-f0-9]{24}\.(webp|jpg|png)$/', $nome) !== 1 || Banco::driver() !== 'pgsql') {
            return null;
        }
        return (new ImagemRepository())->porNome($nome);
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
