<?php
declare(strict_types=1);

namespace App\WhatsApp;

use App\Core\Banco;
use App\Core\Relogio;

/**
 * Conversas do WhatsApp (whatsapp_conversas) e o registro de mensagens (whatsapp_eventos).
 *
 * whatsapp_eventos tem `wamid` único: é a idempotência. A Meta reenvia o mesmo evento quando não recebe o 200 a tempo; o
 * segundo INSERT não entra, e o bot sabe que já respondeu. O `payload_resumido` guarda só o necessário (o texto, para a
 * caixa de entrada do atendimento humano), nunca o JSON inteiro da Meta. Registros com mais de 30 dias são apagados.
 */
final class Conversas
{
    public const JANELA_SEGUNDOS = 86400;   // 24 h depois da última mensagem do cliente, só template
    private const DIAS_DE_REGISTRO = 30;

    /**
     * Registra uma mensagem. Devolve false se este wamid já existia (evento repetido): quem chamou não deve fazer nada.
     * @param array<string,mixed> $resumo
     */
    public function registrarEvento(string $wamid, string $telefone, string $direcao, string $tipo, array $resumo): bool
    {
        $consulta = Banco::conexao()->prepare(
            'INSERT INTO whatsapp_eventos (wamid, telefone, direcao, tipo, payload_resumido, criado_em) VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (wamid) DO NOTHING',
        );
        $consulta->execute([mb_substr($wamid, 0, 200), $telefone, $direcao, mb_substr($tipo, 0, 30), json_encode($resumo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), Relogio::agora()]);
        return $consulta->rowCount() === 1;
    }

    /** @return array{telefone:string, modo:string, humano_ate:?string, ultima_msg_cliente_em:?string, ultimo_pedido_id:?int, criado_em:string}|null */
    public function conversa(string $telefone): ?array
    {
        $consulta = Banco::conexao()->prepare('SELECT * FROM whatsapp_conversas WHERE telefone = ?');
        $consulta->execute([$telefone]);
        $linha = $consulta->fetch();
        if (!$linha) {
            return null;
        }
        $linha['ultimo_pedido_id'] = $linha['ultimo_pedido_id'] === null ? null : (int) $linha['ultimo_pedido_id'];
        return $linha;
    }

    /** O cliente mandou mensagem agora: a janela de 24 h (re)começa. */
    public function tocarCliente(string $telefone): void
    {
        $agora = Relogio::agora();
        Banco::conexao()->prepare(
            "INSERT INTO whatsapp_conversas (telefone, modo, ultima_msg_cliente_em, criado_em) VALUES (?, 'bot', ?, ?)
             ON CONFLICT (telefone) DO UPDATE SET ultima_msg_cliente_em = EXCLUDED.ultima_msg_cliente_em",
        )->execute([$telefone, $agora, $agora]);
    }

    /** Dentro da janela de 24 h vale mensagem livre; fora dela, só template aprovado. */
    public function janelaAberta(string $telefone): bool
    {
        $ultima = $this->conversa($telefone)['ultima_msg_cliente_em'] ?? null;
        return $ultima !== null && $ultima >= gmdate('Y-m-d H:i:s', time() - self::JANELA_SEGUNDOS);
    }

    /** A Meta disse que a janela fechou (o nosso registro estava otimista): os próximos avisos vão como template. */
    public function fecharJanela(string $telefone): void
    {
        Banco::conexao()->prepare('UPDATE whatsapp_conversas SET ultima_msg_cliente_em = NULL WHERE telefone = ?')->execute([$telefone]);
    }

    /** Está em atendimento humano agora? Se o prazo acabou, a conversa volta sozinha para o bot. */
    public function emAtendimentoHumano(string $telefone): bool
    {
        $conversa = $this->conversa($telefone);
        if ($conversa === null || $conversa['modo'] !== 'humano') {
            return false;
        }
        if ($conversa['humano_ate'] !== null && $conversa['humano_ate'] <= Relogio::agora()) {
            $this->voltarAoBot($telefone);
            return false;
        }
        return true;
    }

    /** @return string até quando (UTC) */
    public function entrarNoAtendimentoHumano(string $telefone, int $horas): string
    {
        $ate = gmdate('Y-m-d H:i:s', time() + max(1, $horas) * 3600);
        $agora = Relogio::agora();
        Banco::conexao()->prepare(
            "INSERT INTO whatsapp_conversas (telefone, modo, humano_ate, criado_em) VALUES (?, 'humano', ?, ?)
             ON CONFLICT (telefone) DO UPDATE SET modo = 'humano', humano_ate = EXCLUDED.humano_ate",
        )->execute([$telefone, $ate, $agora]);
        return $ate;
    }

    public function voltarAoBot(string $telefone): void
    {
        Banco::conexao()->prepare("UPDATE whatsapp_conversas SET modo = 'bot', humano_ate = NULL WHERE telefone = ?")->execute([$telefone]);
    }

    public function definirUltimoPedido(string $telefone, int $pedidoId): void
    {
        $agora = Relogio::agora();
        Banco::conexao()->prepare(
            "INSERT INTO whatsapp_conversas (telefone, modo, ultimo_pedido_id, criado_em) VALUES (?, 'bot', ?, ?)
             ON CONFLICT (telefone) DO UPDATE SET ultimo_pedido_id = EXCLUDED.ultimo_pedido_id",
        )->execute([$telefone, $pedidoId, $agora]);
    }

    /**
     * Mensagens de uma conversa, da mais antiga para a mais nova.
     * @return list<array{id:int, direcao:string, tipo:string, em:string, texto:string, botoes:array<string,string>, link:?array, erro:?string, nome:?string, botao:?string}>
     */
    public function mensagens(string $telefone, int $limite = 40): array
    {
        $consulta = Banco::conexao()->prepare('SELECT id, direcao, tipo, payload_resumido, criado_em FROM whatsapp_eventos WHERE telefone = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limite)));
        $consulta->execute([$telefone]);
        $lista = [];
        foreach (array_reverse($consulta->fetchAll()) as $linha) {
            $resumo = json_decode((string) $linha['payload_resumido'], true);
            $resumo = is_array($resumo) ? $resumo : [];
            $lista[] = [
                'id' => (int) $linha['id'],
                'direcao' => $linha['direcao'],
                'tipo' => $linha['tipo'],
                'em' => Relogio::iso($linha['criado_em']),
                'texto' => (string) ($resumo['texto'] ?? ''),
                'botoes' => is_array($resumo['botoes'] ?? null) ? $resumo['botoes'] : [],
                'link' => is_array($resumo['link'] ?? null) ? $resumo['link'] : null,
                'erro' => isset($resumo['erro']) ? (string) $resumo['erro'] : null,
                'nome' => isset($resumo['nome']) ? (string) $resumo['nome'] : null,
                'botao' => isset($resumo['botao']) ? (string) $resumo['botao'] : null,
            ];
        }
        return $lista;
    }

    /**
     * Conversas em atendimento humano agora (a caixa de entrada do painel), com o nome que o cliente usa no WhatsApp.
     * @return list<array{telefone:string, nome:?string, humano_ate:?string, janela_aberta:bool, ultima_msg_cliente_em:?string}>
     */
    public function emAtendimento(): array
    {
        $consulta = Banco::conexao()->prepare("SELECT * FROM whatsapp_conversas WHERE modo = 'humano' AND (humano_ate IS NULL OR humano_ate > ?) ORDER BY ultima_msg_cliente_em DESC");
        $consulta->execute([Relogio::agora()]);
        $lista = [];
        foreach ($consulta->fetchAll() as $c) {
            $nome = null;
            foreach (array_reverse($this->mensagens($c['telefone'], 20)) as $m) {
                if ($m['direcao'] === 'in' && $m['nome']) {
                    $nome = $m['nome'];
                    break;
                }
            }
            $lista[] = [
                'telefone' => $c['telefone'],
                'nome' => $nome,
                'humano_ate' => Relogio::iso($c['humano_ate']),
                'janela_aberta' => $this->janelaAberta($c['telefone']),
                'ultima_msg_cliente_em' => Relogio::iso($c['ultima_msg_cliente_em']),
            ];
        }
        return $lista;
    }

    /**
     * Atividade recente para a tela de integração: sem o texto das mensagens e com o telefone mascarado.
     * @return list<array{em:string, direcao:string, tipo:string, telefone:string, erro:?string, motivo:?string}>
     */
    public function recentes(int $limite = 20): array
    {
        $lista = [];
        foreach (Banco::conexao()->query('SELECT direcao, tipo, telefone, payload_resumido, criado_em FROM whatsapp_eventos ORDER BY id DESC LIMIT ' . max(1, min(100, $limite)))->fetchAll() as $linha) {
            $resumo = json_decode((string) $linha['payload_resumido'], true);
            $lista[] = [
                'em' => Relogio::iso($linha['criado_em']),
                'direcao' => $linha['direcao'],
                'tipo' => $linha['tipo'],
                'telefone' => self::mascarar($linha['telefone']),
                'erro' => is_array($resumo) && isset($resumo['erro']) ? (string) $resumo['erro'] : null,
                'motivo' => is_array($resumo) && isset($resumo['motivo']) ? (string) $resumo['motivo'] : null,
            ];
        }
        return $lista;
    }

    /** Apaga o registro de mensagens com mais de 30 dias (só o necessário fica guardado). */
    public function limparAntigos(): void
    {
        Banco::conexao()->prepare('DELETE FROM whatsapp_eventos WHERE criado_em < ?')->execute([gmdate('Y-m-d H:i:s', time() - self::DIAS_DE_REGISTRO * 86400)]);
    }

    /** "5511912345678" → "55 11 9••••-5678" */
    public static function mascarar(string $telefone): string
    {
        if (strlen($telefone) < 8) {
            return '••••';
        }
        return substr($telefone, 0, 2) . ' ' . substr($telefone, 2, 2) . ' ' . substr($telefone, 4, 1) . '••••-' . substr($telefone, -4);
    }
}
