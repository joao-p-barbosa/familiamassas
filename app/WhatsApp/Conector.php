<?php
declare(strict_types=1);

namespace App\WhatsApp;

use App\Core\Banco;
use App\Core\Env;
use App\Core\Relogio;

/**
 * Canal do WhatsApp por QR code (WHATSAPP_CANAL=qr): um processo Node (pasta /conector, biblioteca Baileys) fica conectado
 * ao WhatsApp da loja e conversa com este sistema por HTTP. Aqui está a parte do PHP:
 *   * o estado do Node (sinal de vida a cada 30 s; 2 min sem sinal ou estado "desconectado" = queda);
 *   * a retenção da fila de avisos: queda de 2 min ou mais retém a fila, e o proprietário escolhe enviar ou descartar (D68);
 *   * a localização (pin) que o cliente manda no pedido pelo chat (D74). Os números "1", "2", "3" não são mais botões:
 *     são respostas aos passos do pedido (FluxoPedido).
 */
final class Conector
{
    public const ESTADOS = ['conectado', 'aguardando_qr', 'desconectado'];
    public const SEM_SINAL_SEGUNDOS = 120;      // sem sinal de vida por tanto tempo = queda
    public const RETER_APOS_SEGUNDOS = 120;     // queda dessa duração retém a fila (um deploy leva menos que isso)
    public const AVISO_VELHO_MINUTOS = 30;      // aviso de status mais velho que isso não vale mais
    public const MENSAGEM_VELHA_MINUTOS = 10;   // mensagem do cliente entregue com mais atraso que isso não é respondida

    public static function ativo(): bool
    {
        return Env::get('WHATSAPP_CANAL') === 'qr';
    }

    /** O Node se identifica com o segredo no cabeçalho X-Conector-Segredo. Sem segredo configurado, nada é aceito. */
    public static function segredoValido(string $recebido): bool
    {
        $segredo = Env::get('CONECTOR_SEGREDO');
        return strlen($segredo) >= 16 && hash_equals($segredo, $recebido);
    }

    /** O sinal de vida do Node. O QR só é guardado enquanto o estado for "aguardando_qr". */
    public function registrarSinal(string $estado, ?string $qr): void
    {
        if (!in_array($estado, self::ESTADOS, true)) {
            throw new \InvalidArgumentException('estado desconhecido');
        }
        if ($qr !== null && (!str_starts_with($qr, 'data:image/png;base64,') || strlen($qr) > 30000)) {
            $qr = null;
        }
        $agora = Relogio::agora();
        Banco::conexao()->prepare(
            'INSERT INTO whatsapp_conector (id, estado, estado_desde, visto_em, qr) VALUES (1, ?, ?, ?, ?)
             ON CONFLICT (id) DO UPDATE SET
               estado_desde = CASE WHEN whatsapp_conector.estado = EXCLUDED.estado THEN whatsapp_conector.estado_desde ELSE EXCLUDED.estado_desde END,
               estado = EXCLUDED.estado, visto_em = EXCLUDED.visto_em, qr = EXCLUDED.qr',
        )->execute([$estado, $agora, $agora, $estado === 'aguardando_qr' ? $qr : null]);
    }

    /**
     * Situação atual, e as duas decisões que dependem do tempo: queda longa retém a fila; queda que acabou sem nada na fila
     * libera sozinha (não há o que decidir).
     * @return array{estado:string, desde:?string, qr:?string, fila_retida:bool, pendentes:int, alarme:bool}
     *   estado: conectado | aguardando_qr | desconectado | sem_sinal (o Node parou de avisar)
     *   alarme: fora do ar há 2 min ou mais; antes disso (um deploy reinicia o Node) o painel não faz barulho
     */
    public function situacao(): array
    {
        $pdo = Banco::conexao();
        $linha = $pdo->query('SELECT * FROM whatsapp_conector WHERE id = 1')->fetch() ?: null;
        $semSinal = $linha === null || $linha['visto_em'] === null || $linha['visto_em'] < gmdate('Y-m-d H:i:s', time() - self::SEM_SINAL_SEGUNDOS);
        $estado = $semSinal ? 'sem_sinal' : (string) $linha['estado'];
        $desde = $linha === null ? null : ($semSinal ? ($linha['visto_em'] ?? $linha['estado_desde']) : $linha['estado_desde']);
        $retida = $linha !== null && (int) $linha['fila_retida'] === 1;
        $pendentes = $this->pendentes();

        $alarme = $estado !== 'conectado' && $desde !== null && $desde <= gmdate('Y-m-d H:i:s', time() - self::RETER_APOS_SEGUNDOS);
        if ($alarme && !$retida) {
            $pdo->exec('UPDATE whatsapp_conector SET fila_retida = 1 WHERE id = 1');
            $retida = true;
        }
        if ($linha !== null && $estado === 'conectado' && $retida && $pendentes === 0) {
            $pdo->exec('UPDATE whatsapp_conector SET fila_retida = 0 WHERE id = 1');
            $retida = false;
        }
        return [
            'estado' => $estado,
            'desde' => Relogio::iso($desde),
            'qr' => $estado === 'aguardando_qr' ? ($linha['qr'] ?? null) : null,
            'fila_retida' => $retida,
            'pendentes' => $pendentes,
            'alarme' => $alarme,
        ];
    }

    /** A fila de avisos pode andar? Só com o Node conectado, com sinal recente e a fila não retida. Sem efeito colateral. */
    public function liberada(): bool
    {
        $linha = Banco::conexao()->query('SELECT estado, visto_em, fila_retida FROM whatsapp_conector WHERE id = 1')->fetch();
        return $linha && $linha['estado'] === 'conectado' && (int) $linha['fila_retida'] === 0
            && $linha['visto_em'] !== null && $linha['visto_em'] >= gmdate('Y-m-d H:i:s', time() - self::SEM_SINAL_SEGUNDOS);
    }

    /** "Enviar pendentes": a fila volta a andar. O que tiver mais de 30 min é descartado na hora de enviar (Avisos). */
    public function liberarFila(): void
    {
        Banco::conexao()->exec('UPDATE whatsapp_conector SET fila_retida = 0 WHERE id = 1');
    }

    /** "Descartar": apaga os avisos esperando e libera a fila. @return int quantos avisos foram apagados */
    public function descartarFila(): int
    {
        $pdo = Banco::conexao();
        $apagados = $pdo->exec('DELETE FROM whatsapp_envios_pendentes');
        $pdo->exec('UPDATE whatsapp_conector SET fila_retida = 0 WHERE id = 1');
        return (int) $apagados;
    }

    /** Avisos esperando para sair (os que a fila já desistiu não contam). */
    private function pendentes(): int
    {
        $consulta = Banco::conexao()->prepare('SELECT COUNT(*) FROM whatsapp_envios_pendentes WHERE proxima_tentativa_em <> ?');
        $consulta->execute([Avisos::DESISTIU]);
        return (int) $consulta->fetchColumn();
    }

    /** O que o painel precisa em toda consulta de pedidos (sem o QR, que é pesado e só a tela WhatsApp mostra). */
    public function paraOPainel(): array
    {
        $s = $this->situacao();
        return ['estado' => $s['estado'], 'desde' => $s['desde'], 'fila_retida' => $s['fila_retida'], 'pendentes' => $s['pendentes'], 'alarme' => $s['alarme']];
    }

    /**
     * Uma mensagem do Node no formato que o bot entende.
     * @param array<string,mixed> $m {id, telefone, texto, nome?, em?: segundos desde 1970, local?: {lat, lng}, enquete?: tag da enquete votada}
     * @return array{wamid:string, telefone:string, tipo:string, texto:string, botao:?string, nome:?string, local:?array{lat:float, lng:float}, enquete:?string}|null
     */
    public static function normalizar(array $m): ?array
    {
        $id = (string) ($m['id'] ?? '');
        $telefone = preg_replace('/\D/', '', (string) ($m['telefone'] ?? ''));
        if ($id === '' || $telefone === '' || strlen($telefone) > 15) {
            return null;
        }
        $texto = mb_substr(trim((string) ($m['texto'] ?? '')), 0, 1000);
        $nome = mb_substr(trim((string) ($m['nome'] ?? '')), 0, 60);
        $local = self::local($m['local'] ?? null);
        $enquete = isset($m['enquete']) && is_string($m['enquete']) && preg_match('/^[A-Za-z0-9_-]{4,40}$/', $m['enquete']) === 1 ? $m['enquete'] : null;
        return [
            'wamid' => 'qr.' . mb_substr($id, 0, 150), 'telefone' => $telefone, 'tipo' => $enquete !== null ? 'poll_vote' : ($local !== null ? 'location' : 'text'),
            'texto' => $texto, 'botao' => null, 'nome' => $nome !== '' ? $nome : null, 'local' => $local, 'enquete' => $enquete,
        ];
    }

    /** Latitude e longitude válidas, ou null. @return array{lat:float, lng:float}|null */
    public static function local(mixed $local): ?array
    {
        if (!is_array($local) || !is_numeric($local['lat'] ?? null) || !is_numeric($local['lng'] ?? null)) {
            return null;
        }
        $lat = (float) $local['lat'];
        $lng = (float) $local['lng'];
        return abs($lat) <= 90 && abs($lng) <= 180 && ($lat !== 0.0 || $lng !== 0.0) ? ['lat' => $lat, 'lng' => $lng] : null;
    }

    /**
     * Mensagens que o Node recebeu. As que chegaram com muito atraso (o WhatsApp entrega o que ficou pendente quando o Node
     * volta) não são respondidas: o cliente já desistiu ou foi atendido por outro caminho.
     * @param list<array<string,mixed>> $mensagens
     * @return array{respondidas:int, repetidas:int, antigas:int, ignoradas:int}
     */
    public function receber(array $mensagens, Bot $bot): array
    {
        $r = ['respondidas' => 0, 'repetidas' => 0, 'antigas' => 0, 'ignoradas' => 0];
        $limite = time() - self::MENSAGEM_VELHA_MINUTOS * 60;
        foreach (array_slice($mensagens, 0, 50) as $m) {
            $normal = is_array($m) ? self::normalizar($m) : null;
            if ($normal === null || ($normal['texto'] === '' && $normal['local'] === null)) {
                $r['ignoradas']++;
            } elseif (isset($m['em']) && (int) $m['em'] > 0 && (int) $m['em'] < $limite) {
                $r['antigas']++;
            } else {
                $bot->receber($normal) === 'repetida' ? $r['repetidas']++ : $r['respondidas']++;
            }
        }
        return $r;
    }

    /**
     * A mensagem no formato da Cloud API vira texto simples: o WhatsApp comum não tem botões. Os botões viram um menu numerado
     * (o número é a posição em WhatsApp::BOTOES, sempre a mesma, mesmo quando só alguns botões são oferecidos) e o link vai no texto.
     */
    public static function texto(array $mensagem): string
    {
        $resumo = WhatsApp::resumo($mensagem);
        $texto = $resumo['texto'];
        if (isset($resumo['botoes'])) {
            $ordem = array_keys(WhatsApp::BOTOES);
            $linhas = [];
            foreach ($resumo['botoes'] as $id => $titulo) {
                $linhas[] = (array_search($id, $ordem, true) + 1) . ' - ' . $titulo;
            }
            $texto .= "\n\nResponda com o número:\n" . implode("\n", $linhas);
        }
        if (isset($resumo['link'])) {
            $texto .= "\n\n" . $resumo['link']['rotulo'] . ': ' . $resumo['link']['url'];
        }
        if (isset($resumo['enquete'])) {   // enquete mandada como texto: as opções já levam o número
            $texto .= "\n\n" . implode("\n", $resumo['enquete']['opcoes']) . "\n\nResponda com o número" . ($resumo['enquete']['multipla'] ? ' (vários: 1, 3; nenhum: 0).' : '.');
        }
        return $texto;
    }
}
