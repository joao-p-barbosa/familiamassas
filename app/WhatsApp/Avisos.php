<?php
declare(strict_types=1);

namespace App\WhatsApp;

use App\Core\Banco;
use App\Core\Relogio;
use App\Repositories\ConfigRepository;
use App\Repositories\PedidoRepository;
use App\Services\PedidoService;

/**
 * Avisos de status ao cliente pelo WhatsApp ("Recebemos seu pedido", "Em preparo", "Saiu para entrega"...).
 *
 * Só para pedidos com origem "whatsapp": foi o cliente quem chamou a loja no WhatsApp e abriu o cardápio pelo link do bot.
 * Quem pediu direto pelo cardápio informou o número para contato, mas não pediu para receber mensagens.
 *
 * Todo aviso passa pela fila whatsapp_envios_pendentes: a mudança de status não espera a Meta responder, e o que falha é
 * tentado de novo (1, 5, 15, 60 e 240 minutos; depois disso a fila desiste e a tela WhatsApp mostra o erro). A fila é
 * processada quando o painel consulta os pedidos (a cada poucos segundos durante o expediente), quando chega um evento no
 * webhook e pelo endereço de cron. Dentro da janela de 24 h vai texto livre; fora dela, o template aprovado na Meta.
 */
final class Avisos
{
    public const DESISTIU = '9999-12-31 00:00:00';
    private const ESPERA_MINUTOS = [1, 5, 15, 60, 240];
    private const RESERVA_MINUTOS = 2;   // enquanto um processo envia, os outros não pegam o mesmo aviso

    public function __construct(
        private readonly Conversas $conversas = new Conversas(),
        private readonly PedidoRepository $pedidos = new PedidoRepository(),
        private readonly ConfigRepository $config = new ConfigRepository(),
    ) {}

    /** Pedido criado ou com status novo: põe o aviso na fila (se for um pedido que veio do WhatsApp). */
    public function pedidoMudou(array $pedido): void
    {
        if (($pedido['origem'] ?? '') !== 'whatsapp' || ($pedido['cliente_telefone'] ?? '') === '' || !isset(PedidoService::ROTULOS[$pedido['status']])) {
            return;
        }
        if ($pedido['status'] === 'novo') {
            $this->conversas->definirUltimoPedido($pedido['cliente_telefone'], (int) $pedido['id']);
        }
        Banco::conexao()->prepare('INSERT INTO whatsapp_envios_pendentes (telefone, pedido_id, tipo, tentativas, proxima_tentativa_em) VALUES (?, ?, ?, 0, ?)')
            ->execute([$pedido['cliente_telefone'], (int) $pedido['id'], $pedido['status'], Relogio::agora()]);
    }

    /** Envia os avisos que estão na hora. @return int quantos foram entregues à Meta (ou ao simulador) */
    public function processar(int $limite = 5): int
    {
        $pdo = Banco::conexao();
        $agora = Relogio::agora();
        $consulta = $pdo->prepare('SELECT * FROM whatsapp_envios_pendentes WHERE proxima_tentativa_em <= ? ORDER BY id LIMIT ' . max(1, min(50, $limite)));
        $consulta->execute([$agora]);
        $enviados = 0;
        foreach ($consulta->fetchAll() as $aviso) {
            // Reserva: só um processo fica com cada aviso (dois painéis abertos não mandam a mesma mensagem duas vezes).
            $reserva = $pdo->prepare('UPDATE whatsapp_envios_pendentes SET proxima_tentativa_em = ? WHERE id = ? AND proxima_tentativa_em = ?');
            $reserva->execute([gmdate('Y-m-d H:i:s', time() + self::RESERVA_MINUTOS * 60), $aviso['id'], $aviso['proxima_tentativa_em']]);
            if ($reserva->rowCount() !== 1) {
                continue;
            }
            if ($this->enviar($aviso)) {
                $enviados++;
            }
        }
        if (random_int(1, 50) === 1) {
            $this->conversas->limparAntigos();
        }
        return $enviados;
    }

    private function enviar(array $aviso): bool
    {
        $id = (int) $aviso['id'];
        $pedido = $this->pedidos->porId((int) $aviso['pedido_id']);
        // Pedido apagado, ou o status já andou (ex.: "Pronto" e logo "Aguardando retirada"): vale só o aviso mais novo.
        if ($pedido === null || ($aviso['tipo'] !== 'novo' && $aviso['tipo'] !== $pedido['status'])) {
            $this->apagar($id);
            return false;
        }
        $telefone = (string) $aviso['telefone'];
        if ($this->conversas->janelaAberta($telefone)) {
            $mensagem = WhatsApp::texto($this->texto($pedido, $aviso['tipo']));
        } elseif (($modelo = WhatsApp::modeloDeStatus()) !== null) {
            $mensagem = WhatsApp::modelo($modelo, [(string) $pedido['id'], PedidoService::ROTULOS[$aviso['tipo']]]);
        } else {
            $this->apagar($id);
            (new Conversas())->registrarEvento('sem_janela.' . bin2hex(random_bytes(8)), $telefone, 'out', 'falha', [
                'texto' => '', 'motivo' => 'aviso:' . $aviso['tipo'], 'erro' => 'Fora da janela de 24 h e sem template configurado: aviso não enviado.',
            ]);
            return false;
        }

        $resultado = WhatsApp::enviar($telefone, $mensagem, 'aviso:' . $aviso['tipo']);
        if ($resultado['ok']) {
            $this->apagar($id);
            return true;
        }
        if ($resultado['janela_fechada']) {
            $this->conversas->fecharJanela($telefone);   // a próxima tentativa vai como template, já em seguida
        }
        $tentativas = (int) $aviso['tentativas'] + 1;
        $erroDefinitivo = !$resultado['transitorio'] && !$resultado['janela_fechada'];   // ex.: número inválido
        $desiste = $erroDefinitivo || $tentativas > count(self::ESPERA_MINUTOS);
        $proxima = $desiste ? self::DESISTIU : gmdate('Y-m-d H:i:s', time() + ($resultado['janela_fechada'] ? 0 : self::ESPERA_MINUTOS[$tentativas - 1] * 60));
        Banco::conexao()->prepare('UPDATE whatsapp_envios_pendentes SET tentativas = ?, proxima_tentativa_em = ?, ultimo_erro = ? WHERE id = ?')
            ->execute([$tentativas, $proxima, mb_substr((string) $resultado['erro'], 0, 300), $id]);
        return false;
    }

    /** O texto do aviso (dentro da janela de 24 h). */
    public function texto(array $pedido, string $tipo): string
    {
        $n = $pedido['id'];
        $link = WhatsApp::urlDoSite() . '/pedido/' . $pedido['codigo'];
        return match ($tipo) {
            'novo' => "Recebemos seu pedido #{$n}! Total: R$ " . number_format($pedido['total_centavos'] / 100, 2, ',', '.') . '. '
                . 'Previsão: ' . ($pedido['tipo'] === 'entrega' ? $this->config->inteiro('tempo_entrega_min', 50) : $this->config->inteiro('tempo_retirada_min', 25)) . " minutos.\nAcompanhe: {$link}",
            'em_preparo' => "Seu pedido #{$n} está em preparo.",
            'pronto' => $pedido['tipo'] === 'entrega' ? "Seu pedido #{$n} está pronto e já vai sair para entrega." : "Seu pedido #{$n} está pronto.",
            'saiu_entrega' => "Seu pedido #{$n} saiu para entrega.",
            'aguardando_retirada' => "Seu pedido #{$n} está pronto e esperando você no balcão.",
            'concluido' => "Pedido #{$n} concluído. Obrigado e bom apetite!",
            'cancelado' => "Seu pedido #{$n} foi cancelado." . (($motivo = $this->motivoDoCancelamento($pedido)) !== '' ? " Motivo: {$motivo}." : '') . "\nSe precisar, fale com a gente por aqui.",
            default => "Pedido #{$n}: " . (PedidoService::ROTULOS[$tipo] ?? $tipo) . '.',
        };
    }

    private function motivoDoCancelamento(array $pedido): string
    {
        foreach (array_reverse($pedido['historico'] ?? []) as $h) {
            if ($h['status'] === 'cancelado') {
                return (string) ($h['motivo'] ?? '');
            }
        }
        return '';
    }

    private function apagar(int $id): void
    {
        Banco::conexao()->prepare('DELETE FROM whatsapp_envios_pendentes WHERE id = ?')->execute([$id]);
    }

    /**
     * Avisos na fila (esperando a hora, ou que a fila desistiu) para a tela de integração.
     * @return list<array{pedido_id:int, tipo:string, tentativas:int, proxima_tentativa_em:?string, desistiu:bool, ultimo_erro:?string, telefone:string}>
     */
    public function pendentes(int $limite = 20): array
    {
        $lista = [];
        foreach (Banco::conexao()->query('SELECT * FROM whatsapp_envios_pendentes ORDER BY id DESC LIMIT ' . max(1, min(100, $limite)))->fetchAll() as $a) {
            $lista[] = [
                'pedido_id' => (int) $a['pedido_id'],
                'tipo' => $a['tipo'],
                'tentativas' => (int) $a['tentativas'],
                'proxima_tentativa_em' => $a['proxima_tentativa_em'] === self::DESISTIU ? null : Relogio::iso($a['proxima_tentativa_em']),
                'desistiu' => $a['proxima_tentativa_em'] === self::DESISTIU,
                'ultimo_erro' => $a['ultimo_erro'],
                'telefone' => Conversas::mascarar((string) $a['telefone']),
            ];
        }
        return $lista;
    }
}
