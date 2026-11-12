<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ErroHttp;

/**
 * Regra de preço do "Monte seu prato". É a ÚNICA fonte do preço que vale:
 * o navegador calcula igual (public/assets/js/shared/preco.js) só para mostrar,
 * mas o servidor sempre recalcula aqui antes de gravar o pedido.
 *
 *   preço = tamanho + acréscimo da massa + acréscimo do molho + extras
 *   extras = ingredientes que passam da quantidade inclusa no tamanho.
 *   Quando passa, os MAIS CAROS entram como inclusos e os mais baratos são cobrados
 *   (o resultado não depende da ordem em que o cliente clicou).
 */
final class PrecoService
{
    /**
     * @param array $cardapio resultado de CardapioRepository::publico()
     * @param array{tamanho_id?:mixed, massa_id?:mixed, molho_id?:mixed, ingrediente_ids?:mixed, queijo_ralado?:mixed} $escolha
     * @return array{preco_centavos:int, montagem:array}
     */
    public function montar(array $cardapio, array $escolha): array
    {
        $tamanho = $this->disponivel($cardapio['tamanhos'], $escolha['tamanho_id'] ?? null, 'tamanho');
        $massa   = $this->disponivel($cardapio['massas'], $escolha['massa_id'] ?? null, 'massa');
        $molho   = $this->disponivel($cardapio['molhos'], $escolha['molho_id'] ?? null, 'molho');

        $ids = $escolha['ingrediente_ids'] ?? [];
        if (!is_array($ids) || count($ids) > 30) {
            throw new ErroHttp(422, 'montagem_invalida', 'A lista de ingredientes do prato não é válida.');
        }
        $ingredientes = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $ingredientes[] = $this->disponivel($cardapio['ingredientes'], $id, 'ingrediente');
        }

        // Mais caros primeiro; empate decide pela ordem do cardápio. Os N primeiros são os inclusos.
        usort($ingredientes, static fn (array $a, array $b): int =>
            [$b['preco_extra_centavos'], $a['ordem'], $a['id']] <=> [$a['preco_extra_centavos'], $b['ordem'], $b['id']]);
        $inclusos = array_slice($ingredientes, 0, $tamanho['inclusos']);
        $extras   = array_slice($ingredientes, $tamanho['inclusos']);
        $valorExtras = array_sum(array_column($extras, 'preco_extra_centavos'));

        $preco = $tamanho['preco_centavos'] + $massa['acrescimo_centavos'] + $molho['acrescimo_centavos'] + $valorExtras;

        $resumo = static fn (array $i): array => ['id' => $i['id'], 'nome' => $i['nome'], 'preco_extra_centavos' => $i['preco_extra_centavos']];
        return [
            'preco_centavos' => $preco,
            'montagem' => [
                'tamanho' => ['id' => $tamanho['id'], 'nome' => $tamanho['nome'], 'sigla' => $tamanho['sigla'], 'descricao' => $tamanho['descricao'],
                              'preco_centavos' => $tamanho['preco_centavos'], 'inclusos' => $tamanho['inclusos']],
                'massa'   => ['id' => $massa['id'], 'nome' => $massa['nome'], 'acrescimo_centavos' => $massa['acrescimo_centavos']],
                'molho'   => ['id' => $molho['id'], 'nome' => $molho['nome'], 'acrescimo_centavos' => $molho['acrescimo_centavos']],
                'inclusos' => array_map($resumo, $inclusos),
                'extras'   => array_map($resumo, $extras),
                'queijo_ralado' => !empty($escolha['queijo_ralado']),
            ],
        ];
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
