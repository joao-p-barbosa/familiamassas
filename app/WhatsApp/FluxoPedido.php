<?php
declare(strict_types=1);

namespace App\WhatsApp;

use App\Core\Banco;
use App\Core\ErroHttp;
use App\Core\Relogio;
use App\IA\Assistente;
use App\Repositories\CardapioRepository;
use App\Repositories\ConfigRepository;
use App\Services\LojaService;
use App\Services\PedidoService;
use App\Services\Geocodificador;
use App\Services\PrecoService;
use App\Services\TaxaDeEntrega;
use App\Services\TokenWhatsApp;

/**
 * Pedido pelo WhatsApp passo a passo (D74). Começa quando o cliente digita a palavra configurada (padrão "Cardápio") e segue o mesmo caminho do cardápio
 * digital: pratos da casa, "Monte a sua massa" (tamanho, massa, molho e os grupos de ingredientes), bebidas e sobremesas,
 * entrega ou retirada, nome, localização e endereço, pagamento, resumo e confirmação. Tudo por número, porque o WhatsApp
 * comum não tem botões; no canal QR as escolhas também saem como enquete (D85) e o voto chega como o número da opção.
 *
 * Nada de IA no caminho do pedido: só quando o cliente escreve algo que o passo não entende (uma dúvida no meio do pedido),
 * o assistente responde em uma ou duas frases e o bot repete a pergunta do passo. O pedido sai pelo mesmo PedidoService do
 * cardápio digital, com o telefone verificado da conversa: o preço é sempre o do servidor e o pedido entra no Kanban com
 * origem "whatsapp" (e os avisos de status de D58 saem sozinhos).
 *
 * O estado de cada cliente fica em whatsapp_sessoes. A cada pergunta, o número de cada opção fica gravado junto: se algo
 * esgotar entre a pergunta e a resposta, o número que o cliente digitou continua apontando para o item que ele viu.
 */
final class FluxoPedido
{
    /** Palavras que o bot usa para outras coisas e não podem abrir o cardápio. */
    public const RESERVADAS = ['cancelar', 'atendente', 'acompanhar'];
    public const VALIDADE_SEGUNDOS = 1800;   // 30 min parado: o pedido em andamento some e o bot volta ao silêncio
    // Limites do WhatsApp para uma enquete (D85).
    public const ENQUETE_MAX_OPCOES = 12;
    public const ENQUETE_MAX_OPCAO = 100;
    public const ENQUETE_MAX_PERGUNTA = 255;
    private const ROTULO_PAGAMENTO = ['pix' => 'Pix', 'cartao' => 'Cartão', 'dinheiro' => 'Dinheiro'];
    private const DESCRICAO_DO_PASSO = [
        'menu' => 'escolhendo um item do cardápio', 'prato_tamanho' => 'escolhendo o tamanho do prato', 'monte_tamanho' => 'escolhendo o tamanho da massa',
        'monte_massa' => 'escolhendo a massa', 'monte_molho' => 'escolhendo o molho', 'monte_grupo' => 'escolhendo os ingredientes',
        'simples' => 'escolhendo bebida ou sobremesa', 'quantidade' => 'dizendo a quantidade', 'mais' => 'decidindo se pede mais itens',
        'tipo' => 'escolhendo entrega ou retirada', 'nome' => 'dizendo o nome', 'local' => 'mandando a localização', 'endereco' => 'digitando o endereço', 'local_confirma' => 'mandando a localização (o endereço não foi achado no mapa) ou respondendo 0', 'endereco_numero' => 'dizendo o número da casa e o complemento',
        'pagamento' => 'escolhendo a forma de pagamento', 'troco' => 'dizendo o troco', 'resumo' => 'conferindo o resumo do pedido',
    ];

    public function __construct(
        private readonly Assistente $assistente = new Assistente(),
        private readonly CardapioRepository $cardapio = new CardapioRepository(),
        private readonly PrecoService $preco = new PrecoService(),
        private readonly LojaService $loja = new LojaService(),
        private readonly ConfigRepository $config = new ConfigRepository(),
    ) {}

    /** Texto comparável: sem acento, minúsculo, sem pontuação. "Cardápio!" vira "cardapio". */
    public static function chave(string $texto): string
    {
        $t = strtr(mb_strtolower(trim($texto)), ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c']);
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9 ]+/', ' ', $t)));
    }

    /**
     * As palavras que abrem o cardápio, configuradas pelo dono na tela Assistente (separadas por vírgula; padrão "Cardápio").
     * Comparadas sem acento, sem maiúscula e sem pontuação. @return list<string>
     */
    public function palavras(): array
    {
        $palavras = array_map(self::chave(...), explode(',', $this->config->get('whatsapp_palavras_cardapio', 'Cardápio')));
        $palavras = array_values(array_diff(array_filter($palavras, static fn (string $p): bool => $p !== ''), self::RESERVADAS));
        return $palavras !== [] ? $palavras : ['cardapio'];
    }

    public function ativo(string $telefone): bool
    {
        return $this->carregar($telefone) !== null;
    }

    /** "Cardápio" fora de um pedido: abre o cardápio (ou avisa que a loja está fechada). @return string o que aconteceu */
    public function iniciar(string $telefone, ?string $nome): string
    {
        $estado = $this->loja->estado();
        if (!$estado['aberta']) {
            $this->enviar($telefone, "Agora estamos fechados ({$estado['mensagem']}). Volte no nosso horário que a gente prepara o seu prato!", 'fechada');
            return 'fechada';
        }
        $s = ['etapa' => 'menu', 'carrinho' => [], 'atual' => [], 'nome_whatsapp' => $nome !== null ? explode(' ', trim($nome))[0] : null];
        $this->perguntar($telefone, $s);
        return 'menu';
    }

    /**
     * Uma mensagem de quem está no meio de um pedido.
     * @param array{telefone:string, texto:string, nome:?string, local?:?array{lat:float, lng:float}, enquete?:?string} $m  enquete: a tag da enquete votada (o voto já chega como o número da opção em "texto")
     * @return string o que aconteceu: cancelado | menu | atendente | invalido | ia | pedido_criado | erro_pedido | enquete_antiga | <passo seguinte>
     */
    public function receber(array $m): string
    {
        $telefone = $m['telefone'];
        $s = $this->carregar($telefone);
        if ($s === null) {
            return 'sem_pedido';
        }
        // Voto numa enquete que não é a do passo atual (o cliente já avançou digitando, ou o bot repetiu a pergunta): ignora em silêncio.
        if (($m['enquete'] ?? null) !== null && ($s['enquete'] ?? null) !== $m['enquete']) {
            return 'enquete_antiga';
        }
        $texto = trim($m['texto']);
        $chave = self::chave($texto);
        if ($chave === 'cancelar') {
            $this->encerrar($telefone);
            $this->enviar($telefone, 'Pedido cancelado. Quando quiser, é só digitar *Cardápio*.', 'cancelado');
            return 'cancelado';
        }
        if ($chave === 'atendente') {
            $this->encerrar($telefone);
            return 'atendente';   // o Bot passa a conversa para uma pessoa
        }
        if (in_array($chave, $this->palavras(), true)) {   // volta ao cardápio sem perder o que já está no pedido
            $s['etapa'] = 'menu';
            $this->perguntar($telefone, $s);
            return 'menu';
        }

        $resultado = $this->passo($s, $texto, $m['local'] ?? null, $telefone);
        if ($resultado === 'fim') {
            return $s['resultado'] ?? 'fim';
        }
        if ($resultado === false) {
            return $this->invalido($telefone, $s, $texto, $m['nome']);
        }
        unset($s['parcial']);
        $this->perguntar($telefone, $s);
        return $s['etapa'];
    }

    // ---------- Passos ----------

    /** @return bool|string true: andou (a próxima pergunta sai); false: não entendeu; "fim": o pedido terminou (mensagem já enviada) */
    private function passo(array &$s, string $texto, ?array $local, string $telefone): bool|string
    {
        $etapa = $s['etapa'];
        $numero = preg_match('/^\d{1,3}$/', $texto) === 1 ? (int) $texto : null;
        $escolhida = $numero !== null ? ($s['opcoes'][$numero - 1] ?? null) : null;

        switch ($etapa) {
            case 'menu':
                if ($escolhida === null) {
                    return false;
                }
                [$tipo, $id] = $escolhida;
                $s['atual'] = $tipo === 'prato' ? ['item_id' => $id] : [];
                $s['categoria'] = $tipo === 'simples' ? $id : null;   // bebidas, sobremesas...: uma opção do menu por categoria
                $s['etapa'] = ['prato' => 'prato_tamanho', 'monte' => 'monte_massa', 'simples' => 'simples', 'fechar' => 'tipo'][$tipo];
                return true;

            case 'prato_tamanho':
            case 'monte_tamanho':
                if ($escolhida === null) {
                    return false;
                }
                // O tamanho é o último passo dos dois: o prato da casa tem receita fixa (D87) e o "Monte" já escolheu o resto.
                $s['atual']['tamanho_id'] = $escolhida;
                $s['etapa'] = 'quantidade';
                return true;

            case 'monte_massa':
                if ($escolhida === null) {
                    return false;
                }
                $s['atual']['massa_id'] = $escolhida;
                $s['etapa'] = 'monte_molho';
                return true;

            case 'monte_molho':   // D110: pode mais de um molho (enquete de marcar, como os ingredientes); pelo menos um
                $escolhas = self::listaDeNumeros($texto);
                if ($escolhas === null || $escolhas === []) {
                    return false;
                }
                $s['atual']['molho_ids'] = [];
                foreach ($escolhas as $n) {
                    if (!isset($s['opcoes'][$n - 1])) {
                        return false;
                    }
                    $s['atual']['molho_ids'][] = $s['opcoes'][$n - 1];
                }
                $s['etapa'] = 'monte_grupo';
                $s['grupo'] = 0;
                $s['atual']['ingrediente_ids'] = [];
                return true;

            case 'monte_grupo':
                $escolhas = self::listaDeNumeros($texto, PrecoService::repeteNoGrupo($this->grupos()[$s['grupo']]));
                if ($escolhas === null) {
                    return false;
                }
                foreach ($escolhas as $n) {
                    if (!isset($s['opcoes'][$n - 1])) {
                        return false;
                    }
                }
                $grupo = $this->grupos()[$s['grupo']];
                $limite = PrecoService::limiteDoGrupo($grupo);
                if ($limite !== null && count($escolhas) > $limite) {   // D115: acompanhamentos até 8 (cada porção conta, D117)
                    $s['aviso'] = "Pode escolher até {$limite} em " . mb_strtolower($grupo) . '. Marque de novo, por favor.';
                    return true;
                }
                foreach ($escolhas as $n) {
                    $s['atual']['ingrediente_ids'][] = $s['opcoes'][$n - 1];
                }
                $s['grupo']++;
                if ($s['grupo'] >= count($this->grupos())) {
                    $s['etapa'] = 'monte_tamanho';
                }
                return true;

            case 'simples':
                if ($escolhida === null) {
                    return false;
                }
                $s['atual'] = ['simples' => $escolhida];
                $s['etapa'] = 'quantidade';
                return true;

            case 'quantidade':
                if ($numero === null || $numero < 1 || $numero > 20) {
                    return false;
                }
                try {
                    $s['carrinho'][] = $this->itemDoCarrinho($s['atual'], $numero);
                } catch (ErroHttp $e) {   // esgotou no meio do caminho
                    $s['aviso'] = $e->getMessage();
                    $s['etapa'] = 'menu';
                    return true;
                }
                $s['atual'] = [];
                $s['etapa'] = 'mais';
                return true;

            case 'mais':
                if (!in_array($numero, [1, 2], true)) {
                    return false;
                }
                $s['etapa'] = $numero === 1 ? 'menu' : 'tipo';
                return true;

            case 'tipo':
                if ($escolhida === null) {
                    return false;
                }
                $s['tipo'] = $escolhida;
                $s['etapa'] = 'nome';
                return true;

            case 'nome':
                $nome = $texto === '1' && !empty($s['nome_whatsapp']) ? $s['nome_whatsapp'] : $texto;
                if (mb_strlen($nome) < 2 || mb_strlen($nome) > 80 || preg_match('/^\d+$/', $nome) === 1) {
                    return false;
                }
                $s['nome'] = $nome;
                $s['etapa'] = $s['tipo'] === 'entrega' ? 'local' : 'pagamento';
                return true;

            case 'local':
                if ($local !== null) {
                    return $this->recebeuPin($s, $local);
                } elseif ($texto !== '0') {   // D112: a localização é um pedido; "0" ou o endereço escrito seguem
                    if (substr_count($texto, ',') < 2) {
                        return false;
                    }
                    $s['etapa'] = 'endereco';   // digitou o endereço em vez de mandar o pin: aceita
                    return $this->passo($s, $texto, null, $telefone);
                }
                $s['etapa'] = 'endereco';
                return true;

            case 'endereco_numero':
                // Rua e bairro vieram do pin: falta o número (e o complemento, se houver). "120, apto 12" ou "120 apto 12".
                if ($local !== null) {
                    return $this->recebeuPin($s, $local);
                }
                if (substr_count($texto, ',') >= 2) {   // escreveu o endereço inteiro: vale o que ele escreveu
                    $s['etapa'] = 'endereco';
                    return $this->passo($s, $texto, null, $telefone);
                }
                if (preg_match('/^\s*(?:n[º°o.]?\s*)?(\d+[a-zA-Z]?|s\/?n)\b[\s,.-]*(.*)$/iu', $texto, $m) !== 1) {
                    return false;   // a IA tenta ler (D89)
                }
                $s['endereco']['numero'] = $m[1];
                $s['endereco']['complemento'] = trim($m[2]) === '0' ? '' : mb_substr(trim($m[2]), 0, 80);
                $s['etapa'] = 'pagamento';
                return true;

            case 'endereco':
                if ($local !== null) {   // mandou o pin agora: tenta tirar o endereço dele
                    return $this->recebeuPin($s, $local);
                }
                $partes = array_values(array_filter(array_map('trim', explode(',', $texto)), static fn (string $p): bool => $p !== ''));
                if (count($partes) < 3) {
                    return false;   // fora do formato: a IA tenta ler o endereço (D89); sem IA, o cliente recebe o formato de novo
                }
                $s['endereco'] = array_merge($s['endereco'] ?? [], [
                    'rua' => $partes[0], 'numero' => $partes[1], 'bairro' => $partes[2], 'complemento' => implode(', ', array_slice($partes, 3)),
                ]);
                return $this->conferirPontoDoEndereco($s);

            case 'local_confirma':
                // D115: o endereço escrito não foi achado no mapa; a localização foi pedida uma vez. Pin: calcula a taxa.
                // Qualquer outra resposta segue sem ela, e a taxa fica a confirmar na entrega.
                if ($local !== null) {
                    $calculo = (new TaxaDeEntrega($this->config))->calcular($local['lat'], $local['lng']);
                    if (!$calculo['atende']) {
                        $s['aviso'] = 'Esse local fica a ' . TaxaDeEntrega::kmTexto((float) $calculo['km']) . ' da loja, fora da nossa área de entrega. 😕 Mande outra localização ou responda *cancelar*.';
                        return true;
                    }
                    $s['endereco'] = array_merge($s['endereco'], ['lat' => $local['lat'], 'lng' => $local['lng']]);
                    $s['aviso'] = '📍 Recebi a localização! Taxa de entrega: ' . self::reais($calculo['taxa_centavos']) . '.';
                }
                $s['etapa'] = 'pagamento';
                return true;

            case 'pagamento':
                $forma = [1 => 'pix', 2 => 'cartao', 3 => 'dinheiro'][$numero] ?? null;
                if ($forma === null) {
                    return false;
                }
                $s['pagamento'] = ['forma' => $forma, 'troco_para_centavos' => null];
                $s['etapa'] = $forma === 'dinheiro' ? 'troco' : 'resumo';
                return true;

            case 'troco':
                $valor = self::dinheiro($texto);
                if ($valor === null) {
                    return false;
                }
                $total = $this->total($s);
                if ($valor !== 0 && $valor < $total) {
                    $s['aviso'] = 'O troco precisa ser para um valor maior que o total (' . self::reais($total) . ').';
                    return true;
                }
                $s['pagamento']['troco_para_centavos'] = $valor === 0 ? null : $valor;
                $s['etapa'] = 'resumo';
                return true;

            case 'resumo':
                if ($numero === 1) {
                    return $this->fechar($s, $telefone);
                }
                if ($numero === 2) {
                    $s = ['etapa' => 'menu', 'carrinho' => [], 'atual' => [], 'nome_whatsapp' => $s['nome_whatsapp'] ?? null];
                    return true;
                }
                if ($numero === 3) {
                    $this->encerrar($telefone);
                    $this->enviar($telefone, 'Pedido cancelado. Quando quiser, é só digitar *Cardápio*.', 'cancelado');
                    $s['resultado'] = 'cancelado';
                    return 'fim';
                }
                return false;
        }
        return false;
    }

    /** Fecha o pedido pelo mesmo caminho do cardápio digital. */
    private function fechar(array &$s, string $telefone): string|bool
    {
        $dados = [
            'tipo' => $s['tipo'],
            'cliente' => ['nome' => $s['nome'], 'telefone' => ''],
            'endereco' => $s['endereco'] ?? [],
            'pagamento' => $s['pagamento'],
            'observacoes' => '',
            'itens' => array_column($s['carrinho'], 'payload'),
            'taxa_a_confirmar' => $this->taxaAConfirmar($s),
            'total_esperado_centavos' => $s['total_mostrado'] ?? null,
            't' => (new TokenWhatsApp())->gerar($telefone),   // telefone verificado da conversa: origem "whatsapp"
        ];
        try {
            $criado = (new PedidoService())->criar($dados);
        } catch (ErroHttp $e) {
            if ($e->codigo === 'total_mudou') {   // preço mudou desde o resumo: mostra o resumo novo
                $s['aviso'] = 'Os preços foram atualizados. Confira o novo total:';
                return true;
            }
            $campos = $e->campos !== [] ? ' ' . implode(' ', $e->campos) : '';
            $this->encerrar($telefone);
            $this->enviar($telefone, 'Não consegui fechar o pedido: ' . $e->getMessage() . $campos . "\nDigite *Cardápio* para tentar de novo ou *atendente* para falar com a gente.", 'erro_pedido');
            $s['resultado'] = 'erro_pedido';
            return 'fim';
        }
        $this->encerrar($telefone);
        $this->confirmar($telefone, $criado['id']);
        $s['resultado'] = 'pedido_criado';
        return 'fim';
    }

    /**
     * "Recebemos seu pedido #N" é o aviso de status de D58, que já está na fila: manda agora mesmo. Se a fila não puder andar
     * (WhatsApp caído há pouco, fila retida), manda uma confirmação direta para o cliente não ficar sem resposta.
     */
    public function confirmar(string $telefone, int $pedidoId): void
    {
        try {
            (new Avisos())->processar(5);
        } catch (\Throwable $e) {
            error_log('[fluxo] fila de avisos: ' . $e->getMessage());
        }
        $pdo = Banco::conexao();
        $ainda = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_envios_pendentes WHERE pedido_id = ? AND tipo = 'novo'");
        $ainda->execute([$pedidoId]);
        if ((int) $ainda->fetchColumn() > 0) {
            $pdo->prepare("DELETE FROM whatsapp_envios_pendentes WHERE pedido_id = ? AND tipo = 'novo'")->execute([$pedidoId]);
            $this->enviar($telefone, "Pedido #{$pedidoId} recebido! Vamos avisando por aqui quando estiver pronto.", 'confirmado');
        }
    }

    /**
     * O cliente digitou algo que o passo não entende.
     *   Texto fora do formato (D89): a IA lê a mensagem e devolve a resposta do passo ("duas" vira 2, um endereço sem vírgulas vira
     *   "rua, número, bairro"), e o pedido segue. Se faltou alguma parte, a IA pergunta só o que falta e guarda o que já veio.
     *   Uma dúvida: a IA responde e o passo se repete. Número errado, ou IA desligada: repete a pergunta.
     */
    private function invalido(string $telefone, array $s, string $texto, ?string $nome): string
    {
        $temLetra = preg_match('/\pL/u', $texto) === 1;
        $esperado = $temLetra ? $this->esperado($s) : null;
        if ($esperado !== null) {
            $lido = trim(($s['parcial'] ?? '') . "\n" . $texto);
            $i = $this->assistente->interpretar($telefone, self::DESCRICAO_DO_PASSO[$s['etapa']] ?? 'fazendo o pedido', $esperado, $lido);
            if (($i['tipo'] ?? null) === 'resposta') {
                $antes = $s['etapa'];
                $r = $this->passo($s, $i['valor'], null, $telefone);
                if ($r === 'fim') {
                    return $s['resultado'] ?? 'fim';
                }
                if ($r !== false) {
                    unset($s['parcial']);
                    if (in_array($antes, ['local', 'endereco'], true) && isset($s['endereco']['rua'])) {
                        $e = $s['endereco'];
                        $s['aviso'] = "Anotei o endereço: {$e['rua']}, {$e['numero']}, {$e['bairro']}" . ($e['complemento'] !== '' ? ", {$e['complemento']}" : '') . '.';
                    }
                    $this->perguntar($telefone, $s);
                    return 'ia_interpretou';
                }
            } elseif (($i['tipo'] ?? null) === 'incompleto') {
                $s['parcial'] = mb_substr($lido, -500);
                $this->salvar($telefone, $s);
                $this->enviar($telefone, $i['pergunta'], 'ia');
                return 'ia';
            }
        }
        if ($temLetra && $this->assistente->indisponivel($telefone) === null) {
            $r = $this->assistente->responder($telefone, $texto, $nome, ['contexto' => 'O cliente está no meio de um pedido por aqui pelo WhatsApp, '
                . (self::DESCRICAO_DO_PASSO[$s['etapa']] ?? 'fazendo o pedido') . '. Responda só a dúvida dele, em 1 ou 2 frases. Não mande link do cardápio nem peça para ele pedir pelo site: logo depois o sistema repete a pergunta do passo em que ele está.']);
            if ($r['ok']) {
                $this->enviar($telefone, $r['texto'], 'ia');
                $this->perguntar($telefone, $s);
                return 'ia';
            }
        }
        $s['aviso'] = $s['etapa'] === 'endereco_numero' ? 'Mande o número da casa (ex.: 120).'
            : (in_array($s['etapa'], ['local', 'endereco'], true) ? 'Faltou alguma parte do endereço. Separe por vírgulas: rua, número e bairro.'
            : ($temLetra ? 'Não entendi. 🙂' : 'Essa opção não existe.'));
        $this->perguntar($telefone, $s);
        return 'invalido';
    }

    /**
     * O formato que o passo atual lê, para a IA traduzir a mensagem do cliente (D89). null: o passo não é interpretado pela IA
     * (o "Confirmar pedido" é sempre escolha explícita do cliente).
     */
    private function esperado(array $s): ?string
    {
        $opcoes = implode("\n", array_map(static fn (int $i, string $r): string => ($i + 1) . '. ' . self::semMarcas($r), array_keys($s['rotulos'] ?? []), $s['rotulos'] ?? []));
        $endereco = 'O endereço de entrega, separado por vírgulas: rua, número, bairro, complemento. Rua, número e bairro são obrigatórios; '
            . 'o complemento (apartamento, bloco, ponto de referência) é opcional. Ex.: Rua das Flores, 120, Vila Nova, portão azul';
        return match ($s['etapa']) {
            'menu', 'prato_tamanho', 'monte_tamanho', 'monte_massa', 'simples', 'mais', 'tipo', 'pagamento'
                => $opcoes !== '' ? "Só o número da opção escolhida (ex.: 2). As opções:\n{$opcoes}" : null,
            'monte_molho' => "Os números dos molhos escolhidos, separados por vírgula (ex.: 1 ou 1, 3). Pelo menos um. As opções:\n{$opcoes}",
            'monte_grupo' => "Os números dos ingredientes escolhidos, separados por vírgula (ex.: 1, 3), ou 0 para nenhum deste grupo. As opções:\n{$opcoes}",
            'quantidade' => 'Só a quantidade, um número inteiro de 1 a 20 (ex.: 2).',
            'local', 'endereco' => $endereco,
            'endereco_numero' => 'O número da casa e, se houver, o complemento, separados por vírgula (ex.: 120, apto 12). Só o número também vale (ex.: 120).',
            'troco' => 'Para quanto o cliente quer troco, só o número em reais (ex.: 100), ou 0 se não precisa de troco.',
            default => null,
        };
    }

    // ---------- Perguntas ----------

    /**
     * Monta a pergunta do passo atual, grava o que cada número quer dizer e envia.
     * No canal QR (D85) os passos de escolha saem como enquete: o texto de contexto (resumo, aviso, tamanhos) vai antes como mensagem
     * comum e as opções vão na enquete, numeradas igual à lista de sempre (o número digitado continua valendo). Passo com opções demais
     * para uma enquete, ou outro canal, mantém a lista numerada.
     */
    private function perguntar(string $telefone, array $s): void
    {
        $catalogo = $this->cardapio->publico();
        $aviso = isset($s['aviso']) ? $s['aviso'] . "\n\n" : '';
        unset($s['aviso'], $s['enquete']);
        $s['opcoes'] = [];
        $linhas = [];        // a pergunta como lista numerada (texto)
        $contexto = [];      // o que fica como texto comum antes da enquete
        $rotulos = [];       // o texto de cada opção da enquete (sem o número)
        $pergunta = null;    // o título da enquete; null = este passo não é de escolha
        $multipla = false;
        $semNumero = false;  // enquete cujas opções já são o número (quantidade): sem o "1. " na frente
        $ambos = static function (string $linha) use (&$linhas, &$contexto): void {
            $linhas[] = $linha;
            $contexto[] = $linha;
        };
        // Opção que vale um valor guardado na sessão ($s['opcoes']): o número digitado ou votado aponta para ele.
        $lista = static function (mixed $valor, string $rotulo) use (&$s, &$linhas, &$rotulos): void {
            $s['opcoes'][] = $valor;
            $rotulos[] = $rotulo;
            $linhas[] = count($rotulos) . '. ' . $rotulo;
        };
        // Opção de número fixo (o passo lê o número direto).
        $fixa = static function (string $rotulo) use (&$linhas, &$rotulos): void {
            $rotulos[] = $rotulo;
            $linhas[] = count($rotulos) . '. ' . $rotulo;
        };

        switch ($s['etapa']) {
            case 'menu':
                $esgotados = [];
                $linhas[] = '🍝 *Cardápio* (responda com o número)';
                $linhas[] = '';
                $linhas[] = '*Pratos da casa*';
                foreach ($this->itens($catalogo, 'prato_casa') as $i) {
                    if ($i['estado'] !== 'disponivel') {
                        $linhas[] = "~{$i['nome']}~ (esgotado hoje)";
                        $esgotados[] = $i['nome'];
                        continue;
                    }
                    $lista(['prato', $i['id']], "{$i['nome']}" . ($i['descricao'] !== '' ? " — {$i['descricao']}" : ''));
                }
                $linhas[] = '';
                $lista(['monte', null], '*Monte a sua massa* (você escolhe tudo)');
                // Uma opção por categoria (Bebidas, Sobremesas...), para cada uma aparecer com o próprio nome (D97).
                foreach ($this->categoriasSimples($catalogo) as $categoria) {
                    $lista(['simples', $categoria['id']], $categoria['nome']);
                }
                if ($s['carrinho'] !== []) {
                    $lista(['fechar', null], '*Fechar o pedido* (' . self::itensTexto($s['carrinho']) . ', ' . self::reais($this->total($s, false)) . ')');
                }
                $linhas[] = '';
                $tamanhos = 'Monte a sua massa: ' . implode(' · ', array_map(static fn (array $t): string => "{$t['nome']} " . self::reais($t['preco_centavos']), $this->disponiveis($catalogo['tamanhos'])));
                $linhas[] = $tamanhos;
                $linhas[] = 'A qualquer momento: *cancelar* ou *atendente*.';
                $contexto = ['🍝 *Cardápio*', ...($esgotados !== [] ? ['Esgotado hoje: ' . implode(', ', $esgotados) . '.'] : []), '', $tamanhos, 'A qualquer momento: *cancelar* ou *atendente*.'];
                $pergunta = 'O que vai ser?';
                break;

            case 'prato_tamanho':
                $prato = $this->itemDoCardapio($s['atual']['item_id']);
                $linhas[] = "*{$prato['nome']}*. Qual tamanho?";
                $precos = array_column($prato['precos'], 'preco_centavos', 'tamanho_id');
                foreach ($this->disponiveis($catalogo['tamanhos']) as $t) {
                    if (isset($precos[$t['id']])) {   // tamanho sem preço cadastrado não é vendido para este prato
                        $lista($t['id'], "{$t['nome']}" . ($t['descricao'] !== '' ? " ({$t['descricao']})" : '') . ' — ' . self::reais($precos[$t['id']]));
                    }
                }
                $pergunta = "{$prato['nome']}: qual tamanho? (escolha 1)";
                break;

            case 'monte_tamanho':
                $linhas[] = 'Por último, o tamanho:';
                foreach ($this->disponiveis($catalogo['tamanhos']) as $t) {
                    $preco = $this->precoMontado($catalogo, ['tamanho_id' => $t['id']] + $s['atual']);
                    $lista($t['id'], "{$t['nome']}" . ($t['descricao'] !== '' ? " ({$t['descricao']})" : '') . ' — ' . self::reais($preco));
                }
                $pergunta = 'Por último, o tamanho (escolha 1):';
                break;

            case 'monte_massa':
            case 'monte_molho':
                $massa = $s['etapa'] === 'monte_massa';
                $titulo = $massa ? 'Monte a sua massa 🍝 Primeiro, qual massa? (escolha 1)' : 'Qual molho? Pode marcar mais de um e tocar em ✅ Pronto';
                $linhas[] = $massa ? '*Monte a sua massa* 🍝 Primeiro, qual massa?' : '*Molho*: pode escolher mais de um (ex.: 1, 3).';
                foreach ($this->disponiveis($catalogo[$massa ? 'massas' : 'molhos']) as $o) {
                    $lista($o['id'], $o['nome'] . self::acrescimoTexto($o, 'acrescimo_centavos', $catalogo['tamanhos']));
                }
                $pergunta = $titulo;
                $multipla = !$massa;
                break;

            case 'monte_grupo':
                $grupo = $this->grupos()[$s['grupo']];
                $dica = PrecoService::dicaDoGrupo($grupo);   // D115: "(escolha até 8)", "(à vontade, sem limite)"
                $linhas[] = "*{$grupo}*" . ($dica !== '' ? " {$dica}" : '') . ' — pode escolher vários (ex.: 1, 3) ou 0 para nenhum.'
                    . (PrecoService::repeteNoGrupo($grupo) ? ' Para duas porções do mesmo, escreva o número duas vezes (ex.: 3, 3).' : '');
                if ($s['grupo'] === 0) {
                    $ambos('Escolha quantos quiser. Os que têm preço ao lado são cobrados à parte.');
                }
                foreach ($this->disponiveis(array_filter($catalogo['ingredientes'], static fn (array $i): bool => $i['grupo'] === $grupo)) as $i) {
                    $lista($i['id'], $i['nome'] . self::acrescimoTexto($i, 'preco_extra_centavos', $catalogo['tamanhos']));
                }
                $pergunta = "{$grupo}" . ($dica !== '' ? " {$dica}" : '') . ': marque o que quiser e toque em ✅ Pronto';
                $multipla = true;
                break;

            case 'simples':
                $esgotados = [];
                $nomeDaCategoria = array_column($catalogo['categorias'], 'nome', 'id')[$s['categoria'] ?? 0] ?? 'Bebidas e sobremesas';
                $linhas[] = "*{$nomeDaCategoria}*";
                foreach ($this->itens($catalogo, 'simples', $s['categoria'] ?? null) as $i) {
                    if ($i['estado'] !== 'disponivel') {
                        $linhas[] = "~{$i['nome']}~ (esgotado hoje)";
                        $esgotados[] = $i['nome'];
                        continue;
                    }
                    $lista($i['id'], "{$i['nome']} — " . self::reais($i['preco_centavos']));
                }
                if ($esgotados !== []) {
                    $contexto[] = 'Esgotado hoje: ' . implode(', ', $esgotados) . '.';
                }
                $pergunta = "{$nomeDaCategoria}: qual vai ser?";
                break;

            case 'quantidade':
                // A pergunta diz o quê (D97). Na enquete as opções são "1 prato", "2 pratos"...: o voto vira o número da quantidade.
                [$umNome, $variosNome, $titulo] = $this->palavrasDaQuantidade($s['atual']);
                $linhas[] = "{$titulo} Responda com o número (de 1 a 20).";
                for ($n = 1; $n <= 5; $n++) {
                    $rotulos[] = $n . ' ' . ($n === 1 ? $umNome : $variosNome);
                }
                $contexto[] = 'Mais de 5? É só digitar o número.';
                $pergunta = $titulo;
                $semNumero = true;
                break;

            case 'mais':
                $ultimo = $s['carrinho'][count($s['carrinho']) - 1];
                $ambos("Anotado: {$ultimo['quantidade']}× {$ultimo['nome']} — " . self::reais($ultimo['preco'] * $ultimo['quantidade']) . '.');
                $ambos('Seu pedido: ' . self::itensTexto($s['carrinho']) . ', ' . self::reais($this->total($s, false)) . '.');
                $linhas[] = '';
                $fixa('Pedir mais alguma coisa');
                $fixa('Fechar o pedido');
                $pergunta = 'Mais alguma coisa?';
                break;

            case 'tipo':
                $linhas[] = 'Entrega ou retirada?';
                if ($this->config->get('aceita_entrega', '1') === '1') {
                    // A taxa só aparece depois da localização (rua, distância e taxa juntas), não aqui.
                    $lista('entrega', 'Entrega');
                }
                if ($this->config->get('aceita_retirada', '1') === '1') {
                    $lista('retirada', 'Retirada no balcão');
                }
                $pergunta = 'Entrega ou retirada?';
                break;

            case 'nome':
                $linhas[] = 'Qual o seu nome?' . (!empty($s['nome_whatsapp']) ? " (ou responda 1 para usar *{$s['nome_whatsapp']}*)" : '');
                break;

            case 'local':
                $linhas[] = 'Se puder, mande a sua localização: 📎 › *Localização* › *Enviar localização atual*. Pegamos o endereço dela'
                    . ((new TaxaDeEntrega($this->config))->porRaio() ? ' e já calculamos a taxa.' : '.');
                $linhas[] = 'Ou escreva o endereço: *rua, número, bairro, complemento*. Se preferir só escrever depois, responda 0.';
                break;

            case 'endereco_numero':
                $e = $s['endereco'];
                $calculo = (new TaxaDeEntrega($this->config))->calcular($e['lat'] ?? null, $e['lng'] ?? null);
                $linhas[] = "📍 Recebi! Endereço: *{$e['rua']}*, {$e['bairro']}" . ($calculo['km'] !== null ? ' (' . TaxaDeEntrega::kmTexto($calculo['km']) . ', taxa ' . self::reais($calculo['taxa_centavos']) . ')' : '') . '.';
                $linhas[] = ($e['numero'] ?? '') !== ''
                    ? "O número é *{$e['numero']}*? Responda o número e o complemento, se tiver (ex.: {$e['numero']}, apto 12)."
                    : 'Qual o número da casa? Se tiver complemento, mande junto (ex.: 120, apto 12).';
                $linhas[] = 'Se a rua não for essa, escreva o endereço inteiro: rua, número, bairro.';
                break;

            case 'local_confirma':
                $linhas[] = 'Pelo endereço não consegui achar aqui no mapa. 😕 Consegue compartilhar a localização, por favor? 📎 › *Localização* › *Enviar localização atual*.';
                $linhas[] = 'Se não puder, responda 0: a taxa de entrega fica para confirmar na entrega.';
                break;

            case 'endereco':
                $linhas[] = isset($s['endereco']['lat']) ? 'Recebi a localização! Agora escreva o endereço assim:' : 'Escreva o endereço assim:';
                $linhas[] = '*Rua, número, bairro, complemento*';
                $linhas[] = 'Ex.: Rua das Flores, 120, Vila Nova, portão azul';
                break;

            case 'pagamento':
                $quando = 'o pagamento é feito na ' . ($s['tipo'] === 'entrega' ? 'entrega' : 'retirada');
                $linhas[] = "Como vai pagar? ({$quando})";
                $contexto[] = ucfirst($quando) . '.';
                $fixa('Pix');
                $fixa('Cartão');
                $fixa('Dinheiro');
                $pergunta = 'Como vai pagar?';
                break;

            case 'troco':
                $linhas[] = 'Troco para quanto? (ex.: 100) Responda 0 se não precisa de troco.';
                break;

            case 'resumo':
                $s['total_mostrado'] = $this->total($s);
                $ambos('*Confira o seu pedido:*');
                foreach ($s['carrinho'] as $item) {
                    $ambos("• {$item['quantidade']}× {$item['nome']} — " . self::reais($item['preco'] * $item['quantidade']));
                }
                if ($s['tipo'] === 'entrega' && $this->taxaAConfirmar($s)) {
                    $ambos('• Taxa de entrega — a confirmar na entrega (sem a localização não deu para calcular)');
                } elseif ($s['tipo'] === 'entrega' && ($taxa = $this->taxa($s)) > 0) {
                    $ambos('• Taxa de entrega — ' . self::reais($taxa));
                }
                $ambos('*Total: ' . self::reais($s['total_mostrado']) . '*');
                $ambos('');
                $ambos($s['tipo'] === 'entrega'
                    ? "Entrega para {$s['nome']}: {$s['endereco']['rua']}, {$s['endereco']['numero']}, {$s['endereco']['bairro']}" . (($s['endereco']['complemento'] ?? '') !== '' ? ", {$s['endereco']['complemento']}" : '')
                    : "Retirada no balcão, em nome de {$s['nome']}");
                $ambos('Pagamento: ' . self::ROTULO_PAGAMENTO[$s['pagamento']['forma']]
                    . ($s['pagamento']['troco_para_centavos'] !== null ? ' (troco para ' . self::reais($s['pagamento']['troco_para_centavos']) . ')' : ''));
                $linhas[] = '';
                $fixa('Confirmar pedido');
                $fixa('Começar de novo');
                $fixa('Cancelar');
                $pergunta = 'Confirmar o pedido?';
                break;
        }

        $s['rotulos'] = $rotulos;   // o texto de cada opção, para a IA ler uma resposta fora do formato (D89)
        $enquete = $pergunta !== null && $this->usaEnquete() ? self::enquete($pergunta, $rotulos, $multipla, !$semNumero, $s['etapa'] === 'monte_molho' ? '✅ Pronto' : '✅ Pronto (ou nenhum)') : null;
        if ($enquete !== null) {
            $s['enquete'] = $enquete['tag'];
        }
        $this->salvar($telefone, $s);
        $texto = $aviso . implode("\n", $linhas);
        if ($enquete === null) {
            $this->enviar($telefone, $texto, 'fluxo:' . $s['etapa']);
            return;
        }
        $antes = trim($aviso . implode("\n", $contexto));
        if ($antes !== '') {
            $this->enviar($telefone, $antes, 'fluxo:' . $s['etapa']);
        }
        $r = WhatsApp::enviar($telefone, WhatsApp::enquete($enquete['pergunta'], $enquete['opcoes'], $multipla, $enquete['tag']), 'fluxo:' . $s['etapa'] . ':enquete');
        if (!$r['ok'] && !$r['transitorio']) {   // o conector recusou a enquete: o cliente nunca fica sem a pergunta, ela vai como lista numerada
            unset($s['enquete']);
            $this->salvar($telefone, $s);
            $this->enviar($telefone, $texto, 'fluxo:' . $s['etapa'] . ':texto');
        }
    }

    /**
     * A enquete deste passo, ou null se não cabe numa (menos de 2 ou mais de 12 opções): aí o passo segue como lista numerada.
     * As opções saem numeradas ("1. Pequeno — R$ 24,90"), então o voto, o número digitado e a lista de sempre apontam para o mesmo item.
     * Na múltipla, a última opção é o "✅ Pronto" (o voto só vale quando ele está marcado).
     * @param list<string> $rotulos
     * @return array{pergunta:string, opcoes:list<string>, tag:string}|null
     */
    private static function enquete(string $pergunta, array $rotulos, bool $multipla, bool $numerar = true, string $pronto = '✅ Pronto (ou nenhum)'): ?array
    {
        $opcoes = [];
        foreach ($rotulos as $i => $rotulo) {
            $opcoes[] = self::cortar(($numerar ? ($i + 1) . '. ' : '') . self::semMarcas($rotulo), self::ENQUETE_MAX_OPCAO);
        }
        if ($multipla) {
            $opcoes[] = $pronto;   // o conector acha o "Pronto" pela posição (a última), não pelo texto
        }
        if (count($opcoes) < 2 || count($opcoes) > self::ENQUETE_MAX_OPCOES) {
            return null;
        }
        return ['pergunta' => self::cortar(self::semMarcas($pergunta), self::ENQUETE_MAX_PERGUNTA), 'opcoes' => $opcoes, 'tag' => bin2hex(random_bytes(6))];
    }

    /** A enquete não desenha negrito do WhatsApp: tira os * e ~. */
    private static function semMarcas(string $texto): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strtr($texto, ['*' => '', '~' => ''])));
    }

    private static function cortar(string $texto, int $max): string
    {
        return mb_strlen($texto) > $max ? rtrim(mb_substr($texto, 0, $max - 1)) . '…' : $texto;
    }

    // ---------- Cardápio e preço ----------

    /** Os grupos de ingredientes na ordem do cardápio (Proteínas, Acompanhamentos, Queijos...). @return list<string> */
    private function grupos(): array
    {
        return array_values(array_unique(array_column($this->cardapio->publico()['ingredientes'], 'grupo')));
    }

    private function itens(array $catalogo, string $tipo, ?int $categoria = null): array
    {
        $categorias = array_column($catalogo['categorias'], null, 'id');
        return array_values(array_filter($catalogo['itens'], static fn (array $i): bool => $i['tipo'] === $tipo && isset($categorias[$i['categoria_id']])
            && ($categoria === null || $i['categoria_id'] === $categoria)));
    }

    /** Categorias que têm itens prontos (bebidas, sobremesas...), na ordem do cardápio. */
    private function categoriasSimples(array $catalogo): array
    {
        $usadas = array_unique(array_column($this->itens($catalogo, 'simples'), 'categoria_id'));
        return array_values(array_filter($catalogo['categorias'], static fn (array $c): bool => in_array($c['id'], $usadas, true)));
    }

    /** ["prato", "pratos", "Quantos pratos ...?"] ou ["unidade", "unidades", "Quantas unidades de Coca?"] para a pergunta da quantidade. */
    private function palavrasDaQuantidade(array $atual): array
    {
        if (isset($atual['simples'])) {
            $nome = $this->itemDoCardapio($atual['simples'])['nome'];
            return ['unidade', 'unidades', "Quantas unidades de *{$nome}*?"];
        }
        $nome = isset($atual['item_id']) ? $this->itemDoCardapio($atual['item_id'])['nome'] : 'Monte a sua massa';
        return ['prato', 'pratos', "Quantos pratos de *{$nome}*?"];
    }

    /** Enquete só no canal QR e se o dono não desligou na tela Assistente (o WhatsApp sempre mostra "Ver votos" nelas). */
    private function usaEnquete(): bool
    {
        return Conector::ativo() && $this->config->get('whatsapp_enquetes', '1') === '1';
    }

    /** Taxa de entrega deste pedido: pela distância do pin, se a loja usa raio (D98), ou a fixa. */
    private function taxa(array $s): int
    {
        return (new TaxaDeEntrega($this->config))->calcular($s['endereco']['lat'] ?? null, $s['endereco']['lng'] ?? null)['taxa_centavos'];
    }

    /** Entrega sem pin, com taxa por distância: a taxa fica a confirmar pela loja, sem travar o pedido (D112). */
    private function taxaAConfirmar(array $s): bool
    {
        return ($s['tipo'] ?? '') === 'entrega' && empty($s['endereco']['lat']) && (new TaxaDeEntrega($this->config))->porRaio();
    }

    /**
     * Endereço escrito (D115): com taxa por distância e sem pin, tenta achar o ponto pelo endereço. Achou: segue com a taxa
     * calculada (ou avisa se fica fora da área). Não achou: pede a localização uma vez (etapa local_confirma).
     */
    private function conferirPontoDoEndereco(array &$s): bool
    {
        $taxa = new TaxaDeEntrega($this->config);
        $e = $s['endereco'];
        if (!$taxa->porRaio() || !empty($e['lat'])) {
            $s['etapa'] = 'pagamento';
            return true;
        }
        $ponto = $taxa->pontoDoEndereco($e['rua'], (string) $e['numero'], $e['bairro']);
        if ($ponto === null) {
            $s['etapa'] = 'local_confirma';
            return true;
        }
        $calculo = $taxa->calcular($ponto['lat'], $ponto['lng']);
        if (!$calculo['atende']) {
            $s['aviso'] = 'Esse endereço fica a ' . TaxaDeEntrega::kmTexto((float) $calculo['km']) . ' da loja, fora da nossa área de entrega. 😕 Escreva outro endereço ou responda *cancelar*.';
            return true;
        }
        $s['endereco'] = array_merge($e, $ponto);
        $s['etapa'] = 'pagamento';
        return true;
    }

    /**
     * O cliente mandou o pin (D98): guarda o ponto, confere se a loja entrega lá e tenta tirar rua e bairro dele.
     * Achou: só pede o número. Não achou: pede o endereço escrito, como antes.
     */
    private function recebeuPin(array &$s, array $local): bool
    {
        $s['endereco'] = ['lat' => $local['lat'], 'lng' => $local['lng']];
        $calculo = (new TaxaDeEntrega($this->config))->calcular($local['lat'], $local['lng']);
        if (!$calculo['atende']) {
            $s['aviso'] = 'Esse local fica a ' . TaxaDeEntrega::kmTexto((float) $calculo['km']) . ' da loja, fora da nossa área de entrega. 😕 Mande outra localização ou responda *cancelar*.';
            $s['etapa'] = 'local';
            return true;
        }
        $achado = Geocodificador::endereco((float) $local['lat'], (float) $local['lng']);
        if ($achado !== null) {
            $s['endereco'] += ['rua' => $achado['rua'], 'numero' => $achado['numero'], 'bairro' => $achado['bairro'], 'complemento' => ''];
            $s['etapa'] = 'endereco_numero';
        } else {
            $s['etapa'] = 'endereco';
        }
        return true;
    }

    private function disponiveis(array $lista): array
    {
        return array_values(array_filter($lista, static fn (array $o): bool => $o['estado'] === 'disponivel'));
    }

    private function itemDoCardapio(int $id): array
    {
        foreach ($this->cardapio->publico()['itens'] as $i) {
            if ($i['id'] === $id) {
                return $i;
            }
        }
        throw new ErroHttp(409, 'item_removido', 'Esse item saiu do cardápio.');
    }

    private function precoMontado(array $catalogo, array $escolha): int
    {
        return $this->preco->montar($catalogo, $escolha)['preco_centavos'];
    }

    /** O item pronto para o carrinho: o que vai para o PedidoService, o nome para o cliente ler e o preço de agora. */
    private function itemDoCarrinho(array $atual, int $quantidade): array
    {
        $catalogo = $this->cardapio->publico();
        if (isset($atual['simples'])) {
            $item = $this->itemDoCardapio($atual['simples']);
            if ($item['estado'] !== 'disponivel') {
                throw new ErroHttp(409, 'esgotado', "{$item['nome']} acabou de esgotar.");
            }
            return ['payload' => ['tipo' => 'simples', 'item_id' => $item['id'], 'quantidade' => $quantidade, 'observacoes' => ''],
                'nome' => $item['nome'], 'preco' => $item['preco_centavos'], 'quantidade' => $quantidade];
        }
        $montado = $this->preco->montar($catalogo, $atual);
        $m = $montado['montagem'];
        $ingredientes = array_map([PrecoService::class, 'nomeComPorcoes'], array_merge($m['inclusos'], $m['extras']));
        $nome = isset($atual['item_id'])
            ? $this->itemDoCardapio($atual['item_id'])['nome'] . " ({$m['tamanho']['nome']})"
            : "Monte a sua massa ({$m['tamanho']['nome']}): {$m['massa']['nome']}, " . (count($m['molhos'] ?? []) > 1 ? 'molhos ' : 'molho ') . "{$m['molho']['nome']}" . ($ingredientes !== [] ? ', ' . implode(', ', $ingredientes) : '');
        return ['payload' => ['tipo' => 'montado', 'item_id' => $atual['item_id'] ?? null, 'tamanho_id' => $atual['tamanho_id'], 'massa_id' => $atual['massa_id'] ?? null,
            'molho_ids' => PrecoService::idsDosMolhos($atual), 'ingrediente_ids' => $atual['ingrediente_ids'] ?? [], 'quantidade' => $quantidade, 'observacoes' => ''],
            'nome' => $nome, 'preco' => $montado['preco_centavos'], 'quantidade' => $quantidade];
    }

    /** Total com os preços de agora (e a taxa de entrega, se for entrega). */
    private function total(array $s, bool $comTaxa = true): int
    {
        $catalogo = $this->cardapio->publico();
        $subtotal = 0;
        foreach ($s['carrinho'] as $item) {
            $p = $item['payload'];
            $preco = $p['tipo'] === 'simples' ? ($this->itemDoCardapio($p['item_id'])['preco_centavos']) : $this->precoMontado($catalogo, $p);
            $subtotal += $preco * $p['quantidade'];
        }
        return $subtotal + ($comTaxa && ($s['tipo'] ?? '') === 'entrega' ? $this->taxa($s) : 0);
    }

    // ---------- Sessão ----------

    private function carregar(string $telefone): ?array
    {
        // A linha com a etapa "ia" é o carrinho do pedido pela IA (D106), não um passo deste fluxo.
        $consulta = Banco::conexao()->prepare("SELECT dados, atualizado_em FROM whatsapp_sessoes WHERE telefone = ? AND etapa <> 'ia'");
        $consulta->execute([$telefone]);
        $linha = $consulta->fetch();
        if (!$linha) {
            return null;
        }
        if ($linha['atualizado_em'] < gmdate('Y-m-d H:i:s', time() - self::VALIDADE_SEGUNDOS)) {
            $this->encerrar($telefone);   // parado há mais de 30 min: o pedido some e o bot volta ao silêncio
            return null;
        }
        $dados = json_decode((string) $linha['dados'], true);
        return is_array($dados) ? $dados : null;
    }

    private function salvar(string $telefone, array $s): void
    {
        Banco::conexao()->prepare(
            'INSERT INTO whatsapp_sessoes (telefone, etapa, dados, atualizado_em) VALUES (?, ?, ?, ?)
             ON CONFLICT (telefone) DO UPDATE SET etapa = EXCLUDED.etapa, dados = EXCLUDED.dados, atualizado_em = EXCLUDED.atualizado_em',
        )->execute([$telefone, $s['etapa'], json_encode($s, JSON_UNESCAPED_UNICODE), Relogio::agora()]);
    }

    private function encerrar(string $telefone): void
    {
        Banco::conexao()->prepare('DELETE FROM whatsapp_sessoes WHERE telefone = ?')->execute([$telefone]);
    }

    private function enviar(string $telefone, string $texto, string $motivo): void
    {
        WhatsApp::enviar($telefone, WhatsApp::texto($texto), $motivo);
    }

    // ---------- Pequenos ----------

    /** "1, 3 4" → [1, 3, 4]; "0" → []; qualquer outra coisa → null. @return list<int>|null */
    /** "1, 3" → [1, 3]. Com $repete (D117), "3, 3" vale duas vezes o 3 (duas porções). */
    private static function listaDeNumeros(string $texto, bool $repete = false): ?array
    {
        if (trim($texto) === '0') {
            return [];
        }
        if (preg_match('/^\s*\d{1,3}(\s*[,;e ]\s*\d{1,3})*\s*$/u', $texto) !== 1) {
            return null;
        }
        preg_match_all('/\d{1,3}/', $texto, $m);
        $numeros = array_map('intval', $m[0]);
        return $repete ? $numeros : array_values(array_unique($numeros));
    }

    /** "100", "R$ 100,00", "100.50" → centavos; "0" → 0; outra coisa → null. */
    private static function dinheiro(string $texto): ?int
    {
        $limpo = str_replace(['r$', ' '], '', mb_strtolower(trim($texto)));
        if (preg_match('/^\d{1,5}([.,]\d{1,2})?$/', $limpo) !== 1) {
            return null;
        }
        return (int) round((float) str_replace(',', '.', $limpo) * 100);
    }

    private static function itensTexto(array $carrinho): string
    {
        $n = array_sum(array_column($carrinho, 'quantidade'));
        return $n === 1 ? '1 item' : "{$n} itens";
    }

    /** " (+R$ 3,00)", " (+R$ 3,00 a R$ 5,00)" quando muda com o tamanho (D108; o tamanho vem por último), ou "" se não custa nada. */
    private static function acrescimoTexto(array $opcao, string $campo, array $tamanhos): string
    {
        [$menor, $maior] = PrecoService::faixa($opcao, $campo, $tamanhos);
        if ($maior === 0) {
            return '';
        }
        if ($menor === $maior) {
            return ' (+' . self::reais($menor) . ')';
        }
        return $menor === 0 ? ' (+até ' . self::reais($maior) . ')' : ' (+' . self::reais($menor) . ' a ' . self::reais($maior) . ')';
    }

    private static function reais(int $centavos): string
    {
        return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
    }
}
