<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ErroHttp;

/**
 * Regra de preço dos pratos (D87). É a ÚNICA fonte do preço que vale:
 * o navegador calcula igual (public/assets/js/shared/preco.js) só para mostrar,
 * mas o servidor sempre recalcula aqui antes de gravar o pedido.
 *
 *   Prato da casa:   preço fixo do prato no tamanho escolhido (tabela item_precos). O cliente não troca nada.
 *   Monte sua massa: preço do tamanho + acréscimo da massa + acréscimo do molho + preço de cada ingrediente escolhido.
 *                    Ingrediente com preço 0 vem sem custo ("inclusos"); os de preço maior que 0 são os "extras".
 */
final class PrecoService
{
    /**
     * @param array $cardapio resultado de CardapioRepository::publico()
     * @param array{item_id?:mixed, tamanho_id?:mixed, massa_id?:mixed, molho_id?:mixed, ingrediente_ids?:mixed} $escolha
     * @return array{preco_centavos:int, montagem:array}
     */
    public function montar(array $cardapio, array $escolha): array
    {
        $tamanho = $this->disponivel($cardapio['tamanhos'], $escolha['tamanho_id'] ?? null, 'tamanho');
        if (isset($escolha['item_id'])) {
            return $this->pratoDaCasa($cardapio, (int) $escolha['item_id'], $tamanho);
        }
        $massa = $this->disponivel($cardapio['massas'], $escolha['massa_id'] ?? null, 'massa');
        $molho = $this->disponivel($cardapio['molhos'], $escolha['molho_id'] ?? null, 'molho');

        $ids = $escolha['ingrediente_ids'] ?? [];
        if (!is_array($ids) || count($ids) > 30) {
            throw new ErroHttp(422, 'montagem_invalida', 'A lista de ingredientes do prato não é válida.');
        }
        $ingredientes = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $ingredientes[] = $this->disponivel($cardapio['ingredientes'], $id, 'ingrediente');
        }
        // Na ordem do cardápio, para a comanda sair igual não importa a ordem do clique.
        usort($ingredientes, static fn (array $a, array $b): int => [$a['ordem'], $a['id']] <=> [$b['ordem'], $b['id']]);
        $extras = array_values(array_filter($ingredientes, static fn (array $i): bool => $i['preco_extra_centavos'] > 0));
        $inclusos = array_values(array_filter($ingredientes, static fn (array $i): bool => $i['preco_extra_centavos'] === 0));

        $preco = $tamanho['preco_centavos'] + $massa['acrescimo_centavos'] + $molho['acrescimo_centavos']
            + array_sum(array_column($extras, 'preco_extra_centavos'));

        return [
            'preco_centavos' => $preco,
            'montagem' => [
                'tamanho' => self::tamanho($tamanho, $tamanho['preco_centavos']),
                'massa'   => ['id' => $massa['id'], 'nome' => $massa['nome'], 'acrescimo_centavos' => $massa['acrescimo_centavos']],
                'molho'   => ['id' => $molho['id'], 'nome' => $molho['nome'], 'acrescimo_centavos' => $molho['acrescimo_centavos']],
                'inclusos' => array_map([self::class, 'resumo'], $inclusos),
                'extras'   => array_map([self::class, 'resumo'], $extras),
            ],
        ];
    }

    /** Prato da casa: preço fixo do tamanho; a montagem vai pronta para a cozinha ler (massa, molho e ingredientes do prato). */
    private function pratoDaCasa(array $cardapio, int $itemId, array $tamanho): array
    {
        $item = null;
        foreach ($cardapio['itens'] as $i) {
            if ($i['id'] === $itemId && $i['tipo'] === 'prato_casa') {
                $item = $i;
            }
        }
        if ($item === null) {
            throw new ErroHttp(409, 'item_removido', 'Um prato do carrinho saiu do cardápio. Atualize a página e confira.');
        }
        $preco = array_column($item['precos'] ?? [], 'preco_centavos', 'tamanho_id')[$tamanho['id']] ?? null;
        if ($preco === null) {
            throw new ErroHttp(409, 'esgotado', "{$item['nome']} não é vendido no tamanho {$tamanho['nome']}. Escolha outro tamanho.");
        }
        $porId = static fn (string $lista, mixed $id): ?array => array_column($cardapio[$lista], null, 'id')[(int) $id] ?? null;
        $nome = static fn (?array $o): array => ['id' => $o['id'] ?? null, 'nome' => $o['nome'] ?? '—', 'acrescimo_centavos' => 0];
        $ingredientes = array_values(array_filter(array_map(static fn (int $id): ?array => $porId('ingredientes', $id), $item['ingrediente_ids'])));
        return [
            'preco_centavos' => $preco,
            'montagem' => [
                'tamanho' => self::tamanho($tamanho, $preco),
                'massa'   => $nome($porId('massas', $item['massa_id'])),
                'molho'   => $nome($porId('molhos', $item['molho_id'])),
                'inclusos' => array_map(static fn (array $i): array => ['id' => $i['id'], 'nome' => $i['nome'], 'preco_extra_centavos' => 0], $ingredientes),
                'extras'   => [],
            ],
        ];
    }

    private static function tamanho(array $t, int $preco): array
    {
        return ['id' => $t['id'], 'nome' => $t['nome'], 'sigla' => $t['sigla'], 'descricao' => $t['descricao'], 'preco_centavos' => $preco];
    }

    private static function resumo(array $i): array
    {
        return ['id' => $i['id'], 'nome' => $i['nome'], 'preco_extra_centavos' => $i['preco_extra_centavos']];
    }

    /** Acha a opção pelo id e garante que ela pode ser pedida agora. */
    private function disponivel(array $lista, mixed $id, string $oQue): array
    {
        foreach ($lista as $opcao) {
            if ($opcao['id'] === (int) $id) {
                if ($opcao['estado'] !== 'disponivel') {
                    throw new ErroHttp(409, 'esgotado', sprintf('%s acabou de esgotar. Escolha outra opção.', $opcao['nome']));
                }
                return $opcao;
            }
        }
        throw new ErroHttp(422, 'montagem_invalida', "Escolha de {$oQue} não encontrada. Atualize o cardápio e tente de novo.");
    }
}
