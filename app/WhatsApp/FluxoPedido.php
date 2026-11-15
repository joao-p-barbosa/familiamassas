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
use App\Services\PrecoService;
use App\Services\TokenWhatsApp;

/**
 * Pedido pelo WhatsApp passo a passo (D74). Começa quando o cliente digita "Cardápio" e segue o mesmo caminho do cardápio
 * digital: pratos da casa, "Monte a sua massa" (tamanho, massa, molho e os grupos de ingredientes), bebidas e sobremesas,
 * entrega ou retirada, nome, localização e endereço, pagamento, resumo e confirmação. Tudo por número, porque o WhatsApp
 * comum não tem botões.
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
    /** Palavras que abrem o cardápio (comparadas sem acento e sem maiúscula). */
    public const PALAVRAS = ['cardapio', 'menu'];
    public const VALIDADE_SEGUNDOS = 1800;   // 30 min parado: o pedido em andamento some e o bot volta ao silêncio
    private const ROTULO_PAGAMENTO = ['pix' => 'Pix', 'cartao' => 'Cartão', 'dinheiro' => 'Dinheiro'];
    private const DESCRICAO_DO_PASSO = [
        'menu' => 'escolhendo um item do cardápio', 'prato_tamanho' => 'escolhendo o tamanho do prato', 'monte_tamanho' => 'escolhendo o tamanho da massa',
        'monte_massa' => 'escolhendo a massa', 'monte_molho' => 'escolhendo o molho', 'monte_grupo' => 'escolhendo os ingredientes',
        'simples' => 'escolhendo bebida ou sobremesa', 'quantidade' => 'dizendo a quantidade', 'mais' => 'decidindo se pede mais itens',
        'tipo' => 'escolhendo entrega ou retirada', 'nome' => 'dizendo o nome', 'local' => 'mandando a localização', 'endereco' => 'digitando o endereço',
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
     * @param array{telefone:string, texto:string, nome:?string, local?:?array{lat:float, lng:float}} $m
     * @return string o que aconteceu: cancelado | menu | atendente | invalido | ia | pedido_criado | erro_pedido | <passo seguinte>
     */
    public function receber(array $m): string
    {
        $telefone = $m['telefone'];
        $s = $this->carregar($telefone);
        if ($s === null) {
            return 'sem_pedido';
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
        if (in_array($chave, self::PALAVRAS, true)) {   // volta ao cardápio sem perder o que já está no pedido
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
                $s['etapa'] = ['prato' => 'prato_tamanho', 'monte' => 'monte_tamanho', 'simples' => 'simples', 'fechar' => 'tipo'][$tipo];
                return true;

            case 'prato_tamanho':
            case 'monte_tamanho':
                if ($escolhida === null) {
                    return false;
                }
                $s['atual']['tamanho_id'] = $escolhida;
                if ($etapa === 'prato_tamanho') {   // o prato da casa já vem com massa, molho e ingredientes
                    $prato = $this->itemDoCardapio($s['atual']['item_id']);
                    $s['atual'] += ['massa_id' => $prato['massa_id'], 'molho_id' => $prato['molho_id'], 'ingrediente_ids' => $prato['ingrediente_ids']];
                    $s['etapa'] = 'quantidade';
                } else {
                    $s['etapa'] = 'monte_massa';
                }
                return true;

            case 'monte_massa':
            case 'monte_molho':
                if ($escolhida === null) {
                    return false;
                }
                $s['atual'][$etapa === 'monte_massa' ? 'massa_id' : 'molho_id'] = $escolhida;
                $s['etapa'] = $etapa === 'monte_massa' ? 'monte_molho' : 'monte_grupo';
                $s['grupo'] = 0;
                $s['atual']['ingrediente_ids'] = [];
                return true;

            case 'monte_grupo':
                $escolhas = self::listaDeNumeros($texto);
                if ($escolhas === null) {
                    return false;
                }
                foreach ($escolhas as $n) {
                    if (!isset($s['opcoes'][$n - 1])) {
                        return false;
                    }
                    $s['atual']['ingrediente_ids'][] = $s['opcoes'][$n - 1];
                }
                $s['grupo']++;
                if ($s['grupo'] >= count($this->grupos())) {
                    $s['etapa'] = 'quantidade';
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
                    $s['endereco'] = ['lat' => $local['lat'], 'lng' => $local['lng']];
                } elseif ($texto !== '0') {
                    if (substr_count($texto, ',') < 2) {
                        return false;
                    }
                    $s['etapa'] = 'endereco';   // digitou o endereço em vez de mandar o pin: aceita
                    return $this->passo($s, $texto, null, $telefone);
                }
                $s['etapa'] = 'endereco';
                return true;

            case 'endereco':
                if ($local !== null) {   // mandou o pin agora: guarda e continua pedindo o endereço escrito
                    $s['endereco'] = array_merge($s['endereco'] ?? [], ['lat' => $local['lat'], 'lng' => $local['lng']]);
                    return true;
                }
                $partes = array_values(array_filter(array_map('trim', explode(',', $texto)), static fn (string $p): bool => $p !== ''));
                if (count($partes) < 3) {
                    $s['aviso'] = 'Faltou alguma parte. Separe por vírgulas: rua, número e bairro.';
                    return true;
                }
                $s['endereco'] = array_merge($s['endereco'] ?? [], [
                    'rua' => $partes[0], 'numero' => $partes[1], 'bairro' => $partes[2], 'complemento' => implode(', ', array_slice($partes, 3)),
                ]);
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
    private function confirmar(string $telefone, int $pedidoId): void
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

    /** O cliente digitou algo que o passo não entende. Número errado: repete a pergunta. Uma dúvida: a IA responde e o passo se repete. */
    private function invalido(string $telefone, array $s, string $texto, ?string $nome): string
    {
        $temLetra = preg_match('/\pL/u', $texto) === 1;
        if ($temLetra && $this->assistente->indisponivel($telefone) === null) {
            $r = $this->assistente->responder($telefone, $texto, $nome, ['contexto' => 'O cliente está no meio de um pedido por aqui pelo WhatsApp, '
                . (self::DESCRICAO_DO_PASSO[$s['etapa']] ?? 'fazendo o pedido') . '. Responda só a dúvida dele, em 1 ou 2 frases. Não mande link do cardápio nem peça para ele pedir pelo site: logo depois o sistema repete a pergunta do passo em que ele está.']);
            if ($r['ok']) {
                $this->enviar($telefone, $r['texto'], 'ia');
                $this->perguntar($telefone, $s);
                return 'ia';
            }
        }
        $s['aviso'] = $temLetra ? 'Não entendi. 🙂' : 'Essa opção não existe.';
        $this->perguntar($telefone, $s);
        return 'invalido';
    }

    // ---------- Perguntas ----------

    /** Monta a pergunta do passo atual, grava o que cada número quer dizer e envia. */
    private function perguntar(string $telefone, array $s): void
    {
        $catalogo = $this->cardapio->publico();
        $aviso = isset($s['aviso']) ? $s['aviso'] . "\n\n" : '';
        unset($s['aviso']);
        $s['opcoes'] = [];
        $linhas = [];
        $lista = static function (array &$s, array &$linhas, mixed $valor, string $rotulo): void {
            $s['opcoes'][] = $valor;
            $linhas[] = count($s['opcoes']) . '. ' . $rotulo;
        };

        switch ($s['etapa']) {
            case 'menu':
                $linhas[] = '🍝 *Cardápio* (responda com o número)';
                $linhas[] = '';
                $linhas[] = '*Pratos da casa*';
                foreach ($this->itens($catalogo, 'prato_casa') as $i) {
                    if ($i['estado'] !== 'disponivel') {
                        $linhas[] = "~{$i['nome']}~ (esgotado hoje)";
                        continue;
                    }
                    $lista($s, $linhas, ['prato', $i['id']], "{$i['nome']}" . ($i['descricao'] !== '' ? " — {$i['descricao']}" : ''));
                }
                $linhas[] = '';
                $lista($s, $linhas, ['monte', null], '*Monte a sua massa* (você escolhe tudo)');
                if ($this->itens($catalogo, 'simples') !== []) {
                    $lista($s, $linhas, ['simples', null], 'Bebidas e sobremesas');
                }
                if ($s['carrinho'] !== []) {
                    $lista($s, $linhas, ['fechar', null], '*Fechar o pedido* (' . self::itensTexto($s['carrinho']) . ', ' . self::reais($this->total($s, false)) . ')');
                }
                $linhas[] = '';
                $linhas[] = 'Tamanhos: ' . implode(' · ', array_map(static fn (array $t): string => "{$t['nome']} " . self::reais($t['preco_centavos']), $this->disponiveis($catalogo['tamanhos'])));
                $linhas[] = 'A qualquer momento: *cancelar* ou *atendente*.';
                break;

            case 'prato_tamanho':
                $prato = $this->itemDoCardapio($s['atual']['item_id']);
                $linhas[] = "*{$prato['nome']}*. Qual tamanho?";
                foreach ($this->disponiveis($catalogo['tamanhos']) as $t) {
                    $preco = $this->precoMontado($catalogo, ['tamanho_id' => $t['id'], 'massa_id' => $prato['massa_id'], 'molho_id' => $prato['molho_id'], 'ingrediente_ids' => $prato['ingrediente_ids']]);
                    $lista($s, $linhas, $t['id'], "{$t['nome']}" . ($t['descricao'] !== '' ? " ({$t['descricao']})" : '') . ' — ' . self::reais($preco));
                }
                break;

            case 'monte_tamanho':
                $linhas[] = '*Monte a sua massa* 🍝 Primeiro, o tamanho:';
                foreach ($this->disponiveis($catalogo['tamanhos']) as $t) {
                    $lista($s, $linhas, $t['id'], "{$t['nome']} — " . self::reais($t['preco_centavos']) . " (inclui {$t['inclusos']} ingredientes)");
                }
                break;

            case 'monte_massa':
            case 'monte_molho':
                $massa = $s['etapa'] === 'monte_massa';
                $linhas[] = $massa ? 'Qual massa?' : 'Qual molho?';
                foreach ($this->disponiveis($catalogo[$massa ? 'massas' : 'molhos']) as $o) {
                    $lista($s, $linhas, $o['id'], $o['nome'] . ($o['acrescimo_centavos'] > 0 ? ' (+' . self::reais($o['acrescimo_centavos']) . ')' : ''));
                }
                break;

            case 'monte_grupo':
                $grupo = $this->grupos()[$s['grupo']];
                $tamanho = array_column($catalogo['tamanhos'], null, 'id')[$s['atual']['tamanho_id']] ?? ['inclusos' => 0];
                $escolhidos = count($s['atual']['ingrediente_ids']);
                $linhas[] = "*{$grupo}* — pode escolher vários (ex.: 1, 3) ou 0 para nenhum.";
                if ($s['grupo'] === 0) {
                    $linhas[] = "Seu tamanho inclui {$tamanho['inclusos']} ingredientes; a partir daí, cada um custa o valor ao lado.";
                } elseif ($escolhidos > 0) {
                    $linhas[] = "Você já escolheu {$escolhidos} de {$tamanho['inclusos']} inclusos.";
                }
                foreach ($this->disponiveis(array_filter($catalogo['ingredientes'], static fn (array $i): bool => $i['grupo'] === $grupo)) as $i) {
                    $lista($s, $linhas, $i['id'], $i['nome'] . ' (+' . self::reais($i['preco_extra_centavos']) . ' se for extra)');
                }
                break;

            case 'simples':
                $linhas[] = '*Bebidas e sobremesas*';
                foreach ($this->itens($catalogo, 'simples') as $i) {
                    if ($i['estado'] !== 'disponivel') {
                        $linhas[] = "~{$i['nome']}~ (esgotado hoje)";
                        continue;
                    }
                    $lista($s, $linhas, $i['id'], "{$i['nome']} — " . self::reais($i['preco_centavos']));
                }
                break;

            case 'quantidade':
                $linhas[] = 'Quantas? (de 1 a 20)';
                break;

            case 'mais':
                $ultimo = $s['carrinho'][count($s['carrinho']) - 1];
                $linhas[] = "Anotado: {$ultimo['quantidade']}× {$ultimo['nome']} — " . self::reais($ultimo['preco'] * $ultimo['quantidade']) . '.';
                $linhas[] = 'Seu pedido: ' . self::itensTexto($s['carrinho']) . ', ' . self::reais($this->total($s, false)) . '.';
                $linhas[] = '';
                $linhas[] = '1. Pedir mais alguma coisa';
                $linhas[] = '2. Fechar o pedido';
                break;

            case 'tipo':
                $linhas[] = 'Entrega ou retirada?';
                if ($this->config->get('aceita_entrega', '1') === '1') {
                    $taxa = $this->config->inteiro('taxa_entrega_centavos');
                    $lista($s, $linhas, 'entrega', 'Entrega (' . ($taxa > 0 ? 'taxa ' . self::reais($taxa) . ', ' : '') . 'cerca de ' . $this->config->inteiro('tempo_entrega_min', 50) . ' min)');
                }
                if ($this->config->get('aceita_retirada', '1') === '1') {
                    $lista($s, $linhas, 'retirada', 'Retirada no balcão (cerca de ' . $this->config->inteiro('tempo_retirada_min', 25) . ' min)');
                }
                break;

            case 'nome':
                $linhas[] = 'Qual o seu nome?' . (!empty($s['nome_whatsapp']) ? " (ou responda 1 para usar *{$s['nome_whatsapp']}*)" : '');
                break;

            case 'local':
                $linhas[] = 'Mande a sua localização para a entrega: 📎 › *Localização* › *Enviar localização atual*.';
                $linhas[] = 'Se preferir não mandar, responda 0.';
                break;

            case 'endereco':
                $linhas[] = isset($s['endereco']['lat']) ? 'Recebi a localização! Agora escreva o endereço assim:' : 'Escreva o endereço assim:';
                $linhas[] = '*Rua, número, bairro, complemento*';
                $linhas[] = 'Ex.: Rua das Flores, 120, Vila Nova, portão azul';
                break;

            case 'pagamento':
                $linhas[] = 'Como vai pagar? (o pagamento é feito na ' . ($s['tipo'] === 'entrega' ? 'entrega' : 'retirada') . ')';
                $linhas[] = '1. Pix';
                $linhas[] = '2. Cartão';
                $linhas[] = '3. Dinheiro';
                break;

            case 'troco':
                $linhas[] = 'Troco para quanto? (ex.: 100) Responda 0 se não precisa de troco.';
                break;

            case 'resumo':
                $s['total_mostrado'] = $this->total($s);
                $linhas[] = '*Confira o seu pedido:*';
                foreach ($s['carrinho'] as $item) {
                    $linhas[] = "• {$item['quantidade']}× {$item['nome']} — " . self::reais($item['preco'] * $item['quantidade']);
                }
                if ($s['tipo'] === 'entrega' && ($taxa = $this->config->inteiro('taxa_entrega_centavos')) > 0) {
                    $linhas[] = '• Taxa de entrega — ' . self::reais($taxa);
                }
                $linhas[] = '*Total: ' . self::reais($s['total_mostrado']) . '*';
                $linhas[] = '';
                $linhas[] = $s['tipo'] === 'entrega'
                    ? "Entrega para {$s['nome']}: {$s['endereco']['rua']}, {$s['endereco']['numero']}, {$s['endereco']['bairro']}" . (($s['endereco']['complemento'] ?? '') !== '' ? ", {$s['endereco']['complemento']}" : '')
                    : "Retirada no balcão, em nome de {$s['nome']}";
                $linhas[] = 'Pagamento: ' . self::ROTULO_PAGAMENTO[$s['pagamento']['forma']]
                    . ($s['pagamento']['troco_para_centavos'] !== null ? ' (troco para ' . self::reais($s['pagamento']['troco_para_centavos']) . ')' : '');
                $linhas[] = '';
                $linhas[] = '1. Confirmar pedido';
                $linhas[] = '2. Começar de novo';
                $linhas[] = '3. Cancelar';
                break;
        }
        $this->salvar($telefone, $s);
        $this->enviar($telefone, $aviso . implode("\n", $linhas), 'fluxo:' . $s['etapa']);
    }

    // ---------- Cardápio e preço ----------

    /** Os grupos de ingredientes na ordem do cardápio (Proteínas, Acompanhamentos, Queijos...). @return list<string> */
    private function grupos(): array
    {
        return array_values(array_unique(array_column($this->cardapio->publico()['ingredientes'], 'grupo')));
    }

    private function itens(array $catalogo, string $tipo): array
    {
        $categorias = array_column($catalogo['categorias'], null, 'id');
        return array_values(array_filter($catalogo['itens'], static fn (array $i): bool => $i['tipo'] === $tipo && isset($categorias[$i['categoria_id']])));
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
        $ingredientes = array_column(array_merge($m['inclusos'], $m['extras']), 'nome');
        $nome = isset($atual['item_id'])
            ? $this->itemDoCardapio($atual['item_id'])['nome'] . " ({$m['tamanho']['nome']})"
            : "Monte a sua massa ({$m['tamanho']['nome']}): {$m['massa']['nome']}, molho {$m['molho']['nome']}" . ($ingredientes !== [] ? ', ' . implode(', ', $ingredientes) : '');
        return ['payload' => ['tipo' => 'montado', 'item_id' => $atual['item_id'] ?? null, 'tamanho_id' => $atual['tamanho_id'], 'massa_id' => $atual['massa_id'],
            'molho_id' => $atual['molho_id'], 'ingrediente_ids' => $atual['ingrediente_ids'], 'quantidade' => $quantidade, 'observacoes' => ''],
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
        return $subtotal + ($comTaxa && ($s['tipo'] ?? '') === 'entrega' ? $this->config->inteiro('taxa_entrega_centavos') : 0);
    }

    // ---------- Sessão ----------

    private function carregar(string $telefone): ?array
    {
        $consulta = Banco::conexao()->prepare('SELECT dados, atualizado_em FROM whatsapp_sessoes WHERE telefone = ?');
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
    private static function listaDeNumeros(string $texto): ?array
    {
        if (trim($texto) === '0') {
            return [];
        }
        if (preg_match('/^\s*\d{1,3}(\s*[,;e ]\s*\d{1,3})*\s*$/u', $texto) !== 1) {
            return null;
        }
        preg_match_all('/\d{1,3}/', $texto, $m);
        return array_values(array_unique(array_map('intval', $m[0])));
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

    private static function reais(int $centavos): string
    {
        return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
    }
}
