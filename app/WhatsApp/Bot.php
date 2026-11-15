<?php
declare(strict_types=1);

namespace App\WhatsApp;

use App\IA\Assistente;
use App\Repositories\ConfigRepository;
use App\Repositories\PedidoRepository;
use App\Services\LojaService;
use App\Services\PedidoService;
use App\Services\TokenWhatsApp;

/**
 * O bot do MVP (sem IA, "link-first"): quem manda qualquer mensagem recebe as boas-vindas, o status da loja e 3 botões.
 *   [Fazer pedido]        → botão com o link do cardápio, que já leva o telefone (token assinado, vale 2 h)
 *   [Acompanhar pedido]   → status do pedido em andamento deste telefone
 *   [Falar com atendente] → modo humano: o bot fica em silêncio por algumas horas (configuração whatsapp_humano_horas)
 * Montar o prato fica no cardápio web (a API aceita no máximo 3 botões e listas de 10 linhas).
 * Pedido pelo chat (D74): "Cardápio" abre o passo a passo (FluxoPedido); "acompanhar" e "atendente" também valem como palavras.
 * No canal QR code o bot fica em SILÊNCIO fora dessas palavras: quem cumprimenta é a saudação do WhatsApp Business do dono.
 * Nos outros canais, o texto livre recebe a IA (se ligada, D70) ou o menu acima.
 */
final class Bot
{
    public function __construct(
        private readonly Conversas $conversas = new Conversas(),
        private readonly LojaService $loja = new LojaService(),
        private readonly ConfigRepository $config = new ConfigRepository(),
        private readonly PedidoRepository $pedidos = new PedidoRepository(),
        private readonly TokenWhatsApp $token = new TokenWhatsApp(),
        private readonly Assistente $assistente = new Assistente(),
        ?FluxoPedido $fluxo = null,
    ) {
        $this->fluxo = $fluxo ?? new FluxoPedido($this->assistente);
    }

    private readonly FluxoPedido $fluxo;

    /**
     * Uma mensagem do cliente.
     * @param array{wamid:string, telefone:string, tipo:string, texto:string, botao:?string, nome:?string} $m
     * @return string o que o bot fez: repetida | humano | boas_vindas | pedir | acompanhar | atendente | ia | silencio | fechada
     *   ou, no pedido pelo chat, o passo em que o cliente está (menu, quantidade, resumo...) e pedido_criado | cancelado | invalido
     */
    public function receber(array $m): string
    {
        // Idempotência: a Meta reenvia o mesmo evento quando o 200 demora. Já registrado = já respondido.
        $registrou = $this->conversas->registrarEvento($m['wamid'], $m['telefone'], 'in', $m['tipo'], [
            'texto' => mb_substr($m['texto'], 0, 1000), 'botao' => $m['botao'], 'nome' => $m['nome'] !== null ? mb_substr($m['nome'], 0, 60) : null,
        ]);
        if (!$registrou) {
            return 'repetida';
        }
        $this->conversas->tocarCliente($m['telefone']);
        if ($this->conversas->emAtendimentoHumano($m['telefone'])) {
            return 'humano';   // alguém da equipe está conversando: o bot não interrompe
        }
        if ($m['botao'] !== null) {   // botões da Cloud API
            return match ($m['botao']) {
                'pedir' => $this->pedir($m['telefone']),
                'acompanhar' => $this->acompanhar($m['telefone']),
                'atendente' => $this->atendente($m['telefone']),
                default => $this->livre($m),
            };
        }
        if ($this->fluxo->ativo($m['telefone'])) {   // no meio de um pedido pelo chat
            $resultado = $this->fluxo->receber($m);
            return $resultado === 'atendente' ? $this->atendente($m['telefone']) : $resultado;
        }
        $chave = FluxoPedido::chave($m['texto']);
        if (in_array($chave, FluxoPedido::PALAVRAS, true)) {
            return $this->fluxo->iniciar($m['telefone'], $m['nome']);
        }
        if ($chave === 'acompanhar') {
            return $this->acompanhar($m['telefone']);
        }
        if ($chave === 'atendente') {
            return $this->atendente($m['telefone']);
        }
        // Canal QR: silêncio. A saudação (com o link e "digite Cardápio") é do WhatsApp Business do dono, e o bot não gasta nada.
        return Conector::ativo() ? 'silencio' : $this->livre($m);
    }

    /** Texto livre: a IA responde quando está ligada e disponível. Qualquer falha cai no menu de 3 opções (o cliente nunca fica sem resposta). */
    private function livre(array $m): string
    {
        if ($m['texto'] !== '' && $this->assistente->indisponivel($m['telefone']) === null) {
            try {
                $r = $this->assistente->responder($m['telefone'], $m['texto'], $m['nome']);
            } catch (\Throwable $e) {
                $r = ['ok' => false, 'erro' => $e->getMessage(), 'texto' => ''];
            }
            if ($r['ok']) {
                WhatsApp::enviar($m['telefone'], WhatsApp::texto($r['texto']), 'ia');
                return 'ia';
            }
            error_log('[ia] sem resposta da IA, voltando ao menu: ' . $r['erro']);
        }
        return $this->boasVindas($m['telefone'], $m['nome']);
    }

    private function boasVindas(string $telefone, ?string $nome): string
    {
        $primeiro = $nome !== null && trim($nome) !== '' ? explode(' ', trim($nome))[0] : '';
        $loja = $this->config->get('nome_loja', 'Família Massas');
        $estado = $this->loja->estado();
        $texto = ($primeiro !== '' ? "Olá, {$primeiro}!" : 'Olá!') . " Aqui é a {$loja}.\n"
            . $estado['mensagem'] . ".\n\nComo podemos ajudar?";
        WhatsApp::enviar($telefone, WhatsApp::botoes($texto, WhatsApp::BOTOES), 'boas_vindas');
        return 'boas_vindas';
    }

    private function pedir(string $telefone): string
    {
        $estado = $this->loja->estado();
        if (!$estado['aberta']) {
            WhatsApp::enviar($telefone, WhatsApp::texto("Agora estamos fechados ({$estado['mensagem']}).\nVolte nesse horário que a gente prepara o seu prato."), 'pedir_fechado');
            return 'pedir';
        }
        $url = WhatsApp::urlDoSite() . '/?t=' . $this->token->gerar($telefone);
        $horas = intdiv(TokenWhatsApp::VALIDADE_SEGUNDOS, 3600);
        WhatsApp::enviar($telefone, WhatsApp::link(
            "Monte o seu prato no nosso cardápio. O link já leva o seu número e vale por {$horas} horas.",
            'Abrir cardápio',
            $url,
        ), 'pedir');
        return 'pedir';
    }

    private function acompanhar(string $telefone): string
    {
        $pedido = $this->pedidos->ultimoAbertoDoTelefone($telefone);
        if ($pedido === null && Conector::ativo()) {
            WhatsApp::enviar($telefone, WhatsApp::texto('Você não tem pedido em andamento. Para pedir por aqui, digite *Cardápio*.'), 'acompanhar_sem_pedido');
            return 'acompanhar';
        }
        if ($pedido === null) {
            WhatsApp::enviar($telefone, WhatsApp::botoes('Você não tem pedido em andamento. Quer fazer um?', [
                'pedir' => WhatsApp::BOTOES['pedir'], 'atendente' => WhatsApp::BOTOES['atendente'],
            ]), 'acompanhar_sem_pedido');
            return 'acompanhar';
        }
        $texto = "Pedido #{$pedido['id']}: " . PedidoService::ROTULOS[$pedido['status']] . ".\n"
            . 'Acompanhe por aqui: ' . WhatsApp::urlDoSite() . '/pedido/' . $pedido['codigo'];
        WhatsApp::enviar($telefone, WhatsApp::texto($texto), 'acompanhar');
        return 'acompanhar';
    }

    private function atendente(string $telefone): string
    {
        $horas = max(1, $this->config->inteiro('whatsapp_humano_horas', 2));
        $this->conversas->entrarNoAtendimentoHumano($telefone, $horas);
        WhatsApp::enviar($telefone, WhatsApp::texto(
            "Certo! Uma pessoa da nossa equipe vai responder por aqui.\nSe ninguém responder em {$horas} " . ($horas === 1 ? 'hora' : 'horas') . ', o atendimento automático volta.',
        ), 'atendente');
        return 'atendente';
    }
}
