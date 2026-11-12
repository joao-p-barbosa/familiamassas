<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Requisicao;
use App\Core\Resposta;
use App\Core\Sessao;
use App\Services\AtendimentoService;

// API do Salão: atendimentos (a conta de cada cliente), os pedidos de cada um e o fechamento da conta.
// Proprietário (admin) e atendente usam as mesmas rotas; só o histórico é do proprietário.
final class AdminAtendimentoController
{
    /** GET /api/admin/atendimentos → o quadro do Salão: abertos + encerrados hoje, cada um com os pedidos e a fase. */
    public function listar(Requisicao $req): void
    {
        $usuario = Sessao::exigir($req, ['admin', 'atendente']);
        $quadro = (new AtendimentoService())->quadro();
        if ($usuario['perfil'] !== 'admin') {
            unset($quadro['resumo']['vendido_hoje_centavos']);   // quanto se vendeu no dia é do proprietário
        }
        Resposta::json($quadro);
    }

    /**
     * POST /api/admin/atendimentos   corpo: {"cliente": {"nome": "Carlos"}, "modalidade": "mesa" | "viagem", "mesa": "5",
     *                                        "itens": [...], "total_esperado_centavos": 3190}
     * Abre o atendimento e registra o primeiro pedido. Resposta 201: {id, pedido_id, numero, codigo}.
     */
    public function abrir(Requisicao $req): void
    {
        $usuario = Sessao::exigir($req, ['admin', 'atendente'], true);
        Resposta::json((new AtendimentoService())->abrir($req->json(), $usuario), 201);
    }

    /** GET /api/admin/atendimentos/{id} → o atendimento completo, com os pedidos, itens e personalizações. */
    public function detalhe(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin', 'atendente']);
        Resposta::json((new AtendimentoService())->detalhe((int) $req->parametro('id')));
    }

    /**
     * POST /api/admin/atendimentos/{id}/pedidos   corpo: {"itens": [...], "total_esperado_centavos": 1690}
     * Mais produtos para a mesma conta. Conta encerrada → 409 atendimento_encerrado.
     */
    public function adicionarPedido(Requisicao $req): void
    {
        $usuario = Sessao::exigir($req, ['admin', 'atendente'], true);
        Resposta::json((new AtendimentoService())->adicionarPedido((int) $req->parametro('id'), $req->json(), $usuario), 201);
    }

    /**
     * POST /api/admin/atendimentos/{id}/fechar   corpo: {"pagamento": "pix" | "cartao" | "dinheiro", "total_esperado_centavos": 8400}
     * Fecha a conta. Conta já fechada → 409 ja_encerrado; a conta mudou enquanto era conferida → 409 total_mudou.
     */
    public function fechar(Requisicao $req): void
    {
        $usuario = Sessao::exigir($req, ['admin', 'atendente'], true);
        Resposta::json((new AtendimentoService())->fechar((int) $req->parametro('id'), $req->json(), $usuario));
    }

    /** POST /api/admin/atendimentos/{id}/servir → passa os pedidos prontos do atendimento para Concluído ("servido"). */
    public function servir(Requisicao $req): void
    {
        $usuario = Sessao::exigir($req, ['admin', 'atendente'], true);
        Resposta::json((new AtendimentoService())->servirProntos((int) $req->parametro('id'), $usuario));
    }

    /** GET /api/admin/historico?periodo=hoje|7d|30d&status=aberto|encerrado → histórico de atendimentos e pagamentos (só o proprietário). */
    public function historico(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin']);
        $status = $req->consulta('status');
        Resposta::json((new AtendimentoService())->historico($req->consulta('periodo', 'hoje'), $status === '' ? null : $status));
    }
}
