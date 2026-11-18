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
                if ($this->itens($catalogo, 'simples') !== []) {
                    $lista(['simples', null], 'Bebidas e sobremesas');
                }
                if ($s['carrinho'] !== []) {
                    $lista(['fechar', null], '*Fechar o pedido* (' . self::itensTexto($s['carrinho']) . ', ' . self::reais($this->total($s, false)) . ')');
                }
                $linhas[] = '';
                $tamanhos = 'Tamanhos: ' . implode(' · ', array_map(static fn (array $t): string => "{$t['nome']} " . self::reais($t['preco_centavos']), $this->disponiveis($catalogo['tamanhos'])));
                $linhas[] = $tamanhos;
                $linhas[] = 'A qualquer momento: *cancelar* ou *atendente*.';
                $contexto = ['🍝 *Cardápio*', ...($esgotados !== [] ? ['Esgotado hoje: ' . implode(', ', $esgotados) . '.'] : []), '', $tamanhos, 'A qualquer momento: *cancelar* ou *atendente*.'];
                $pergunta = 'O que vai ser?';
                break;

            case 'prato_tamanho':
                $prato = $this->itemDoCardapio($s['atual']['item_id']);
                $linhas[] = "*{$prato['nome']}*. Qual tamanho?";
                foreach ($this->disponiveis($catalogo['tamanhos']) as $t) {
                    $preco = $this->precoMontado($catalogo, ['tamanho_id' => $t['id'], 'massa_id' => $prato['massa_id'], 'molho_id' => $prato['molho_id'], 'ingrediente_ids' => $prato['ingrediente_ids']]);
                    $lista($t['id'], "{$t['nome']}" . ($t['descricao'] !== '' ? " ({$t['descricao']})" : '') . ' — ' . self::reais($preco));
                }
                $pergunta = "{$prato['nome']}: qual tamanho?";
                break;

            case 'monte_tamanho':
                $linhas[] = '*Monte a sua massa* 🍝 Primeiro, o tamanho:';
                foreach ($this->disponiveis($catalogo['tamanhos']) as $t) {
                    $lista($t['id'], "{$t['nome']} — " . self::reais($t['preco_centavos']) . " (inclui {$t['inclusos']} ingredientes)");
                }
                $pergunta = 'Monte a sua massa 🍝 Primeiro, o tamanho:';
                break;

            case 'monte_massa':
            case 'monte_molho':
                $massa = $s['etapa'] === 'monte_massa';
                $linhas[] = $massa ? 'Qual massa?' : 'Qual molho?';
                foreach ($this->disponiveis($catalogo[$massa ? 'massas' : 'molhos']) as $o) {
                    $lista($o['id'], $o['nome'] . ($o['acrescimo_centavos'] > 0 ? ' (+' . self::reais($o['acrescimo_centavos']) . ')' : ''));
                }
                $pergunta = $massa ? 'Qual massa?' : 'Qual molho?';
                break;

            case 'monte_grupo':
                $grupo = $this->grupos()[$s['grupo']];
                $tamanho = array_column($catalogo['tamanhos'], null, 'id')[$s['atual']['tamanho_id']] ?? ['inclusos' => 0];
                $escolhidos = count($s['atual']['ingrediente_ids']);
                $linhas[] = "*{$grupo}* — pode escolher vários (ex.: 1, 3) ou 0 para nenhum.";
                if ($s['grupo'] === 0) {
                    $ambos("Seu tamanho inclui {$tamanho['inclusos']} ingredientes; a partir daí, cada um custa o valor ao lado.");
                } elseif ($escolhidos > 0) {
                    $ambos("Você já escolheu {$escolhidos} de {$tamanho['inclusos']} inclusos.");
                }
                foreach ($this->disponiveis(array_filter($catalogo['ingredientes'], static fn (array $i): bool => $i['grupo'] === $grupo)) as $i) {
                    $lista($i['id'], $i['nome'] . ' (+' . self::reais($i['preco_extra_centavos']) . ' se for extra)');
                }
                $pergunta = "{$grupo}: marque o que quiser e toque em ✅ Pronto";
                $multipla = true;
                break;

            case 'simples':
                $esgotados = [];
                $linhas[] = '*Bebidas e sobremesas*';
                foreach ($this->itens($catalogo, 'simples') as $i) {
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
                $pergunta = 'Bebidas e sobremesas';
                break;

            case 'quantidade':
                $linhas[] = 'Quantas? (de 1 a 20)';
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
                    $taxa = $this->config->inteiro('taxa_entrega_centavos');
                    $lista('entrega', 'Entrega (' . ($taxa > 0 ? 'taxa ' . self::reais($taxa) . ', ' : '') . 'cerca de ' . $this->config->inteiro('tempo_entrega_min', 50) . ' min)');
                }
                if ($this->config->get('aceita_retirada', '1') === '1') {
                    $lista('retirada', 'Retirada no balcão (cerca de ' . $this->config->inteiro('tempo_retirada_min', 25) . ' min)');
                }
                $pergunta = 'Entrega ou retirada?';
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
                if ($s['tipo'] === 'entrega' && ($taxa = $this->config->inteiro('taxa_entrega_centavos')) > 0) {
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

        $enquete = $pergunta !== null && Conector::ativo() ? self::enquete($pergunta, $rotulos, $multipla) : null;
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
    private static function enquete(string $pergunta, array $rotulos, bool $multipla): ?array
    {
        $opcoes = [];
        foreach ($rotulos as $i => $rotulo) {
            $opcoes[] = self::cortar(($i + 1) . '. ' . self::semMarcas($rotulo), self::ENQUETE_MAX_OPCAO);
        }
        if ($multipla) {
            $opcoes[] = '✅ Pronto (ou nenhum)';
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
