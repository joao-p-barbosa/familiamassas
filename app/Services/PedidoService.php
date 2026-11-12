<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ErroHttp;
use App\Repositories\CardapioRepository;
use App\Repositories\ConfigRepository;
use App\Repositories\PedidoRepository;

// Regras do pedido: validar o que veio do cardápio, recalcular preços e controlar as mudanças de status.
final class PedidoService
{
    /** Caminho de status de cada tipo de pedido (seção 7.4 do briefing). */
    public const FLUXOS = [
        'entrega'  => ['novo', 'em_preparo', 'pronto', 'saiu_entrega', 'concluido'],
        'retirada' => ['novo', 'em_preparo', 'pronto', 'aguardando_retirada', 'concluido'],
        'local'    => ['novo', 'em_preparo', 'pronto', 'concluido'],
    ];

    public const ROTULOS = [
        'novo' => 'Recebido', 'em_preparo' => 'Em preparo', 'pronto' => 'Pronto', 'saiu_entrega' => 'Saiu para entrega',
        'aguardando_retirada' => 'Aguardando retirada', 'concluido' => 'Concluído', 'cancelado' => 'Cancelado',
    ];

    public function __construct(
        private readonly CardapioRepository $cardapio = new CardapioRepository(),
        private readonly PedidoRepository $pedidos = new PedidoRepository(),
        private readonly ConfigRepository $config = new ConfigRepository(),
        private readonly PrecoService $preco = new PrecoService(),
        private readonly LojaService $loja = new LojaService(),
        private readonly TokenWhatsApp $token = new TokenWhatsApp(),
    ) {}

    /**
     * Cria o pedido a partir do que o cardápio enviou. Nada de preço vindo do navegador é aceito:
     * tudo é recalculado com o cardápio atual.
     * @return array{id:int, codigo:string}
     */
    public function criar(array $dados): array
    {
        if (!$this->loja->estado()['aberta']) {
            throw new ErroHttp(409, 'loja_fechada', 'A loja fechou enquanto você montava o pedido. ' . $this->loja->estado()['mensagem'] . '.');
        }

        $erros = [];
        $texto = static fn (mixed $v, int $max): string => mb_substr(trim(is_string($v) ? $v : ''), 0, $max);

        // ---- Tipo ----
        $tipo = $texto($dados['tipo'] ?? '', 20);
        if (!isset(self::FLUXOS[$tipo]) || $this->config->get("aceita_{$tipo}", '1') !== '1') {
            $erros['tipo'] = 'Escolha entrega, retirada ou comer no local.';
        }

        // ---- Cliente ----
        $cliente = is_array($dados['cliente'] ?? null) ? $dados['cliente'] : [];
        $nome = $texto($cliente['nome'] ?? '', 80);
        if (mb_strlen($nome) < 2) {
            $erros['nome'] = 'Diga seu nome para chamarmos você.';
        }
        // Link do WhatsApp: se o token valer, o telefone vem dele (não do formulário) e a origem é "whatsapp".
        $telefoneDoToken = $this->token->validar($texto($dados['t'] ?? '', 200));
        $telefone = $telefoneDoToken ?? self::normalizarTelefone($texto($cliente['telefone'] ?? '', 30));
        if ($telefone === null) {
            $erros['telefone'] = 'Informe um WhatsApp com DDD, por exemplo (11) 91234-5678.';
        }

        // ---- Entrega / mesa ----
        $endereco = is_array($dados['endereco'] ?? null) ? $dados['endereco'] : [];
        $rua = $texto($endereco['rua'] ?? '', 120);
        $numero = $texto($endereco['numero'] ?? '', 20);
        $bairro = $texto($endereco['bairro'] ?? '', 80);
        $mesa = $texto($dados['mesa'] ?? '', 10);
        if ($tipo === 'entrega') {
            if ($rua === '')    { $erros['rua'] = 'Informe a rua.'; }
            if ($numero === '') { $erros['numero'] = 'Informe o número (ou "s/n").'; }
            if ($bairro === '') { $erros['bairro'] = 'Informe o bairro.'; }
        }
        if ($tipo === 'local' && $mesa === '') {
            $erros['mesa'] = 'Informe o número da mesa.';
        }

        // ---- Pagamento ----
        $pagamento = is_array($dados['pagamento'] ?? null) ? $dados['pagamento'] : [];
        $forma = $texto($pagamento['forma'] ?? '', 20);
        if (!in_array($forma, ['pix', 'cartao', 'dinheiro'], true)) {
            $erros['pagamento'] = 'Escolha a forma de pagamento.';
        }

        // ---- Itens ----
        $itensEnviados = is_array($dados['itens'] ?? null) ? array_values($dados['itens']) : [];
        if ($itensEnviados === [] || count($itensEnviados) > 40) {
            throw new ErroHttp(422, 'carrinho_vazio', 'Seu carrinho está vazio.');
        }
        $catalogo = $this->cardapio->publico();
        $itensDoCardapio = array_column($catalogo['itens'], null, 'id');
        $itens = [];
        $subtotal = 0;
        foreach ($itensEnviados as $enviado) {
            if (!is_array($enviado)) {
                throw new ErroHttp(422, 'item_invalido', 'Um item do carrinho não é válido.');
            }
            $quantidade = (int) ($enviado['quantidade'] ?? 0);
            if ($quantidade < 1 || $quantidade > 20) {
                throw new ErroHttp(422, 'item_invalido', 'A quantidade de cada item deve ficar entre 1 e 20.');
            }
            $itemId = isset($enviado['item_id']) ? (int) $enviado['item_id'] : null;
            $doCardapio = $itemId !== null ? ($itensDoCardapio[$itemId] ?? null) : null;
            if ($itemId !== null && $doCardapio === null) {
                throw new ErroHttp(409, 'item_removido', 'Um item do carrinho saiu do cardápio. Atualize a página e confira.');
            }
            if ($doCardapio !== null && $doCardapio['estado'] !== 'disponivel') {
                throw new ErroHttp(409, 'esgotado', "{$doCardapio['nome']} acabou de esgotar. Tire do carrinho para continuar.");
            }

            if (($enviado['tipo'] ?? '') === 'montado') {
                if ($doCardapio !== null && $doCardapio['tipo'] !== 'prato_casa') {
                    throw new ErroHttp(422, 'item_invalido', 'Um item do carrinho não é válido.');
                }
                $montado = $this->preco->montar($catalogo, $enviado);
                $linha = [
                    'item_id' => $itemId, 'tipo' => 'montado',
                    'nome' => $doCardapio['nome'] ?? 'Monte seu prato',
                    'preco_unit_centavos' => $montado['preco_centavos'], 'montagem' => $montado['montagem'],
                ];
            } else {
                if ($doCardapio === null || $doCardapio['tipo'] !== 'simples') {
                    throw new ErroHttp(422, 'item_invalido', 'Um item do carrinho não é válido.');
                }
                $linha = [
                    'item_id' => $itemId, 'tipo' => 'simples', 'nome' => $doCardapio['nome'],
                    'preco_unit_centavos' => $doCardapio['preco_centavos'], 'montagem' => null,
                ];
            }
            $linha['quantidade'] = $quantidade;
            $linha['observacoes'] = $texto($enviado['observacoes'] ?? '', 200);
            $subtotal += $linha['preco_unit_centavos'] * $quantidade;
            $itens[] = $linha;
        }

        $taxa = $tipo === 'entrega' ? $this->config->inteiro('taxa_entrega_centavos') : 0;
        $total = $subtotal + $taxa;

        $trocoPara = null;
        if ($forma === 'dinheiro' && isset($pagamento['troco_para_centavos']) && $pagamento['troco_para_centavos'] !== null) {
            $trocoPara = (int) $pagamento['troco_para_centavos'];
            if ($trocoPara < $total) {
                $erros['troco'] = 'O valor para troco precisa ser maior que o total do pedido.';
            }
        }

        if ($erros !== []) {
            throw ErroHttp::validacao($erros);
        }

        // O cliente confirmou um total na revisão. Se o cardápio mudou nesse meio-tempo, ele precisa ver o novo valor.
        if (isset($dados['total_esperado_centavos']) && (int) $dados['total_esperado_centavos'] !== $total) {
            throw new ErroHttp(409, 'total_mudou', 'Os preços foram atualizados enquanto você pedia. Confira o novo total antes de confirmar.');
        }

        return $this->pedidos->criar([
            'cliente_nome' => $nome,
            'cliente_telefone' => $telefone,
            'tipo' => $tipo,
            'mesa' => $tipo === 'local' ? $mesa : null,
            'endereco_rua' => $tipo === 'entrega' ? $rua : null,
            'endereco_numero' => $tipo === 'entrega' ? $numero : null,
            'endereco_bairro' => $tipo === 'entrega' ? $bairro : null,
            'endereco_complemento' => $tipo === 'entrega' ? $texto($endereco['complemento'] ?? '', 80) : null,
            'endereco_referencia' => $tipo === 'entrega' ? $texto($endereco['referencia'] ?? '', 120) : null,
            'pagamento' => $forma,
            'troco_para_centavos' => $trocoPara,
            'observacoes' => $texto($dados['observacoes'] ?? '', 300),
            'subtotal_centavos' => $subtotal,
            'taxa_entrega_centavos' => $taxa,
            'total_centavos' => $total,
            'origem' => $telefoneDoToken !== null ? 'whatsapp' : 'cardapio',
        ], $itens);
    }

    /**
     * Muda o status de um pedido, respeitando o caminho do tipo dele e o perfil de quem pede.
     * @param array{id:int, perfil:string} $usuario
     */
    public function mudarStatus(int $id, string $para, ?string $motivo, array $usuario): array
    {
        $pedido = $this->pedidos->porId($id);
        if ($pedido === null) {
            throw ErroHttp::naoEncontrado('Pedido não encontrado.');
        }
        $atual = $pedido['status'];
        if (in_array($atual, PedidoRepository::ENCERRADOS, true)) {
            throw new ErroHttp(409, 'pedido_encerrado', 'Este pedido já foi encerrado e não muda mais de etapa.');
        }
        $fluxo = self::FLUXOS[$pedido['tipo']];
        if ($para === 'cancelado') {
            $motivo = mb_substr(trim((string) $motivo), 0, 200);
            if ($motivo === '') {
                throw ErroHttp::validacao(['motivo' => 'Diga o motivo do cancelamento.']);
            }
            if ($usuario['perfil'] === 'cozinha') {
                throw new ErroHttp(403, 'sem_permissao', 'A cozinha não cancela pedidos. Chame o atendimento.');
            }
        } elseif (!in_array($para, $fluxo, true) || $para === $atual) {
            throw new ErroHttp(422, 'status_invalido', 'Esta etapa não existe para este tipo de pedido.');
        } else {
            $motivo = null;
            if ($usuario['perfil'] === 'cozinha' && !in_array($para, ['novo', 'em_preparo', 'pronto'], true)) {
                throw new ErroHttp(403, 'sem_permissao', 'A cozinha só move pedidos até "Pronto".');
            }
        }
        if (!$this->pedidos->mudarStatus($id, $atual, $para, $motivo, $usuario['id'])) {
            throw new ErroHttp(409, 'conflito', 'Outra pessoa acabou de mover este pedido. A tela já vai atualizar.');
        }
        return $this->pedidos->porId($id);
    }

    /** O que a página de acompanhamento pode mostrar (sem telefone nem endereço). */
    public function publico(array $pedido): array
    {
        $quando = [];
        foreach ($pedido['historico'] ?? [] as $h) {
            $quando[$h['status']] = $h['em'];
        }
        $posicao = array_search($pedido['status'], self::FLUXOS[$pedido['tipo']], true);
        $etapas = [];
        foreach (self::FLUXOS[$pedido['tipo']] as $i => $status) {
            $etapas[] = [
                'status' => $status,
                'rotulo' => self::ROTULOS[$status],
                'feita' => $posicao !== false && $i <= $posicao,
                'em' => $posicao !== false && $i <= $posicao ? ($quando[$status] ?? null) : null,
            ];
        }
        $cancelamento = null;
        foreach ($pedido['historico'] ?? [] as $h) {
            if ($h['status'] === 'cancelado') {
                $cancelamento = $h['motivo'];
            }
        }
        $minutos = $pedido['tipo'] === 'entrega' ? $this->config->inteiro('tempo_entrega_min', 50) : $this->config->inteiro('tempo_retirada_min', 25);
        return [
            'numero' => $pedido['id'],
            'codigo' => $pedido['codigo'],
            'status' => $pedido['status'],
            'rotulo' => self::ROTULOS[$pedido['status']],
            'tipo' => $pedido['tipo'],
            'mesa' => $pedido['mesa'],
            'primeiro_nome' => explode(' ', $pedido['cliente_nome'])[0],
            'etapas' => $etapas,
            'motivo_cancelamento' => $cancelamento,
            'previsao_minutos' => $minutos,
            'itens' => $pedido['itens'],
            'pagamento' => $pedido['pagamento'],
            'subtotal_centavos' => $pedido['subtotal_centavos'],
            'taxa_entrega_centavos' => $pedido['taxa_entrega_centavos'],
            'total_centavos' => $pedido['total_centavos'],
            'criado_em' => $pedido['criado_em'],
            'status_atualizado_em' => $pedido['status_atualizado_em'],
        ];
    }

    /** Deixa o telefone só com dígitos e com o DDI 55. Devolve null se não parecer um celular/fixo brasileiro. */
    public static function normalizarTelefone(string $telefone): ?string
    {
        $digitos = preg_replace('/\D/', '', $telefone) ?? '';
        if (strlen($digitos) >= 12 && str_starts_with($digitos, '55')) {
            $digitos = substr($digitos, 2);
        }
        if (!preg_match('/^[1-9][0-9]9?[0-9]{8}$/', $digitos)) {
            return null;
        }
        return '55' . $digitos;
    }
}
