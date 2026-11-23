<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ErroHttp;
use App\Core\Requisicao;
use App\Core\Resposta;
use App\Core\Sessao;
use App\Services\FaturamentoService;

// Faturamento (D101): tela Vendas, Fechamento do dia e cancelamento de venda. Só o proprietário.
final class FaturamentoController
{
    private FaturamentoService $servico;

    public function __construct()
    {
        $this->servico = new FaturamentoService();
    }

    /** GET /api/admin/vendas?de=AAAA-MM-DD&ate=AAAA-MM-DD&canal=&pagamento=&situacao=&pagina= */
    public function vendas(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin']);
        $hoje = FaturamentoService::hoje();
        $de = $req->consulta('de', $hoje);
        $ate = $req->consulta('ate', $de);
        if (!FaturamentoService::dataValida($de) || !FaturamentoService::dataValida($ate) || $de > $ate) {
            throw ErroHttp::validacao(['periodo' => 'Escolha um período válido (a data inicial antes da final).']);
        }
        if ((new \DateTimeImmutable($de))->diff(new \DateTimeImmutable($ate))->days > 366) {
            throw ErroHttp::validacao(['periodo' => 'Escolha um período de até 1 ano.']);
        }
        Resposta::json($this->servico->relatorio($de, $ate, [
            'canal' => $req->consulta('canal'), 'pagamento' => $req->consulta('pagamento'), 'situacao' => $req->consulta('situacao'),
        ], (int) $req->consulta('pagina', '1')));
    }

    /** POST /api/admin/vendas/cancelar   corpo: {"venda": "pedido:12" | "conta:5", "tipo": "total" | "parcial", "itens": [{"id", "quantidade"}], "motivo"} */
    public function cancelar(Requisicao $req): void
    {
        $usuario = Sessao::exigir($req, ['admin'], true);
        $d = $req->json();
        $this->servico->cancelar((string) ($d['venda'] ?? ''), (string) ($d['tipo'] ?? ''), is_array($d['itens'] ?? null) ? $d['itens'] : [], (string) ($d['motivo'] ?? ''), $usuario);
        Resposta::json(['ok' => true]);
    }

    /** GET /api/admin/fechamento?mes=AAAA-MM */
    public function calendario(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin']);
        Resposta::json($this->servico->calendario($req->consulta('mes', substr(FaturamentoService::hoje(), 0, 7))));
    }

    /** GET /api/admin/fechamento/{data} */
    public function dia(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin']);
        $data = $req->parametro('data');
        if (!FaturamentoService::dataValida($data)) {
            throw ErroHttp::naoEncontrado('Data inválida.');
        }
        Resposta::json($this->servico->dia($data));
    }

    /** POST /api/admin/fechamento/{data}   corpo: {caixa_inicial_centavos, retiradas_centavos, contado_centavos, observacao} */
    public function fechar(Requisicao $req): void
    {
        $usuario = Sessao::exigir($req, ['admin'], true);
        Resposta::json($this->servico->fechar($req->parametro('data'), $req->json(), $usuario), 201);
    }

    /** POST /api/admin/fechamento/{id}/reabrir   corpo: {motivo} */
    public function reabrir(Requisicao $req): void
    {
        $usuario = Sessao::exigir($req, ['admin'], true);
        Resposta::json($this->servico->reabrir((int) $req->parametro('id'), (string) ($req->json()['motivo'] ?? ''), $usuario));
    }
}
