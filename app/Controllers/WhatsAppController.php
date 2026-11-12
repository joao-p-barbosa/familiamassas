<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Banco;
use App\Core\Env;
use App\Core\ErroHttp;
use App\Core\Relogio;
use App\Core\Requisicao;
use App\Core\Resposta;
use App\Core\Sessao;
use App\Services\PedidoService;
use App\WhatsApp\Avisos;
use App\WhatsApp\Bot;
use App\WhatsApp\Conversas;
use App\WhatsApp\Webhook;
use App\WhatsApp\WhatsApp;

// WhatsApp: o webhook da Meta, o cron da fila de avisos e as rotas do painel (situação, simulador e atendimento humano).
final class WhatsAppController
{
    /** GET /webhook/whatsapp.php: a Meta confere o webhook na hora do cadastro (hub.mode, hub.verify_token, hub.challenge). */
    public function verificar(Requisicao $req): void
    {
        $desafio = Webhook::desafio($req->consulta('hub_mode'), $req->consulta('hub_verify_token'), $req->consulta('hub_challenge'), Env::get('WHATSAPP_VERIFY_TOKEN'));
        http_response_code($desafio === null ? 403 : 200);
        header('Content-Type: text/plain; charset=utf-8');
        echo $desafio ?? 'verify token não confere';
    }

    /**
     * POST /webhook/whatsapp.php: mensagens dos clientes e status das nossas mensagens.
     * Assinatura inválida → 401. O resto responde 200 rápido (a Meta reenvia o que não recebe 200 a tempo).
     */
    public function receber(Requisicao $req): void
    {
        $corpo = $req->corpoCru();
        if (!Webhook::assinaturaValida($corpo, $req->cabecalho('X-Hub-Signature-256'), Env::get('WHATSAPP_APP_SECRET'))) {
            throw new ErroHttp(401, 'assinatura_invalida', 'Assinatura do webhook inválida.');
        }
        $dados = json_decode($corpo, true);
        $resultado = (new Webhook())->processar(is_array($dados) ? $dados : []);
        self::processarFila(3);
        Resposta::json(['ok' => true] + $resultado);
    }

    /** GET /cron/whatsapp?chave=<CRON_TOKEN>: processa a fila de avisos (para um serviço de cron externo, opcional). */
    public function cron(Requisicao $req): void
    {
        $chave = Env::get('CRON_TOKEN');
        if ($chave === '' || !hash_equals($chave, $req->consulta('chave'))) {
            throw ErroHttp::naoEncontrado();
        }
        Resposta::json(['enviados' => (new Avisos())->processar(20)]);
    }

    /** A fila nunca derruba quem a chamou (o painel e o webhook seguem funcionando mesmo com a Meta fora do ar). */
    public static function processarFila(int $limite): void
    {
        try {
            (new Avisos())->processar($limite);
        } catch (\Throwable $e) {
            error_log('[whatsapp] fila de avisos: ' . $e->getMessage());
        }
    }

    /** GET /api/admin/whatsapp: situação da integração (sem nenhum segredo e sem o texto das mensagens). */
    public function situacao(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin']);
        self::processarFila(5);
        $pdo = Banco::conexao();
        $ontem = gmdate('Y-m-d H:i:s', time() - 86400);
        $conta = static function (string $sql, array $parametros = []) use ($pdo): int {
            $consulta = $pdo->prepare($sql);
            $consulta->execute($parametros);
            return (int) $consulta->fetchColumn();
        };
        $chaves = [];
        foreach (['WHATSAPP_TOKEN' => 'Token permanente', 'WHATSAPP_PHONE_NUMBER_ID' => 'ID do número', 'WHATSAPP_APP_SECRET' => 'App secret', 'WHATSAPP_VERIFY_TOKEN' => 'Verify token'] as $chave => $rotulo) {
            $chaves[] = ['chave' => $chave, 'rotulo' => $rotulo, 'preenchida' => Env::get($chave) !== ''];
        }
        $conversas = new Conversas();
        Resposta::json([
            'configurado' => !in_array(false, array_column($chaves, 'preenchida'), true),
            'simulado' => WhatsApp::simulado(),
            'chaves' => $chaves,
            'https' => $req->https(),
            'webhook' => WhatsApp::urlDoSite() . '/webhook/whatsapp.php',
            'modelo_status' => WhatsApp::modeloDeStatus(),
            'humano_horas' => max(1, (new \App\Repositories\ConfigRepository())->inteiro('whatsapp_humano_horas', 2)),
            'recebidas_24h' => $conta("SELECT COUNT(*) FROM whatsapp_eventos WHERE direcao = 'in' AND criado_em >= ?", [$ontem]),
            'enviadas_24h' => $conta("SELECT COUNT(*) FROM whatsapp_eventos WHERE direcao = 'out' AND tipo NOT IN ('falha', 'falha_entrega') AND criado_em >= ?", [$ontem]),
            'falhas_24h' => $conta("SELECT COUNT(*) FROM whatsapp_eventos WHERE tipo IN ('falha', 'falha_entrega') AND criado_em >= ?", [$ontem]),
            'envios_pendentes' => $conta('SELECT COUNT(*) FROM whatsapp_envios_pendentes WHERE proxima_tentativa_em <> ?', [Avisos::DESISTIU]),
            'pedidos_whatsapp_hoje' => $conta("SELECT COUNT(*) FROM pedidos WHERE origem = 'whatsapp' AND criado_em >= ?", [Relogio::inicioDoDiaDaLoja()]),
            'recentes' => $conversas->recentes(20),
            'fila' => (new Avisos())->pendentes(20),
            'atendimento' => $conversas->emAtendimento(),
        ]);
    }

    /**
     * POST /api/admin/whatsapp/simular  {"telefone", "texto"?, "botao"?, "nome"?}
     * Faz de conta que o cliente mandou uma mensagem (só no modo simulado): passa pelo mesmo bot do webhook.
     */
    public function simular(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        if (!WhatsApp::simulado()) {
            throw new ErroHttp(409, 'whatsapp_real', 'O WhatsApp está ligado de verdade: o simulador fica desligado para não misturar conversas.');
        }
        $dados = $req->json();
        $telefone = self::telefone((string) ($dados['telefone'] ?? ''));
        $botao = isset($dados['botao']) && isset(WhatsApp::BOTOES[$dados['botao']]) ? (string) $dados['botao'] : null;
        $texto = $botao !== null ? WhatsApp::BOTOES[$botao] : mb_substr(trim((string) ($dados['texto'] ?? '')), 0, 1000);
        if ($texto === '') {
            throw ErroHttp::validacao(['texto' => 'Escreva a mensagem do cliente.']);
        }
        $nome = mb_substr(trim((string) ($dados['nome'] ?? '')), 0, 60);
        (new Bot())->receber([
            'wamid' => 'sim.in.' . bin2hex(random_bytes(10)),
            'telefone' => $telefone,
            'tipo' => $botao !== null ? 'interactive' : 'text',
            'texto' => $texto,
            'botao' => $botao,
            'nome' => $nome !== '' ? $nome : null,
        ]);
        self::processarFila(5);
        Resposta::json(self::estadoDaConversa($telefone));
    }

    /** GET /api/admin/whatsapp/conversa?telefone=  mensagens e estado de uma conversa (simulador e atendimento humano). */
    public function conversa(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin']);
        self::processarFila(5);
        Resposta::json(self::estadoDaConversa(self::telefone($req->consulta('telefone'))));
    }

    /** POST /api/admin/whatsapp/conversas/{telefone}/responder  {"texto"}: resposta da equipe (só dentro da janela de 24 h). */
    public function responder(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        $telefone = self::telefone($req->parametro('telefone'));
        $texto = mb_substr(trim((string) ($req->json()['texto'] ?? '')), 0, 4096);
        if ($texto === '') {
            throw ErroHttp::validacao(['texto' => 'Escreva a resposta.']);
        }
        if (!(new Conversas())->janelaAberta($telefone)) {
            throw new ErroHttp(409, 'janela_fechada', 'Já passaram 24 horas da última mensagem do cliente: o WhatsApp só deixa responder quando ele escrever de novo.');
        }
        $resultado = WhatsApp::enviar($telefone, WhatsApp::texto($texto), 'equipe');
        if (!$resultado['ok']) {
            throw new ErroHttp(502, 'whatsapp_falhou', 'A mensagem não foi enviada: ' . $resultado['erro']);
        }
        Resposta::json(self::estadoDaConversa($telefone));
    }

    /** POST /api/admin/whatsapp/conversas/{telefone}/bot: encerra o atendimento humano (o bot volta a responder). */
    public function devolverAoBot(Requisicao $req): void
    {
        Sessao::exigir($req, ['admin'], true);
        $telefone = self::telefone($req->parametro('telefone'));
        (new Conversas())->voltarAoBot($telefone);
        Resposta::json(self::estadoDaConversa($telefone));
    }

    /** @return array{telefone:string, modo:string, humano_ate:?string, janela_aberta:bool, mensagens:list<array>} */
    private static function estadoDaConversa(string $telefone): array
    {
        $conversas = new Conversas();
        $humano = $conversas->emAtendimentoHumano($telefone);
        $conversa = $conversas->conversa($telefone);
        return [
            'telefone' => $telefone,
            'modo' => $humano ? 'humano' : 'bot',
            'humano_ate' => $humano ? Relogio::iso($conversa['humano_ate'] ?? null) : null,
            'janela_aberta' => $conversas->janelaAberta($telefone),
            'mensagens' => $conversas->mensagens($telefone, 60),
        ];
    }

    private static function telefone(string $valor): string
    {
        $digitos = preg_replace('/\D/', '', $valor);
        // Número que veio do WhatsApp já está no formato da Meta (DDI + DDD + número); o digitado no simulador é normalizado.
        $telefone = strlen($digitos) >= 12 && str_starts_with($digitos, '55') ? $digitos : PedidoService::normalizarTelefone($valor);
        if ($telefone === null || strlen($telefone) > 15) {
            throw ErroHttp::validacao(['telefone' => 'Informe um número com DDD, por exemplo (11) 91234-5678.']);
        }
        return $telefone;
    }
}
