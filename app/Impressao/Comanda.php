<?php
declare(strict_types=1);

namespace App\Impressao;

use App\Core\Relogio;

/**
 * A comanda como uma lista de linhas, para o agente de impressão desenhar (D80). É o mesmo conteúdo da comanda do navegador
 * (public/assets/js/painel/impressao.js), mas sem HTML: o agente não abre navegador nenhum, só escreve texto na impressora.
 * Cada linha: {"t": texto, "e": estilo, "a": alinhamento, "d": texto à direita (opcional)}.
 *   estilos: loja | numero | caixa (tipo do pedido, em destaque) | destaque | negrito | normal | pequeno | linha (separador) | espaco
 *   alinhamento: e (esquerda, padrão) | c (centro)
 */
final class Comanda
{
    private const TIPOS = ['entrega' => 'Entrega', 'retirada' => 'Retirada', 'local' => 'Mesa', 'viagem' => 'Pra viagem'];
    private const ORIGENS = ['cardapio' => 'Cardápio', 'whatsapp' => 'WhatsApp', 'balcao' => 'Salão', 'ifood' => 'iFood'];
    private const PAGAMENTOS = ['pix' => 'Pix', 'cartao' => 'Cartão', 'dinheiro' => 'Dinheiro', 'caixa' => 'Na conta'];

    /**
     * @param array<string,mixed> $pedido como o PedidoRepository devolve (com itens e, se do iFood, o bloco "ifood")
     * @param array{nome_loja:string, com_preco:bool, reimpressao?:bool} $opcoes
     * @return list<array{t:string, e:string, a?:string, d?:string}>
     */
    public static function linhas(array $pedido, array $opcoes): array
    {
        $l = [];
        $ifood = is_array($pedido['ifood'] ?? null) ? $pedido['ifood'] : null;
        $comPreco = $opcoes['com_preco'];

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
        foreach ($pedido['itens'] as $item) {
            $linha = ['t' => $item['quantidade'] . 'x ' . $item['nome'], 'e' => 'destaque'];
            if ($comPreco) {
                $linha['d'] = self::reais($item['preco_unit_centavos'] * $item['quantidade']);
            }
            $l[] = $linha;
            $m = $item['montagem'];
            if (is_array($m)) {
                $nomes = static fn (array $lista): string => implode(', ', array_map(static fn (array $i): string => (string) $i['nome'], $lista));
                $l[] = ['t' => '  ' . $m['tamanho']['nome'] . (!empty($m['tamanho']['descricao']) ? ' (' . $m['tamanho']['descricao'] . ')' : ''), 'e' => 'normal'];
                $l[] = ['t' => '  Massa: ' . $m['massa']['nome'], 'e' => 'negrito'];
                $l[] = ['t' => '  Molho: ' . $m['molho']['nome'], 'e' => 'negrito'];
                if (!empty($m['inclusos'])) {
                    $l[] = ['t' => '  Ingredientes: ' . $nomes($m['inclusos']), 'e' => 'normal'];
                }
                if (!empty($m['extras'])) {
                    $l[] = ['t' => '  EXTRAS: ' . $nomes($m['extras']), 'e' => 'negrito'];
                }
                if (empty($m['inclusos']) && empty($m['extras'])) {
                    $l[] = ['t' => '  Sem ingredientes', 'e' => 'normal'];
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

        // ---- Valores e pagamento ----
        if ($comPreco) {
            $l[] = ['t' => '', 'e' => 'linha'];
            if ((int) $pedido['taxa_entrega_centavos'] > 0) {
                $l[] = ['t' => 'Taxa de entrega', 'e' => 'normal', 'd' => self::reais((int) $pedido['taxa_entrega_centavos'])];
            }
            $l[] = ['t' => 'TOTAL', 'e' => 'destaque', 'd' => self::reais((int) $pedido['total_centavos'])];
            if ($ifood === null) {
                $troco = $pedido['pagamento'] === 'dinheiro' && $pedido['troco_para_centavos'] ? ' · troco para ' . self::reais((int) $pedido['troco_para_centavos']) : '';
                $l[] = ['t' => (self::PAGAMENTOS[$pedido['pagamento']] ?? (string) $pedido['pagamento']) . $troco, 'e' => 'normal'];
            }
        }
        // No iFood, "pago ou cobrar" sai sempre, mesmo na comanda sem preço: é o que evita cobrar duas vezes na porta.
        if ($ifood !== null) {
            if (!$comPreco) {
                $l[] = ['t' => '', 'e' => 'linha'];
            }
            $l[] = ['t' => $ifood['pago_online'] ? 'PAGO ONLINE - NÃO COBRAR' : 'COBRAR ' . self::reais((int) $pedido['total_centavos']), 'e' => 'caixa', 'a' => 'c'];
            $l[] = ['t' => (string) $ifood['pagamento_texto'], 'e' => 'pequeno', 'a' => 'c'];
        }
        return $l;
    }

    /** Comanda de teste (botão da tela Impressora): um pedido de exemplo com as partes que mais ocupam papel. */
    public static function teste(array $opcoes): array
    {
        $pedido = [
            'id' => 0, 'tipo' => 'entrega', 'mesa' => null, 'origem' => 'cardapio', 'criado_em' => Relogio::iso(Relogio::agora()),
            'cliente_nome' => 'Comanda de teste', 'cliente_telefone' => '5511912345678',
            'endereco_rua' => 'Rua de Exemplo', 'endereco_numero' => '100', 'endereco_bairro' => 'Centro', 'endereco_complemento' => 'Apto 12', 'endereco_referencia' => '',
            'observacoes' => 'Tocar o interfone', 'pagamento' => 'dinheiro', 'troco_para_centavos' => 10000, 'taxa_entrega_centavos' => 600, 'total_centavos' => 5990,
            'itens' => [
                ['nome' => 'Monte seu prato', 'quantidade' => 1, 'preco_unit_centavos' => 4690, 'observacoes' => 'Molho à parte', 'montagem' => [
                    'tamanho' => ['nome' => 'Inteiro', 'descricao' => 'serve 2 pessoas · 550 g'], 'massa' => ['nome' => 'Penne'], 'molho' => ['nome' => 'Bolonhesa'],
                    'inclusos' => [['nome' => 'Picadinho de frango'], ['nome' => 'Bacon'], ['nome' => 'Muçarela']], 'extras' => [['nome' => 'Brócolis']],
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
