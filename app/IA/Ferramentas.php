<?php
declare(strict_types=1);

namespace App\IA;

use App\Repositories\ConfigRepository;
use App\Repositories\PedidoRepository;
use App\Services\LojaService;
use App\Services\PedidoService;
use App\Services\TokenWhatsApp;
use App\WhatsApp\Conversas;
use App\WhatsApp\WhatsApp;

/**
 * As ferramentas que o modelo pode chamar (é o que o "Playbook" do Botpress faz, sem editor visual: D70).
 * Nenhuma recebe argumento: o telefone sai da conversa, nunca do modelo, então uma mensagem maliciosa do cliente não consegue
 * consultar o pedido de outra pessoa nem mexer em outra conversa. Todas só devolvem texto para o modelo usar na resposta.
 * No modo teste (`$simulacao`), nada é gravado: o teste do painel não muda conversa nenhuma.
 */
final class Ferramentas
{
    public const LINK = 'enviar_link_do_cardapio';
    public const PEDIDO = 'status_do_pedido';
    public const ATENDENTE = 'falar_com_atendente';

    public function __construct(
        private readonly LojaService $loja = new LojaService(),
        private readonly ConfigRepository $config = new ConfigRepository(),
        private readonly PedidoRepository $pedidos = new PedidoRepository(),
        private readonly Conversas $conversas = new Conversas(),
    ) {}

    /** @return list<array<string,mixed>> definições no formato de ferramentas do Chat Completions */
    public static function definicoes(): array
    {
        $semArgumentos = ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
        return [
            ['type' => 'function', 'function' => [
                'name' => self::LINK,
                'description' => 'Gera o link do cardápio, onde o cliente monta o prato e faz o pedido. Use quando o cliente quiser pedir, ver o cardápio completo ou montar o prato. Não recebe argumentos.',
                'parameters' => $semArgumentos,
            ]],
            ['type' => 'function', 'function' => [
                'name' => self::PEDIDO,
                'description' => 'Consulta o pedido em andamento deste cliente (status e link de acompanhamento). Use quando ele perguntar do pedido. Não recebe argumentos.',
                'parameters' => $semArgumentos,
            ]],
            ['type' => 'function', 'function' => [
                'name' => self::ATENDENTE,
                'description' => 'Passa a conversa para uma pessoa da equipe. Use quando o cliente pedir um atendente, reclamar, ou quando você não souber responder. Não recebe argumentos.',
                'parameters' => $semArgumentos,
            ]],
        ];
    }

    public function executar(string $nome, string $telefone, bool $simulacao = false): string
    {
        return match ($nome) {
            self::LINK => $this->link($telefone, $simulacao),
            self::PEDIDO => $this->pedido($telefone, $simulacao),
            self::ATENDENTE => $this->atendente($telefone, $simulacao),
            default => 'Ferramenta desconhecida. Responda sem ela.',
        };
    }

    private function link(string $telefone, bool $simulacao): string
    {
        $estado = $this->loja->estado();
        if (!$estado['aberta']) {
            return "A loja está fechada agora ({$estado['mensagem']}). Diga isso ao cliente e que ele pode pedir no horário de funcionamento. Não envie o link.";
        }
        $token = $simulacao ? 'teste' : (new TokenWhatsApp())->gerar($telefone);
        $horas = intdiv(TokenWhatsApp::VALIDADE_SEGUNDOS, 3600);
        return 'Link do cardápio (vale por ' . $horas . ' horas): ' . WhatsApp::urlDoSite() . '/?t=' . $token . ' . Escreva o endereço exatamente assim na resposta.';
    }

    private function pedido(string $telefone, bool $simulacao): string
    {
        $pedido = $simulacao ? null : $this->pedidos->ultimoAbertoDoTelefone($telefone);
        if ($pedido === null) {
            return $simulacao ? 'Modo de teste: não há pedido neste número. Diga ao cliente que não encontrou pedido em andamento.' : 'O cliente não tem pedido em andamento. Pergunte se ele quer fazer um.';
        }
        return "Pedido #{$pedido['id']}: " . PedidoService::ROTULOS[$pedido['status']] . '. Acompanhamento: ' . WhatsApp::urlDoSite() . '/pedido/' . $pedido['codigo'];
    }

    private function atendente(string $telefone, bool $simulacao): string
    {
        $horas = max(1, $this->config->inteiro('whatsapp_humano_horas', 2));
        if (!$simulacao) {
            $this->conversas->entrarNoAtendimentoHumano($telefone, $horas);
        }
        return "Atendimento humano acionado. Avise o cliente que uma pessoa da equipe vai responder por aqui, e que se ninguém responder em {$horas} h o atendimento automático volta.";
    }
}
