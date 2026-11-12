<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ErroHttp;
use App\Core\Relogio;
use App\Repositories\AtendimentoRepository;
use App\Repositories\PedidoRepository;

/**
 * Atendimento do salão = a conta de um cliente (Mesa ou Pra viagem).
 *
 * Um atendimento reúne vários pedidos. Cada pedido tem o seu próprio status (a cozinha o move: novo → em preparo → pronto →
 * concluído) e o atendimento tem um status independente: continua "aberto" até a conta ser fechada com o pagamento,
 * mesmo que todos os pedidos já tenham sido servidos. O pagamento é registrado só no fechamento da conta.
 */
final class AtendimentoService
{
    public const MODALIDADES = ['mesa' => 'Mesa', 'viagem' => 'Pra viagem'];
    public const FORMAS_DE_PAGAMENTO = ['pix', 'cartao', 'dinheiro'];

    public function __construct(
        private readonly AtendimentoRepository $atendimentos = new AtendimentoRepository(),
        private readonly PedidoRepository $pedidos = new PedidoRepository(),
        private readonly PedidoService $servicoDePedidos = new PedidoService(),
    ) {}

    /**
     * Abre o atendimento e registra o primeiro pedido.
     * @param array{id:int, perfil:string} $usuario
     * @return array{id:int, pedido_id:int, numero:int, codigo:string}
     */
    public function abrir(array $dados, array $usuario): array
    {
        $erros = [];
        $cliente = is_array($dados['cliente'] ?? null) ? $dados['cliente'] : [];
        $nome = self::texto($cliente['nome'] ?? '', 80);
        if (mb_strlen($nome) < 2) {
            $erros['nome'] = 'Informe o nome do cliente.';
        }
        $modalidade = self::texto($dados['modalidade'] ?? '', 10);
        if (!isset(self::MODALIDADES[$modalidade])) {
            $erros['modalidade'] = 'Escolha Mesa ou Pra viagem.';
        }
        $mesa = self::texto($dados['mesa'] ?? '', 20);
        if ($modalidade === 'mesa' && $mesa === '') {
            $erros['mesa'] = 'Informe a mesa.';
        }

        ['itens' => $itens, 'subtotal' => $subtotal] = $this->servicoDePedidos->validarItens($dados);
        if ($erros !== []) {
            throw ErroHttp::validacao($erros);
        }
        $this->servicoDePedidos->conferirTotal($dados, $subtotal);

        $mesaDoAtendimento = $modalidade === 'mesa' ? $mesa : null;
        $criado = $this->atendimentos->abrir(
            ['cliente_nome' => $nome, 'modalidade' => $modalidade, 'mesa' => $mesaDoAtendimento],
            $this->colunasDoPedido($nome, $modalidade, $mesaDoAtendimento, $subtotal),
            $itens,
            $usuario['id'],
        );
        return ['id' => $criado['atendimento_id'], 'pedido_id' => $criado['id'], 'numero' => $criado['id'], 'codigo' => $criado['codigo']];
    }

    /**
     * Soma um pedido a um atendimento aberto (mais produtos para a mesma conta).
     * @param array{id:int, perfil:string} $usuario
     * @return array{id:int, numero:int, codigo:string}
     */
    public function adicionarPedido(int $atendimentoId, array $dados, array $usuario): array
    {
        $atendimento = $this->atendimentos->porId($atendimentoId) ?? throw ErroHttp::naoEncontrado('Atendimento não encontrado.');
        if ($atendimento['status'] !== 'aberto') {
            throw $this->contaEncerrada($atendimento);
        }
        ['itens' => $itens, 'subtotal' => $subtotal] = $this->servicoDePedidos->validarItens($dados);
        $this->servicoDePedidos->conferirTotal($dados, $subtotal);

        $criado = $this->atendimentos->adicionarPedido(
            $atendimentoId,
            $this->colunasDoPedido($atendimento['cliente_nome'], $atendimento['modalidade'], $atendimento['mesa'], $subtotal),
            $itens,
            $usuario['id'],
        ) ?? throw $this->contaEncerrada($atendimento);   // fechou no meio do caminho
        return ['id' => $criado['id'], 'numero' => $criado['id'], 'codigo' => $criado['codigo']];
    }

    /**
     * Fecha a conta: confere o total, registra o pagamento e encerra o atendimento.
     * @param array{id:int, perfil:string} $usuario
     */
    public function fechar(int $atendimentoId, array $dados, array $usuario): array
    {
        $pagamento = self::texto($dados['pagamento'] ?? '', 20);
        if ($pagamento !== '' && !in_array($pagamento, self::FORMAS_DE_PAGAMENTO, true)) {
            throw ErroHttp::validacao(['pagamento' => 'Escolha Pix, Cartão ou Dinheiro.']);
        }
        $esperado = isset($dados['total_esperado_centavos']) ? (int) $dados['total_esperado_centavos'] : null;
        $resultado = $this->atendimentos->fechar($atendimentoId, $pagamento === '' ? null : $pagamento, $esperado, $usuario['id']);
        match ($resultado['estado']) {
            'ok' => null,
            'nao_encontrado' => throw ErroHttp::naoEncontrado('Atendimento não encontrado.'),
            'ja_encerrado' => throw new ErroHttp(409, 'ja_encerrado', 'Esta conta já foi fechada.'),
            'total_mudou' => throw new ErroHttp(409, 'total_mudou', 'A conta mudou enquanto você conferia (entrou ou saiu um pedido). Confira o novo total antes de fechar.'),
            'pagamento_obrigatorio' => throw ErroHttp::validacao(['pagamento' => 'Escolha a forma de pagamento para fechar a conta.']),
        };
        return $this->detalhe($atendimentoId);
    }

    /**
     * "Servido": passa para Concluído todos os pedidos do atendimento que estão Prontos.
     * @param array{id:int, perfil:string} $usuario
     */
    public function servirProntos(int $atendimentoId, array $usuario): array
    {
        $this->atendimentos->porId($atendimentoId) ?? throw ErroHttp::naoEncontrado('Atendimento não encontrado.');
        foreach ($this->pedidos->dosAtendimentos([$atendimentoId])[$atendimentoId] ?? [] as $pedido) {
            if ($pedido['status'] === 'pronto') {
                $this->servicoDePedidos->mudarStatus($pedido['id'], 'concluido', null, $usuario);
            }
        }
        return $this->detalhe($atendimentoId);
    }

    /** Um atendimento completo: dados, pedidos (com itens e montagem) e os números da conta. */
    public function detalhe(int $atendimentoId): array
    {
        $atendimento = $this->atendimentos->porId($atendimentoId) ?? throw ErroHttp::naoEncontrado('Atendimento não encontrado.');
        return $this->apresentar($atendimento, $this->pedidos->dosAtendimentos([$atendimentoId])[$atendimentoId] ?? []);
    }

    /**
     * O quadro do Salão: atendimentos abertos e encerrados hoje, cada um na sua fase, e os números do dia.
     * @return array{agora:string, atendimentos:list<array<string,mixed>>, resumo:array<string,int>}
     */
    public function quadro(): array
    {
        $linhas = $this->atendimentos->doQuadro();
        $pedidos = $this->pedidos->dosAtendimentos(array_column($linhas, 'id'));
        $lista = array_map(fn (array $a): array => $this->apresentar($a, $pedidos[$a['id']] ?? []), $linhas);
        $resumo = ['abertos' => 0, 'cozinha' => 0, 'pronto' => 0, 'conta' => 0, 'encerrados_hoje' => 0, 'valor_em_aberto_centavos' => 0, 'vendido_hoje_centavos' => 0];
        foreach ($lista as $a) {
            if ($a['status'] === 'encerrado') {
                $resumo['encerrados_hoje']++;
                $resumo['vendido_hoje_centavos'] += (int) $a['total_centavos'];
                continue;
            }
            $resumo['abertos']++;
            $resumo[$a['fase']]++;
            $resumo['valor_em_aberto_centavos'] += $a['total_centavos'];
        }
        return ['agora' => Relogio::iso(Relogio::agora()), 'atendimentos' => $lista, 'resumo' => $resumo];
    }

    /**
     * Histórico para o proprietário.
     * @param string $periodo hoje | 7d | 30d
     * @return array{periodo:string, atendimentos:list<array<string,mixed>>, resumo:array<string,mixed>}
     */
    public function historico(string $periodo, ?string $status): array
    {
        $periodo = in_array($periodo, ['hoje', '7d', '30d'], true) ? $periodo : 'hoje';
        $status = in_array($status, ['aberto', 'encerrado'], true) ? $status : null;
        $dias = ['hoje' => 0, '7d' => 6, '30d' => 29][$periodo];
        $fuso = Relogio::fusoDaLoja();
        $de = (new \DateTimeImmutable('today', $fuso))->modify("-{$dias} days")->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $ate = (new \DateTimeImmutable('tomorrow', $fuso))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $linhas = $this->atendimentos->historico($de, $ate, $status);
        $pedidos = $this->pedidos->dosAtendimentos(array_column($linhas, 'id'));
        $lista = array_map(fn (array $a): array => $this->apresentar($a, $pedidos[$a['id']] ?? []), $linhas);

        $resumo = ['quantidade' => count($lista), 'encerrados' => 0, 'abertos' => 0, 'total_cobrado_centavos' => 0, 'por_pagamento' => array_fill_keys(self::FORMAS_DE_PAGAMENTO, 0)];
        foreach ($lista as $a) {
            if ($a['status'] === 'aberto') {
                $resumo['abertos']++;
                continue;
            }
            $resumo['encerrados']++;
            $resumo['total_cobrado_centavos'] += (int) $a['total_centavos'];
            if ($a['pagamento'] !== null) {
                $resumo['por_pagamento'][$a['pagamento']] += (int) $a['total_centavos'];
            }
        }
        return ['periodo' => $periodo, 'atendimentos' => $lista, 'resumo' => $resumo];
    }

    /**
     * Junta o atendimento aos pedidos e calcula o que a tela precisa, para o navegador não repetir regra:
     *   total_centavos  valor da conta: soma dos pedidos não cancelados (enquanto aberta) ou o valor cobrado (encerrada);
     *   quantidade_itens, pedidos_ativos e a "fase" no quadro.
     * Fases (conta aberta): cozinha = algum pedido ainda novo ou em preparo · pronto = nada em produção e algum pedido
     * pronto para servir · conta = tudo servido (ou cancelado), só falta fechar a conta. Conta encerrada: encerrado.
     * @param array<string,mixed> $atendimento
     * @param list<array<string,mixed>> $pedidos
     */
    private function apresentar(array $atendimento, array $pedidos): array
    {
        $ativos = array_values(array_filter($pedidos, static fn (array $p): bool => $p['status'] !== 'cancelado'));
        $soma = array_sum(array_column($ativos, 'total_centavos'));
        $quantidade = 0;
        foreach ($ativos as $p) {
            $quantidade += array_sum(array_column($p['itens'], 'quantidade'));
        }

        $status = array_column($ativos, 'status');
        $fase = match (true) {
            $atendimento['status'] === 'encerrado' => 'encerrado',
            (bool) array_intersect($status, ['novo', 'em_preparo']) => 'cozinha',
            in_array('pronto', $status, true) => 'pronto',
            default => 'conta',
        };

        // Dados que o cartão e a conta precisam de cada pedido (o resto da linha do pedido não interessa aqui).
        $resumo = array_map(static fn (array $p): array => [
            'id' => $p['id'], 'status' => $p['status'], 'total_centavos' => $p['total_centavos'], 'criado_em' => $p['criado_em'],
            'status_atualizado_em' => $p['status_atualizado_em'], 'itens' => $p['itens'],
        ], $pedidos);

        return [
            'id' => $atendimento['id'],
            'cliente_nome' => $atendimento['cliente_nome'],
            'modalidade' => $atendimento['modalidade'],
            'mesa' => $atendimento['mesa'],
            'status' => $atendimento['status'],
            'fase' => $fase,
            'pagamento' => $atendimento['pagamento'],
            'total_centavos' => $atendimento['status'] === 'encerrado' ? (int) $atendimento['total_centavos'] : $soma,
            'quantidade_itens' => $quantidade,
            'pedidos_ativos' => count($ativos),
            'aberto_em' => $atendimento['aberto_em'],
            'encerrado_em' => $atendimento['encerrado_em'],
            'aberto_por_nome' => $atendimento['aberto_por_nome'] ?? null,
            'encerrado_por_nome' => $atendimento['encerrado_por_nome'] ?? null,
            'pedidos' => $resumo,
        ];
    }

    /** Colunas do pedido do salão. A forma de pagamento real só existe no fechamento da conta ("caixa" = na conta). */
    private function colunasDoPedido(string $nome, string $modalidade, ?string $mesa, int $subtotal): array
    {
        return [
            'cliente_nome' => $nome,
            'cliente_telefone' => '',
            'tipo' => $modalidade === 'mesa' ? 'local' : 'viagem',
            'mesa' => $mesa,
            'pagamento' => 'caixa',
            'troco_para_centavos' => null,
            'observacoes' => '',
            'subtotal_centavos' => $subtotal,
            'taxa_entrega_centavos' => 0,
            'total_centavos' => $subtotal,
            'origem' => 'balcao',
        ];
    }

    private function contaEncerrada(array $atendimento): ErroHttp
    {
        return new ErroHttp(409, 'atendimento_encerrado', "A conta de {$atendimento['cliente_nome']} já foi fechada: não dá para somar produtos. Abra um novo atendimento.");
    }

    private static function texto(mixed $valor, int $max): string
    {
        return mb_substr(trim(is_string($valor) ? $valor : ''), 0, $max);
    }
}
