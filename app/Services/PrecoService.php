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
 *   D108: cada acréscimo pode ter um valor por tamanho (opcao['precos']); tamanho sem valor usa o do cadastro.
 *         Um ingrediente pode ser incluso no Meio e extra no Inteiro.
 *   D110: o cliente pode escolher mais de um molho (molho_ids); cada um soma o seu acréscimo. A massa continua uma só.
 *         A montagem guarda a lista em "molhos" e, em "molho", os nomes juntos ("Sugo e Bolonhesa") com a soma dos acréscimos,
 *         para as telas e comandas que mostram uma linha só. Pedido antigo (molho_id) continua valendo.
 */
final class PrecoService
{
    /**
     * @param array $cardapio resultado de CardapioRepository::publico()
     * @param array{item_id?:mixed, tamanho_id?:mixed, massa_id?:mixed, molho_ids?:mixed, molho_id?:mixed, ingrediente_ids?:mixed} $escolha
     * @return array{preco_centavos:int, montagem:array}
     */
    public function montar(array $cardapio, array $escolha): array
    {
        $tamanho = $this->disponivel($cardapio['tamanhos'], $escolha['tamanho_id'] ?? null, 'tamanho');
        if (isset($escolha['item_id'])) {
            return $this->pratoDaCasa($cardapio, (int) $escolha['item_id'], $tamanho);
        }
        $massa = $this->disponivel($cardapio['massas'], $escolha['massa_id'] ?? null, 'massa');
        $molhos = [];
        foreach (self::idsDosMolhos($escolha) as $id) {
            $molhos[] = $this->disponivel($cardapio['molhos'], $id, 'molho');
        }
        if ($molhos === []) {
            throw new ErroHttp(422, 'montagem_invalida', 'Escolha de molho não encontrada. Atualize o cardápio e tente de novo.');
        }
        usort($molhos, static fn (array $a, array $b): int => [$a['ordem'], $a['id']] <=> [$b['ordem'], $b['id']]);

        $ids = $escolha['ingrediente_ids'] ?? [];
        if (!is_array($ids) || count($ids) > 30) {
            throw new ErroHttp(422, 'montagem_invalida', 'A lista de ingredientes do prato não é válida.');
        }
        // D117: o mesmo id repetido = mais de uma porção. Só vale em Acompanhamentos e Proteínas; nos outros grupos conta uma vez.
        $ingredientes = [];
        foreach (array_count_values(array_map('intval', $ids)) as $id => $vezes) {
            $i = $this->disponivel($cardapio['ingredientes'], $id, 'ingrediente');
            $i['quantidade'] = self::repeteNoGrupo((string) $i['grupo']) ? $vezes : 1;
            $ingredientes[] = $i;
        }
        // Limite por grupo (D115): acompanhamentos até 8 (cada porção conta, D117); tempero à vontade.
        $porGrupo = [];
        foreach ($ingredientes as $i) {
            $porGrupo[(string) $i['grupo']] = ($porGrupo[(string) $i['grupo']] ?? 0) + $i['quantidade'];
        }
        foreach ($porGrupo as $grupo => $quantos) {
            $limite = self::limiteDoGrupo((string) $grupo);
            if ($limite !== null && $quantos > $limite) {
                throw new ErroHttp(422, 'montagem_invalida', "Escolha até {$limite} em " . mb_strtolower((string) $grupo) . '.');
            }
        }
        // Na ordem do cardápio, para a comanda sair igual não importa a ordem do clique.
        usort($ingredientes, static fn (array $a, array $b): int => [$a['ordem'], $a['id']] <=> [$b['ordem'], $b['id']]);
        // O valor de cada opção neste tamanho (D108) substitui o do cadastro em tudo que segue (preço, extras, comanda).
        $t = (int) $tamanho['id'];
        $massa['acrescimo_centavos'] = self::noTamanho($massa, 'acrescimo_centavos', $t);
        $molhos = array_map(static fn (array $o): array => ['acrescimo_centavos' => self::noTamanho($o, 'acrescimo_centavos', $t)] + $o, $molhos);
        $valorMolhos = array_sum(array_column($molhos, 'acrescimo_centavos'));
        $ingredientes = array_map(static fn (array $i): array => ['preco_extra_centavos' => self::noTamanho($i, 'preco_extra_centavos', $t)] + $i, $ingredientes);
        $extras = array_values(array_filter($ingredientes, static fn (array $i): bool => $i['preco_extra_centavos'] > 0));
        $inclusos = array_values(array_filter($ingredientes, static fn (array $i): bool => $i['preco_extra_centavos'] === 0));

        $preco = $tamanho['preco_centavos'] + $massa['acrescimo_centavos'] + $valorMolhos
            + array_sum(array_map(static fn (array $i): int => $i['preco_extra_centavos'] * $i['quantidade'], $extras));

        return [
            'preco_centavos' => $preco,
            'montagem' => [
                'tamanho' => self::tamanho($tamanho, $tamanho['preco_centavos']),
                'massa'   => ['id' => $massa['id'], 'nome' => $massa['nome'], 'acrescimo_centavos' => $massa['acrescimo_centavos']],
                'molho'   => ['id' => $molhos[0]['id'], 'nome' => self::juntar(array_column($molhos, 'nome')), 'acrescimo_centavos' => $valorMolhos],
                'molhos'  => array_map(static fn (array $o): array => ['id' => $o['id'], 'nome' => $o['nome'], 'acrescimo_centavos' => $o['acrescimo_centavos']], $molhos),
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

    /** Os molhos escolhidos (D110): a lista molho_ids ou, no formato antigo, o molho_id. Sem repetir. @return list<int> */
    /** Máximo de escolhas por grupo de ingredientes (D115), pelo começo do nome do grupo. Grupo fora da lista: sem limite. */
    public const LIMITES_POR_GRUPO = ['acompanhamento' => 8];

    public static function limiteDoGrupo(string $grupo): ?int
    {
        $nome = mb_strtolower(trim($grupo));
        foreach (self::LIMITES_POR_GRUPO as $comeco => $limite) {
            if (str_starts_with($nome, $comeco)) {
                return $limite;
            }
        }
        return null;
    }

    /** Grupos em que o cliente pode pedir mais de uma porção do mesmo ingrediente (D117), pelo começo do nome. */
    public const REPETE_POR_GRUPO = ['acompanhamento', 'proteína', 'proteina'];

    public static function repeteNoGrupo(string $grupo): bool
    {
        $nome = mb_strtolower(trim($grupo));
        foreach (self::REPETE_POR_GRUPO as $comeco) {
            if (str_starts_with($nome, $comeco)) {
                return true;
            }
        }
        return false;
    }

    /** "2x Calabresa" quando há mais de uma porção; só o nome quando é uma (D117). */
    public static function nomeComPorcoes(array $i): string
    {
        $q = (int) ($i['quantidade'] ?? 1);
        return ($q > 1 ? "{$q}x " : '') . $i['nome'];
    }

    /** O que vai entre parênteses ao lado do grupo (D115): "(escolha até 8)", "(à vontade, sem limite)" no tempero, ou nada. */
    public static function dicaDoGrupo(string $grupo): string
    {
        $limite = self::limiteDoGrupo($grupo);
        if ($limite !== null) {
            return "(escolha até {$limite})";
        }
        return str_starts_with(mb_strtolower(trim($grupo)), 'tempero') && !str_contains($grupo, '(') ? '(à vontade, sem limite)' : '';
    }

    public static function idsDosMolhos(array $escolha): array
    {
        $ids = $escolha['molho_ids'] ?? null;
        if ($ids === null) {
            $ids = isset($escolha['molho_id']) ? [$escolha['molho_id']] : [];
        }
        if (!is_array($ids) || count($ids) > 10) {
            throw new ErroHttp(422, 'montagem_invalida', 'A lista de molhos do prato não é válida.');
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** "Sugo", "Sugo e Bolonhesa", "Sugo, Bolonhesa e Pesto". @param list<string> $nomes */
    public static function juntar(array $nomes): string
    {
        $ultimo = array_pop($nomes);
        return $nomes === [] ? (string) $ultimo : implode(', ', $nomes) . ' e ' . $ultimo;
    }

    /** Valor de uma massa, molho ou ingrediente num tamanho (D108): o da tabela por tamanho ou, sem ele, o do cadastro. */
    public static function noTamanho(array $opcao, string $campo, int $tamanhoId): int
    {
        foreach ($opcao['precos'] ?? [] as $p) {
            if ((int) $p['tamanho_id'] === $tamanhoId) {
                return (int) $p['preco_centavos'];
            }
        }
        return (int) ($opcao[$campo] ?? 0);
    }

    /**
     * Menor e maior valor da opção entre os tamanhos à venda, para mostrar antes de o cliente escolher o tamanho
     * ("+ R$ 3,00" ou "+ R$ 3,00 a R$ 5,00"). @param list<array> $tamanhos @return array{0:int, 1:int}
     */
    public static function faixa(array $opcao, string $campo, array $tamanhos): array
    {
        $valores = array_map(static fn (array $t): int => self::noTamanho($opcao, $campo, (int) $t['id']), array_values(array_filter($tamanhos, static fn (array $t): bool => ($t['estado'] ?? 'disponivel') !== 'oculto')));
        return $valores === [] ? [(int) ($opcao[$campo] ?? 0), (int) ($opcao[$campo] ?? 0)] : [min($valores), max($valores)];
    }

    private static function tamanho(array $t, int $preco): array
    {
        return ['id' => $t['id'], 'nome' => $t['nome'], 'sigla' => $t['sigla'], 'descricao' => $t['descricao'], 'preco_centavos' => $preco];
    }

    private static function resumo(array $i): array
    {
        return ['id' => $i['id'], 'nome' => $i['nome'], 'preco_extra_centavos' => $i['preco_extra_centavos'], 'quantidade' => $i['quantidade'] ?? 1];
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
