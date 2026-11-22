<?php
declare(strict_types=1);

namespace App\IA;

use App\Core\Banco;
use App\Core\ErroHttp;
use App\Core\Relogio;
use App\Repositories\ConfigRepository;
use App\Services\Geocodificador;
use App\Services\LojaService;
use App\Services\PedidoService;
use App\Services\TaxaDeEntrega;
use App\Services\TokenWhatsApp;
use App\WhatsApp\FluxoPedido;
use App\WhatsApp\WhatsApp;

/**
 * Pedido pelo WhatsApp conduzido pela IA (D106). O cliente escreve do jeito dele ("2 carbonara média e uma coca, entrega na
 * rua X") e a IA monta o pedido com ferramentas. Quem decide é sempre o servidor:
 *   - cada item é conferido no cardápio e o preço é o do PedidoService (o mesmo do cardápio digital); a IA nunca soma valores;
 *   - o resumo com o total é escrito pelo servidor, não pela IA, e vai numa mensagem separada;
 *   - o pedido só é criado se o cliente confirmou o resumo E nada mudou desde ele (impressão digital do carrinho).
 * O carrinho fica em whatsapp_sessoes com a etapa "ia" (o passo a passo ignora essas linhas). Se a IA falhar, quem chamou
 * (o Bot) segue pelo passo a passo com enquete: o cliente nunca fica sem resposta.
 */
final class PedidoPorIa
{
    public const ETAPA = 'ia';
    public const VALIDADE_SEGUNDOS = 3600;   // 1 h parado: o carrinho some
    public const LIMITE_POR_HORA = 40;       // respostas por telefone por hora (um pedido troca bem mais mensagens que uma dúvida)
    private const RODADAS = 6;
    private const RESUMO_VALE_SEGUNDOS = 1800;
    private const PAGAMENTOS = ['pix' => 'Pix', 'cartao' => 'Cartão', 'dinheiro' => 'Dinheiro'];

    /** Regras que entram no prompt quando a IA faz o pedido (no lugar de "você não monta pedido"). */
    public const REGRAS = "- Você MONTA O PEDIDO do cliente pela conversa, com as ferramentas. Quem calcula preço e total é o sistema: nunca some valores nem diga um total que não veio de uma ferramenta.\n"
        . "- Se o cliente já mandar o pedido na primeira mensagem, coloque tudo de uma vez (adicionar_ao_pedido aceita vários itens) e pergunte só o que faltar.\n"
        . "- Use só os códigos do CARDÁPIO. Se o cliente pedir algo que não existe, ou estiver em dúvida entre opções, pergunte em vez de escolher por ele. Prato da casa precisa do tamanho; o \"Monte sua massa\" precisa de tamanho, uma massa e um ou mais molhos (o cliente pode misturar molhos; cada um soma o seu valor).\n"
        . "- Para fechar, o pedido precisa de: itens, entrega ou retirada, nome e forma de pagamento (e endereço, se for entrega). Pergunte o que faltar, uma ou duas coisas por vez.\n"
        . "- Entrega: peça a localização pelo WhatsApp (clipe 📎 > Localização) ou o endereço com rua, número e bairro. Dinheiro: pergunte se precisa de troco e para quanto.\n"
        . "- Com tudo completo, chame mostrar_resumo: o sistema manda o resumo com o total logo depois da sua mensagem (não repita os itens nem o total). Só chame confirmar_pedido quando o cliente responder que confirma DEPOIS de ver o resumo. Se ele mudar algo, ajuste e mostre o resumo de novo.\n"
        . "- Não pergunte o telefone: o sistema já tem o número desta conversa.\n";

    public function __construct(
        private readonly Assistente $assistente = new Assistente(),
        private readonly PedidoService $pedidos = new PedidoService(),
        private readonly ConfigRepository $config = new ConfigRepository(),
        private readonly LojaService $loja = new LojaService(),
        private ?FluxoPedido $fluxo = null,
    ) {}

    /** O dono escolheu "a IA faz o pedido" (tela Assistente). Não diz se a IA está disponível agora: isso é Assistente::indisponivel. */
    public function ligado(): bool
    {
        return $this->config->get('ia_pedido', '1') === '1';
    }

    /**
     * Uma mensagem do cliente. @param array{telefone:string, texto:string, nome:?string, local?:?array{lat:float, lng:float}} $m
     * @return string|null o que aconteceu (ia | pedido_criado), ou null se a IA falhou (o Bot segue pelo passo a passo)
     */
    public function receber(array $m): ?string
    {
        $telefone = $m['telefone'];
        $trava = $this->travar($telefone);   // duas mensagens seguidas do mesmo cliente não mexem no carrinho ao mesmo tempo
        try {
            $s = $this->carregar($telefone) ?? ['carrinho' => [], 'nome_whatsapp' => $m['nome'] !== null ? trim($m['nome']) : null];
            $texto = trim($m['texto']);
            if (($m['local'] ?? null) !== null) {
                $texto = trim($texto . "\n" . $this->recebeuLocal($s, $m['local']));
            }
            if ($texto === '') {
                return 'ia';
            }
            $s['enviar_resumo'] = false;
            $s['resultado'] = 'ia';
            $r = $this->assistente->responder($telefone, $texto, $m['nome'], [
                'pedido' => true,
                'memoria' => 14,
                'rodadas' => self::RODADAS,
                'contexto' => $this->situacao($s),
                'ferramentas' => self::definicoes(),
                'executor' => function (string $nome, array $args) use (&$s, $telefone): ?string {
                    return $this->executar($nome, is_array($args) ? $args : [], $s, $telefone);
                },
            ]);
            if (!$r['ok'] && $s['resultado'] === 'pedido_criado') {
                $this->fluxo()->confirmar($telefone, (int) $s['pedido_id']);   // o pedido saiu; só a frase final da IA falhou
                return 'pedido_criado';
            }
            if (!$r['ok']) {
                error_log('[ia-pedido] sem resposta da IA: ' . $r['erro']);
                $this->salvar($telefone, $s);   // o que a IA já colocou no carrinho não se perde
                return null;
            }
            WhatsApp::enviar($telefone, WhatsApp::texto($r['texto']), 'ia_pedido');
            if ($s['resultado'] === 'pedido_criado') {
                $this->fluxo()->confirmar($telefone, (int) $s['pedido_id']);
                return 'pedido_criado';
            }
            if ($s['enviar_resumo']) {
                WhatsApp::enviar($telefone, WhatsApp::texto($this->resumo($s)), 'ia_resumo');
            }
            unset($s['enviar_resumo'], $s['resultado']);
            $this->salvar($telefone, $s);
            return 'ia';
        } finally {
            $this->destravar($trava);
        }
    }

    // ---------- Ferramentas ----------

    /** @return list<array<string,mixed>> as de pedido mais as de sempre (status, atendente, link do cardápio) */
    public static function definicoes(): array
    {
        $inteiro = ['type' => 'integer'];
        $texto = ['type' => 'string'];
        $nada = ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
        $f = static fn (string $nome, string $descricao, array $parametros): array => ['type' => 'function', 'function' => ['name' => $nome, 'description' => $descricao, 'parameters' => $parametros]];
        return [
            $f('adicionar_ao_pedido', 'Coloca um ou mais itens no pedido. Para bebida/sobremesa: item_id e quantidade. Para prato da casa: item_id, tamanho_id e quantidade. Para "Monte sua massa": sem item_id, com tamanho_id, massa_id, molho_ids (um ou mais molhos), ingrediente_ids e quantidade. Devolve o pedido com os preços do sistema.', [
                'type' => 'object',
                'properties' => ['itens' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'item_id' => $inteiro, 'tamanho_id' => $inteiro, 'massa_id' => $inteiro, 'molho_ids' => ['type' => 'array', 'items' => $inteiro],
                    'ingrediente_ids' => ['type' => 'array', 'items' => $inteiro], 'quantidade' => $inteiro,
                    'observacao' => ['type' => 'string', 'description' => 'Pedido do cliente para este item, como "sem cebola". Opcional.'],
                ], 'required' => ['quantidade']]]],
                'required' => ['itens'],
            ]),
            $f('remover_do_pedido', 'Tira um item do pedido pelo número dele na lista do pedido (1, 2, 3...). Com quantidade, tira só essa quantidade.', [
                'type' => 'object', 'properties' => ['numero' => $inteiro, 'quantidade' => $inteiro], 'required' => ['numero'],
            ]),
            $f('definir_entrega', 'Entrega ou retirada. Na entrega, informe o endereço que o cliente escreveu (rua, número, bairro; complemento e referência se houver). Se ele mandou a localização, o sistema já tem o ponto.', [
                'type' => 'object',
                'properties' => ['tipo' => ['type' => 'string', 'enum' => ['entrega', 'retirada']], 'rua' => $texto, 'numero' => $texto, 'bairro' => $texto, 'complemento' => $texto, 'referencia' => $texto],
                'required' => ['tipo'],
            ]),
            $f('definir_cliente', 'Nome do cliente e forma de pagamento. troco_para: valor em reais da nota (ex.: 100); 0 = não precisa de troco.', [
                'type' => 'object',
                'properties' => ['nome' => $texto, 'pagamento' => ['type' => 'string', 'enum' => ['pix', 'cartao', 'dinheiro']], 'troco_para' => ['type' => 'number']],
                'required' => [],
            ]),
            $f('mostrar_resumo', 'Quando o pedido estiver completo: o sistema manda ao cliente o resumo com o total para ele conferir. Diz o que falta, se faltar algo.', $nada),
            $f('confirmar_pedido', 'Cria o pedido de verdade. Só depois de o cliente ver o resumo e responder que confirma.', $nada),
            ...array_values(array_filter(Ferramentas::definicoes(), static fn (array $d): bool => $d['function']['name'] !== Ferramentas::LINK)),
            $f(Ferramentas::LINK, 'Gera o link do cardápio com fotos, para quem preferir montar o pedido por lá. Use só se o cliente pedir para ver o cardápio ou as fotos.', $nada),
        ];
    }

    /** @return string|null a resposta da ferramenta, ou null para as de sempre (status, atendente, link) */
    private function executar(string $nome, array $args, array &$s, string $telefone): ?string
    {
        try {
            return match ($nome) {
                'adicionar_ao_pedido' => $this->adicionar($s, $args),
                'remover_do_pedido' => $this->remover($s, $args),
                'definir_entrega' => $this->definirEntrega($s, $args),
                'definir_cliente' => $this->definirCliente($s, $args),
                'mostrar_resumo' => $this->mostrarResumo($s),
                'confirmar_pedido' => $this->confirmarPedido($s, $telefone),
                default => null,
            };
        } catch (ErroHttp $e) {
            return 'Não deu certo: ' . $e->getMessage() . ' Explique ao cliente e pergunte como seguir.';
        }
    }

    private function adicionar(array &$s, array $args): string
    {
        $estado = $this->loja->estado();
        if (!$estado['aberta']) {
            return "A loja está fechada agora ({$estado['mensagem']}). Não monte o pedido: avise o cliente do horário.";
        }
        $lista = is_array($args['itens'] ?? null) ? array_slice($args['itens'], 0, 20) : [];
        $linhas = [];
        foreach ($lista as $i) {
            if (!is_array($i)) {
                continue;
            }
            $payload = self::payload($i);
            try {
                $this->pedidos->validarItens(['itens' => [$payload]]);   // confere o item sozinho: o erro diz qual item tem problema
            } catch (ErroHttp $e) {
                $linhas[] = 'Item recusado (' . json_encode($i, JSON_UNESCAPED_UNICODE) . '): ' . $e->getMessage();
                continue;
            }
            $s['carrinho'][] = $payload;
            $linhas[] = 'Adicionado: ' . $payload['quantidade'] . 'x ' . $this->nomeDoItem($payload);
        }
        if ($linhas === []) {
            return 'Nenhum item informado. Pergunte ao cliente o que ele quer.';
        }
        return implode("\n", $linhas) . "\n\n" . $this->textoDoCarrinho($s);
    }

    private function remover(array &$s, array $args): string
    {
        $indice = (int) ($args['numero'] ?? 0) - 1;
        if (!isset($s['carrinho'][$indice])) {
            return 'Esse número não está no pedido. ' . $this->textoDoCarrinho($s);
        }
        $menos = (int) ($args['quantidade'] ?? 0);
        if ($menos > 0 && $menos < $s['carrinho'][$indice]['quantidade']) {
            $s['carrinho'][$indice]['quantidade'] -= $menos;
        } else {
            array_splice($s['carrinho'], $indice, 1);
        }
        return 'Pronto. ' . $this->textoDoCarrinho($s);
    }

    private function definirEntrega(array &$s, array $args): string
    {
        $tipo = (string) ($args['tipo'] ?? '');
        $aceita = ['entrega' => $this->config->get('aceita_entrega', '1') === '1', 'retirada' => $this->config->get('aceita_retirada', '1') === '1'];
        if (!($aceita[$tipo] ?? false)) {
            return 'A loja não faz ' . ($tipo === 'entrega' ? 'entrega' : 'retirada') . ' agora. Ofereça a outra opção.';
        }
        $s['tipo'] = $tipo;
        if ($tipo === 'retirada') {
            return 'Retirada no balcão anotada.';
        }
        $e = $s['endereco'] ?? [];
        foreach (['rua' => 120, 'numero' => 20, 'bairro' => 80, 'complemento' => 80, 'referencia' => 120] as $campo => $max) {
            $valor = trim((string) ($args[$campo] ?? ''));
            if ($valor !== '') {
                $e[$campo] = mb_substr($valor, 0, $max);
            }
        }
        $escreveuRua = trim((string) ($args['rua'] ?? '')) !== '';
        // Endereço escrito sem pin (ou diferente do pin): acha o ponto pelo endereço, para a taxa por distância.
        if ($escreveuRua && (empty($e['lat']) || !empty($e['aproximado'])) && ($e['rua'] ?? '') !== '' && ($e['bairro'] ?? '') !== '') {
            $ponto = Geocodificador::coordenadas("{$e['rua']}, " . ($e['numero'] ?? '') . ", {$e['bairro']}", $this->cidadeDaLoja());
            if ($ponto !== null && $this->pertoDaLoja($ponto)) {
                $e = array_merge($e, $ponto, ['aproximado' => true]);
            }
        }
        $s['endereco'] = $e;
        $faltam = array_keys(array_filter(['rua' => $e['rua'] ?? '', 'número' => $e['numero'] ?? '', 'bairro' => $e['bairro'] ?? ''], static fn (string $v): bool => $v === ''));
        $taxa = new TaxaDeEntrega($this->config);
        $r = 'Entrega anotada' . ($faltam !== [] ? '. Falta: ' . implode(', ', $faltam) . '.' : '.');
        if ($taxa->porRaio() && empty($e['lat'])) {
            return $r . ' Não achei esse endereço no mapa: peça ao cliente para mandar a localização pelo WhatsApp (clipe 📎 > Localização), que é por ela que sai a taxa.';
        }
        $calculo = $taxa->calcular($e['lat'] ?? null, $e['lng'] ?? null);
        if (!$calculo['atende']) {
            return $r . ' Esse endereço fica a ' . TaxaDeEntrega::kmTexto((float) $calculo['km']) . ' da loja, fora da área de entrega. Ofereça a retirada.';
        }
        return $r . ' Taxa de entrega: ' . self::reais($calculo['taxa_centavos']) . '.';
    }

    private function definirCliente(array &$s, array $args): string
    {
        $r = [];
        $nome = trim((string) ($args['nome'] ?? ''));
        if ($nome !== '') {
            if (mb_strlen($nome) < 2 || preg_match('/^\d+$/', $nome) === 1) {
                $r[] = 'Nome não aceito: pergunte de novo.';
            } else {
                $s['nome'] = mb_substr($nome, 0, 80);
                $r[] = 'Nome anotado.';
            }
        }
        $forma = (string) ($args['pagamento'] ?? '');
        if (isset(self::PAGAMENTOS[$forma])) {
            $s['pagamento'] = ['forma' => $forma, 'troco_para_centavos' => $forma === 'dinheiro' ? ($s['pagamento']['troco_para_centavos'] ?? null) : null];
            $r[] = 'Pagamento: ' . self::PAGAMENTOS[$forma] . ' (na entrega ou na retirada).';
        }
        if (isset($args['troco_para']) && is_numeric($args['troco_para']) && ($s['pagamento']['forma'] ?? '') === 'dinheiro') {
            $centavos = (int) round((float) $args['troco_para'] * 100);
            $total = $this->totais($s)['total'];
            if ($centavos > 0 && $centavos < $total) {
                $r[] = 'Troco recusado: precisa ser para um valor maior que o total (' . self::reais($total) . ').';
            } else {
                $s['pagamento']['troco_para_centavos'] = $centavos > 0 ? $centavos : null;
                $r[] = $centavos > 0 ? 'Troco para ' . self::reais($centavos) . '.' : 'Sem troco.';
            }
        }
        return $r === [] ? 'Nada mudou: informe nome e/ou pagamento.' : implode(' ', $r);
    }

    private function mostrarResumo(array &$s): string
    {
        $faltam = $this->faltando($s);
        if ($faltam !== []) {
            return 'Ainda falta: ' . implode(', ', $faltam) . '. Pergunte ao cliente.';
        }
        $this->totais($s);   // confere que todos os itens ainda valem (esgotado entre uma mensagem e outra)
        $s['resumo_digital'] = $this->digital($s);
        $s['resumo_em'] = time();
        $s['enviar_resumo'] = true;
        return 'O sistema vai mandar o resumo com o total logo depois da sua mensagem. Escreva só uma frase curta pedindo para o cliente conferir (não repita os itens nem o total).';
    }

    private function confirmarPedido(array &$s, string $telefone): string
    {
        if (($s['resumo_digital'] ?? null) === null || time() - (int) ($s['resumo_em'] ?? 0) > self::RESUMO_VALE_SEGUNDOS) {
            return 'O cliente ainda não viu o resumo. Chame mostrar_resumo primeiro.';
        }
        if ($s['resumo_digital'] !== $this->digital($s)) {
            return 'O pedido mudou depois do último resumo. Chame mostrar_resumo de novo para o cliente conferir.';
        }
        $totais = $this->totais($s);
        try {
            $criado = $this->pedidos->criar([
                'tipo' => $s['tipo'],
                'cliente' => ['nome' => $s['nome'], 'telefone' => ''],
                'endereco' => $s['tipo'] === 'entrega' ? array_diff_key($s['endereco'] ?? [], ['aproximado' => 1]) : [],
                'pagamento' => $s['pagamento'],
                'observacoes' => '',
                'itens' => $s['carrinho'],
                'total_esperado_centavos' => $totais['total'],
                't' => (new TokenWhatsApp())->gerar($telefone),   // telefone verificado da conversa: origem "whatsapp"
            ]);
        } catch (ErroHttp $e) {
            if ($e->codigo === 'total_mudou') {
                unset($s['resumo_digital']);
                return 'Os preços mudaram agora há pouco. Chame mostrar_resumo de novo.';
            }
            throw $e;
        }
        $this->encerrar($telefone);
        $s['resultado'] = 'pedido_criado';
        $s['pedido_id'] = $criado['id'];
        return "Pedido #{$criado['id']} criado. O sistema já manda a confirmação com o acompanhamento. Responda só com um agradecimento curto.";
    }

    // ---------- Localização ----------

    /** O pin que o cliente mandou: guarda o ponto, confere a área e tira rua e bairro dele. Devolve o texto que a IA lê. */
    private function recebeuLocal(array &$s, array $local): string
    {
        $calculo = (new TaxaDeEntrega($this->config))->calcular($local['lat'], $local['lng']);
        if (!$calculo['atende']) {
            return '[O cliente mandou a localização, a ' . TaxaDeEntrega::kmTexto((float) $calculo['km']) . ' da loja: fora da área de entrega. Ofereça a retirada.]';
        }
        $s['tipo'] = 'entrega';
        $e = ['lat' => $local['lat'], 'lng' => $local['lng']] + array_diff_key($s['endereco'] ?? [], ['lat' => 1, 'lng' => 1, 'aproximado' => 1]);
        $achado = Geocodificador::endereco((float) $local['lat'], (float) $local['lng']);
        if ($achado !== null) {
            $e['rua'] = $achado['rua'];
            $e['bairro'] = $achado['bairro'];
            if ($achado['numero'] !== '' && empty($e['numero'])) {
                $e['numero'] = $achado['numero'];
            }
        }
        $s['endereco'] = $e;
        $onde = $achado !== null ? "{$achado['rua']}, {$achado['bairro']}" : 'endereço não identificado no mapa';
        return "[O cliente mandou a localização para entrega: {$onde}. Taxa de entrega: " . self::reais($calculo['taxa_centavos']) . '.'
            . (empty($e['numero']) ? ' Pergunte o número da casa e o complemento.' : '') . ']';
    }

    private function cidadeDaLoja(): string
    {
        $partes = array_map('trim', explode(',', $this->config->get('endereco_loja')));
        return implode(', ', array_slice($partes, -2));
    }

    /** O ponto achado pelo endereço escrito está perto da loja? (rua de mesmo nome em outra cidade fica de fora) */
    private function pertoDaLoja(array $ponto): bool
    {
        $r = (new TaxaDeEntrega($this->config))->regras();
        return $r['lat'] === null || TaxaDeEntrega::km($r['lat'], $r['lng'], $ponto['lat'], $ponto['lng']) <= 60;
    }

    // ---------- Carrinho e resumo ----------

    private static function payload(array $i): array
    {
        $item = isset($i['item_id']) && is_numeric($i['item_id']) ? (int) $i['item_id'] : null;
        $quantidade = max(1, min(20, (int) ($i['quantidade'] ?? 1)));
        $observacao = mb_substr(trim((string) ($i['observacao'] ?? '')), 0, 200);
        $temTamanho = isset($i['tamanho_id']) && is_numeric($i['tamanho_id']);
        if ($item !== null && !$temTamanho) {   // bebida, sobremesa (se for prato da casa sem tamanho, o PedidoService recusa)
            return ['tipo' => 'simples', 'item_id' => $item, 'quantidade' => $quantidade, 'observacoes' => $observacao];
        }
        return ['tipo' => 'montado', 'item_id' => $item, 'tamanho_id' => $temTamanho ? (int) $i['tamanho_id'] : null,
            'massa_id' => isset($i['massa_id']) ? (int) $i['massa_id'] : null, 'molho_ids' => array_values(array_map('intval', array_filter((array) ($i['molho_ids'] ?? $i['molho_id'] ?? []), 'is_numeric'))),
            'ingrediente_ids' => array_values(array_map('intval', array_filter((array) ($i['ingrediente_ids'] ?? []), 'is_numeric'))),
            'quantidade' => $quantidade, 'observacoes' => $observacao];
    }

    /** Nome legível de um item, como na comanda. */
    private function nomeDoItem(array $payload): string
    {
        $linha = $this->pedidos->validarItens(['itens' => [['quantidade' => 1] + $payload]])['itens'][0];
        $obs = $payload['observacoes'] !== '' ? " ({$payload['observacoes']})" : '';
        if ($linha['montagem'] === null) {
            return $linha['nome'] . $obs;
        }
        $m = $linha['montagem'];
        if ($linha['item_id'] !== null) {
            return "{$linha['nome']} ({$m['tamanho']['nome']})" . $obs;
        }
        $ingredientes = array_column(array_merge($m['inclusos'] ?? [], $m['extras'] ?? []), 'nome');
        return "Monte sua massa ({$m['tamanho']['nome']}): {$m['massa']['nome']}, " . (count($m['molhos'] ?? []) > 1 ? 'molhos ' : 'molho ') . "{$m['molho']['nome']}" . ($ingredientes !== [] ? ', ' . implode(', ', $ingredientes) : '') . $obs;
    }

    /** @return array{linhas:list<array{nome:string, quantidade:int, total:int}>, subtotal:int, taxa:int, total:int} com os preços de agora */
    private function totais(array $s): array
    {
        if ($s['carrinho'] === []) {
            return ['linhas' => [], 'subtotal' => 0, 'taxa' => 0, 'total' => 0];
        }
        $v = $this->pedidos->validarItens(['itens' => $s['carrinho']]);
        $linhas = [];
        foreach ($v['itens'] as $n => $linha) {
            $linhas[] = ['nome' => $this->nomeDoItem($s['carrinho'][$n]), 'quantidade' => $linha['quantidade'], 'total' => $linha['preco_unit_centavos'] * $linha['quantidade']];
        }
        $taxa = ($s['tipo'] ?? '') === 'entrega'
            ? (new TaxaDeEntrega($this->config))->calcular($s['endereco']['lat'] ?? null, $s['endereco']['lng'] ?? null)['taxa_centavos'] : 0;
        return ['linhas' => $linhas, 'subtotal' => $v['subtotal'], 'taxa' => $taxa, 'total' => $v['subtotal'] + $taxa];
    }

    private function textoDoCarrinho(array $s): string
    {
        if ($s['carrinho'] === []) {
            return 'O pedido está vazio.';
        }
        $t = $this->totais($s);
        $itens = array_map(static fn (array $l, int $n): string => ($n + 1) . ". {$l['quantidade']}x {$l['nome']}: " . self::reais($l['total']), $t['linhas'], array_keys($t['linhas']));
        return "Pedido agora:\n" . implode("\n", $itens) . "\nSubtotal: " . self::reais($t['subtotal']) . ($t['taxa'] > 0 ? ' (+ entrega ' . self::reais($t['taxa']) . ')' : '');
    }

    /** @return list<string> o que ainda falta para fechar */
    private function faltando(array $s): array
    {
        $e = $s['endereco'] ?? [];
        return array_keys(array_filter([
            'itens' => $s['carrinho'] === [],
            'entrega ou retirada' => !isset($s['tipo']),
            'endereço com rua, número e bairro' => ($s['tipo'] ?? '') === 'entrega' && (($e['rua'] ?? '') === '' || ($e['numero'] ?? '') === '' || ($e['bairro'] ?? '') === ''),
            'localização para a taxa de entrega' => ($s['tipo'] ?? '') === 'entrega' && empty($e['lat']) && (new TaxaDeEntrega($this->config))->porRaio(),
            'nome' => ($s['nome'] ?? '') === '',
            'forma de pagamento' => !isset($s['pagamento']['forma']),
        ]));
    }

    /** O resumo que o cliente confirma, escrito pelo sistema. */
    private function resumo(array $s): string
    {
        $t = $this->totais($s);
        $l = ['*Confira o seu pedido:*'];
        foreach ($t['linhas'] as $linha) {
            $l[] = "• {$linha['quantidade']}x {$linha['nome']}: " . self::reais($linha['total']);
        }
        if ($s['tipo'] === 'entrega') {
            $e = $s['endereco'];
            $l[] = 'Entrega: ' . self::reais($t['taxa']) . (!empty($e['aproximado']) ? ' (calculada pelo endereço escrito)' : '');
        }
        $l[] = '*Total: ' . self::reais($t['total']) . '*';
        $l[] = '';
        if ($s['tipo'] === 'entrega') {
            $e = $s['endereco'];
            $l[] = "📍 {$e['rua']}, {$e['numero']}" . (!empty($e['complemento']) ? " ({$e['complemento']})" : '') . " – {$e['bairro']}";
        } else {
            $l[] = '🏪 Retirada no balcão';
        }
        $troco = $s['pagamento']['troco_para_centavos'] ?? null;
        $l[] = '💳 ' . self::PAGAMENTOS[$s['pagamento']['forma']] . ($troco ? ' (troco para ' . self::reais($troco) . ')' : '');
        $l[] = "👤 {$s['nome']}";
        $l[] = '';
        $l[] = 'Está tudo certo? Responda *sim* para confirmar, ou diga o que mudar.';
        return implode("\n", $l);
    }

    /** Impressão digital do que o cliente confere: itens, entrega, endereço, nome, pagamento e total. */
    private function digital(array $s): string
    {
        return hash('sha256', json_encode([$s['carrinho'], $s['tipo'] ?? null, $s['endereco'] ?? null, $s['nome'] ?? null, $s['pagamento'] ?? null, $this->totais($s)['total']]));
    }

    /** O que a IA precisa saber do pedido nesta mensagem (vai no fim do prompt, a cada resposta). */
    private function situacao(array $s): string
    {
        $estado = $this->loja->estado();
        $l = [$estado['aberta'] ? 'A loja está aberta.' : "A loja está FECHADA agora ({$estado['mensagem']}): não monte pedido, só informe o horário."];
        $l[] = $this->textoDoCarrinhoSeguro($s);
        $l[] = 'Entrega ou retirada: ' . ($s['tipo'] ?? 'não definido') . '.';
        if (($s['tipo'] ?? '') === 'entrega') {
            $e = $s['endereco'] ?? [];
            $l[] = 'Endereço: ' . (($e['rua'] ?? '') !== '' ? trim("{$e['rua']}, " . ($e['numero'] ?? '?') . ' – ' . ($e['bairro'] ?? '?')) : 'não informado') . (!empty($e['lat']) ? ' (com localização)' : ' (sem localização)') . '.';
        }
        $l[] = 'Nome: ' . ($s['nome'] ?? 'não informado') . (empty($s['nome']) && !empty($s['nome_whatsapp']) ? " (no WhatsApp aparece \"{$s['nome_whatsapp']}\": confirme se é esse)" : '') . '.';
        $l[] = 'Pagamento: ' . (isset($s['pagamento']['forma']) ? self::PAGAMENTOS[$s['pagamento']['forma']] : 'não informado') . '.';
        $faltam = $this->faltando($s);
        $l[] = $faltam === [] ? (($s['resumo_digital'] ?? null) === $this->digitalSeguro($s) ? 'O resumo já foi mostrado: se o cliente confirmar, chame confirmar_pedido.' : 'Está completo: chame mostrar_resumo.') : 'Falta: ' . implode(', ', $faltam) . '.';
        return implode("\n", $l);
    }

    private function digitalSeguro(array $s): ?string
    {
        try {
            return $this->digital($s);
        } catch (ErroHttp) {
            return null;
        }
    }

    private function textoDoCarrinhoSeguro(array $s): string
    {
        try {
            return $this->textoDoCarrinho($s);
        } catch (ErroHttp $e) {
            return 'Problema no pedido: ' . $e->getMessage() . ' Avise o cliente e tire o item.';
        }
    }

    // ---------- Sessão ----------

    private function carregar(string $telefone): ?array
    {
        $consulta = Banco::conexao()->prepare('SELECT dados, atualizado_em FROM whatsapp_sessoes WHERE telefone = ? AND etapa = ?');
        $consulta->execute([$telefone, self::ETAPA]);
        $linha = $consulta->fetch();
        if (!$linha || $linha['atualizado_em'] < gmdate('Y-m-d H:i:s', time() - self::VALIDADE_SEGUNDOS)) {
            return null;
        }
        $dados = json_decode((string) $linha['dados'], true);
        return is_array($dados) && is_array($dados['carrinho'] ?? null) ? $dados : null;
    }

    private function salvar(string $telefone, array $s): void
    {
        Banco::conexao()->prepare(
            'INSERT INTO whatsapp_sessoes (telefone, etapa, dados, atualizado_em) VALUES (?, ?, ?, ?)
             ON CONFLICT (telefone) DO UPDATE SET etapa = EXCLUDED.etapa, dados = EXCLUDED.dados, atualizado_em = EXCLUDED.atualizado_em',
        )->execute([$telefone, self::ETAPA, json_encode($s, JSON_UNESCAPED_UNICODE), Relogio::agora()]);
    }

    private function encerrar(string $telefone): void
    {
        Banco::conexao()->prepare('DELETE FROM whatsapp_sessoes WHERE telefone = ? AND etapa = ?')->execute([$telefone, self::ETAPA]);
    }

    /** Postgres: trava por telefone enquanto a IA responde (no SQLite do desenvolvimento, um processo por vez já basta). */
    private function travar(string $telefone): ?int
    {
        if (Banco::driver() !== 'pgsql') {
            return null;
        }
        $chave = crc32('ia-pedido:' . $telefone);
        Banco::conexao()->prepare('SELECT pg_advisory_lock(?)')->execute([$chave]);
        return $chave;
    }

    private function destravar(?int $chave): void
    {
        if ($chave !== null) {
            Banco::conexao()->prepare('SELECT pg_advisory_unlock(?)')->execute([$chave]);
        }
    }

    private function fluxo(): FluxoPedido
    {
        return $this->fluxo ??= new FluxoPedido($this->assistente);
    }

    private static function reais(int $centavos): string
    {
        return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
    }
}
