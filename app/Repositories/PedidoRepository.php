<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Banco;
use App\Core\Relogio;
use App\Services\TempoDePreparo;

// Tudo que lê ou grava pedidos. Um pedido é um registro só; Kanban, impressão e avisos partem dele.
final class PedidoRepository
{
    public const ENCERRADOS = ['concluido', 'cancelado'];

    /**
     * @param array<string,mixed> $pedido colunas da tabela pedidos (sem datas e sem código)
     * @param list<array<string,mixed>> $itens
     * @param int|null $usuarioId quem registrou o pedido (só o salão; o cliente do cardápio não tem usuário)
     * @return array{id:int, codigo:string}
     */
    public function criar(array $pedido, array $itens, ?int $usuarioId = null): array
    {
        return Banco::transacao(fn (\PDO $pdo): array => $this->inserir($pdo, $pedido, $itens, $usuarioId));
    }

    /**
     * Grava o pedido (cliente, itens e primeira linha do histórico) dentro de uma transação que JÁ está aberta.
     * É por aqui que o atendimento do salão cria pedidos: a transação é dele, para a checagem "conta ainda aberta"
     * e a gravação do pedido valerem juntas.
     * @return array{id:int, codigo:string}
     */
    public function inserir(\PDO $pdo, array $pedido, array $itens, ?int $usuarioId = null): array
    {
        $agora = Relogio::agora();

        // Cliente: um registro por telefone (base do futuro CRM). Pedido do salão não tem telefone: sem cadastro.
        $clienteId = null;
        if ($pedido['cliente_telefone'] !== '') {
            $sql = $pdo->prepare('SELECT id FROM clientes WHERE telefone = ?');
            $sql->execute([$pedido['cliente_telefone']]);
            $clienteId = $sql->fetchColumn();
            if ($clienteId) {
                $pdo->prepare('UPDATE clientes SET nome = ?, atualizado_em = ? WHERE id = ?')->execute([$pedido['cliente_nome'], $agora, $clienteId]);
            } else {
                $clienteId = Banco::inserir($pdo, 'INSERT INTO clientes (nome, telefone, criado_em, atualizado_em) VALUES (?, ?, ?, ?)',
                    [$pedido['cliente_nome'], $pedido['cliente_telefone'], $agora, $agora]);
            }
        }

        $pedido += [
            'codigo' => bin2hex(random_bytes(8)),
            'cliente_id' => $clienteId ? (int) $clienteId : null,
            'criado_por' => $usuarioId,
            'status' => 'novo',
            'status_atualizado_em' => $agora,
            'atualizado_em' => $agora,
            'criado_em' => $agora,
        ];
        $colunas = array_keys($pedido);
        $id = Banco::inserir($pdo, sprintf('INSERT INTO pedidos (%s) VALUES (%s)', implode(', ', $colunas), implode(', ', array_fill(0, count($colunas), '?'))), array_values($pedido));

        $insere = $pdo->prepare('INSERT INTO itens_pedido (pedido_id, item_id, tipo, nome, quantidade, preco_unit_centavos, montagem, observacoes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($itens as $item) {
            $insere->execute([
                $id, $item['item_id'], $item['tipo'], $item['nome'], $item['quantidade'], $item['preco_unit_centavos'],
                $item['montagem'] === null ? null : json_encode($item['montagem'], JSON_UNESCAPED_UNICODE), $item['observacoes'],
            ]);
        }
        $pdo->prepare('INSERT INTO pedido_status_historico (pedido_id, status, motivo, usuario_id, criado_em) VALUES (?, ?, NULL, ?, ?)')
            ->execute([$id, 'novo', $usuarioId, $agora]);

        return ['id' => $id, 'codigo' => $pedido['codigo']];
    }

    public function porCodigo(string $codigo): ?array
    {
        $sql = Banco::conexao()->prepare('SELECT * FROM pedidos WHERE codigo = ?');
        $sql->execute([$codigo]);
        $pedido = $sql->fetch();
        return $pedido ? $this->completar([$pedido], true)[0] : null;
    }

    public function porId(int $id): ?array
    {
        $sql = Banco::conexao()->prepare('SELECT * FROM pedidos WHERE id = ?');
        $sql->execute([$id]);
        $pedido = $sql->fetch();
        return $pedido ? $this->completar([$pedido], true)[0] : null;
    }

    /** O pedido mais recente ainda em andamento deste telefone (o "Acompanhar pedido" do bot do WhatsApp). */
    public function ultimoAbertoDoTelefone(string $telefone): ?array
    {
        $sql = Banco::conexao()->prepare("SELECT * FROM pedidos WHERE cliente_telefone = ? AND status NOT IN ('concluido', 'cancelado') ORDER BY id DESC LIMIT 1");
        $sql->execute([$telefone]);
        $pedido = $sql->fetch();
        return $pedido ? $this->completar([$pedido], true)[0] : null;
    }

    /**
     * Pedidos para o painel.
     * Sem $desde: só os que estão abertos. Com $desde: o que mudou desde então, inclusive o que acabou de encerrar
     * (é assim que o cartão sai do quadro). Os encerrados do dia ficam resumidos em fechamentoDoDia().
     */
    public function paraPainel(?string $desde): array
    {
        $pdo = Banco::conexao();
        if ($desde === null) {
            // Abertos + os do salão já servidos cuja conta ainda não foi fechada (ficam na coluna "Salão" até o pagamento).
            $sql = $pdo->prepare("SELECT * FROM pedidos WHERE status NOT IN ('concluido', 'cancelado')
                                     OR (status = 'concluido' AND atendimento_id IN (SELECT id FROM atendimentos WHERE status = 'aberto'))
                                  ORDER BY id");
            $sql->execute();
        } else {
            $sql = $pdo->prepare('SELECT * FROM pedidos WHERE atualizado_em >= ? ORDER BY id');
            $sql->execute([$desde]);
        }
        return $this->completar($sql->fetchAll(), false);
    }

    /**
     * Pedidos de cada atendimento do salão, completos (itens e montagem), agrupados pelo número do atendimento.
     * @param list<int> $atendimentoIds
     * @return array<int, list<array<string,mixed>>>
     */
    public function dosAtendimentos(array $atendimentoIds): array
    {
        if ($atendimentoIds === []) {
            return [];
        }
        $marcas = implode(', ', array_fill(0, count($atendimentoIds), '?'));
        $sql = Banco::conexao()->prepare("SELECT * FROM pedidos WHERE atendimento_id IN ({$marcas}) ORDER BY id");
        $sql->execute(array_values($atendimentoIds));
        $agrupados = [];
        foreach ($this->completar($sql->fetchAll(), false) as $pedido) {
            $agrupados[$pedido['atendimento_id']][] = $pedido;
        }
        return $agrupados;
    }

    /**
     * Números do dia da loja para o indicador de fechamento (pedidos criados hoje).
     * O "vendido" soma o que foi de fato cobrado: pedidos de entrega e retirada concluídos + contas do salão fechadas hoje.
     * (Um pedido de salão servido ainda não é dinheiro recebido: ele só entra quando a conta é fechada.)
     * @return array{pedidos:int, concluidos:int, cancelados:int, em_andamento:int, faturamento_centavos:int}
     */
    public function fechamentoDoDia(): array
    {
        $inicio = Relogio::inicioDoDiaDaLoja();
        $pdo = Banco::conexao();
        $sql = $pdo->prepare('SELECT status, COUNT(*) AS n, COALESCE(SUM(CASE WHEN atendimento_id IS NULL THEN total_centavos ELSE 0 END), 0) AS soma
                              FROM pedidos WHERE criado_em >= ? GROUP BY status');
        $sql->execute([$inicio]);
        $fechamento = ['pedidos' => 0, 'concluidos' => 0, 'cancelados' => 0, 'em_andamento' => 0, 'faturamento_centavos' => 0];
        foreach ($sql as $linha) {
            $n = (int) $linha['n'];
            $fechamento['pedidos'] += $n;
            if ($linha['status'] === 'concluido') {
                $fechamento['concluidos'] = $n;
                $fechamento['faturamento_centavos'] = (int) $linha['soma'];
            } elseif ($linha['status'] === 'cancelado') {
                $fechamento['cancelados'] = $n;
            } else {
                $fechamento['em_andamento'] += $n;
            }
        }
        $contas = $pdo->prepare("SELECT COALESCE(SUM(total_centavos), 0) FROM atendimentos WHERE status = 'encerrado' AND encerrado_em >= ?");
        $contas->execute([$inicio]);
        $fechamento['faturamento_centavos'] += (int) $contas->fetchColumn();
        return $fechamento;
    }

    /** Muda o status e registra no histórico. Devolve false se o pedido já não estava no status esperado. */
    public function mudarStatus(int $id, string $de, string $para, ?string $motivo, ?int $usuarioId): bool
    {
        return Banco::transacao(static function (\PDO $pdo) use ($id, $de, $para, $motivo, $usuarioId): bool {
            $agora = Relogio::agora();
            $sql = $pdo->prepare('UPDATE pedidos SET status = ?, status_atualizado_em = ?, atualizado_em = ? WHERE id = ? AND status = ?');
            $sql->execute([$para, $agora, $agora, $id, $de]);
            if ($sql->rowCount() === 0) {
                return false;
            }
            $pdo->prepare('INSERT INTO pedido_status_historico (pedido_id, status, motivo, usuario_id, criado_em) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id, $para, $motivo, $usuarioId, $agora]);
            return true;
        });
    }

    /**
     * "Claim" atômico da impressão automática: só a primeira estação que chegar marca o pedido.
     * Devolve true se ESTA chamada ficou com a impressão.
     */
    public function reivindicarImpressao(int $id, string $estacao): bool
    {
        $agora = Relogio::agora();
        $sql = Banco::conexao()->prepare('UPDATE pedidos SET impresso_em = ?, impresso_por = ?, atualizado_em = ? WHERE id = ? AND impresso_em IS NULL');
        $sql->execute([$agora, $estacao, $agora, $id]);
        return $sql->rowCount() === 1;
    }

    public function registrarEstacao(string $id, string $nome): void
    {
        $pdo = Banco::conexao();
        $agora = Relogio::agora();
        $sql = $pdo->prepare('UPDATE estacoes_impressao SET nome = ?, ativa = 1, ultimo_contato_em = ? WHERE id = ?');
        $sql->execute([$nome, $agora, $id]);
        if ($sql->rowCount() === 0) {
            $pdo->prepare('INSERT INTO estacoes_impressao (id, nome, ativa, ultimo_contato_em) VALUES (?, ?, 1, ?)')->execute([$id, $nome, $agora]);
        }
    }

    /** Junta itens (e histórico, se pedido) em cada pedido e converte os tipos. */
    private function completar(array $pedidos, bool $comHistorico): array
    {
        if ($pedidos === []) {
            return [];
        }
        $pdo = Banco::conexao();
        $ids = array_map(static fn (array $p): int => (int) $p['id'], $pedidos);
        $marcas = implode(', ', array_fill(0, count($ids), '?'));

        $itens = [];
        $sql = $pdo->prepare("SELECT * FROM itens_pedido WHERE pedido_id IN ({$marcas}) ORDER BY id");
        $sql->execute($ids);
        foreach ($sql as $item) {
            $itens[(int) $item['pedido_id']][] = [
                'id' => (int) $item['id'],
                'tipo' => $item['tipo'],
                'nome' => $item['nome'],
                'quantidade' => (int) $item['quantidade'],
                'preco_unit_centavos' => (int) $item['preco_unit_centavos'],
                'montagem' => $item['montagem'] === null ? null : json_decode($item['montagem'], true),
                'observacoes' => $item['observacoes'],
            ];
        }

        // O histórico serve para o tempo de preparo (todo pedido) e para a lista da linha do tempo (só o detalhe).
        $historico = [];
        $linhasDeTempo = [];
        $sql = $pdo->prepare("SELECT h.pedido_id, h.status, h.motivo, h.criado_em, u.nome AS usuario
                              FROM pedido_status_historico h LEFT JOIN usuarios u ON u.id = h.usuario_id
                              WHERE h.pedido_id IN ({$marcas}) ORDER BY h.id");
        $sql->execute($ids);
        foreach ($sql as $h) {
            $pedidoId = (int) $h['pedido_id'];
            $linhasDeTempo[$pedidoId][] = ['status' => $h['status'], 'criado_em' => $h['criado_em']];
            $historico[$pedidoId][] = [
                'status' => $h['status'], 'motivo' => $h['motivo'], 'usuario' => $h['usuario'], 'em' => Relogio::iso($h['criado_em']),
            ];
        }

        // Pedido do salão: a conta (atendimento) dele ainda está aberta?
        $contas = [];
        $atendimentoIds = array_values(array_unique(array_filter(array_map(static fn (array $p): ?int => $p['atendimento_id'] === null ? null : (int) $p['atendimento_id'], $pedidos))));
        if ($atendimentoIds !== []) {
            $sql = $pdo->prepare('SELECT id, status FROM atendimentos WHERE id IN (' . implode(', ', array_fill(0, count($atendimentoIds), '?')) . ')');
            $sql->execute($atendimentoIds);
            foreach ($sql as $a) {
                $contas[(int) $a['id']] = $a['status'] === 'aberto';
            }
        }

        return array_map(static function (array $p) use ($itens, $historico, $linhasDeTempo, $comHistorico, $contas): array {
            $id = (int) $p['id'];
            foreach (['id', 'cliente_id', 'atendimento_id', 'criado_por', 'troco_para_centavos', 'subtotal_centavos', 'taxa_entrega_centavos', 'total_centavos'] as $coluna) {
                $p[$coluna] = $p[$coluna] === null ? null : (int) $p[$coluna];
            }
            foreach (['entrega_lat', 'entrega_lng'] as $coluna) {
                $p[$coluna] = $p[$coluna] === null ? null : (float) $p[$coluna];
            }
            foreach (['impresso_em', 'status_atualizado_em', 'atualizado_em', 'criado_em'] as $coluna) {
                $p[$coluna] = Relogio::iso($p[$coluna]);
            }
            $p['itens'] = $itens[$id] ?? [];
            $p['conta_aberta'] = $p['atendimento_id'] === null ? null : ($contas[$p['atendimento_id']] ?? false);
            // Tempo em "Novo" + "Em preparo" (regra das cores do cartão): o navegador soma o período que ainda está aberto.
            $tempo = TempoDePreparo::calcular($linhasDeTempo[$id] ?? []);
            $p['producao_acumulado_s'] = $tempo['acumulado_s'];
            $p['producao_desde'] = $tempo['desde'];
            if ($comHistorico) {
                $p['historico'] = $historico[$id] ?? [];
            }
            return $p;
        }, $pedidos);
    }
}
