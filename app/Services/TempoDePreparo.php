<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Banco;
use App\Core\Relogio;
use App\Repositories\ConfigRepository;

/**
 * Tempo de produção de um pedido = soma dos períodos em que ele esteve em "Novo" e "Em preparo".
 *
 * É calculado pelo histórico de status, não pela hora do pedido: mudar de Novo para Em preparo não zera o
 * relógio, e um pedido que voltou de "Pronto" para "Em preparo" soma o tempo antigo ao novo.
 * O painel recebe duas coisas e faz a conta viva no navegador:
 *   acumulado_s  segundos dos períodos já fechados;
 *   desde        quando começou o período aberto (null se o pedido não está mais sendo produzido).
 * Tempo atual = acumulado_s + (agora - desde).
 */
final class TempoDePreparo
{
    /** Status que contam como "em produção". */
    public const CONTADOS = ['novo', 'em_preparo'];

    /** Limites das cores do cartão, em minutos de produção (também em public/assets/js/shared/tempo.js). */
    public const LIMITE_ATENCAO_MIN = 20;
    public const LIMITE_ATRASO_MIN = 30;

    /**
     * @param list<array{status:string, criado_em:string}> $historico  mudanças de status em ordem; datas "Y-m-d H:i:s" (UTC)
     * @return array{acumulado_s:int, desde:?string}  `desde` em ISO 8601
     */
    public static function calcular(array $historico): array
    {
        $acumulado = 0;
        $desde = null;
        $total = count($historico);
        foreach ($historico as $i => $linha) {
            if (!in_array($linha['status'], self::CONTADOS, true)) {
                continue;
            }
            if ($i + 1 < $total) {
                $acumulado += max(0, self::segundos($historico[$i + 1]['criado_em']) - self::segundos($linha['criado_em']));
            } else {
                $desde = Relogio::iso($linha['criado_em']);   // período aberto: o painel soma o resto
            }
        }
        return ['acumulado_s' => $acumulado, 'desde' => $desde];
    }

    /** Tempo de um prato quando a massa não tem tempo cadastrado (massa apagada). */
    public const PREPARO_PADRAO_MIN = 15;

    /**
     * Previsão de preparo pela linha do tempo da cozinha (D99, D100). A cozinha tem `cozinha_capacidade` bocas (pratos ao
     * mesmo tempo; 0 = sem limite). Os pedidos em produção (Novo e Em preparo) entram na ordem de chegada; cada prato ocupa a
     * boca que fica livre primeiro, pelo tempo de preparo dele (o do prato da casa ou o da massa). O pedido fica pronto quando
     * o último prato dele sai. Itens prontos (bebida, sobremesa) não ocupam boca. Pedido já Em preparo conta só o que falta.
     *
     * A previsão é a hora em que o pedido FICA PRONTO (não a de chegada na casa do cliente), gravada em
     * pedidos.previsao_minutos como minutos desde o pedido (0 = nada para a cozinha).
     *   * pedido novo: só a dele é calculada; quem chega depois não mexe em quem já pediu;
     *   * mudança de status (saiu da cozinha, cancelado, voltou para preparo) e configuração salva: a fila toda é refeita,
     *     e quem está atrás adianta quando a cozinha libera boca. Quem já saiu da cozinha fica com a última previsão.
     */
    public static function recalcularFila(\PDO $pdo, ?ConfigRepository $config = null, ?int $soPedido = null): void
    {
        $config ??= new ConfigRepository();
        $agora = time();
        $fila = self::fila($pdo);
        $prontos = self::linhaDoTempo($fila, $config->inteiro('cozinha_capacidade', 0), $agora);
        $grava = $pdo->prepare('UPDATE pedidos SET previsao_minutos = ? WHERE id = ?');
        foreach ($fila as $p) {
            if ($soPedido !== null && $p['id'] !== $soPedido) {
                continue;
            }
            $minutos = $p['pratos'] === [] ? 0 : (int) ceil(($prontos[$p['id']] - $p['criado']) / 60);
            if ($p['previsao'] !== $minutos) {
                $grava->execute([$minutos, $p['id']]);
            }
        }
    }

    /**
     * Em quantos minutos ficaria pronto um pedido que entrasse agora com um prato do maior tempo cadastrado.
     * É o "a partir de" que o cardápio e o bot mostram antes do pedido existir.
     */
    public static function estimativaDeUmPrato(?\PDO $pdo = null, ?ConfigRepository $config = null): int
    {
        $pdo ??= Banco::conexao();
        $config ??= new ConfigRepository();
        $maior = (int) ($pdo->query("SELECT MAX(preparo_min) FROM massas WHERE estado <> 'oculto'")->fetchColumn() ?: self::PREPARO_PADRAO_MIN);
        $agora = time();
        $fila = self::fila($pdo);
        $fila[] = ['id' => 0, 'criado' => $agora, 'inicio' => null, 'previsao' => null, 'pratos' => [$maior]];
        return (int) ceil((self::linhaDoTempo($fila, $config->inteiro('cozinha_capacidade', 0), $agora)[0] - $agora) / 60);
    }

    /**
     * A simulação em si, sem banco (testável à parte).
     * @param list<array{id:int, inicio:?int, pratos:list<int>}> $fila  na ordem de chegada; inicio = timestamp em que entrou
     *   Em preparo (null se ainda Novo); pratos = minutos de cada prato
     * @return array<int,int> id => timestamp em que o pedido fica pronto
     */
    public static function linhaDoTempo(array $fila, int $bocas, int $agora): array
    {
        $livre = array_fill(0, max(1, $bocas), $agora);   // quando cada boca fica livre
        $prontos = [];
        foreach ($fila as $p) {
            $fim = $agora;
            foreach ($p['pratos'] as $minutos) {
                $duracao = $minutos * 60;
                if ($p['inicio'] !== null) {
                    $duracao = max(60, $duracao - max(0, $agora - $p['inicio']));   // já está no fogo: só o que falta (no mínimo 1 min)
                }
                if ($bocas <= 0) {
                    $fim = max($fim, $agora + $duracao);   // sem limite: todos os pratos começam já
                    continue;
                }
                $boca = array_search(min($livre), $livre, true);
                $livre[$boca] += $duracao;
                $fim = max($fim, $livre[$boca]);
            }
            $prontos[$p['id']] = $fim;
        }
        return $prontos;
    }

    /** @return list<array{id:int, criado:int, inicio:?int, previsao:?int, pratos:list<int>}> */
    private static function fila(\PDO $pdo): array
    {
        $consulta = $pdo->prepare('SELECT id, status, criado_em, status_atualizado_em, previsao_minutos FROM pedidos WHERE status IN (?, ?) ORDER BY id');
        $consulta->execute(self::CONTADOS);
        $linhas = $consulta->fetchAll();
        if ($linhas === []) {
            return [];
        }
        $massas = [];
        foreach ($pdo->query('SELECT id, preparo_min FROM massas') as $l) {
            $massas[(int) $l['id']] = (int) $l['preparo_min'];
        }
        $pratosDaCasa = [];
        foreach ($pdo->query('SELECT id, preparo_min FROM itens WHERE preparo_min IS NOT NULL') as $l) {
            $pratosDaCasa[(int) $l['id']] = (int) $l['preparo_min'];
        }
        $ids = array_map(static fn (array $l): int => (int) $l['id'], $linhas);
        // shortcut: itens do iFood chegam como "simples" e não ocupam boca; mapear o catálogo do iFood quando a loja vender por lá.
        $itens = $pdo->prepare("SELECT pedido_id, item_id, quantidade, montagem FROM itens_pedido WHERE tipo = 'montado' AND pedido_id IN ("
            . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY id');
        $itens->execute($ids);
        $pratos = [];
        foreach ($itens->fetchAll() as $l) {
            $massa = (int) (json_decode((string) $l['montagem'], true)['massa']['id'] ?? 0);
            $minutos = ($l['item_id'] !== null ? ($pratosDaCasa[(int) $l['item_id']] ?? null) : null) ?? $massas[$massa] ?? self::PREPARO_PADRAO_MIN;
            for ($n = 0; $n < (int) $l['quantidade']; $n++) {
                $pratos[(int) $l['pedido_id']][] = $minutos;
            }
        }
        $segundos = static fn (string $data): int => (int) strtotime($data . ' UTC');
        return array_map(static fn (array $l): array => [
            'id' => (int) $l['id'],
            'criado' => $segundos($l['criado_em']),
            'inicio' => $l['status'] === 'em_preparo' ? $segundos($l['status_atualizado_em']) : null,
            'previsao' => $l['previsao_minutos'] === null ? null : (int) $l['previsao_minutos'],
            'pratos' => $pratos[(int) $l['id']] ?? [],
        ], $linhas);
    }

    /** "Cor" do cartão para um tempo em segundos: neutro (até 19 min), atencao (20 a 29) ou atrasado (30 ou mais). */
    public static function faixa(int $segundos): string
    {
        $minutos = intdiv(max(0, $segundos), 60);
        return match (true) {
            $minutos >= self::LIMITE_ATRASO_MIN => 'atrasado',
            $minutos >= self::LIMITE_ATENCAO_MIN => 'atencao',
            default => 'neutro',
        };
    }

    private static function segundos(string $dataUtc): int
    {
        return (int) strtotime($dataUtc . ' UTC');
    }
}
