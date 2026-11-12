<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Banco;
use App\Core\Relogio;

// Tudo que lê ou grava pedidos. Um pedido é um registro só; Kanban, impressão e avisos partem dele.
final class PedidoRepository
{
    public const ENCERRADOS = ['concluido', 'cancelado'];

    /**
     * @param array<string,mixed> $pedido colunas da tabela pedidos (sem datas e sem código)
     * @param list<array<string,mixed>> $itens
     * @return array{id:int, codigo:string}
     */
    public function criar(array $pedido, array $itens): array
    {
        return Banco::transacao(function (\PDO $pdo) use ($pedido, $itens): array {
            $agora = Relogio::agora();

            // Cliente: um registro por telefone (base do futuro CRM).
            $sql = $pdo->prepare('SELECT id FROM clientes WHERE telefone = ?');
            $sql->execute([$pedido['cliente_telefone']]);
            $clienteId = $sql->fetchColumn();
            if ($clienteId) {
                $pdo->prepare('UPDATE clientes SET nome = ?, atualizado_em = ? WHERE id = ?')->execute([$pedido['cliente_nome'], $agora, $clienteId]);
            } else {
                $pdo->prepare('INSERT INTO clientes (nome, telefone, criado_em, atualizado_em) VALUES (?, ?, ?, ?)')
                    ->execute([$pedido['cliente_nome'], $pedido['cliente_telefone'], $agora, $agora]);
                $clienteId = $pdo->lastInsertId();
            }

            $pedido += [
                'codigo' => bin2hex(random_bytes(8)),
                'cliente_id' => (int) $clienteId,
                'status' => 'novo',
                'status_atualizado_em' => $agora,
                'atualizado_em' => $agora,
                'criado_em' => $agora,
            ];
            $colunas = array_keys($pedido);
            $pdo->prepare(sprintf('INSERT INTO pedidos (%s) VALUES (%s)', implode(', ', $colunas), implode(', ', array_fill(0, count($colunas), '?'))))
                ->execute(array_values($pedido));
            $id = (int) $pdo->lastInsertId();

            $insere = $pdo->prepare('INSERT INTO itens_pedido (pedido_id, item_id, tipo, nome, quantidade, preco_unit_centavos, montagem, observacoes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($itens as $item) {
                $insere->execute([
                    $id, $item['item_id'], $item['tipo'], $item['nome'], $item['quantidade'], $item['preco_unit_centavos'],
                    $item['montagem'] === null ? null : json_encode($item['montagem'], JSON_UNESCAPED_UNICODE), $item['observacoes'],
                ]);
            }
            $pdo->prepare('INSERT INTO pedido_status_historico (pedido_id, status, motivo, usuario_id, criado_em) VALUES (?, ?, NULL, NULL, ?)')
                ->execute([$id, 'novo', $agora]);

            return ['id' => $id, 'codigo' => $pedido['codigo']];
        });
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

    /**
     * Pedidos para o painel.
     * Sem $desde: todos os abertos + os encerrados de hoje. Com $desde: só o que mudou desde então.
     */
    public function paraPainel(?string $desde): array
    {
        $pdo = Banco::conexao();
        if ($desde === null) {
            $sql = $pdo->prepare("SELECT * FROM pedidos WHERE status NOT IN ('concluido', 'cancelado') OR status_atualizado_em >= ? ORDER BY id");
            $sql->execute([Relogio::inicioDoDiaDaLoja()]);
        } else {
            $sql = $pdo->prepare('SELECT * FROM pedidos WHERE atualizado_em >= ? ORDER BY id');
            $sql->execute([$desde]);
        }
        return $this->completar($sql->fetchAll(), false);
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

        $historico = [];
        if ($comHistorico) {
            $sql = $pdo->prepare("SELECT h.pedido_id, h.status, h.motivo, h.criado_em, u.nome AS usuario
                                  FROM pedido_status_historico h LEFT JOIN usuarios u ON u.id = h.usuario_id
                                  WHERE h.pedido_id IN ({$marcas}) ORDER BY h.id");
            $sql->execute($ids);
            foreach ($sql as $h) {
                $historico[(int) $h['pedido_id']][] = [
                    'status' => $h['status'], 'motivo' => $h['motivo'], 'usuario' => $h['usuario'], 'em' => Relogio::iso($h['criado_em']),
                ];
            }
        }

        return array_map(static function (array $p) use ($itens, $historico, $comHistorico): array {
            $id = (int) $p['id'];
            foreach (['id', 'cliente_id', 'troco_para_centavos', 'subtotal_centavos', 'taxa_entrega_centavos', 'total_centavos'] as $coluna) {
                $p[$coluna] = $p[$coluna] === null ? null : (int) $p[$coluna];
            }
            foreach (['impresso_em', 'status_atualizado_em', 'atualizado_em', 'criado_em'] as $coluna) {
                $p[$coluna] = Relogio::iso($p[$coluna]);
            }
            $p['itens'] = $itens[$id] ?? [];
            if ($comHistorico) {
                $p['historico'] = $historico[$id] ?? [];
            }
            return $p;
        }, $pedidos);
    }
}
