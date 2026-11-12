<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ErroHttp;
use App\Core\Relogio;
use App\Core\Requisicao;
use App\Core\Resposta;
use App\Core\Sessao;
use App\Repositories\PedidoRepository;
use App\Services\PedidoService;

// API do quadro de pedidos (a produção) e da impressão. Proprietário (admin) e atendente usam as mesmas rotas.
final class AdminPedidoController
{
    /**
     * GET /api/admin/pedidos?desde=<ISO>  →  só o que mudou desde a última consulta (polling, decisão D5).
     * Vai junto o fechamento do dia; o faturamento é do proprietário e a atendente não recebe.
     */
    public function listar(Requisicao $req): void
    {
        $usuario = Sessao::exigir($req, ['admin', 'atendente']);
        $desde = $req->consulta('desde');
        $desdeBanco = $desde !== '' ? Relogio::doIso($desde) : null;
        if ($desde !== '' && $desdeBanco === null) {
            throw new ErroHttp(422, 'desde_invalido', 'O parâmetro "desde" precisa ser uma data ISO 8601.');
        }
        // A hora é lida ANTES da consulta: na próxima chamada, nada que mudou no meio do caminho se perde.
        $agora = Relogio::iso(Relogio::agora());
        $pedidos = new PedidoRepository();
        $fechamento = $pedidos->fechamentoDoDia();
        if ($usuario['perfil'] !== 'admin') {
            unset($fechamento['faturamento_centavos']);
        }
        $lista = $pedidos->paraPainel($desdeBanco);
        // O painel consulta a cada poucos segundos durante o expediente: é o "relógio" da fila de avisos do WhatsApp.
        WhatsAppController::processarFila(3);
        Resposta::json(['agora' => $agora, 'pedidos' => $lista, 'fechamento' => $fechamento]);
    }

    public function detalhe(Requisicao $req): void
    {
        Sessao::exigir($req);
        $pedido = (new PedidoRepository())->porId((int) $req->parametro('id'));
        if ($pedido === null) {
            throw ErroHttp::naoEncontrado('Pedido não encontrado.');
        }
        Resposta::json($pedido);
    }

    /** PATCH /api/admin/pedidos/{id}/status   corpo: {"status": "...", "motivo": "..."} */
    public function mudarStatus(Requisicao $req): void
    {
        $usuario = Sessao::exigir($req, [], true);
        $dados = $req->json();
        $pedido = (new PedidoService())->mudarStatus(
            (int) $req->parametro('id'),
            (string) ($dados['status'] ?? ''),
            isset($dados['motivo']) ? (string) $dados['motivo'] : null,
            $usuario,
        );
        Resposta::json($pedido);
    }

    /**
     * POST /api/admin/pedidos/{id}/impressao   corpo: {"estacao_id": "...", "estacao_nome": "...", "modo": "auto" | "manual"}
     * "auto": só responde imprimir=true para a PRIMEIRA estação que pedir (comanda sai uma vez só).
     * "manual": botão Reimprimir, sempre imprime.
     */
    public function impressao(Requisicao $req): void
    {
        Sessao::exigir($req, [], true);
        $dados = $req->json();
        $estacao = mb_substr(trim((string) ($dados['estacao_id'] ?? '')), 0, 60);
        if ($estacao === '') {
            throw ErroHttp::validacao(['estacao_id' => 'Informe a estação de impressão.']);
        }
        $pedidos = new PedidoRepository();
        $id = (int) $req->parametro('id');
        if ($pedidos->porId($id) === null) {
            throw ErroHttp::naoEncontrado('Pedido não encontrado.');
        }
        $pedidos->registrarEstacao($estacao, mb_substr(trim((string) ($dados['estacao_nome'] ?? 'Estação')), 0, 60) ?: 'Estação');
        $ficouComEla = $pedidos->reivindicarImpressao($id, $estacao);
        $manual = ($dados['modo'] ?? 'auto') === 'manual';
        Resposta::json(['imprimir' => $manual || $ficouComEla, 'pedido' => $pedidos->porId($id)]);
    }
}
