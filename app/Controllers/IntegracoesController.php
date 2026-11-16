<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Env;
use App\Core\ErroHttp;
use App\Core\Requisicao;
use App\Core\Resposta;
use App\Core\Sessao;
use App\Ifood\Ifood;
use App\Impressao\AgenteImpressao;

/**
 * Telas iFood e Impressora do painel (só o proprietário) e a API do agente de impressão.
 * Nenhuma resposta leva token do iFood, credencial ou o código do agente (que só aparece uma vez, ao ser gerado).
 */
final class IntegracoesController
{
    // ============================== iFood ==============================

    /** GET /api/admin/ifood */
    public function ifood(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin']);
        $ifood = new Ifood();
        $ifood->cicloSeHora();
        Resposta::json($ifood->situacao());
    }

    /** POST /api/admin/ifood/conectar: centralizado conecta na hora; distribuído devolve o código para o Portal do Parceiro. */
    public function ifoodConectar(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        Resposta::json((new Ifood())->conectar());
    }

    /** POST /api/admin/ifood/autorizar   {"codigo": "..."} */
    public function ifoodAutorizar(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        Resposta::json((new Ifood())->autorizar(mb_substr((string) ($req->json()['codigo'] ?? ''), 0, 200)));
    }

    public function ifoodDesconectar(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        Resposta::json((new Ifood())->desconectar());
    }

    /** GET /api/admin/pedidos/{id}/ifood/motivos: motivos de cancelamento que o iFood aceita para este pedido. */
    public function ifoodMotivos(Requisicao $req): void
    {
        Sessao::exigir($req);
        Resposta::json((new Ifood())->motivosDeCancelamento((int) $req->parametro('id')));
    }

    /** PUT /api/admin/ifood/credenciais   {"client_id", "client_secret" (vazio = mantém o salvo), "tipo_app"} */
    public function ifoodCredenciais(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        $d = $req->json();
        Resposta::json((new Ifood())->salvarCredenciais(
            mb_substr((string) ($d['client_id'] ?? ''), 0, 200),
            mb_substr((string) ($d['client_secret'] ?? ''), 0, 400),
            (string) ($d['tipo_app'] ?? ''),
        ));
    }

    public function ifoodRemoverCredenciais(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        Resposta::json((new Ifood())->removerCredenciais());
    }

    /** PUT /api/admin/ifood/ajustes   {"confirmar_auto": bool} */
    public function ifoodAjustes(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        (new \App\Repositories\ConfigRepository())->salvar(['ifood_confirmar_auto' => !empty($req->json()['confirmar_auto']) ? '1' : '0']);
        Resposta::json((new Ifood())->situacao());
    }

    /** POST /api/admin/ifood/simular   {"tipo": "DELIVERY" | "TAKEOUT"}: pedido de teste no formato do iFood. */
    public function ifoodSimular(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        $tipo = ($req->json()['tipo'] ?? 'DELIVERY') === 'TAKEOUT' ? 'TAKEOUT' : 'DELIVERY';
        $ifood = new Ifood();
        $criado = $ifood->simularPedido($tipo);
        Resposta::json(['pedido_id' => $criado['id'], 'situacao' => $ifood->situacao()], 201);
    }

    /** POST /api/admin/ifood/sincronizar: roda o ciclo agora (sem esperar os 30 s). */
    public function ifoodSincronizar(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        $ifood = new Ifood();
        $resultado = $ifood->ciclo();
        Resposta::json(['resultado' => $resultado, 'situacao' => $ifood->situacao()]);
    }

    /** GET /cron/ifood?chave=CRON_TOKEN: para um cron externo, se o processo de fundo não estiver rodando. */
    public function ifoodCron(Requisicao $req): void
    {
        $chave = Env::get('CRON_TOKEN');
        if ($chave === '' || !hash_equals($chave, $req->consulta('chave'))) {
            throw ErroHttp::naoEncontrado();
        }
        Resposta::json((new Ifood())->cicloSeHora());
    }

    // ============================== Impressora (painel) ==============================

    /** GET /api/admin/impressora */
    public function impressora(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin']);
        Resposta::json((new AgenteImpressao())->situacao());
    }

    /** PUT /api/admin/impressora   parametrização (modo, impressora, papel, letra, vias, preço, origens) */
    public function impressoraSalvar(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        $agente = new AgenteImpressao();
        $erros = $agente->salvarAjustes($req->json());
        if ($erros !== []) {
            throw ErroHttp::validacao($erros);
        }
        Resposta::json($agente->situacao());
    }

    /** POST /api/admin/impressora/codigo: código novo do agente (aparece uma vez; o anterior para de valer). */
    public function impressoraCodigo(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        $agente = new AgenteImpressao();
        $codigo = $agente->gerarCodigo();
        Resposta::json(['codigo' => $codigo, 'servidor' => self::enderecoDoSite($req), 'situacao' => $agente->situacao()]);
    }

    public function impressoraRevogar(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        $agente = new AgenteImpressao();
        $agente->revogar();
        Resposta::json($agente->situacao());
    }

    /** POST /api/admin/impressora/teste: comanda de teste pelo agente. */
    public function impressoraTeste(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        $agente = new AgenteImpressao();
        if (!$agente->online()) {
            throw new ErroHttp(409, 'agente_offline', 'O agente de impressão não está conectado. Abra o "iniciar-impressora" no computador da impressora.');
        }
        $agente->pedirTeste();
        Resposta::json($agente->situacao(), 201);
    }

    /** GET /api/admin/impressora/agente/{arquivo}: os arquivos do agente para baixar no computador da loja. */
    public function impressoraArquivo(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin']);
        $arquivos = ['agente.ps1' => 'text/plain', 'iniciar-impressora.bat' => 'application/octet-stream', 'LEIA-ME.txt' => 'text/plain'];
        $nome = $req->parametro('arquivo');
        if (!isset($arquivos[$nome])) {
            throw ErroHttp::naoEncontrado();
        }
        header('Content-Type: ' . $arquivos[$nome] . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nome . '"');
        header('Cache-Control: no-store');
        readfile(RAIZ . '/impressora/' . $nome);
    }

    // ============================== API do agente (sem sessão; vale o código) ==============================

    private static function exigirAgente(Requisicao $req): AgenteImpressao
    {
        $agente = new AgenteImpressao();
        if (!$agente->codigoValido($req->cabecalho('X-Impressora-Codigo'))) {
            throw new ErroHttp(401, 'codigo_invalido', 'Código do agente inválido. Gere um código novo no painel (tela Impressora).');
        }
        return $agente;
    }

    /** POST /api/impressora/ciclo   {computador, versao, impressoras: [{nome, status, padrao}]} → {ajustes, trabalhos, intervalo_s} */
    public function agenteCiclo(Requisicao $req): void
    {
        $agente = self::exigirAgente($req);
        Resposta::json($agente->ciclo($req->json()));
    }

    /** POST /api/impressora/resultado   {"trabalho": id, "ok": bool, "erro": "..."} */
    public function agenteResultado(Requisicao $req): void
    {
        $agente = self::exigirAgente($req);
        $d = $req->json();
        $agente->resultado((int) ($d['trabalho'] ?? 0), !empty($d['ok']), isset($d['erro']) ? mb_substr((string) $d['erro'], 0, 300) : null);
        Resposta::json(['ok' => true]);
    }

    private static function enderecoDoSite(Requisicao $req): string
    {
        $configurado = rtrim(Env::get('APP_URL'), '/');
        if ($configurado !== '') {
            return $configurado;
        }
        $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        return ($req->https() ? 'https' : 'http') . '://' . $host;
    }
}
