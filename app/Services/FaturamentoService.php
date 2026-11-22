<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Banco;
use App\Core\ErroHttp;
use App\Core\Relogio;
use App\Repositories\PedidoRepository;

/**
 * Faturamento (D101): tela Vendas, Fechamento do dia e cancelamento de venda (total ou parcial). Adaptado do Cantinho de Sabores.
 *
 * Venda = pedido do cardápio, WhatsApp ou iFood que chegou a Concluído (ou foi Cancelado) e conta do Salão encerrada com valor.
 * A data da venda é a do pedido (criado_em) ou a do fechamento da conta (encerrado_em), no fuso da loja.
 * Valor da venda = total menos os cancelamentos registrados nela (tabela cancelamentos). Pedido com status Cancelado vale 0.
 * Dinheiro sempre em centavos.
 */
final class FaturamentoService
{
    public const CANAIS = ['cardapio' => 'Cardápio digital', 'whatsapp' => 'WhatsApp', 'ifood' => 'iFood', 'balcao' => 'Balcão', 'salao' => 'Salão'];
    public const PAGAMENTOS = ['pix' => 'Pix', 'cartao' => 'Cartão', 'dinheiro' => 'Dinheiro'];
    public const DIAS = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
    private const POR_PAGINA = 30;

    public static function hoje(): string
    {
        return (new \DateTimeImmutable('today', Relogio::fusoDaLoja()))->format('Y-m-d');
    }

    public static function dataValida(string $data): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $data, Relogio::fusoDaLoja());
        return $d !== false && $d->format('Y-m-d') === $data;
    }

    /** @return array{0:string, 1:string} [início, fim) em UTC dos dias locais $de a $ate (inclusive) */
    private static function intervalo(string $de, string $ate): array
    {
        $fuso = Relogio::fusoDaLoja();
        $utc = new \DateTimeZone('UTC');
        $inicio = (new \DateTimeImmutable($de . ' 00:00:00', $fuso))->setTimezone($utc);
        $fim = (new \DateTimeImmutable($ate . ' 00:00:00', $fuso))->modify('+1 day')->setTimezone($utc);
        return [$inicio->format('Y-m-d H:i:s'), $fim->format('Y-m-d H:i:s')];
    }

    private static function local(string $utc): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(Relogio::fusoDaLoja());
    }

    /**
     * Todas as vendas do intervalo, da mais nova para a mais antiga.
     * @return list<array<string,mixed>>
     */
    public function vendas(string $de, string $ate): array
    {
        [$inicio, $fim] = self::intervalo($de, $ate);
        $pdo = Banco::conexao();
        $cancelado = $this->canceladoPorVenda($pdo);

        $vendas = [];
        $sql = $pdo->prepare("SELECT id, origem, pagamento, total_centavos, status, criado_em, cliente_nome FROM pedidos
                              WHERE atendimento_id IS NULL AND status IN ('concluido', 'cancelado') AND criado_em >= ? AND criado_em < ?");
        $sql->execute([$inicio, $fim]);
        foreach ($sql->fetchAll() as $p) {
            $chave = 'pedido:' . $p['id'];
            $vendas[$chave] = $this->venda($chave, '#' . $p['id'], (string) $p['cliente_nome'], $p['criado_em'], $p['origem'], $p['pagamento'],
                (int) $p['total_centavos'], $p['status'] === 'cancelado' ? (int) $p['total_centavos'] : ($cancelado[$chave] ?? 0));
        }
        $sql = $pdo->prepare("SELECT id, cliente_nome, modalidade, mesa, pagamento, total_centavos, encerrado_em FROM atendimentos
                              WHERE status = 'encerrado' AND COALESCE(total_centavos, 0) > 0 AND encerrado_em >= ? AND encerrado_em < ?");
        $sql->execute([$inicio, $fim]);
        foreach ($sql->fetchAll() as $a) {
            $chave = 'conta:' . $a['id'];
            $onde = $a['modalidade'] === 'mesa' ? ('Mesa' . ($a['mesa'] ? ' ' . $a['mesa'] : '')) : 'Pra viagem';
            $vendas[$chave] = $this->venda($chave, 'Conta ' . $onde, (string) $a['cliente_nome'], $a['encerrado_em'], 'salao', (string) $a['pagamento'],
                (int) $a['total_centavos'], $cancelado[$chave] ?? 0);
        }

        // Itens: os pedidos avulsos e os pedidos (não cancelados) de cada conta.
        $itens = $pdo->prepare("SELECT i.id, i.pedido_id, NULL AS atendimento_id, i.nome, i.quantidade, i.quantidade_cancelada, i.preco_unit_centavos
                                FROM itens_pedido i JOIN pedidos p ON p.id = i.pedido_id
                                WHERE p.atendimento_id IS NULL AND p.status IN ('concluido', 'cancelado') AND p.criado_em >= ? AND p.criado_em < ?
                                UNION ALL
                                SELECT i.id, i.pedido_id, p.atendimento_id, i.nome, i.quantidade, i.quantidade_cancelada, i.preco_unit_centavos
                                FROM itens_pedido i JOIN pedidos p ON p.id = i.pedido_id JOIN atendimentos a ON a.id = p.atendimento_id
                                WHERE p.status <> 'cancelado' AND a.status = 'encerrado' AND COALESCE(a.total_centavos, 0) > 0 AND a.encerrado_em >= ? AND a.encerrado_em < ?
                                ORDER BY 1");
        $itens->execute([$inicio, $fim, $inicio, $fim]);
        foreach ($itens->fetchAll() as $i) {
            $chave = $i['atendimento_id'] !== null ? 'conta:' . $i['atendimento_id'] : 'pedido:' . $i['pedido_id'];
            if (!isset($vendas[$chave])) {
                continue;
            }
            $vendas[$chave]['itens'][] = [
                'id' => (int) $i['id'], 'nome' => $i['nome'], 'quantidade' => (int) $i['quantidade'],
                'cancelada' => $vendas[$chave]['situacao'] === 'cancelada' ? (int) $i['quantidade'] : (int) $i['quantidade_cancelada'],
                'preco_unit_centavos' => (int) $i['preco_unit_centavos'],
            ];
        }
        $vendas = array_values($vendas);
        usort($vendas, static fn (array $a, array $b): int => strcmp($b['em_utc'], $a['em_utc']));
        return $vendas;
    }

    /** @return array<string,mixed> */
    private function venda(string $chave, string $numero, string $cliente, string $emUtc, string $canal, string $pagamento, int $total, int $cancelado): array
    {
        $local = self::local($emUtc);
        $valor = max(0, $total - $cancelado);
        return [
            'chave' => $chave, 'numero' => $numero, 'cliente' => $cliente,
            'em' => Relogio::iso($emUtc), 'em_utc' => $emUtc, 'data' => $local->format('Y-m-d'),
            'dia_semana' => (int) $local->format('w'), 'hora' => (int) $local->format('G'),
            'canal' => $canal, 'canal_rotulo' => self::CANAIS[$canal] ?? $canal,
            'pagamento' => $pagamento, 'pagamento_rotulo' => self::PAGAMENTOS[$pagamento] ?? $pagamento,
            'total_centavos' => $total, 'cancelado_centavos' => min($total, $cancelado), 'valor_centavos' => $valor,
            'situacao' => $valor === 0 ? 'cancelada' : ($cancelado > 0 ? 'parcial' : 'ativa'),
            'itens' => [],
        ];
    }

    /** @return array<string,int> 'pedido:12' | 'conta:5' => soma cancelada */
    private function canceladoPorVenda(\PDO $pdo): array
    {
        $soma = [];
        foreach ($pdo->query('SELECT pedido_id, atendimento_id, SUM(valor_centavos) AS v FROM cancelamentos GROUP BY pedido_id, atendimento_id') as $l) {
            $soma[$l['atendimento_id'] !== null ? 'conta:' . $l['atendimento_id'] : 'pedido:' . $l['pedido_id']] = (int) $l['v'];
        }
        return $soma;
    }

    // ---------- Tela Vendas ----------

    /**
     * @param array{canal?:string, pagamento?:string, situacao?:string} $filtros  só para os lançamentos; os indicadores são do período todo
     * @return array<string,mixed>
     */
    public function relatorio(string $de, string $ate, array $filtros = [], int $pagina = 1): array
    {
        $vendas = $this->vendas($de, $ate);
        $valendo = array_values(array_filter($vendas, static fn (array $v): bool => $v['valor_centavos'] > 0));
        $faturamento = array_sum(array_column($valendo, 'valor_centavos'));
        $n = count($valendo);

        $produtos = [];
        $qtdItens = 0;
        foreach ($valendo as $v) {
            foreach ($v['itens'] as $i) {
                $q = $i['quantidade'] - $i['cancelada'];
                $qtdItens += $q;
                $produtos[$i['nome']] ??= ['nome' => $i['nome'], 'quantidade' => 0, 'valor_centavos' => 0];
                $produtos[$i['nome']]['quantidade'] += $q;
                $produtos[$i['nome']]['valor_centavos'] += $q * $i['preco_unit_centavos'];
            }
        }
        usort($produtos, static fn (array $a, array $b): int => [$b['quantidade'], $b['valor_centavos']] <=> [$a['quantidade'], $a['valor_centavos']]);
        $canceladas = array_filter($vendas, static fn (array $v): bool => $v['cancelado_centavos'] > 0);

        $lista = array_values(array_filter($vendas, static fn (array $v): bool =>
            (($filtros['canal'] ?? '') === '' || $v['canal'] === $filtros['canal'])
            && (($filtros['pagamento'] ?? '') === '' || $v['pagamento'] === $filtros['pagamento'])
            && match ($filtros['situacao'] ?? '') {
                'ativas' => $v['situacao'] !== 'cancelada',
                'canceladas' => $v['cancelado_centavos'] > 0,
                default => true,
            }));
        $paginas = max(1, (int) ceil(count($lista) / self::POR_PAGINA));
        $pagina = min(max(1, $pagina), $paginas);

        return [
            'periodo' => ['de' => $de, 'ate' => $ate],
            'hoje' => self::hoje(),
            'resumo' => [
                'faturamento_centavos' => $faturamento,
                'vendas' => $n,
                'ticket_centavos' => $n > 0 ? intdiv($faturamento, $n) : null,
                'itens' => $qtdItens,
                'mais_vendido' => $produtos[0] ?? null,
                'canceladas' => count($canceladas),
                'cancelado_centavos' => array_sum(array_column($canceladas, 'cancelado_centavos')),
            ],
            'calor' => $this->calor($valendo),
            'por_canal' => $this->agrupar($valendo, 'canal', 'canal_rotulo'),
            'por_pagamento' => $this->agrupar($valendo, 'pagamento', 'pagamento_rotulo'),
            'lancamentos' => array_map(static function (array $v): array {
                unset($v['em_utc'], $v['dia_semana'], $v['hora']);
                return $v;
            }, array_slice($lista, ($pagina - 1) * self::POR_PAGINA, self::POR_PAGINA)),
            'total_lancamentos' => count($lista),
            'pagina' => $pagina,
            'paginas' => $paginas,
            'canais' => self::CANAIS,
            'pagamentos' => self::PAGAMENTOS,
        ];
    }

    /** Mapa de calor dia da semana × hora: quantidade de vendas e valor; nível 1 a 4 pelo valor. */
    private function calor(array $vendas): array
    {
        if ($vendas === []) {
            return ['horas' => [], 'dias' => [], 'pico' => null, 'melhor_dia' => null];
        }
        $horas = array_column($vendas, 'hora');
        $faixa = range(min($horas), max($horas));
        $celulas = [];
        $porDia = array_fill(0, 7, ['vendas' => 0, 'total' => 0]);
        foreach ($vendas as $v) {
            $c = &$celulas[$v['dia_semana']][$v['hora']];
            $c = ['vendas' => ($c['vendas'] ?? 0) + 1, 'total' => ($c['total'] ?? 0) + $v['valor_centavos']];
            unset($c);
            $porDia[$v['dia_semana']]['vendas']++;
            $porDia[$v['dia_semana']]['total'] += $v['valor_centavos'];
        }
        $maior = max(array_map(static fn (array $dia): int => max(array_column($dia, 'total')), $celulas));
        $pico = null;
        $dias = [];
        foreach ([1, 2, 3, 4, 5, 6, 0] as $d) {   // de segunda a domingo
            $linha = [];
            foreach ($faixa as $h) {
                $c = $celulas[$d][$h] ?? ['vendas' => 0, 'total' => 0];
                $c['nivel'] = $c['vendas'] === 0 ? 0 : max(1, min(4, (int) ceil($c['total'] / max(1, $maior) * 4)));
                if ($c['vendas'] > 0 && ($pico === null || $c['total'] > $pico['total'])) {
                    $pico = ['dia' => self::DIAS[$d], 'hora' => $h, 'total' => $c['total'], 'vendas' => $c['vendas']];
                }
                $linha[] = $c;
            }
            $dias[] = ['nome' => self::DIAS[$d], 'rotulo' => mb_substr(self::DIAS[$d], 0, 3), 'celulas' => $linha] + $porDia[$d];
        }
        $melhor = array_reduce($dias, static fn (?array $m, array $dia): ?array => $dia['total'] > ($m['total'] ?? 0) ? $dia : $m);
        return ['horas' => $faixa, 'dias' => $dias, 'pico' => $pico, 'melhor_dia' => $melhor['nome'] ?? null];
    }

    /** @return list<array{chave:string, rotulo:string, vendas:int, total_centavos:int, ticket_centavos:int}> do maior ticket para o menor */
    private function agrupar(array $vendas, string $campo, string $rotulo): array
    {
        $grupos = [];
        foreach ($vendas as $v) {
            $g = &$grupos[$v[$campo]];
            $g ??= ['chave' => $v[$campo], 'rotulo' => $v[$rotulo], 'vendas' => 0, 'total_centavos' => 0];
            $g['vendas']++;
            $g['total_centavos'] += $v['valor_centavos'];
            unset($g);
        }
        $grupos = array_map(static fn (array $g): array => $g + ['ticket_centavos' => intdiv($g['total_centavos'], $g['vendas'])], array_values($grupos));
        usort($grupos, static fn (array $a, array $b): int => $b['ticket_centavos'] <=> $a['ticket_centavos']);
        return $grupos;
    }

    // ---------- Cancelamento de venda (total ou parcial) ----------

    /**
     * @param list<array{id:int, quantidade:int}> $itens  só no parcial: o item do pedido e quantas unidades cancelar
     * @param array{id:int} $usuario
     */
    public function cancelar(string $chave, string $tipo, array $itens, string $motivo, array $usuario): void
    {
        $motivo = mb_substr(trim($motivo), 0, 200);
        if ($motivo === '') {
            throw ErroHttp::validacao(['motivo' => 'Diga o motivo do cancelamento.']);
        }
        if (!in_array($tipo, ['total', 'parcial'], true)) {
            throw ErroHttp::validacao(['tipo' => 'Escolha cancelamento total ou parcial.']);
        }
        if (preg_match('/^(pedido|conta):(\d+)$/', $chave, $m) !== 1) {
            throw ErroHttp::naoEncontrado('Venda não encontrada.');
        }
        [$deConta, $id] = [$m[1] === 'conta', (int) $m[2]];

        // Tudo numa transação que começa travando a linha da venda: dois cancelamentos ao mesmo tempo (clique duplo, duas pessoas)
        // ficam em fila, e o segundo já confere o que o primeiro cancelou.
        $venda = Banco::transacao(function (\PDO $pdo) use ($deConta, $id, $chave, $tipo, $itens, $motivo, $usuario): array {
            $trava = $pdo->prepare($deConta
                ? "UPDATE atendimentos SET atualizado_em = atualizado_em WHERE id = ? AND status = 'encerrado'"
                : "UPDATE pedidos SET atualizado_em = atualizado_em WHERE id = ? AND atendimento_id IS NULL AND status IN ('concluido', 'cancelado')");
            $trava->execute([$id]);
            if ($trava->rowCount() === 0) {
                throw ErroHttp::naoEncontrado('Venda não encontrada.');
            }
            $sql = $pdo->prepare($deConta
                ? 'SELECT total_centavos AS total, encerrado_em AS em FROM atendimentos WHERE id = ?'
                : 'SELECT total_centavos AS total, criado_em AS em, status FROM pedidos WHERE id = ?');
            $sql->execute([$id]);
            $venda = $sql->fetch();
            if (($venda['status'] ?? '') === 'cancelado') {
                throw new ErroHttp(409, 'ja_cancelada', 'Esta venda já está cancelada.');
            }
            if ($this->fechamentoAtivo(self::local($venda['em'])->format('Y-m-d')) !== null) {
                throw new ErroHttp(409, 'dia_fechado', 'O caixa deste dia já foi fechado. Reabra o dia em Fechamento do dia para cancelar.');
            }
            $soma = $pdo->prepare('SELECT COALESCE(SUM(valor_centavos), 0) FROM cancelamentos WHERE ' . ($deConta ? 'atendimento_id' : 'pedido_id') . ' = ?');
            $soma->execute([$id]);
            $restante = (int) $venda['total'] - (int) $soma->fetchColumn();
            if ($restante <= 0) {
                throw new ErroHttp(409, 'ja_cancelada', 'Esta venda já está cancelada.');
            }

            // Os itens da venda que ainda podem ser cancelados.
            $consulta = $deConta
                ? $pdo->prepare("SELECT i.id, i.nome, i.quantidade, i.quantidade_cancelada, i.preco_unit_centavos FROM itens_pedido i JOIN pedidos p ON p.id = i.pedido_id WHERE p.atendimento_id = ? AND p.status <> 'cancelado'")
                : $pdo->prepare('SELECT id, nome, quantidade, quantidade_cancelada, preco_unit_centavos FROM itens_pedido WHERE pedido_id = ?');
            $consulta->execute([$id]);
            $daVenda = array_column($consulta->fetchAll(), null, 'id');

            $escolhidos = [];
            if ($tipo === 'total') {
                foreach ($daVenda as $i) {
                    if ((int) $i['quantidade'] > (int) $i['quantidade_cancelada']) {
                        $escolhidos[] = ['id' => (int) $i['id'], 'nome' => $i['nome'], 'quantidade' => (int) $i['quantidade'] - (int) $i['quantidade_cancelada']];
                    }
                }
                $valor = $restante;   // o total inteiro que falta, com a taxa de entrega
            } else {
                $valor = 0;
                foreach ($itens as $pedido) {
                    $item = $daVenda[(int) ($pedido['id'] ?? 0)] ?? null;
                    $q = (int) ($pedido['quantidade'] ?? 0);
                    if ($q <= 0) {
                        continue;
                    }
                    if ($item === null || $q > (int) $item['quantidade'] - (int) $item['quantidade_cancelada']) {
                        throw ErroHttp::validacao(['itens' => 'Um item escolhido não pode ser cancelado nessa quantidade.']);
                    }
                    $escolhidos[] = ['id' => (int) $item['id'], 'nome' => $item['nome'], 'quantidade' => $q];
                    $valor += $q * (int) $item['preco_unit_centavos'];
                }
                if ($escolhidos === []) {
                    throw ErroHttp::validacao(['itens' => 'Escolha pelo menos um item para cancelar.']);
                }
                $valor = min($valor, $restante);
            }

            $marca = $pdo->prepare('UPDATE itens_pedido SET quantidade_cancelada = quantidade_cancelada + ? WHERE id = ?');
            foreach ($escolhidos as $e) {
                $marca->execute([$e['quantidade'], $e['id']]);
            }
            Banco::inserir($pdo, 'INSERT INTO cancelamentos (pedido_id, atendimento_id, tipo, valor_centavos, itens, motivo, usuario_id, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
                $deConta ? null : $id, $deConta ? $id : null, $tipo, $valor,
                json_encode($escolhidos, JSON_UNESCAPED_UNICODE), $motivo, $usuario['id'], Relogio::agora(),
            ]);
            return $venda;
        });
        if (!$deConta && $tipo === 'total') {
            // O pedido também fica Cancelado (histórico com o motivo); sem aviso ao cliente: a venda já tinha sido entregue.
            (new PedidoRepository())->mudarStatus($id, (string) $venda['status'], 'cancelado', $motivo, $usuario['id']);
        }
    }

    // ---------- Fechamento do dia ----------

    /** @return array<string,mixed>|null */
    /** Tabelas que o "Zerar vendas" esvazia (D116), filhas antes das mães. Cardápio, clientes, configurações e usuários ficam. */
    private const TABELAS_DE_VENDA = ['itens_pedido', 'pedido_status_historico', 'ifood_acoes', 'ifood_pedidos', 'impressao_trabalhos', 'cancelamentos', 'pedidos', 'atendimentos', 'fechamentos_caixa'];

    /**
     * Zera as vendas (D116): apaga todos os pedidos, contas do Salão, cancelamentos, fechamentos de caixa e a fila de impressão,
     * e volta a numeração para #1. Para começar a vender de verdade depois dos testes. Só o proprietário, digitando "ZERAR".
     * @return array{pedidos:int, contas:int, fechamentos:int}
     */
    public function zerar(string $confirmacao, array $usuario): array
    {
        if (trim($confirmacao) !== 'ZERAR') {
            throw ErroHttp::validacao(['confirmacao' => 'Digite ZERAR, em maiúsculas, para confirmar.']);
        }
        return Banco::transacao(function (\PDO $pdo): array {
            $conta = static fn (string $tabela): int => (int) $pdo->query("SELECT COUNT(*) FROM {$tabela}")->fetchColumn();
            $antes = ['pedidos' => $conta('pedidos'), 'contas' => $conta('atendimentos'), 'fechamentos' => $conta('fechamentos_caixa')];
            $pdo->exec('UPDATE whatsapp_conversas SET ultimo_pedido_id = NULL');
            $pdo->exec('DELETE FROM whatsapp_envios_pendentes WHERE pedido_id IS NOT NULL');
            foreach (self::TABELAS_DE_VENDA as $tabela) {
                $pdo->exec("DELETE FROM {$tabela}");
            }
            // Numeração de volta para #1.
            if (Banco::ehPostgres($pdo)) {
                foreach (self::TABELAS_DE_VENDA as $tabela) {
                    if ($tabela !== 'ifood_pedidos') {   // a chave dela é o pedido_id, sem numeração própria
                        $pdo->exec("ALTER TABLE {$tabela} ALTER COLUMN id RESTART WITH 1");
                    }
                }
            } else {
                $pdo->exec("DELETE FROM sqlite_sequence WHERE name IN ('" . implode("', '", self::TABELAS_DE_VENDA) . "')");
            }
            return $antes;
        });
    }

    public function fechamentoAtivo(string $data): ?array
    {
        $sql = Banco::conexao()->prepare('SELECT f.*, u.nome AS fechado_por_nome FROM fechamentos_caixa f LEFT JOIN usuarios u ON u.id = f.fechado_por
                                          WHERE f.data = ? AND f.cancelado_em IS NULL ORDER BY f.id DESC LIMIT 1');
        $sql->execute([$data]);
        $linha = $sql->fetch();
        return $linha ? self::tiparFechamento($linha) : null;
    }

    private static function tiparFechamento(array $f): array
    {
        foreach (['id', 'caixa_inicial_centavos', 'vendas_dinheiro_centavos', 'retiradas_centavos', 'esperado_centavos', 'contado_centavos', 'diferenca_centavos', 'faturamento_centavos'] as $c) {
            $f[$c] = (int) $f[$c];
        }
        $f['fechado_em'] = Relogio::iso($f['fechado_em']);
        $f['cancelado_em'] = Relogio::iso($f['cancelado_em'] ?? null);
        return $f;
    }

    /** O que o dia vendeu, por forma de pagamento e por canal. */
    public function resumoDoDia(string $data): array
    {
        $valendo = array_values(array_filter($this->vendas($data, $data), static fn (array $v): bool => $v['valor_centavos'] > 0));
        $porPagamento = [];
        foreach (self::PAGAMENTOS as $chave => $rotulo) {
            $deste = array_filter($valendo, static fn (array $v): bool => $v['pagamento'] === $chave);
            $porPagamento[] = ['chave' => $chave, 'rotulo' => $rotulo, 'vendas' => count($deste), 'total_centavos' => array_sum(array_column($deste, 'valor_centavos'))];
        }
        $dinheiro = array_values(array_filter($porPagamento, static fn (array $p): bool => $p['chave'] === 'dinheiro'))[0]['total_centavos'];
        return [
            'faturamento_centavos' => array_sum(array_column($valendo, 'valor_centavos')),
            'vendas' => count($valendo),
            'por_pagamento' => $porPagamento,
            'por_canal' => $this->agrupar($valendo, 'canal', 'canal_rotulo'),
            'dinheiro_centavos' => $dinheiro,
        ];
    }

    public function dia(string $data): array
    {
        $hoje = self::hoje();
        $fechamento = $this->fechamentoAtivo($data);
        $anterior = Banco::conexao()->prepare('SELECT data, caixa_inicial_centavos, contado_centavos FROM fechamentos_caixa WHERE data < ? AND cancelado_em IS NULL ORDER BY data DESC, id DESC LIMIT 1');
        $anterior->execute([$data]);
        $historico = Banco::conexao()->prepare('SELECT f.*, u.nome AS fechado_por_nome FROM fechamentos_caixa f LEFT JOIN usuarios u ON u.id = f.fechado_por WHERE f.data = ? AND f.cancelado_em IS NOT NULL ORDER BY f.id DESC');
        $historico->execute([$data]);
        return [
            'data' => $data,
            'hoje' => $hoje,
            'estado' => $fechamento !== null ? 'fechado' : ($data > $hoje ? 'futuro' : ($data === $hoje ? 'hoje' : 'pendente')),
            'resumo' => $data > $hoje ? null : $this->resumoDoDia($data),
            'fechamento' => $fechamento,
            'anterior' => ($a = $anterior->fetch()) ? ['data' => $a['data'], 'caixa_inicial_centavos' => (int) $a['caixa_inicial_centavos'], 'contado_centavos' => (int) $a['contado_centavos']] : null,
            'historico' => array_map([self::class, 'tiparFechamento'], $historico->fetchAll()),
        ];
    }

    /**
     * @param array{caixa_inicial_centavos:mixed, retiradas_centavos:mixed, contado_centavos:mixed, observacao?:mixed} $d
     * @param array{id:int} $usuario
     */
    public function fechar(string $data, array $d, array $usuario): array
    {
        if (!self::dataValida($data) || $data > self::hoje()) {
            throw ErroHttp::validacao(['data' => 'Só dá para fechar o caixa de hoje ou de um dia que já passou.']);
        }
        $erros = [];
        $valor = static function (string $campo, string $mensagem) use ($d, &$erros): int {
            $v = $d[$campo] ?? null;
            if (!is_numeric($v) || (int) $v < 0 || (int) $v > 100_000_000) {
                $erros[$campo] = $mensagem;
                return 0;
            }
            return (int) $v;
        };
        // D104: a tela pede só o caixa inicial. Retiradas e contado continuam aceitos (API e fechamentos antigos); sem eles,
        // retiradas = 0 e contado = esperado (sem sobra nem falta).
        $opcional = static fn (string $campo): bool => !isset($d[$campo]) || $d[$campo] === '';
        $inicial = $valor('caixa_inicial_centavos', 'Informe quanto havia na gaveta ao abrir (0 se nada).');
        $retiradas = $opcional('retiradas_centavos') ? 0 : $valor('retiradas_centavos', 'Informe as retiradas (0 se nenhuma).');
        $contado = $opcional('contado_centavos') ? null : $valor('contado_centavos', 'Conte o dinheiro da gaveta e informe o total.');
        if ($erros !== []) {
            throw ErroHttp::validacao($erros);
        }
        $resumo = $this->resumoDoDia($data);
        $esperado = $inicial + $resumo['dinheiro_centavos'] - $retiradas;
        $contado ??= $esperado;
        try {
            $this->gravarFechamento($data, $inicial, $retiradas, $contado, $esperado, $resumo, $d, $usuario);
        } catch (\PDOException $e) {
            if (in_array($e->getCode(), ['23505', '23000'], true)) {   // idx_fechamentos_um_por_dia: outro fechamento chegou antes
                throw new ErroHttp(409, 'ja_fechado', 'O caixa deste dia já foi fechado.');
            }
            throw $e;
        }
        return $this->dia($data);
    }

    private function gravarFechamento(string $data, int $inicial, int $retiradas, int $contado, int $esperado, array $resumo, array $d, array $usuario): void
    {
        Banco::transacao(function (\PDO $pdo) use ($data, $inicial, $retiradas, $contado, $esperado, $resumo, $d, $usuario): void {
            $existe = $pdo->prepare('SELECT 1 FROM fechamentos_caixa WHERE data = ? AND cancelado_em IS NULL');
            $existe->execute([$data]);
            if ($existe->fetchColumn()) {
                throw new ErroHttp(409, 'ja_fechado', 'O caixa deste dia já foi fechado.');
            }
            Banco::inserir($pdo, 'INSERT INTO fechamentos_caixa (data, caixa_inicial_centavos, vendas_dinheiro_centavos, retiradas_centavos, esperado_centavos,
                contado_centavos, diferenca_centavos, faturamento_centavos, observacao, fechado_por, fechado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $data, $inicial, $resumo['dinheiro_centavos'], $retiradas, $esperado, $contado, $contado - $esperado, $resumo['faturamento_centavos'],
                mb_substr(trim((string) ($d['observacao'] ?? '')), 0, 500), $usuario['id'], Relogio::agora(),
            ]);
        });
    }

    public function reabrir(int $id, string $motivo, array $usuario): array
    {
        $sql = Banco::conexao()->prepare('UPDATE fechamentos_caixa SET cancelado_em = ?, cancelado_por = ?, motivo_cancelamento = ? WHERE id = ? AND cancelado_em IS NULL');
        $sql->execute([Relogio::agora(), $usuario['id'], mb_substr(trim($motivo), 0, 200), $id]);
        if ($sql->rowCount() === 0) {
            throw ErroHttp::naoEncontrado('Este fechamento não existe ou já foi reaberto.');
        }
        $data = Banco::conexao()->prepare('SELECT data FROM fechamentos_caixa WHERE id = ?');
        $data->execute([$id]);
        return $this->dia((string) $data->fetchColumn());
    }

    /** Calendário do mês: estado e valor de cada dia, mais os dias pendentes (desde a primeira venda). */
    public function calendario(string $mes): array
    {
        $fuso = Relogio::fusoDaLoja();
        $hoje = self::hoje();
        $primeiro = \DateTimeImmutable::createFromFormat('!Y-m-d', $mes . '-01', $fuso);
        if ($primeiro === false || $primeiro->format('Y-m') !== $mes) {
            $primeiro = new \DateTimeImmutable('first day of this month 00:00', $fuso);
        }
        $ultimo = $primeiro->modify('last day of this month');
        $doMes = $this->vendas($primeiro->format('Y-m-d'), $ultimo->format('Y-m-d'));
        $vendido = [];
        foreach ($doMes as $v) {
            $vendido[$v['data']] = ($vendido[$v['data']] ?? 0) + $v['valor_centavos'];
        }
        $pdo = Banco::conexao();
        $fechados = [];
        foreach ($pdo->query('SELECT data, faturamento_centavos FROM fechamentos_caixa WHERE cancelado_em IS NULL') as $l) {
            $fechados[$l['data']] = (int) $l['faturamento_centavos'];
        }
        $inicioVendas = $this->primeiraVenda($pdo);

        $dias = [];
        for ($d = $primeiro; $d <= $ultimo; $d = $d->modify('+1 day')) {
            $data = $d->format('Y-m-d');
            $estado = match (true) {
                isset($fechados[$data]) => 'fechado',
                $data > $hoje => 'futuro',
                $data === $hoje => 'hoje',
                $inicioVendas !== null && $data >= $inicioVendas => 'pendente',
                default => 'neutro',
            };
            $dias[] = ['data' => $data, 'dia' => (int) $d->format('j'), 'semana' => (int) $d->format('w'), 'estado' => $estado,
                       'valor_centavos' => $fechados[$data] ?? ($vendido[$data] ?? null)];
        }
        $pendentes = [];
        if ($inicioVendas !== null) {
            for ($d = new \DateTimeImmutable($inicioVendas, $fuso); $d->format('Y-m-d') < $hoje; $d = $d->modify('+1 day')) {
                if (!isset($fechados[$d->format('Y-m-d')])) {
                    $pendentes[] = $d->format('Y-m-d');
                }
            }
        }
        return [
            'mes' => $primeiro->format('Y-m'),
            'anterior' => $primeiro->modify('-1 month')->format('Y-m'),
            'proximo' => $primeiro->format('Y-m') < substr($hoje, 0, 7) ? $primeiro->modify('+1 month')->format('Y-m') : null,
            'hoje' => $hoje,
            'dias' => $dias,
            'pendentes' => $pendentes,
        ];
    }

    /** Data local da primeira venda do sistema (antes dela não havia caixa para fechar). */
    private function primeiraVenda(\PDO $pdo): ?string
    {
        $p = $pdo->query("SELECT MIN(criado_em) FROM pedidos WHERE atendimento_id IS NULL AND status = 'concluido'")->fetchColumn();
        $a = $pdo->query("SELECT MIN(encerrado_em) FROM atendimentos WHERE status = 'encerrado' AND COALESCE(total_centavos, 0) > 0")->fetchColumn();
        $datas = array_filter([$p ?: null, $a ?: null]);
        return $datas === [] ? null : self::local(min($datas))->format('Y-m-d');
    }
}
