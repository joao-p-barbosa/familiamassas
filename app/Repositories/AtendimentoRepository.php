<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Banco;
use App\Core\Relogio;

// Tudo que lê ou grava atendimentos do salão (a conta de um cliente). Os pedidos de cada atendimento ficam em PedidoRepository.
// As três escritas que importam (abrir, adicionar pedido e fechar a conta) são transações em que a checagem
// "a conta ainda está aberta" e a gravação acontecem juntas: não dá para somar um pedido a uma conta que está
// sendo fechada, nem fechar a mesma conta duas vezes.
final class AtendimentoRepository
{
    public function __construct(private readonly PedidoRepository $pedidos = new PedidoRepository()) {}

    /**
     * Abre o atendimento e já grava o primeiro pedido: ou valem os dois, ou nenhum.
     * @param array{cliente_nome:string, modalidade:string, mesa:?string} $atendimento
     * @param array<string,mixed> $pedido colunas do pedido (sem o atendimento_id)
     * @param list<array<string,mixed>> $itens
     * @return array{atendimento_id:int, id:int, codigo:string}
     */
    public function abrir(array $atendimento, array $pedido, array $itens, int $usuarioId): array
    {
        return Banco::transacao(function (\PDO $pdo) use ($atendimento, $pedido, $itens, $usuarioId): array {
            $agora = Relogio::agora();
            $pdo->prepare("INSERT INTO atendimentos (cliente_nome, modalidade, mesa, status, aberto_por, aberto_em, atualizado_em) VALUES (?, ?, ?, 'aberto', ?, ?, ?)")
                ->execute([$atendimento['cliente_nome'], $atendimento['modalidade'], $atendimento['mesa'], $usuarioId, $agora, $agora]);
            $id = (int) $pdo->lastInsertId();
            return ['atendimento_id' => $id] + $this->pedidos->inserir($pdo, $pedido + ['atendimento_id' => $id], $itens, $usuarioId);
        });
    }

    /**
     * Soma um pedido a um atendimento que ainda está aberto. Devolve null se a conta já foi encerrada (ou não existe).
     * @return array{id:int, codigo:string}|null
     */
    public function adicionarPedido(int $atendimentoId, array $pedido, array $itens, int $usuarioId): ?array
    {
        return Banco::transacao(function (\PDO $pdo) use ($atendimentoId, $pedido, $itens, $usuarioId): ?array {
            // O UPDATE só acerta se a conta ainda estiver aberta e já trava o banco para escrita: ninguém consegue fechar
            // a conta entre esta checagem e a gravação do pedido.
            $guarda = $pdo->prepare("UPDATE atendimentos SET atualizado_em = ? WHERE id = ? AND status = 'aberto'");
            $guarda->execute([Relogio::agora(), $atendimentoId]);
            if ($guarda->rowCount() === 0) {
                return null;
            }
            return $this->pedidos->inserir($pdo, $pedido + ['atendimento_id' => $atendimentoId], $itens, $usuarioId);
        });
    }

    /**
     * Fecha a conta: soma os pedidos que não foram cancelados, confere com o total que a atendente viu e registra o
     * pagamento, quem fechou e quando. Tudo numa transação.
     * @return array{estado:string, total_centavos?:int}
     *   estado: ok · nao_encontrado · ja_encerrado · total_mudou (a conta mudou enquanto ela conferia) · pagamento_obrigatorio
     */
    public function fechar(int $id, ?string $pagamento, ?int $totalEsperado, int $usuarioId): array
    {
        try {
            return Banco::transacao(function (\PDO $pdo) use ($id, $pagamento, $totalEsperado, $usuarioId): array {
                $agora = Relogio::agora();
                $marca = $pdo->prepare("UPDATE atendimentos SET status = 'encerrado', encerrado_em = ?, encerrado_por = ?, atualizado_em = ? WHERE id = ? AND status = 'aberto'");
                $marca->execute([$agora, $usuarioId, $agora, $id]);
                if ($marca->rowCount() === 0) {
                    return ['estado' => $this->existe($pdo, $id) ? 'ja_encerrado' : 'nao_encontrado'];
                }
                $total = $this->somaDosPedidos($pdo, $id);
                if ($totalEsperado !== null && $totalEsperado !== $total) {
                    throw new \DomainException('total_mudou');
                }
                if ($total > 0 && $pagamento === null) {
                    throw new \DomainException('pagamento_obrigatorio');
                }
                $pdo->prepare('UPDATE atendimentos SET pagamento = ?, total_centavos = ? WHERE id = ?')->execute([$total > 0 ? $pagamento : null, $total, $id]);
                return ['estado' => 'ok', 'total_centavos' => $total];
            });
        } catch (\DomainException $e) {
            // A transação foi desfeita: a conta continua aberta.
            return ['estado' => $e->getMessage(), 'total_centavos' => $this->somaDosPedidos(Banco::conexao(), $id)];
        }
    }

    public function porId(int $id): ?array
    {
        $sql = Banco::conexao()->prepare('SELECT a.*, ua.nome AS aberto_por_nome, ue.nome AS encerrado_por_nome
                                          FROM atendimentos a
                                          LEFT JOIN usuarios ua ON ua.id = a.aberto_por
                                          LEFT JOIN usuarios ue ON ue.id = a.encerrado_por
                                          WHERE a.id = ?');
        $sql->execute([$id]);
        $linha = $sql->fetch();
        return $linha ? $this->converter($linha) : null;
    }

    /**
     * O que o quadro do Salão mostra: todos os atendimentos abertos + os encerrados hoje (dia da loja).
     * @return list<array<string,mixed>>
     */
    public function doQuadro(): array
    {
        $sql = Banco::conexao()->prepare("SELECT a.*, ua.nome AS aberto_por_nome FROM atendimentos a LEFT JOIN usuarios ua ON ua.id = a.aberto_por
                                          WHERE a.status = 'aberto' OR a.encerrado_em >= ? ORDER BY a.id");
        $sql->execute([Relogio::inicioDoDiaDaLoja()]);
        return array_map($this->converter(...), $sql->fetchAll());
    }

    /**
     * Histórico para o proprietário: atendimentos de um período (pela data do fechamento, ou da abertura se ainda abertos).
     * @param string $de  "Y-m-d H:i:s" UTC (inclusive)
     * @param string $ate "Y-m-d H:i:s" UTC (exclusive)
     * @return list<array<string,mixed>>
     */
    public function historico(string $de, string $ate, ?string $status, int $limite = 300): array
    {
        $sql = Banco::conexao()->prepare("SELECT a.*, ua.nome AS aberto_por_nome, ue.nome AS encerrado_por_nome
                                          FROM atendimentos a
                                          LEFT JOIN usuarios ua ON ua.id = a.aberto_por
                                          LEFT JOIN usuarios ue ON ue.id = a.encerrado_por
                                          WHERE COALESCE(a.encerrado_em, a.aberto_em) >= ? AND COALESCE(a.encerrado_em, a.aberto_em) < ?
                                            AND (? IS NULL OR a.status = ?)
                                          ORDER BY COALESCE(a.encerrado_em, a.aberto_em) DESC, a.id DESC LIMIT {$limite}");
        $sql->execute([$de, $ate, $status, $status]);
        return array_map($this->converter(...), $sql->fetchAll());
    }

    private function existe(\PDO $pdo, int $id): bool
    {
        $sql = $pdo->prepare('SELECT COUNT(*) FROM atendimentos WHERE id = ?');
        $sql->execute([$id]);
        return (int) $sql->fetchColumn() > 0;
    }

    /** Soma o valor de todos os pedidos do atendimento que não foram cancelados (cada pedido entra uma vez só). */
    private function somaDosPedidos(\PDO $pdo, int $id): int
    {
        $sql = $pdo->prepare("SELECT COALESCE(SUM(total_centavos), 0) FROM pedidos WHERE atendimento_id = ? AND status <> 'cancelado'");
        $sql->execute([$id]);
        return (int) $sql->fetchColumn();
    }

    private function converter(array $linha): array
    {
        foreach (['id', 'aberto_por', 'encerrado_por', 'total_centavos'] as $coluna) {
            if (array_key_exists($coluna, $linha)) {
                $linha[$coluna] = $linha[$coluna] === null ? null : (int) $linha[$coluna];
            }
        }
        foreach (['aberto_em', 'encerrado_em', 'atualizado_em'] as $coluna) {
            $linha[$coluna] = Relogio::iso($linha[$coluna]);
        }
        return $linha;
    }
}
