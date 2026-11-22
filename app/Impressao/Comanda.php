<?php
declare(strict_types=1);

namespace App\Impressao;

use App\Core\Relogio;
use App\Repositories\CardapioRepository;
use App\Services\PedidoService;

/**
 * A comanda como uma lista de linhas, para o agente de impressão desenhar (D80). É o mesmo conteúdo da comanda do navegador
 * (public/assets/js/painel/impressao.js), mas sem HTML: o agente não abre navegador nenhum, só escreve texto na impressora.
 * Cada linha: {"t": texto, "e": estilo, "a": alinhamento, "d": texto à direita (opcional)}.
 *   estilos: loja | numero | caixa (tipo do pedido, em destaque) | destaque | negrito | normal | pequeno | linha (separador) | espaco
 *   alinhamento: e (esquerda, padrão) | c (centro)
 *
 * Prato montado (D95, enxugado no D112): uma linha por seção, na ordem do panfleto (Tamanho, Massa, Ingredientes por grupo, Molho),
 * só com o que foi escolhido ("Massa: Nhoque", "Acompanhamentos: Milho, Brócolis"). Seção sem escolha não sai.
 * Entre um prato e outro, um traço (estilo linha).
 *
 * D111: depois dos pratos, antes do pagamento, o "RESUMO DO PEDIDO": uma linha por item ("1x Nhoque (Meio)", "2x Coca-Cola lata"),
 * com o valor (se a comanda leva preço), a taxa e o total. Dinheiro sai com "troco para R$ ..." ou "sem troco".
 */
final class Comanda
{
    private const TIPOS = ['entrega' => 'Entrega', 'retirada' => 'Retirada', 'local' => 'Mesa', 'viagem' => 'Pra viagem'];
    private const ORIGENS = ['cardapio' => 'Cardápio', 'whatsapp' => 'WhatsApp', 'balcao' => 'Salão', 'ifood' => 'iFood'];
    private const PAGAMENTOS = ['pix' => 'Pix', 'cartao' => 'Cartão', 'dinheiro' => 'Dinheiro', 'caixa' => 'Na conta'];

    /**
     * @param array<string,mixed> $pedido como o PedidoRepository devolve (com itens e, se do iFood, o bloco "ifood")
     * @param array{nome_loja:string, com_preco:bool, reimpressao?:bool} $opcoes
     * @return list<array{t:string, e:string, a?:string, d?:string, c?:string, n?:string}>
     */
    public static function linhas(array $pedido, array $opcoes): array
    {
        $l = [];
        $ifood = is_array($pedido['ifood'] ?? null) ? $pedido['ifood'] : null;
        $comPreco = $opcoes['com_preco'];
        $catalogo = null;

        $l[] = ['t' => (string) $opcoes['nome_loja'], 'e' => 'loja', 'a' => 'c'];
        $l[] = ['t' => '#' . $pedido['id'], 'e' => 'numero', 'a' => 'c'];
        if (!empty($opcoes['reimpressao'])) {   // no topo: no fim, numa etiqueta, ela pode cair sozinha numa etiqueta nova
            $l[] = ['t' => 'REIMPRESSÃO · ' . self::hora(gmdate('Y-m-d\TH:i:s\Z')), 'e' => 'pequeno', 'a' => 'c'];
        }
        if ($ifood !== null) {
            $l[] = ['t' => 'iFood nº ' . $ifood['display_id'] . ($ifood['simulado'] ? ' (TESTE)' : ''), 'e' => 'destaque', 'a' => 'c'];
        }
        $tipo = $pedido['tipo'] === 'local' && $pedido['mesa'] ? 'Mesa ' . $pedido['mesa'] : (self::TIPOS[$pedido['tipo']] ?? $pedido['tipo']);
        $l[] = ['t' => mb_strtoupper($tipo), 'e' => 'caixa', 'a' => 'c'];
        if ($ifood !== null && $ifood['agendado_para']) {
            $l[] = ['t' => 'AGENDADO PARA ' . self::dataHora($ifood['agendado_para']), 'e' => 'destaque', 'a' => 'c'];
        }
        $l[] = ['t' => self::dataHora((string) $pedido['criado_em']), 'e' => 'pequeno', 'd' => self::ORIGENS[$pedido['origem']] ?? (string) $pedido['origem']];

        // ---- Cliente e endereço ----
        $l[] = ['t' => '', 'e' => 'linha'];
        $l[] = ['t' => (string) $pedido['cliente_nome'], 'e' => 'negrito'];
        if ($ifood !== null && $ifood['telefone'] !== '') {
            $l[] = ['t' => $ifood['telefone'] . ($ifood['localizador'] !== '' ? ' · localizador ' . $ifood['localizador'] : ''), 'e' => 'normal'];
        } elseif (($pedido['cliente_telefone'] ?? '') !== '') {
            $l[] = ['t' => self::telefone((string) $pedido['cliente_telefone']), 'e' => 'normal'];
        }
        if ($pedido['tipo'] === 'entrega') {
            $l[] = ['t' => trim($pedido['endereco_rua'] . ', ' . $pedido['endereco_numero'] . ($pedido['endereco_complemento'] ? ' · ' . $pedido['endereco_complemento'] : ''), ', '), 'e' => 'normal'];
            if ($pedido['endereco_bairro']) {
                $l[] = ['t' => (string) $pedido['endereco_bairro'], 'e' => 'normal'];
            }
            if ($pedido['endereco_referencia']) {
                $l[] = ['t' => 'Ref.: ' . $pedido['endereco_referencia'], 'e' => 'normal'];
            }
        }
        if ($ifood !== null) {
            if ($pedido['tipo'] === 'entrega') {
                $l[] = ['t' => ($ifood['entregue_por'] ?? '') === 'IFOOD' ? 'Entregador do iFood' : 'Entrega da loja', 'e' => 'negrito'];
            }
            if ($ifood['codigo_coleta'] !== '') {
                $l[] = ['t' => 'Código de coleta: ' . $ifood['codigo_coleta'], 'e' => 'negrito'];
            }
        }

        // ---- Itens ----
        $l[] = ['t' => '', 'e' => 'linha'];
        foreach ($pedido['itens'] as $n => $item) {
            if ($n > 0) {
                $l[] = ['t' => '', 'e' => 'linha'];   // D112: um traço separa um prato do outro
            }
            $l[] = ['t' => $item['quantidade'] . 'x ' . $item['nome'], 'e' => 'destaque'];   // o valor vai no resumo (D111)
            $m = $item['montagem'];
            if (is_array($m)) {
                $catalogo ??= (new CardapioRepository())->publico();
                // D112/D115: só o que foi escolhido. Uma escolha: na mesma linha ("Massa: Nhoque"); mais de uma: em lista,
                // um item por linha ("Acompanhamentos:" e embaixo "- Milho", "- Brócolis").
                foreach ($item['checklist'] ?? self::checklist($m, $catalogo) as $secao) {
                    $nomes = array_column($secao['opcoes'], 'nome');
                    if (count($nomes) === 1) {
                        $l[] = ['t' => $secao['titulo'] . ': ' . $nomes[0], 'e' => 'negrito'];
                        continue;
                    }
                    $l[] = ['t' => $secao['titulo'] . ':', 'e' => 'negrito'];
                    foreach ($nomes as $nome) {
                        $l[] = ['t' => '- ' . $nome, 'e' => 'negrito'];
                    }
                }
            }
            if (($item['observacoes'] ?? '') !== '') {
                $l[] = ['t' => '  ' . ($ifood !== null ? '' : 'OBS: ') . $item['observacoes'], 'e' => 'negrito'];
            }
        }
        if (($pedido['observacoes'] ?? '') !== '') {
            $l[] = ['t' => '', 'e' => 'linha'];
            $l[] = ['t' => 'OBS DO PEDIDO: ' . $pedido['observacoes'], 'e' => 'negrito'];
        }

        // ---- Resumo do pedido (D111) e valores ----
        $l[] = ['t' => '', 'e' => 'linha'];
        $l[] = ['t' => 'RESUMO DO PEDIDO', 'e' => 'negrito', 'a' => 'c'];
        foreach ($pedido['itens'] as $item) {
            $linha = ['t' => $item['quantidade'] . 'x ' . self::nomeNoResumo($item), 'e' => 'normal'];
            if ($comPreco) {
                $linha['d'] = self::reais($item['preco_unit_centavos'] * $item['quantidade']);
            }
            $l[] = $linha;
        }
        // D118: o total sai sempre no resumo (mesmo na comanda sem preço por item). No iFood, quem manda é o "pago ou cobrar".
        if ($comPreco || $ifood === null) {
            $l[] = ['t' => '', 'e' => 'espaco'];
            if ((int) $pedido['taxa_entrega_centavos'] > 0) {
                $l[] = ['t' => 'Taxa de entrega', 'e' => 'normal', 'd' => self::reais((int) $pedido['taxa_entrega_centavos'])];
            } elseif (str_starts_with((string) ($pedido['observacoes'] ?? ''), PedidoService::TAXA_A_CONFIRMAR)) {
                $l[] = ['t' => 'Taxa de entrega', 'e' => 'normal', 'd' => 'a confirmar'];
            }
            $l[] = ['t' => 'TOTAL', 'e' => 'destaque', 'd' => self::reais((int) $pedido['total_centavos'])];
        }
        // A forma de pagamento sai sempre, num quadro (D97): quem entrega ou serve precisa saber se leva a maquininha ou o troco.
        if ($ifood === null) {
            $l[] = ['t' => '', 'e' => 'espaco'];
            $l[] = ['t' => self::pagamentoEmDestaque($pedido), 'e' => 'caixa', 'a' => 'c'];
        }
        // No iFood, "pago ou cobrar" sai sempre, mesmo na comanda sem preço: é o que evita cobrar duas vezes na porta.
        if ($ifood !== null) {
            if (!$comPreco) {
                $l[] = ['t' => '', 'e' => 'linha'];
            }
            $aCobrar = (int) ($ifood['a_cobrar_centavos'] ?? $pedido['total_centavos']);
            $l[] = ['t' => $ifood['pago_online'] ? 'PAGO ONLINE - NÃO COBRAR' : 'COBRAR ' . self::reais($aCobrar), 'e' => 'caixa', 'a' => 'c'];
            $l[] = ['t' => (string) $ifood['pagamento_texto'], 'e' => 'pequeno', 'a' => 'c'];
            // Cupom e quem paga (iFood ou loja): exigência da homologação do iFood.
            foreach ($ifood['cupons'] ?? [] as $cupom) {
                $l[] = ['t' => (string) $cupom, 'e' => 'pequeno', 'a' => 'c'];
            }
        }
        return $l;
    }

    /**
     * A lista de marcar de um prato montado: todas as opções do cardápio, a escolhida com marcada=true.
     * Ordem do panfleto da cozinha: tamanho, massa, ingredientes (um bloco por grupo; os grupos "Tempero..." por último) e molho.
     * A escolha é achada pelo id e, na falta dele, pelo nome. O que foi pedido mas não está mais no cardápio (apagado ou oculto
     * depois do pedido) entra no fim da seção, marcado: a cozinha nunca deixa de ver o que o cliente pediu.
     * @param array<string,mixed> $m a montagem gravada no item do pedido
     * @param array<string,mixed>|null $catalogo CardapioRepository::publico()
     * @return list<array{titulo:string, opcoes:list<array{nome:string, marcada:bool}>}>
     */
    public static function checklist(array $m, ?array $catalogo = null): array
    {
        $catalogo ??= (new CardapioRepository())->publico();
        // A mesma opção: mesmo id ou, na falta dele, mesmo nome.
        $igual = static fn (array $escolhido, array $opcao): bool => (isset($escolhido['id']) && $escolhido['id'] === $opcao['id'])
            || mb_strtolower(trim((string) $escolhido['nome'])) === mb_strtolower(trim((string) $opcao['nome']));
        $secao = static function (string $titulo, array $lista, array $escolhidos) use ($igual): array {
            $opcoes = [];
            // D117: mais de uma porção sai "2x Calabresa".
            $porcoes = static fn (array $e, string $nome): string => (int) ($e['quantidade'] ?? 1) > 1 ? ((int) $e['quantidade']) . 'x ' . $nome : $nome;
            foreach ($lista as $o) {
                $achado = array_values(array_filter($escolhidos, static fn (array $e): bool => $igual($e, $o)));
                $opcoes[] = ['nome' => $achado !== [] ? $porcoes($achado[0], (string) $o['nome']) : (string) $o['nome'], 'marcada' => $achado !== []];
            }
            foreach ($escolhidos as $e) {
                if (array_filter($lista, static fn (array $o): bool => $igual($e, $o)) === []) {
                    $opcoes[] = ['nome' => $porcoes($e, (string) $e['nome']), 'marcada' => true];
                }
            }
            // D112: a comanda leva só o que foi escolhido (a lista inteira deixava o papel gigante).
            return ['titulo' => $titulo, 'opcoes' => array_values(array_filter($opcoes, static fn (array $o): bool => $o['marcada']))];
        };
        $um = static fn (mixed $v): array => is_array($v) && isset($v['nome']) ? [$v] : [];
        $visiveis = static fn (array $l): array => array_values(array_filter($l, static fn (array $o): bool => ($o['estado'] ?? 'disponivel') !== 'oculto'));

        $secoes = [
            $secao('Tamanho', $visiveis($catalogo['tamanhos'] ?? []), $um($m['tamanho'] ?? null)),
            $secao('Massa', $visiveis($catalogo['massas'] ?? []), $um($m['massa'] ?? null)),
        ];
        $escolhidos = array_merge($m['inclusos'] ?? [], $m['extras'] ?? []);
        $grupos = [];
        foreach ($visiveis($catalogo['ingredientes'] ?? []) as $i) {
            $grupos[(string) $i['grupo']][] = $i;
        }
        $ehTempero = static fn (string $g): bool => str_starts_with(mb_strtolower(trim($g)), 'tempero');
        $ordem = array_merge(array_filter(array_keys($grupos), static fn (string $g): bool => !$ehTempero($g)), array_filter(array_keys($grupos), $ehTempero));
        $restantes = $escolhidos;   // o que não caiu em nenhum grupo (apagado ou oculto depois do pedido) vai para "Outros"
        foreach ($ordem as $grupo) {
            $doGrupo = array_values(array_filter($escolhidos, static fn (array $e): bool => array_filter($grupos[$grupo], static fn (array $o): bool => $igual($e, $o)) !== []));
            $restantes = array_values(array_filter($restantes, static fn (array $e): bool => array_filter($grupos[$grupo], static fn (array $o): bool => $igual($e, $o)) === []));
            $secoes[] = $secao($grupo, $grupos[$grupo], $doGrupo);
        }
        if ($restantes !== []) {
            $secoes[] = $secao('Outros', [], $restantes);
        }
        $secoes[] = $secao('Molho', $visiveis($catalogo['molhos'] ?? []), isset($m['molhos']) && is_array($m['molhos']) ? $m['molhos'] : $um($m['molho'] ?? null));   // D110: mais de um molho
        return array_values(array_filter($secoes, static fn (array $s): bool => $s['opcoes'] !== []));   // seção sem escolha não sai
    }

    /** Põe a lista de marcar em cada prato montado do pedido (para a comanda impressa pelo navegador, que não conhece o cardápio). */
    public static function comChecklist(?array $pedido): ?array
    {
        if ($pedido === null) {
            return null;
        }
        $catalogo = null;
        foreach ($pedido['itens'] ?? [] as $i => $item) {
            if (is_array($item['montagem'] ?? null)) {
                $catalogo ??= (new CardapioRepository())->publico();
                $pedido['itens'][$i]['checklist'] = self::checklist($item['montagem'], $catalogo);
            }
            $pedido['itens'][$i]['nome_resumo'] = self::nomeNoResumo($item);
        }
        return $pedido;
    }

    /** Comanda de teste (botão da tela Impressora): um pedido de exemplo com as partes que mais ocupam papel. */
    public static function teste(array $opcoes): array
    {
        $pedido = [
            'id' => 0, 'tipo' => 'entrega', 'mesa' => null, 'origem' => 'cardapio', 'criado_em' => Relogio::iso(Relogio::agora()),
            'cliente_nome' => 'Comanda de teste', 'cliente_telefone' => '5511912345678',
            'endereco_rua' => 'Rua de Exemplo', 'endereco_numero' => '100', 'endereco_bairro' => 'Centro', 'endereco_complemento' => 'Apto 12', 'endereco_referencia' => '',
            'observacoes' => 'Tocar o interfone', 'pagamento' => 'dinheiro', 'troco_para_centavos' => 10000, 'taxa_entrega_centavos' => 600, 'total_centavos' => 8980,
            'itens' => [
                ['nome' => 'Monte seu prato', 'quantidade' => 1, 'preco_unit_centavos' => 4690, 'observacoes' => 'Molho à parte', 'montagem' => [
                    'tamanho' => ['nome' => 'Inteiro', 'descricao' => 'serve 2 pessoas · 550 g'], 'massa' => ['nome' => 'Penne'], 'molho' => ['nome' => 'Bolonhesa'],
                    'inclusos' => [['nome' => 'Picadinho de frango'], ['nome' => 'Bacon'], ['nome' => 'Muçarela']], 'extras' => [['nome' => 'Brócolis']],
                ]],
                ['nome' => 'Lasanha da casa', 'item_id' => 5, 'quantidade' => 1, 'preco_unit_centavos' => 2990, 'observacoes' => '', 'montagem' => [
                    'tamanho' => ['nome' => 'Meio'], 'massa' => ['nome' => 'Lasanha'], 'molho' => ['nome' => 'Bolonhesa'], 'inclusos' => [], 'extras' => [],
                ]],
                ['nome' => 'Refrigerante lata', 'quantidade' => 2, 'preco_unit_centavos' => 650, 'observacoes' => '', 'montagem' => null],
            ],
        ];
        $linhas = self::linhas($pedido, $opcoes);
        $linhas[1] = ['t' => 'TESTE', 'e' => 'numero', 'a' => 'c'];
        $linhas[] = ['t' => '', 'e' => 'espaco'];
        $linhas[] = ['t' => 'Acentos: ação, pão, café, açúcar, maçã', 'e' => 'pequeno', 'a' => 'c'];
        return $linhas;
    }

    /** "PAGAMENTO: PIX", "PAGAMENTO: DINHEIRO, TROCO PARA R$ 100,00", "PAGAMENTO NA MESA" (D112; salão pra viagem: "NA CONTA"). */
    public static function pagamentoEmDestaque(array $pedido): string
    {
        if ($pedido['pagamento'] === 'caixa') {
            return ($pedido['tipo'] ?? 'local') === 'local' ? 'PAGAMENTO NA MESA' : 'PAGAMENTO NA CONTA';
        }
        $forma = mb_strtoupper(self::PAGAMENTOS[$pedido['pagamento']] ?? (string) $pedido['pagamento']);
        $troco = '';
        if ($pedido['pagamento'] === 'dinheiro') {
            $troco = $pedido['troco_para_centavos'] ? ', TROCO PARA ' . self::reais((int) $pedido['troco_para_centavos']) : ', SEM TROCO';
        }
        return "PAGAMENTO: {$forma}{$troco}";
    }

    /**
     * O item numa linha curta, para o resumo (D111). Monte seu prato: a massa e o tamanho ("Nhoque (Meio)"); prato da casa: o nome e o
     * tamanho ("Lasanha (Inteiro)"); bebida e sobremesa: o nome.
     */
    public static function nomeNoResumo(array $item): string
    {
        $m = $item['montagem'] ?? null;
        if (!is_array($m)) {
            return (string) $item['nome'];
        }
        $tamanho = trim((string) ($m['tamanho']['nome'] ?? ''));
        $base = empty($item['item_id']) && !empty($m['massa']['nome']) ? (string) $m['massa']['nome'] : (string) $item['nome'];
        return $base . ($tamanho !== '' ? " ({$tamanho})" : '');
    }

    private static function reais(int $centavos): string
    {
        return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
    }

    /** "16/11 19:42" no fuso da loja. */
    private static function dataHora(string $iso): string
    {
        $data = new \DateTimeImmutable($iso);
        return $data->setTimezone(Relogio::fusoDaLoja())->format('d/m H:i');
    }

    private static function hora(string $iso): string
    {
        return (new \DateTimeImmutable($iso))->setTimezone(Relogio::fusoDaLoja())->format('H:i');
    }

    /** 5511912345678 → (11) 91234-5678 */
    private static function telefone(string $digitos): string
    {
        $local = str_starts_with($digitos, '55') && strlen($digitos) >= 12 ? substr($digitos, 2) : $digitos;
        if (preg_match('/^(\d{2})(\d{4,5})(\d{4})$/', $local, $m) === 1) {
            return "({$m[1]}) {$m[2]}-{$m[3]}";
        }
        return $digitos;
    }
}
