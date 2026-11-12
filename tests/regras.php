<?php
// Testes das regras do servidor: preço do "Monte seu prato", pedido, token do WhatsApp e horários.
// Rodar (na raiz do projeto):   php -d extension=pdo_sqlite tests/regras.php
// Usa um banco temporário com os dados iniciais; não toca no banco de desenvolvimento.
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Banco;
use App\Core\Env;
use App\Core\ErroHttp;
use App\Repositories\CardapioRepository;
use App\Repositories\ConfigRepository;
use App\Services\LojaService;
use App\Services\PedidoService;
use App\Controllers\PainelController;
use App\Core\Requisicao;
use App\Core\Sessao;
use App\Services\AcompanhamentoDoCliente;
use App\Services\AtendimentoService;
use App\Services\PrecoService;
use App\Services\TempoDePreparo;
use App\Services\TokenWhatsApp;

// Banco dos testes. Padrão: um SQLite temporário. Com TEST_DATABASE_URL (a connection string do Neon, no .env ou no ambiente)
// a mesma suíte roda no Postgres de verdade, dentro de um schema que ela cria e apaga no fim: os dados reais não são tocados.
// Só roda no Postgres quando pedido ("php tests/regras.php pg"): cada consulta atravessa a internet, então leva uns 10 minutos.
$urlPgTeste = Env::get('TEST_DATABASE_URL');
$emPg = $urlPgTeste !== '' && in_array('pg', $argv ?? [], true);
if (in_array('pg', $argv ?? [], true) && $urlPgTeste === '') {
    fwrite(STDERR, "Para testar no Postgres, defina TEST_DATABASE_URL no .env (a connection string do Neon).\n");
    exit(1);
}
$banco = sys_get_temp_dir() . '/familiamassas-teste-' . bin2hex(random_bytes(4)) . '.sqlite';
$configBancoDeTeste = [];   // o que os processos filhos precisam saber para abrir o mesmo banco
if ($emPg) {
    $urlDireta = (string) preg_replace('/-pooler(?=\.)/', '', $urlPgTeste);   // a criação do schema pede conexão direta, não o pooler
    $criarSchemaDeTeste = static function () use ($urlDireta): array {
        $schema = 'teste_' . bin2hex(random_bytes(5));
        Env::definir('DATABASE_URL', $urlDireta);
        [$dsn, $usuario, $senha] = Banco::dadosDoPostgres();
        $admin = new PDO($dsn, $usuario, $senha, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $admin->exec("CREATE SCHEMA \"{$schema}\"");
        register_shutdown_function(static function () use ($admin, $schema): void {
            $admin->exec("DROP SCHEMA IF EXISTS \"{$schema}\" CASCADE");
        });
        return [$schema, $admin, ['DB_DRIVER' => 'pgsql', 'DATABASE_URL' => $urlDireta, 'DB_PG_SCHEMA' => $schema]];
    };
    [$schemaDeTeste, $adminPg, $configBancoDeTeste] = $criarSchemaDeTeste();
    foreach ($configBancoDeTeste as $chave => $valor) {
        Env::definir($chave, $valor);
    }
    echo "Rodando no Postgres (schema temporário {$schemaDeTeste}).\n";
} else {
    Env::definir('DB_DRIVER', 'sqlite');
    Env::definir('DB_SQLITE_PATH', $banco);
    register_shutdown_function(static function () use ($banco): void {
        foreach ([$banco, $banco . '-wal', $banco . '-shm'] as $arquivo) {
            @unlink($arquivo);
        }
    });
}

$falhas = 0;
$total = 0;
function conferir(string $descricao, mixed $obtido, mixed $esperado): void
{
    global $falhas, $total;
    $total++;
    if ($obtido === $esperado) {
        echo "  ok    {$descricao}\n";
        return;
    }
    $falhas++;
    echo "  FALHA {$descricao}\n        esperado: " . json_encode($esperado, JSON_UNESCAPED_UNICODE) . "\n        obtido:   " . json_encode($obtido, JSON_UNESCAPED_UNICODE) . "\n";
}
function codigoDoErro(callable $acao): string
{
    try {
        $acao();
        return 'sem erro';
    } catch (ErroHttp $e) {
        return $e->status . ' ' . $e->codigo;
    }
}

$cardapio = (new CardapioRepository())->publico();
$id = static function (string $lista, string $nome) use ($cardapio): int {
    foreach ($cardapio[$lista] as $linha) {
        if ($linha['nome'] === $nome) {
            return $linha['id'];
        }
    }
    throw new RuntimeException("Não achei {$nome} em {$lista}");
};
$ing = static fn (string ...$nomes): array => array_map(static fn (string $n): int => $id('ingredientes', $n), $nomes);
$preco = new PrecoService();
$montar = static fn (string $tamanho, string $massa, string $molho, array $ingredientes): array => $preco->montar($cardapio, [
    'tamanho_id' => $id('tamanhos', $tamanho), 'massa_id' => $id('massas', $massa), 'molho_id' => $id('molhos', $molho), 'ingrediente_ids' => $ingredientes,
]);
$nomes = static fn (array $lista): array => array_column($lista, 'nome');

// Os mesmos casos são conferidos em JavaScript (tests/preco.test.mjs) a partir deste arquivo.
$casos = [
    ['Meio sem ingredientes custa só a base', 'Meio', 'Penne', 'Sugo', [], 2490, []],
    ['Meio com 3 ingredientes (o limite): nada extra', 'Meio', 'Penne', 'Sugo', ['Picadinho de frango', 'Bacon', 'Brócolis'], 2490, []],
    ['Meio com 4: o mais barato vira extra', 'Meio', 'Penne', 'Sugo', ['Picadinho de frango', 'Bacon', 'Brócolis', 'Milho'], 2790, ['Milho']],
    ['Inteiro, bolonhesa (+3,00), 8 ingredientes: 2 extras', 'Inteiro', 'Penne', 'Bolonhesa', ['Picadinho de frango', 'Bacon', 'Camarão', 'Muçarela', 'Brócolis', 'Milho', 'Ervilha', 'Champignon'], 4890, ['Milho', 'Ervilha']],
    ['Mesma montagem em outra ordem de clique: mesmo preço', 'Inteiro', 'Penne', 'Bolonhesa', ['Champignon', 'Ervilha', 'Milho', 'Brócolis', 'Muçarela', 'Camarão', 'Bacon', 'Picadinho de frango'], 4890, ['Milho', 'Ervilha']],
    ['Inteiro, nhoque (+4,00), quatro queijos (+5,00), 6 ingredientes: tudo incluso', 'Inteiro', 'Nhoque', 'Quatro queijos', ['Picadinho de frango', 'Bacon', 'Brócolis', 'Milho', 'Muçarela', 'Camarão'], 4890, []],
    ['Camarão (o mais caro) nunca é o extra quando há mais baratos', 'Meio', 'Espaguete', 'Sugo', ['Camarão', 'Milho', 'Ervilha', 'Brócolis'], 2790, ['Ervilha']],
    ['Ingrediente repetido conta uma vez só', 'Meio', 'Penne', 'Sugo', ['Bacon', 'Bacon', 'Bacon', 'Bacon'], 2490, []],
];

echo "\nPREÇO DO MONTE SEU PRATO\n";
$paraJs = [];
foreach ($casos as [$descricao, $tamanho, $massa, $molho, $ingredientes, $esperado, $extras]) {
    $r = $montar($tamanho, $massa, $molho, $ing(...$ingredientes));
    conferir($descricao, [$r['preco_centavos'], $nomes($r['montagem']['extras'])], [$esperado, $extras]);
    $paraJs[] = ['descricao' => $descricao, 'escolha' => ['tamanho_id' => $id('tamanhos', $tamanho), 'massa_id' => $id('massas', $massa), 'molho_id' => $id('molhos', $molho), 'ingrediente_ids' => $ing(...$ingredientes)],
                 'preco' => $esperado, 'extras' => $extras];
}
file_put_contents(__DIR__ . '/casos-preco.json', json_encode(['cardapio' => $cardapio, 'casos' => $paraJs], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

conferir('Ingrediente esgotado (Palmito) é recusado', codigoDoErro(fn () => $montar('Meio', 'Penne', 'Sugo', $ing('Palmito'))), '409 esgotado');
conferir('Montagem sem tamanho é recusada', codigoDoErro(fn () => $preco->montar($cardapio, ['massa_id' => 1, 'molho_id' => 1])), '422 montagem_invalida');

echo "\nCARDÁPIO\n";
conferir('Dois tamanhos: Meio e Inteiro', array_column($cardapio['tamanhos'], 'nome'), ['Meio', 'Inteiro']);
$grupos = [];
foreach ($cardapio['ingredientes'] as $ingrediente) {
    $grupos[$ingrediente['grupo']][] = $ingrediente['nome'];
}
conferir('Ingredientes em três grupos, nesta ordem: Proteínas, Acompanhamentos e Queijos', array_keys($grupos), ['Proteínas', 'Acompanhamentos', 'Queijos']);
conferir('Proteínas: carne moída, picadinho de frango, carne seca e camarão (nessa ordem)', $grupos['Proteínas'], ['Carne moída', 'Picadinho de frango', 'Carne seca', 'Camarão']);
conferir('As proteínas não foram para os acompanhamentos', array_values(array_intersect($grupos['Proteínas'], $grupos['Acompanhamentos'])), []);
conferir('Bacon, calabresa e presunto estão em Acompanhamentos', array_values(array_intersect(['Bacon', 'Calabresa', 'Presunto'], $grupos['Acompanhamentos'])), ['Bacon', 'Calabresa', 'Presunto']);
conferir('Os vegetais também (nenhum grupo "Vegetais" sobrou)', [in_array('Brócolis', $grupos['Acompanhamentos'], true), isset($grupos['Vegetais'])], [true, false]);
conferir('Queijos continuam como estavam', in_array('Muçarela', $grupos['Queijos'], true), true);
conferir('A montagem não guarda mais "queijo ralado"', array_key_exists('queijo_ralado', $montar('Meio', 'Penne', 'Sugo', [])['montagem']), false);
conferir('O cliente só vê entrega e retirada', (new LojaService())->publica()['tipos_pedido'], ['entrega', 'retirada']);

echo "\nPEDIDO\n";
$pedidos = new PedidoService();
(new ConfigRepository())->salvar(['funcionamento' => 'aberta']);
$base = ['tipo' => 'retirada', 'cliente' => ['nome' => 'Teste', 'telefone' => '(11) 91234-5678'], 'pagamento' => ['forma' => 'pix'],
         'itens' => [['tipo' => 'simples', 'item_id' => $id('itens', 'Tiramisù'), 'quantidade' => 2]]];
$criado = $pedidos->criar($base);
$salvo = (new App\Repositories\PedidoRepository())->porId($criado['id']);
conferir('Pedido simples: total = 2 × 16,90, sem taxa na retirada', [$salvo['total_centavos'], $salvo['taxa_entrega_centavos'], $salvo['origem'], $salvo['status']], [3380, 0, 'cardapio', 'novo']);
conferir('Telefone é guardado só com dígitos e DDI', $salvo['cliente_telefone'], '5511912345678');

$entrega = $base + ['endereco' => ['rua' => 'Rua A', 'numero' => '1', 'bairro' => 'Centro']];
$entrega['tipo'] = 'entrega';
$salvo = (new App\Repositories\PedidoRepository())->porId($pedidos->criar($entrega)['id']);
conferir('Entrega soma a taxa (6,00)', [$salvo['subtotal_centavos'], $salvo['taxa_entrega_centavos'], $salvo['total_centavos']], [3380, 600, 3980]);

conferir('Preço mandado pelo navegador é ignorado; total divergente é recusado', codigoDoErro(fn () => $pedidos->criar($base + ['total_esperado_centavos' => 100])), '409 total_mudou');
conferir('Item esgotado (Chá gelado) é recusado', codigoDoErro(fn () => $pedidos->criar(['itens' => [['tipo' => 'simples', 'item_id' => $id('itens', 'Chá gelado de limão'), 'quantidade' => 1]]] + $base)), '409 esgotado');
conferir('Entrega sem endereço é recusada', codigoDoErro(fn () => $pedidos->criar(['tipo' => 'entrega'] + $base)), '422 validacao');
conferir('Dinheiro com troco menor que o total é recusado', codigoDoErro(fn () => $pedidos->criar(['pagamento' => ['forma' => 'dinheiro', 'troco_para_centavos' => 1000]] + $base)), '422 validacao');
conferir('Carrinho vazio é recusado', codigoDoErro(fn () => $pedidos->criar(['itens' => []] + $base)), '422 carrinho_vazio');

$token = (new TokenWhatsApp())->gerar('5511955554444');
$salvo = (new App\Repositories\PedidoRepository())->porId($pedidos->criar($base + ['t' => $token])['id']);
conferir('Com token válido: origem whatsapp e telefone do token (não o do formulário)', [$salvo['origem'], $salvo['cliente_telefone']], ['whatsapp', '5511955554444']);

$repo = new App\Repositories\PedidoRepository();
// (No "+" do PHP vale o valor da esquerda: o endereço novo vem primeiro para trocar o do $entrega.)
$comCoordenadas = $repo->porId($pedidos->criar(['endereco' => ['rua' => 'Rua A', 'numero' => '1', 'bairro' => 'Centro', 'lat' => -23.5614, 'lng' => -46.6558]] + $entrega)['id']);
conferir('Entrega guarda o local marcado no mapa', [$comCoordenadas['entrega_lat'], $comCoordenadas['entrega_lng']], [-23.5614, -46.6558]);
$foraDoPlaneta = $repo->porId($pedidos->criar(['endereco' => ['rua' => 'Rua A', 'numero' => '1', 'bairro' => 'Centro', 'lat' => 123, 'lng' => 'abc']] + $entrega)['id']);
conferir('Coordenada inválida é ignorada e o pedido segue com o endereço digitado', [$foraDoPlaneta['entrega_lat'], $foraDoPlaneta['endereco_rua']], [null, 'Rua A']);
$retiradaComObs = $repo->porId($pedidos->criar($base + ['observacoes' => 'não deveria ficar'])['id']);
conferir('Retirada não tem observação do pedido (o servidor descarta)', $retiradaComObs['observacoes'], '');
$entregaComObs = $repo->porId($pedidos->criar($entrega + ['observacoes' => 'interfone quebrado'])['id']);
conferir('Entrega mantém a observação do pedido', $entregaComObs['observacoes'], 'interfone quebrado');
conferir('Cliente não pede mesa (é do salão)', codigoDoErro(fn () => $pedidos->criar(['tipo' => 'local', 'mesa' => '3'] + $base)), '422 validacao');
conferir('Cliente não pede "pra viagem" (é do salão)', codigoDoErro(fn () => $pedidos->criar(['tipo' => 'viagem'] + $base)), '422 validacao');
conferir('Cliente não paga "no caixa" (é do salão)', codigoDoErro(fn () => $pedidos->criar(['pagamento' => ['forma' => 'caixa']] + $base)), '422 validacao');

echo "\nATENDIMENTO NO SALÃO (a conta do cliente)\n";
$atendente = ['id' => 2, 'perfil' => 'atendente'];
$admin = ['id' => 1, 'perfil' => 'admin'];
$salao = new AtendimentoService();
$contarAtendimentos = static fn (): int => (int) App\Core\Banco::conexao()->query('SELECT COUNT(*) FROM atendimentos')->fetchColumn();
$tiramisu = [['tipo' => 'simples', 'item_id' => $id('itens', 'Tiramisù'), 'quantidade' => 1]];   // R$ 16,90
$doisTiramisus = [['tipo' => 'simples', 'item_id' => $id('itens', 'Tiramisù'), 'quantidade' => 2]];   // R$ 33,80
$esgotado = [['tipo' => 'simples', 'item_id' => $id('itens', 'Chá gelado de limão'), 'quantidade' => 1]];
$abrir = static fn (array $mudar): array => $mudar + ['cliente' => ['nome' => 'Bia'], 'modalidade' => 'mesa', 'mesa' => '2', 'itens' => $tiramisu];

$ana = $salao->abrir(['cliente' => ['nome' => 'Ana'], 'modalidade' => 'mesa', 'mesa' => '5', 'itens' => $tiramisu], $atendente);
$contaAna = $salao->detalhe($ana['id']);
$primeiro = $repo->porId($ana['pedido_id']);
conferir('Abrir (Mesa): cliente, modalidade, mesa, status aberto e sem pagamento ainda', [$contaAna['cliente_nome'], $contaAna['modalidade'], $contaAna['mesa'], $contaAna['status'], $contaAna['pagamento']], ['Ana', 'mesa', '5', 'aberto', null]);
conferir('A conta registra quem abriu; ainda não tem fechamento', [$contaAna['aberto_por_nome'] !== null, $contaAna['encerrado_em'], $contaAna['total_centavos']], [true, null, 1690]);
conferir('O primeiro pedido nasce ligado ao atendimento, no salão e "na conta" (sem forma de pagamento)', [$primeiro['atendimento_id'], $primeiro['origem'], $primeiro['tipo'], $primeiro['mesa'], $primeiro['pagamento'], $primeiro['status'], $primeiro['criado_por']], [$ana['id'], 'balcao', 'local', '5', 'caixa', 'novo', 2]);
conferir('O atendimento tem o seu próprio id, diferente do número do pedido', [$ana['id'], $ana['numero']], [1, $ana['pedido_id']]);

$carlos = $salao->abrir(['cliente' => ['nome' => 'Carlos'], 'modalidade' => 'viagem', 'mesa' => '9', 'itens' => $tiramisu], $atendente);
$contaCarlos = $salao->detalhe($carlos['id']);
$pedidoCarlos = $repo->porId($carlos['pedido_id']);
conferir('Pra viagem: não tem mesa (mesmo que venha uma) e o pedido é do tipo "viagem"', [$contaCarlos['modalidade'], $contaCarlos['mesa'], $pedidoCarlos['tipo'], $pedidoCarlos['mesa']], ['viagem', null, 'viagem', null]);

$antes = $contarAtendimentos();
conferir('Nome do cliente é obrigatório', codigoDoErro(fn () => $salao->abrir($abrir(['cliente' => ['nome' => '  ']]), $atendente)), '422 validacao');
conferir('Nome de uma letra só é recusado', codigoDoErro(fn () => $salao->abrir($abrir(['cliente' => ['nome' => 'A']]), $atendente)), '422 validacao');
conferir('A modalidade é obrigatória (vem antes dos produtos)', codigoDoErro(fn () => $salao->abrir($abrir(['modalidade' => '']), $atendente)), '422 validacao');
conferir('Modalidade só aceita Mesa ou Pra viagem', codigoDoErro(fn () => $salao->abrir($abrir(['modalidade' => 'entrega']), $atendente)), '422 validacao');
conferir('Mesa exige o número da mesa', codigoDoErro(fn () => $salao->abrir($abrir(['mesa' => '']), $atendente)), '422 validacao');
conferir('Sem produtos não abre atendimento', codigoDoErro(fn () => $salao->abrir($abrir(['itens' => []]), $atendente)), '422 carrinho_vazio');
conferir('O preço é do servidor: total divergente é recusado', codigoDoErro(fn () => $salao->abrir($abrir(['total_esperado_centavos' => 1]), $atendente)), '409 total_mudou');
conferir('Item esgotado é recusado', codigoDoErro(fn () => $salao->abrir($abrir(['itens' => $esgotado]), $atendente)), '409 esgotado');
conferir('Nenhum pedido recusado deixou atendimento para trás', $contarAtendimentos() - $antes, 0);

(new ConfigRepository())->salvar(['funcionamento' => 'fechada']);
$noite = $salao->abrir($abrir(['cliente' => ['nome' => 'Noite'], 'modalidade' => 'viagem', 'mesa' => '']), $atendente);
(new ConfigRepository())->salvar(['funcionamento' => 'aberta']);
conferir('Pra viagem não pede mesa, e o salão atende mesmo com o cardápio fechado para o cliente', $noite['id'] > 0, true);

$segundo = $salao->adicionarPedido($ana['id'], ['itens' => $doisTiramisus], $atendente);
$contaAna = $salao->detalhe($ana['id']);
$segundoPedido = $repo->porId($segundo['id']);
conferir('Mais produtos na mesma conta: 2 pedidos, cada um com o seu número', [count($contaAna['pedidos']), $segundo['id'] !== $ana['pedido_id']], [2, true]);
conferir('O pedido novo herda cliente, mesa e atendimento', [$segundoPedido['atendimento_id'], $segundoPedido['cliente_nome'], $segundoPedido['mesa'], $segundoPedido['pagamento']], [$ana['id'], 'Ana', '5', 'caixa']);
conferir('O valor da conta soma os pedidos uma vez cada (16,90 + 2 × 16,90) e conta os itens', [$contaAna['total_centavos'], $contaAna['quantidade_itens'], $contaAna['pedidos_ativos']], [5070, 3, 2]);
conferir('Somar pedido também confere o total do servidor', codigoDoErro(fn () => $salao->adicionarPedido($ana['id'], ['itens' => $tiramisu, 'total_esperado_centavos' => 1], $atendente)), '409 total_mudou');
conferir('Atendimento que não existe', codigoDoErro(fn () => $salao->adicionarPedido(99999, ['itens' => $tiramisu], $atendente)), '404 nao_encontrado');

conferir('Pedidos novos: a conta está na fase "cozinha"', $contaAna['fase'], 'cozinha');
$pedidos->mudarStatus($ana['pedido_id'], 'pronto', null, $atendente);
$contaAna = $salao->detalhe($ana['id']);
conferir('Cada pedido tem o seu status: um pronto, o outro ainda novo', array_column($contaAna['pedidos'], 'status'), ['pronto', 'novo']);
conferir('Com pedido na cozinha a fase é "cozinha"; a conta segue aberta', [$contaAna['fase'], $contaAna['status']], ['cozinha', 'aberto']);
$pedidos->mudarStatus($segundo['id'], 'pronto', null, $atendente);
conferir('Tudo pronto: a conta vai para "pronto para servir"', $salao->detalhe($ana['id'])['fase'], 'pronto');
$servidos = $salao->servirProntos($ana['id'], $atendente);
conferir('Servir leva os pedidos prontos a Concluído, e a conta continua aberta esperando o pagamento', [array_column($servidos['pedidos'], 'status'), $servidos['fase'], $servidos['status']], [['concluido', 'concluido'], 'conta', 'aberto']);

echo "\nFECHAR A CONTA\n";
conferir('Sem forma de pagamento não fecha', codigoDoErro(fn () => $salao->fechar($ana['id'], ['total_esperado_centavos' => 5070], $atendente)), '422 validacao');
conferir('Forma de pagamento inválida é recusada', codigoDoErro(fn () => $salao->fechar($ana['id'], ['pagamento' => 'fiado', 'total_esperado_centavos' => 5070], $atendente)), '422 validacao');
conferir('Total conferido diferente do da conta é recusado', codigoDoErro(fn () => $salao->fechar($ana['id'], ['pagamento' => 'pix', 'total_esperado_centavos' => 100], $atendente)), '409 total_mudou');
conferir('Nenhuma dessas tentativas fechou a conta', $salao->detalhe($ana['id'])['status'], 'aberto');
$fechada = $salao->fechar($ana['id'], ['pagamento' => 'pix', 'total_esperado_centavos' => 5070], $atendente);
conferir('Fechar: status encerrado, forma de pagamento e valor cobrado', [$fechada['status'], $fechada['fase'], $fechada['pagamento'], $fechada['total_centavos']], ['encerrado', 'encerrado', 'pix', 5070]);
conferir('Fechar registra a data/hora e quem fechou', [$fechada['encerrado_em'] !== null, $fechada['encerrado_por_nome'] !== null], [true, true]);
conferir('O pagamento fica na conta; os pedidos continuam "na conta"', $repo->porId($ana['pedido_id'])['pagamento'], 'caixa');
conferir('Fechar de novo (duplo clique) é recusado', codigoDoErro(fn () => $salao->fechar($ana['id'], ['pagamento' => 'pix', 'total_esperado_centavos' => 5070], $atendente)), '409 ja_encerrado');
conferir('Conta encerrada não aceita produtos novos', codigoDoErro(fn () => $salao->adicionarPedido($ana['id'], ['itens' => $tiramisu], $atendente)), '409 atendimento_encerrado');
conferir('Nem pedidos novos nem o fechamento mexeram no valor cobrado', $salao->detalhe($ana['id'])['total_centavos'], 5070);

$bia = $salao->abrir($abrir(['modalidade' => 'viagem', 'mesa' => '']), $atendente);
$bia2 = $salao->adicionarPedido($bia['id'], ['itens' => $doisTiramisus], $atendente);
$pedidos->mudarStatus($bia2['id'], 'cancelado', 'Errou o pedido', $atendente);
$contaBia = $salao->detalhe($bia['id']);
conferir('Pedido cancelado não entra na conta', [$contaBia['total_centavos'], $contaBia['quantidade_itens'], $contaBia['pedidos_ativos'], count($contaBia['pedidos'])], [1690, 1, 1, 2]);
$fechadaBia = $salao->fechar($bia['id'], ['pagamento' => 'dinheiro', 'total_esperado_centavos' => 1690], $atendente);
conferir('Dá para fechar com pedido ainda na cozinha: ele continua no quadro de Pedidos', [$fechadaBia['status'], $repo->porId($bia['pedido_id'])['status']], ['encerrado', 'novo']);
conferir('Depois de fechada, não se cancela pedido da conta (o valor já foi cobrado)', codigoDoErro(fn () => $pedidos->mudarStatus($bia['pedido_id'], 'cancelado', 'Desistiu', $atendente)), '409 conta_encerrada');
conferir('Mas a cozinha termina o pedido normalmente', $pedidos->mudarStatus($bia['pedido_id'], 'concluido', null, $atendente)['status'], 'concluido');

$ze = $salao->abrir($abrir(['cliente' => ['nome' => 'Zé'], 'modalidade' => 'viagem', 'mesa' => '']), $atendente);
$pedidos->mudarStatus($ze['pedido_id'], 'cancelado', 'Cliente desistiu', $atendente);
$fechadaZe = $salao->fechar($ze['id'], ['total_esperado_centavos' => 0], $atendente);
conferir('Conta sem nada a cobrar (tudo cancelado) fecha sem forma de pagamento', [$fechadaZe['status'], $fechadaZe['pagamento'], $fechadaZe['total_centavos']], ['encerrado', null, 0]);

echo "\nQUADRO E HISTÓRICO DO SALÃO\n";
$quadro = $salao->quadro();
$noQuadro = array_column($quadro['atendimentos'], null, 'id');
conferir('O quadro traz as contas abertas e as encerradas hoje, cada uma na sua fase', [$noQuadro[$ana['id']]['fase'], $noQuadro[$carlos['id']]['fase'], $noQuadro[$bia['id']]['fase']], ['encerrado', 'cozinha', 'encerrado']);
conferir('Resumo: abertos, na cozinha, encerrados hoje, valor em aberto e contas pagas hoje', [$quadro['resumo']['abertos'], $quadro['resumo']['cozinha'], $quadro['resumo']['encerrados_hoje'], $quadro['resumo']['valor_em_aberto_centavos'], $quadro['resumo']['vendido_hoje_centavos']], [2, 2, 3, 3380, 6760]);
$historico = $salao->historico('hoje', null);
conferir('Histórico de hoje: contas, encerradas e abertas', [$historico['resumo']['quantidade'], $historico['resumo']['encerrados'], $historico['resumo']['abertos']], [5, 3, 2]);
conferir('Histórico: total cobrado e total por forma de pagamento', [$historico['resumo']['total_cobrado_centavos'], $historico['resumo']['por_pagamento']], [6760, ['pix' => 5070, 'cartao' => 0, 'dinheiro' => 1690]]);
conferir('Histórico filtra por situação', [count($salao->historico('hoje', 'encerrado')['atendimentos']), count($salao->historico('hoje', 'aberto')['atendimentos'])], [3, 2]);
conferir('Período desconhecido vira "hoje"', $salao->historico('qualquer', null)['periodo'], 'hoje');
conferir('Cada atendimento do histórico traz pedidos, itens e personalizações para consulta', isset($historico['atendimentos'][0]['pedidos'][0]['itens']), true);

echo "\nTEMPO DE PREPARO (cor do cartão)\n";
$t = static fn (int $segundos): string => gmdate('Y-m-d H:i:s', 1_800_000_000 + $segundos);
$soNovo = TempoDePreparo::calcular([['status' => 'novo', 'criado_em' => $t(0)]]);
conferir('Só "Novo" aberto: nada fechado e o período aberto começa na criação', [$soNovo['acumulado_s'], $soNovo['desde'] !== null], [0, true]);
conferir('Novo (10 min) e depois Em preparo aberto: soma o período fechado e deixa o aberto', TempoDePreparo::calcular([['status' => 'novo', 'criado_em' => $t(0)], ['status' => 'em_preparo', 'criado_em' => $t(600)]])['acumulado_s'], 600);
conferir('Mudar de Novo para Em preparo não zera o relógio: 10 min + 15 min = 25', 600 + 900, 1500);
$historico = [['status' => 'novo', 'criado_em' => $t(0)], ['status' => 'em_preparo', 'criado_em' => $t(600)], ['status' => 'pronto', 'criado_em' => $t(1800)], ['status' => 'em_preparo', 'criado_em' => $t(2400)]];
conferir('Voltou de Pronto: soma os dois períodos de preparo e não conta o tempo em Pronto (10 + 20 min)', TempoDePreparo::calcular($historico)['acumulado_s'], 1800);
conferir('Pedido pronto não tem período aberto (o relógio para)', TempoDePreparo::calcular([['status' => 'novo', 'criado_em' => $t(0)], ['status' => 'pronto', 'criado_em' => $t(900)]])['desde'], null);
foreach ([[0, 'neutro'], [19 * 60, 'neutro'], [19 * 60 + 59, 'neutro'], [20 * 60, 'atencao'], [29 * 60 + 59, 'atencao'], [30 * 60, 'atrasado'], [90 * 60, 'atrasado']] as [$segundos, $esperado]) {
    conferir(sprintf('%d min %02d s → %s', intdiv($segundos, 60), $segundos % 60, $esperado), TempoDePreparo::faixa($segundos), $esperado);
}
$doBanco = $repo->porId($carlos['pedido_id']);
conferir('O pedido vindo do banco traz o tempo de preparo (período aberto desde a criação)', [$doBanco['producao_acumulado_s'], $doBanco['producao_desde'] !== null], [0, true]);

echo "\nFECHAMENTO DO DIA\n";
$antes = $repo->fechamentoDoDia();
$retirada = $pedidos->criar($base)['id'];
$desistiu = $pedidos->criar($base)['id'];
$pedidos->mudarStatus($retirada, 'concluido', null, $atendente);
$pedidos->mudarStatus($desistiu, 'cancelado', 'Cliente desistiu', $atendente);
$depois = $repo->fechamentoDoDia();
conferir('Concluir um pedido do cardápio soma ao faturamento (2 × 16,90) e cancelar não soma', [$depois['concluidos'] - $antes['concluidos'], $depois['cancelados'] - $antes['cancelados'], $depois['faturamento_centavos'] - $antes['faturamento_centavos']], [1, 1, 3380]);
conferir('Os pedidos do dia são a soma dos três grupos', $depois['pedidos'], $depois['concluidos'] + $depois['cancelados'] + $depois['em_andamento']);

$caio = $salao->abrir(['cliente' => ['nome' => 'Caio'], 'modalidade' => 'viagem', 'itens' => $doisTiramisus], $atendente);
$pedidos->mudarStatus($caio['pedido_id'], 'concluido', null, $atendente);
$semFechar = $repo->fechamentoDoDia();
conferir('Pedido servido de uma conta aberta ainda não é dinheiro recebido', $semFechar['faturamento_centavos'] - $depois['faturamento_centavos'], 0);
$salao->fechar($caio['id'], ['pagamento' => 'cartao', 'total_esperado_centavos' => 3380], $atendente);
$fechado = $repo->fechamentoDoDia();
conferir('Ao fechar a conta, o valor cobrado entra uma vez só no faturamento', $fechado['faturamento_centavos'] - $semFechar['faturamento_centavos'], 3380);
conferir('O quadro de Pedidos só recebe os pedidos abertos (encerrados saem)', count(array_filter($repo->paraPainel(null), static fn (array $p): bool => in_array($p['status'], ['concluido', 'cancelado'], true))), 0);

echo "\nSTATUS\n";
$idPedido = $criado['id'];
conferir('Retirada não aceita "saiu para entrega"', codigoDoErro(fn () => $pedidos->mudarStatus($idPedido, 'saiu_entrega', null, $admin)), '422 status_invalido');
conferir('Atendente move o pedido até "pronto"', $pedidos->mudarStatus($idPedido, 'pronto', null, $atendente)['status'], 'pronto');
conferir('Cancelar exige motivo', codigoDoErro(fn () => $pedidos->mudarStatus($idPedido, 'cancelado', '  ', $admin)), '422 validacao');
conferir('Cancelar com motivo funciona', $pedidos->mudarStatus($idPedido, 'cancelado', 'Cliente desistiu', $admin)['status'], 'cancelado');
conferir('Pedido encerrado não muda mais', codigoDoErro(fn () => $pedidos->mudarStatus($idPedido, 'novo', null, $admin)), '409 pedido_encerrado');

echo "\nIMPRESSÃO (claim atômico)\n";
$repo = new App\Repositories\PedidoRepository();
$outro = $pedidos->criar($base)['id'];
conferir('Primeira estação fica com a impressão', $repo->reivindicarImpressao($outro, 'estacao-a'), true);
conferir('Segunda estação não imprime o mesmo pedido', $repo->reivindicarImpressao($outro, 'estacao-b'), false);
conferir('Fica registrado quem imprimiu', $repo->porId($outro)['impresso_por'], 'estacao-a');

echo "\nPERFIS E TELAS\n";
conferir('Só existem os perfis proprietário (admin) e atendente', Sessao::PERFIS, ['admin', 'atendente']);
conferir('Atendente entra direto no quadro do Salão; o proprietário, no quadro de pedidos', [PainelController::telaInicial('atendente'), PainelController::telaInicial('admin')], ['/painel/salao', '/painel/pedidos']);
conferir('Menu da atendente: salão, pedidos e cardápio (nessa ordem)', array_keys(PainelController::menuDo('atendente')), ['salao', 'pedidos', 'cardapio']);
conferir('Menu do proprietário: tudo, com o histórico do salão', array_keys(PainelController::menuDo('admin')), ['pedidos', 'salao', 'historico', 'cardapio', 'configuracoes', 'whatsapp']);
conferir('"Produtos do atendimento" é uma tela de apoio: fora do menu, mas a atendente a abre', [isset(PainelController::menuDo('atendente')['atendimento']), PainelController::TELAS['atendimento']['perfis']], [false, ['admin', 'atendente']]);
conferir('O módulo Cozinha não existe mais', isset(PainelController::TELAS['cozinha']), false);
conferir('Histórico, Configurações e WhatsApp são só do proprietário', [PainelController::TELAS['historico']['perfis'], PainelController::TELAS['configuracoes']['perfis'], PainelController::TELAS['whatsapp']['perfis']], [['admin'], ['admin'], ['admin']]);

echo "\nACOMPANHAMENTO NA HOME (cookie)\n";
$acompanhamento = new AcompanhamentoDoCliente();
$req = new Requisicao();
$_COOKIE[AcompanhamentoDoCliente::COOKIE] = '';
$codigos = [];
foreach ([$criado['id'], $outro, $comCoordenadas['id'], $foraDoPlaneta['id']] as $numero) {
    $codigo = $repo->porId($numero)['codigo'];
    $codigos[] = $codigo;
    $acompanhamento->registrar($req, $codigo);
}
conferir('Guarda no máximo 3 pedidos (os mais novos)', $acompanhamento->codigos($req), [$codigos[3], $codigos[2], $codigos[1]]);
$_COOKIE[AcompanhamentoDoCliente::COOKIE] = "lixo,../../etc,{$codigos[3]},{$codigos[3]},ZZZZZZZZZZZZZZZZ";
conferir('Ignora texto que não é um código e repetições', $acompanhamento->codigos($req), [$codigos[3]]);
$acompanhamento->remover($req, $codigos[3]);
conferir('Dispensar tira o pedido do cookie', $acompanhamento->codigos($req), []);
$_COOKIE[AcompanhamentoDoCliente::COOKIE] = $codigos[3] . ',0123456789abcdef';
$paraHome = $acompanhamento->paraHome($req);
conferir('Pedido que não existe mais sai da Home e do cookie', [count($paraHome), $acompanhamento->codigos($req)], [1, [$codigos[3]]]);
conferir('A Home recebe o pedido no formato público, sem itens, telefone nem endereço', [isset($paraHome[0]['itens']), isset($paraHome[0]['cliente_telefone']), $paraHome[0]['status'], $paraHome[0]['primeiro_nome']], [false, false, 'novo', 'Teste']);
$repo->mudarStatus($foraDoPlaneta['id'], 'novo', 'concluido', null, null);
App\Core\Banco::conexao()->prepare('UPDATE pedidos SET status_atualizado_em = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 4 * 3600), $foraDoPlaneta['id']]);
conferir('Pedido encerrado há mais de 3 h sai da Home', count($acompanhamento->paraHome($req)), 0);

echo "\nWHATSAPP: webhook, bot, avisos de status e atendimento humano (Etapa 4)\n";
// Um mensageiro de mentira: guarda o que seria enviado e devolve a resposta que o teste quiser.
$falsoWhats = new class implements App\WhatsApp\Mensageiro {
    public array $enviadas = [];
    public array $respostas = [];
    public function enviar(string $telefone, array $mensagem): array
    {
        $this->enviadas[] = ['para' => $telefone] + $mensagem;
        return array_shift($this->respostas) ?? ['ok' => true, 'wamid' => 'teste.' . bin2hex(random_bytes(6)), 'erro' => null, 'transitorio' => false, 'janela_fechada' => false];
    }
};
App\WhatsApp\WhatsApp::usarMensageiro($falsoWhats);
Env::definir('APP_URL', 'https://exemplo.test');
App\Core\Banco::conexao()->exec('DELETE FROM whatsapp_envios_pendentes');   // avisos de pedidos das seções anteriores não contam aqui
$logAntesWa = (string) ini_get('error_log');
ini_set('error_log', sys_get_temp_dir() . '/familiamassas-whatsapp-teste.log');   // as falhas simuladas de propósito não poluem a saída
(new ConfigRepository())->salvar(['funcionamento' => 'aberta']);
$webhook = new App\WhatsApp\Webhook();
$conversasWa = new App\WhatsApp\Conversas();
$avisosWa = new App\WhatsApp\Avisos();
$foneWa = '5511987650001';
$mensagemWa = static fn (string $wamid, string $texto, ?string $botao = null): array => ['entry' => [['changes' => [['value' => [
    'contacts' => [['wa_id' => $foneWa, 'profile' => ['name' => 'Maria Silva']]],
    'messages' => [$botao === null
        ? ['id' => $wamid, 'from' => $foneWa, 'type' => 'text', 'text' => ['body' => $texto]]
        : ['id' => $wamid, 'from' => $foneWa, 'type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $botao, 'title' => $texto]]]],
]]]]]];
$ultimaWa = static function () use ($falsoWhats): array {
    return $falsoWhats->enviadas[count($falsoWhats->enviadas) - 1] ?? [];
};

conferir('GET da Meta: verify token certo devolve o hub.challenge', App\WhatsApp\Webhook::desafio('subscribe', 'segredo-verificacao', '12345', 'segredo-verificacao'), '12345');
conferir('GET da Meta: verify token errado, ou sem verify token configurado, é recusado', [App\WhatsApp\Webhook::desafio('subscribe', 'outro', '1', 'segredo-verificacao'), App\WhatsApp\Webhook::desafio('subscribe', '', '1', '')], [null, null]);
$corpoWa = '{"entry":[]}';
$assinaturaWa = 'sha256=' . hash_hmac('sha256', $corpoWa, 'app-secret-de-teste');
conferir('Assinatura X-Hub-Signature-256 certa é aceita', App\WhatsApp\Webhook::assinaturaValida($corpoWa, $assinaturaWa, 'app-secret-de-teste'), true);
conferir('Corpo alterado, assinatura sem "sha256=" ou sem app secret: recusado', [
    App\WhatsApp\Webhook::assinaturaValida($corpoWa . ' ', $assinaturaWa, 'app-secret-de-teste'),
    App\WhatsApp\Webhook::assinaturaValida($corpoWa, substr($assinaturaWa, 7), 'app-secret-de-teste'),
    App\WhatsApp\Webhook::assinaturaValida($corpoWa, $assinaturaWa, ''),
], [false, false, false]);

$antes = count($falsoWhats->enviadas);
$r = $webhook->processar($mensagemWa('wamid.oi.1', 'oi'));
$boasVindas = $ultimaWa();
conferir('"oi" → boas-vindas com o nome do cliente, o status da loja e os 3 botões', [
    $r['mensagens'], count($falsoWhats->enviadas) - $antes, $boasVindas['type'] ?? null,
    str_contains($boasVindas['interactive']['body']['text'] ?? '', 'Olá, Maria!'), str_contains($boasVindas['interactive']['body']['text'] ?? '', 'Aberto agora'),
    array_column(array_column($boasVindas['interactive']['action']['buttons'] ?? [], 'reply'), 'id'),
], [1, 1, 'interactive', true, true, ['pedir', 'acompanhar', 'atendente']]);
$r = $webhook->processar($mensagemWa('wamid.oi.1', 'oi'));
conferir('Evento repetido da Meta (mesmo wamid): não responde de novo', [$r['repetidas'], $r['mensagens'], count($falsoWhats->enviadas) - $antes], [1, 0, 1]);
conferir('A mensagem do cliente abre a janela de 24 h', $conversasWa->janelaAberta($foneWa), true);

$webhook->processar($mensagemWa('wamid.pedir.1', 'Fazer pedido', 'pedir'));
$link = $ultimaWa();
$urlDoLink = $link['interactive']['action']['parameters']['url'] ?? '';
parse_str((string) parse_url($urlDoLink, PHP_URL_QUERY), $consultaDoLink);
conferir('[Fazer pedido] → botão com o link do cardápio; o token do link leva o telefone de quem pediu', [
    $link['interactive']['type'] ?? null, str_starts_with($urlDoLink, 'https://exemplo.test/?t='), (new TokenWhatsApp())->validar($consultaDoLink['t'] ?? ''),
], ['cta_url', true, $foneWa]);
(new ConfigRepository())->salvar(['funcionamento' => 'fechada']);
$webhook->processar($mensagemWa('wamid.pedir.2', 'Fazer pedido', 'pedir'));
conferir('Loja fechada: [Fazer pedido] avisa o horário e não manda o link', [$ultimaWa()['type'] ?? null, str_contains($ultimaWa()['text']['body'] ?? '', 'fechados')], ['text', true]);
(new ConfigRepository())->salvar(['funcionamento' => 'aberta']);

$webhook->processar($mensagemWa('wamid.acomp.1', 'Acompanhar pedido', 'acompanhar'));
conferir('[Acompanhar pedido] sem pedido em andamento: oferece fazer um ou falar com atendente', array_column(array_column($ultimaWa()['interactive']['action']['buttons'] ?? [], 'reply'), 'id'), ['pedir', 'atendente']);

// O cliente abre o link e faz o pedido no cardápio: origem whatsapp, e o aviso "Recebemos" entra na fila.
$pedidoWa = $pedidos->criar($base + ['t' => $consultaDoLink['t']]);
$doBancoWa = $repo->porId($pedidoWa['id']);
conferir('Pedido pelo link do bot: origem "whatsapp" e o telefone vem do token', [$doBancoWa['origem'], $doBancoWa['cliente_telefone']], ['whatsapp', $foneWa]);
$antes = count($falsoWhats->enviadas);
conferir('O aviso "Recebemos seu pedido" é enviado pela fila (dentro da janela, texto livre)', [
    $avisosWa->processar(), count($falsoWhats->enviadas) - $antes, $ultimaWa()['type'] ?? null, str_contains($ultimaWa()['text']['body'] ?? '', "Recebemos seu pedido #{$pedidoWa['id']}"),
], [1, 1, 'text', true]);
conferir('A fila ficou vazia e a conversa guarda o último pedido', [count($avisosWa->pendentes()), $conversasWa->conversa($foneWa)['ultimo_pedido_id']], [0, $pedidoWa['id']]);
$webhook->processar($mensagemWa('wamid.acomp.2', 'Acompanhar pedido', 'acompanhar'));
conferir('[Acompanhar pedido] com pedido: número, status e link de acompanhamento', [
    str_contains($ultimaWa()['text']['body'] ?? '', "Pedido #{$pedidoWa['id']}: Recebido"), str_contains($ultimaWa()['text']['body'] ?? '', "https://exemplo.test/pedido/{$doBancoWa['codigo']}"),
], [true, true]);

$pedidos->mudarStatus($pedidoWa['id'], 'em_preparo', null, $atendente);
$avisosWa->processar();
conferir('Avanço no Kanban → aviso ao cliente ("está em preparo")', str_contains($ultimaWa()['text']['body'] ?? '', 'está em preparo'), true);
$pedidos->mudarStatus($pedidoWa['id'], 'pronto', null, $atendente);
$pedidos->mudarStatus($pedidoWa['id'], 'aguardando_retirada', null, $atendente);
$antes = count($falsoWhats->enviadas);
$avisosWa->processar();
conferir('Dois avanços seguidos antes de enviar: vai só o mais novo ("esperando você no balcão")', [count($falsoWhats->enviadas) - $antes, str_contains($ultimaWa()['text']['body'] ?? '', 'esperando você no balcão')], [1, true]);

App\Core\Banco::conexao()->prepare('UPDATE whatsapp_conversas SET ultima_msg_cliente_em = ? WHERE telefone = ?')->execute([gmdate('Y-m-d H:i:s', time() - 25 * 3600), $foneWa]);
$pedidos->mudarStatus($pedidoWa['id'], 'concluido', null, $atendente);
$avisosWa->processar();
conferir('Fora da janela de 24 h: o aviso vai pelo template aprovado (número e status como parâmetros)', [
    $ultimaWa()['type'] ?? null, $ultimaWa()['template']['name'] ?? null, array_column($ultimaWa()['template']['components'][0]['parameters'] ?? [], 'text'),
], ['template', 'atualizacao_pedido', [(string) $pedidoWa['id'], 'Concluído']]);

$pedidoSemBot = $pedidos->criar($base);
$pedidos->mudarStatus($pedidoSemBot['id'], 'em_preparo', null, $atendente);
conferir('Pedido feito direto no cardápio (sem o bot): nenhum aviso pelo WhatsApp', count($avisosWa->pendentes()), 0);

// Falhas: tentativa de novo (com espera) quando a falha é passageira; desiste quando é definitiva.
$webhook->processar($mensagemWa('wamid.oi.2', 'oi de novo'));   // reabre a janela
$pedidoFalha = $pedidos->criar($base + ['t' => (new TokenWhatsApp())->gerar($foneWa)]);
$falsoWhats->respostas[] = ['ok' => false, 'wamid' => null, 'erro' => 'Meta 503/0: fora do ar', 'transitorio' => true, 'janela_fechada' => false];
$avisosWa->processar();
$filaWa = $avisosWa->pendentes();
conferir('Falha passageira: o aviso fica na fila, com 1 tentativa, o erro e a próxima tentativa marcada', [count($filaWa), $filaWa[0]['tentativas'] ?? null, $filaWa[0]['ultimo_erro'] ?? null, $filaWa[0]['desistiu'] ?? null, ($filaWa[0]['proxima_tentativa_em'] ?? '') > gmdate('Y-m-d\TH:i:s\Z')], [1, 1, 'Meta 503/0: fora do ar', false, true]);
App\Core\Banco::conexao()->exec("UPDATE whatsapp_envios_pendentes SET proxima_tentativa_em = '2000-01-01 00:00:00'");
$antes = count($falsoWhats->enviadas);
conferir('Na hora marcada a fila tenta de novo e entrega', [$avisosWa->processar(), count($avisosWa->pendentes()), str_contains($ultimaWa()['text']['body'] ?? '', "Recebemos seu pedido #{$pedidoFalha['id']}")], [1, 0, true]);
$pedidos->mudarStatus($pedidoFalha['id'], 'em_preparo', null, $atendente);
$falsoWhats->respostas[] = ['ok' => false, 'wamid' => null, 'erro' => 'Meta 400/100: número inválido', 'transitorio' => false, 'janela_fechada' => false];
$avisosWa->processar();
conferir('Falha definitiva (ex.: número inválido): a fila desiste e a tela mostra o erro', [$avisosWa->pendentes()[0]['desistiu'] ?? null, $avisosWa->pendentes()[0]['ultimo_erro'] ?? null], [true, 'Meta 400/100: número inválido']);
conferir('Aviso que a fila desistiu não volta a ser tentado', $avisosWa->processar(), 0);
App\Core\Banco::conexao()->exec('DELETE FROM whatsapp_envios_pendentes');
$pedidos->mudarStatus($pedidoFalha['id'], 'pronto', null, $atendente);
$falsoWhats->respostas[] = ['ok' => false, 'wamid' => null, 'erro' => 'Meta 400/131047: fora da janela', 'transitorio' => false, 'janela_fechada' => true];
$avisosWa->processar();
conferir('A Meta diz que a janela fechou: a janela é dada como fechada e o aviso volta já, agora como template', [$conversasWa->janelaAberta($foneWa), $avisosWa->processar(), $ultimaWa()['type'] ?? null], [false, 1, 'template']);

// Atendimento humano
$webhook->processar($mensagemWa('wamid.humano.1', 'Falar com atendente', 'atendente'));
conferir('[Falar com atendente] → modo humano, com aviso ao cliente', [$conversasWa->emAtendimentoHumano($foneWa), str_contains($ultimaWa()['text']['body'] ?? '', 'equipe vai responder')], [true, true]);
$antes = count($falsoWhats->enviadas);
$webhook->processar($mensagemWa('wamid.humano.2', 'olá, alguém aí?'));
$mensagensWa = $conversasWa->mensagens($foneWa);
conferir('No modo humano o bot fica em silêncio (a mensagem fica na caixa de entrada)', [count($falsoWhats->enviadas) - $antes, $mensagensWa[count($mensagensWa) - 1]['texto'] ?? null], [0, 'olá, alguém aí?']);
$caixa = $conversasWa->emAtendimento();
conferir('A caixa de entrada lista a conversa, com o nome do cliente e a janela aberta', [count($caixa), $caixa[0]['telefone'] ?? null, $caixa[0]['nome'] ?? null, $caixa[0]['janela_aberta'] ?? null], [1, $foneWa, 'Maria Silva', true]);
App\Core\Banco::conexao()->prepare('UPDATE whatsapp_conversas SET humano_ate = ? WHERE telefone = ?')->execute([gmdate('Y-m-d H:i:s', time() - 60), $foneWa]);
$antes = count($falsoWhats->enviadas);
$webhook->processar($mensagemWa('wamid.humano.3', 'oi'));
conferir('Passou o prazo do atendimento humano: o bot volta sozinho e responde', [$conversasWa->emAtendimentoHumano($foneWa), count($falsoWhats->enviadas) - $antes], [false, 1]);
$webhook->processar($mensagemWa('wamid.humano.4', 'Falar com atendente', 'atendente'));
$conversasWa->voltarAoBot($foneWa);
conferir('"Devolver ao bot" no painel encerra o atendimento humano', $conversasWa->emAtendimentoHumano($foneWa), false);

// Status de entrega que a Meta manda de volta: só as falhas ficam registradas, uma vez só.
$statusFalho = ['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.saida.9', 'status' => 'failed', 'recipient_id' => $foneWa, 'errors' => [['code' => 131026, 'title' => 'Message undeliverable']]]]]]]]]];
conferir('Status "failed" da Meta fica registrado uma vez (repetido não duplica)', [$webhook->processar($statusFalho)['falhas_de_entrega'], $webhook->processar($statusFalho)['falhas_de_entrega']], [1, 0]);
conferir('A atividade recente mostra o telefone mascarado e nunca o texto', [($conversasWa->recentes(1)[0]['telefone'] ?? ''), array_key_exists('texto', $conversasWa->recentes(1)[0] ?? [])], ['55 11 9••••-0001', false]);
conferir('Sem credenciais da Meta o WhatsApp fica no modo simulado', App\WhatsApp\WhatsApp::simulado(), true);
App\WhatsApp\WhatsApp::usarMensageiro(null);
Env::definir('APP_URL', '');
ini_set('error_log', $logAntesWa);
@unlink(sys_get_temp_dir() . '/familiamassas-whatsapp-teste.log');

echo "\nTOKEN DO WHATSAPP\n";
$tokens = new TokenWhatsApp();
$agora = 1_800_000_000;
$t = $tokens->gerar('(11) 95555-4444', $agora);
conferir('Token válido devolve o telefone', $tokens->validar($t, $agora + 60), '11955554444');
conferir('Token vence depois de 2 horas', $tokens->validar($t, $agora + 7201), null);
conferir('Token com um caractere trocado é recusado', $tokens->validar(substr($t, 0, -1) . ($t[-1] === 'A' ? 'B' : 'A'), $agora), null);
conferir('Texto qualquer não é token', $tokens->validar('abc.def', $agora), null);

echo "\nTELEFONE\n";
conferir('(11) 91234-5678', PedidoService::normalizarTelefone('(11) 91234-5678'), '5511912345678');
conferir('+55 11 91234 5678', PedidoService::normalizarTelefone('+55 11 91234 5678'), '5511912345678');
conferir('Fixo com DDD', PedidoService::normalizarTelefone('1133334444'), '551133334444');
conferir('Sem DDD é recusado', PedidoService::normalizarTelefone('912345678'), null);

echo "\nLOJA ABERTA OU FECHADA (horários do seed: quarta a domingo, 11:00 às 23:00)\n";
(new ConfigRepository())->salvar(['funcionamento' => 'auto']);
$loja = new LojaService();
$em = static fn (string $quando): DateTimeImmutable => new DateTimeImmutable($quando, new DateTimeZone('America/Sao_Paulo'));
conferir('Quarta 12:00', $loja->estado($em('2026-11-11 12:00')), ['aberta' => true, 'mensagem' => 'Aberto até 23:00']);
conferir('Quarta 10:00', $loja->estado($em('2026-11-11 10:00')), ['aberta' => false, 'mensagem' => 'Fechado · abre hoje às 11:00']);
conferir('Quarta 23:00 (fechou)', $loja->estado($em('2026-11-11 23:00')), ['aberta' => false, 'mensagem' => 'Fechado · abre amanhã às 11:00']);
conferir('Segunda 12:00', $loja->estado($em('2026-11-09 12:00')), ['aberta' => false, 'mensagem' => 'Fechado · abre quarta às 11:00']);
conferir('Terça 12:00', $loja->estado($em('2026-11-10 12:00')), ['aberta' => false, 'mensagem' => 'Fechado · abre amanhã às 11:00']);
(new ConfigRepository())->salvar(['funcionamento' => 'fechada']);
conferir('Fechada à mão vence o horário', $loja->estado($em('2026-11-11 12:00'))['aberta'], false);
conferir('Loja fechada recusa pedido', codigoDoErro(fn () => $pedidos->criar($base)), '409 loja_fechada');

echo "\nOS DOIS ESQUEMAS (SQLite e Postgres) DESCREVEM AS MESMAS TABELAS\n";
$tabelasDoEsquema = static function (string $arquivo): array {
    $sql = (string) file_get_contents(dirname(__DIR__) . '/data/' . $arquivo);
    $tabelas = [];
    preg_match_all('/CREATE TABLE (\w+) \((.*?)\n\);/s', $sql, $achadas, PREG_SET_ORDER);
    foreach ($achadas as [, $nome, $corpo]) {
        preg_match_all('/^  ([a-z_]+)\s/m', $corpo, $colunas);   // coluna = linha que começa com 2 espaços e um nome em minúsculas
        $tabelas[$nome] = $colunas[1];
    }
    return $tabelas;
};
$esquemaSqlite = $tabelasDoEsquema('schema.sqlite.sql');
$esquemaPg = $tabelasDoEsquema('schema.pgsql.sql');
$soNoPostgres = ['esquema_versao', 'sessoes', 'imagens'];
conferir('Postgres: as mesmas tabelas do SQLite, mais as que só existem lá (versão, sessões e fotos)', array_values(array_diff(array_keys($esquemaPg), $soNoPostgres)), array_keys($esquemaSqlite));
$colunasDiferentes = [];
foreach ($esquemaSqlite as $tabela => $colunas) {
    if (($esquemaPg[$tabela] ?? null) !== $colunas) {
        $colunasDiferentes[] = $tabela;
    }
}
conferir('Cada tabela tem as mesmas colunas, na mesma ordem, nos dois esquemas', $colunasDiferentes, []);
conferir('As tabelas só do Postgres existem no esquema dele', array_values(array_intersect($soNoPostgres, array_keys($esquemaPg))), $soNoPostgres);

if ($emPg) {
    echo "\nPOSTGRES: sessões e fotos no banco\n";
    $sessoes = new App\Core\SessaoNoBanco();
    $idSessao = bin2hex(random_bytes(16));
    conferir('Sessão que não existe: leitura vazia e id inválido (use_strict_mode)', [$sessoes->read($idSessao), $sessoes->validateId($idSessao)], ['', false]);
    $sessoes->write($idSessao, 'usuario|a:1:{s:4:"nome";s:7:"Joaquim";}csrf|s:3:"abc";');
    conferir('Sessão gravada: volta igual e o id passa a valer', [$sessoes->read($idSessao), $sessoes->validateId($idSessao)], ['usuario|a:1:{s:4:"nome";s:7:"Joaquim";}csrf|s:3:"abc";', true]);
    $sessoes->write($idSessao, 'csrf|s:3:"xyz";');
    conferir('Gravar de novo troca o conteúdo (uma linha só por sessão)', [$sessoes->read($idSessao), (int) Banco::conexao()->query('SELECT COUNT(*) FROM sessoes')->fetchColumn()], ['csrf|s:3:"xyz";', 1]);
    Banco::conexao()->prepare('UPDATE sessoes SET atualizado_em = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 13 * 3600), $idSessao]);
    conferir('Sessão parada há mais de 12 h não vale mais', [$sessoes->read($idSessao), $sessoes->validateId($idSessao)], ['', false]);
    $sessoes->gc(43200);
    conferir('A limpeza (gc) apaga a sessão vencida', (int) Banco::conexao()->query('SELECT COUNT(*) FROM sessoes')->fetchColumn(), 0);
    $sessoes->write($idSessao, 'a|b;');
    $sessoes->destroy($idSessao);
    conferir('Sair apaga a sessão', $sessoes->read($idSessao), '');

    $imagens = new App\Repositories\ImagemRepository();
    $bytes = "\x89PNG\r\n\x1a\n" . "\x00\x01\x02\xff\xfe\x00" . str_repeat("\x00\xff", 2000);   // com zeros e bytes altos, como uma foto de verdade
    $nomeFoto = bin2hex(random_bytes(12)) . '.png';
    $imagens->guardar($nomeFoto, 'image/png', $bytes);
    $volta = $imagens->porNome($nomeFoto);
    conferir('Foto no banco: os bytes voltam idênticos (inclusive zeros) e o tipo também', [$volta['tipo'] ?? null, ($volta['dados'] ?? '') === $bytes, strlen($volta['dados'] ?? '')], ['image/png', true, strlen($bytes)]);
    conferir('Foto que não existe', $imagens->porNome(bin2hex(random_bytes(12)) . '.png'), null);
    conferir('GET /uploads/{nome} entrega a foto guardada (mesmos bytes e tipo)', [App\Controllers\PublicoController::fotoGuardada($nomeFoto)['dados'] === $bytes, App\Controllers\PublicoController::fotoGuardada($nomeFoto)['tipo']], [true, 'image/png']);
    conferir('Nome fora do padrão (../, sem 24 hex, extensão estranha) não é entregue', [
        App\Controllers\PublicoController::fotoGuardada('../../etc/passwd'),
        App\Controllers\PublicoController::fotoGuardada('abc.png'),
        App\Controllers\PublicoController::fotoGuardada(bin2hex(random_bytes(12)) . '.php'),
    ], [null, null, null]);
}

echo "\nPRIMEIRO ACESSO EM PARALELO (corrida na criação do banco)\n";
// Na hospedagem, o Apache atende várias requisições ao mesmo tempo, e as primeiras chegam juntas (a Render ainda manda a
// verificação de saúde). Aqui 8 processos PHP abrem o MESMO banco novo ao mesmo tempo: o esquema e os dados de exemplo
// precisam ser criados uma vez só, e nenhum deles pode falhar. Vale para o SQLite e para o Postgres.
/**
 * Abre o banco descrito em $config (pares de Env: DB_DRIVER, DB_SQLITE_PATH ou DATABASE_URL + DB_PG_SCHEMA) em vários processos
 * PHP ao mesmo tempo, como o Apache faz. Devolve o que cada um respondeu ("ok" ou o erro).
 * @param array<string,string> $config
 */
$abrirEmParalelo = static function (array $config, int $quantos = 8): array {
    $ajustes = '';
    foreach ($config + ['APP_ENV' => 'dev'] as $chave => $valor) {
        $ajustes .= ' App\Core\Env::definir(' . var_export($chave, true) . ', ' . var_export($valor, true) . ');';
    }
    $codigo = 'require ' . var_export(dirname(__DIR__) . '/app/bootstrap.php', true) . ';' . $ajustes
        . ' try { $pdo = App\Core\Banco::conexao(); $v = App\Core\Banco::versaoDoBanco($pdo); echo $v === App\Core\Banco::VERSAO_DO_ESQUEMA ? "ok" : "versao " . $v; }'
        . ' catch (Throwable $e) { echo "ERRO: " . $e->getMessage(); }';
    $filhos = [];
    for ($i = 0; $i < $quantos; $i++) {
        $filhos[] = [proc_open([PHP_BINARY, '-d', 'extension=pdo_sqlite', '-r', $codigo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $canais), $canais];
    }
    $respostas = [];
    foreach ($filhos as [$processo, $canais]) {
        $respostas[] = trim((string) stream_get_contents($canais[1]));
        fclose($canais[1]);
        stream_get_contents($canais[2]);
        fclose($canais[2]);
        proc_close($processo);
    }
    return $respostas;
};
$pastaCorrida = sys_get_temp_dir() . '/familiamassas-corrida-' . bin2hex(random_bytes(4));
mkdir($pastaCorrida);
if ($emPg) {
    [$schemaCorrida, $adminCorrida, $configCorrida] = $criarSchemaDeTeste();   // um schema novo e vazio só para esta corrida
} else {
    $configCorrida = ['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $pastaCorrida . '/novo.sqlite'];
}
$resultados = $abrirEmParalelo($configCorrida);
conferir('Os 8 acessos simultâneos ao banco novo funcionam (nenhum 500)', array_count_values($resultados), ['ok' => 8]);
if ($emPg) {
    $usuariosCorrida = (int) $adminCorrida->query("SELECT COUNT(*) FROM \"{$schemaCorrida}\".usuarios")->fetchColumn();
    $versaoCorrida = (int) $adminCorrida->query("SELECT versao FROM \"{$schemaCorrida}\".esquema_versao")->fetchColumn();
} else {
    $bancoCorrida = new PDO('sqlite:' . $configCorrida['DB_SQLITE_PATH'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $usuariosCorrida = (int) $bancoCorrida->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
    $versaoCorrida = (int) $bancoCorrida->query('PRAGMA user_version')->fetchColumn();
    $bancoCorrida = null;
}
conferir('Os dados de exemplo foram criados uma vez só (2 usuários) e o banco já está na versão atual', [$usuariosCorrida, $versaoCorrida], [2, Banco::VERSAO_DO_ESQUEMA]);
foreach (glob($pastaCorrida . '/*') ?: [] as $restante) {
    @unlink($restante);
}
@rmdir($pastaCorrida);

echo "\nPRODUÇÃO (Render): ambiente, IP atrás do proxy e senhas iniciais\n";
putenv('FM_TESTE_AMBIENTE=do-processo');
conferir('Sem .env, vale a variável de ambiente do processo', Env::get('FM_TESTE_AMBIENTE'), 'do-processo');
Env::definir('FM_TESTE_AMBIENTE', 'do-env');
conferir('Com o .env, ele vale primeiro', Env::get('FM_TESTE_AMBIENTE'), 'do-env');
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9, 10.0.0.1';
$requisicaoProxy = new Requisicao();
Env::definir('TRUST_PROXY', '0');
conferir('Sem TRUST_PROXY o IP é o da conexão (X-Forwarded-For não vale)', $requisicaoProxy->ip(), '10.0.0.1');
Env::definir('TRUST_PROXY', '1');
conferir('Com TRUST_PROXY o IP é o do cliente em X-Forwarded-For', $requisicaoProxy->ip(), '203.0.113.9');
$_SERVER['HTTP_X_FORWARDED_FOR'] = 'lixo, 10.0.0.1';
conferir('X-Forwarded-For inválido é ignorado', $requisicaoProxy->ip(), '10.0.0.1');
Env::definir('TRUST_PROXY', '0');
unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR']);

$antesAmbiente = Env::get('APP_ENV');
$logAntes = (string) ini_get('error_log');
ini_set('error_log', sys_get_temp_dir() . '/familiamassas-seed-teste.log');   // a senha sorteada vai para o log, não para a tela
$senhas = static function (string $ambiente) use ($banco): array {
    Env::definir('APP_ENV', $ambiente);
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec((string) file_get_contents(dirname(__DIR__) . '/data/schema.sqlite.sql'));
    (require dirname(__DIR__) . '/data/seed.php')($pdo);
    return array_column($pdo->query('SELECT login, senha_hash FROM usuarios')->fetchAll(), 'senha_hash', 'login');
};
$emDev = $senhas('dev');
conferir('Em desenvolvimento a senha inicial continua "trocar123"', [password_verify('trocar123', $emDev['admin']), password_verify('trocar123', $emDev['atendente'])], [true, true]);
Env::definir('SENHA_ADMIN_INICIAL', 'minha-senha-forte');
Env::definir('SENHA_ATENDENTE_INICIAL', 'curta');
$emProducao = $senhas('producao');
conferir('Em produção, a senha do admin vem da variável SENHA_ADMIN_INICIAL', password_verify('minha-senha-forte', $emProducao['admin']), true);
conferir('Em produção, senha curta demais não vale: a do atendente é sorteada, nunca "trocar123"', [password_verify('curta', $emProducao['atendente']), password_verify('trocar123', $emProducao['atendente']), password_verify('trocar123', $emProducao['admin'])], [false, false, false]);
Env::definir('APP_ENV', $antesAmbiente);
Env::definir('SENHA_ADMIN_INICIAL', '');
Env::definir('SENHA_ATENDENTE_INICIAL', '');
ini_set('error_log', $logAntes);
@unlink(sys_get_temp_dir() . '/familiamassas-seed-teste.log');

echo "\nATUALIZAÇÃO DO BANCO (migrações)\n";
if ($emPg) {
    echo "  (só no SQLite: as migrações 001 e 002 foram escritas para o banco antigo, que nunca existiu no Postgres)\n";
}
if (!$emPg) {
$ultima = max(array_map(static fn (string $caminho): int => (int) basename($caminho), glob(dirname(__DIR__) . '/data/migracoes/*.php') ?: [0]));
conferir('A versão do esquema é a da última migração', App\Core\Banco::VERSAO_DO_ESQUEMA, $ultima);

// Um banco "antigo": o esquema da versão 1 (congelado em tests/fixtures) com dados de antes dos atendimentos.
$pastaMigracao = sys_get_temp_dir() . '/familiamassas-migracao-' . bin2hex(random_bytes(4));
mkdir($pastaMigracao);
$arquivoAntigo = $pastaMigracao . '/antigo.sqlite';
$antigo = new PDO('sqlite:' . $arquivoAntigo, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$antigo->exec('PRAGMA foreign_keys = ON');
$antigo->exec((string) file_get_contents(__DIR__ . '/fixtures/schema-v1.sqlite.sql'));
$antigo->exec('PRAGMA user_version = 1');
$agoraAntigo = gmdate('Y-m-d H:i:s');
$antigo->exec("INSERT INTO usuarios (nome, login, senha_hash, perfil, ativo, criado_em) VALUES ('Atendimento', 'atendente', 'x', 'atendente', 1, '{$agoraAntigo}')");
foreach ([['Carne moída', 'Acompanhamentos', 500, 1], ['Frango desfiado', 'Acompanhamentos', 500, 2], ['Camarão', 'Acompanhamentos', 900, 3], ['Bacon', 'Acompanhamentos', 500, 4],
          ['Brócolis', 'Vegetais', 300, 5], ['Milho', 'Vegetais', 300, 6], ['Muçarela', 'Queijos', 400, 7]] as [$nomeIngrediente, $grupoIngrediente, $precoIngrediente, $ordemIngrediente]) {
    $antigo->exec("INSERT INTO ingredientes (nome, grupo, preco_extra_centavos, estado, ordem) VALUES ('{$nomeIngrediente}', '{$grupoIngrediente}', {$precoIngrediente}, 'disponivel', {$ordemIngrediente})");
}
$idDoFrango = (int) $antigo->query("SELECT id FROM ingredientes WHERE nome = 'Frango desfiado'")->fetchColumn();
$pedidoAntigo = $antigo->prepare("INSERT INTO pedidos (codigo, cliente_nome, cliente_telefone, tipo, mesa, pagamento, subtotal_centavos, total_centavos, status, origem, criado_por, status_atualizado_em, atualizado_em, criado_em)
                                  VALUES (?, ?, '', ?, ?, ?, 1690, 1690, ?, ?, 1, ?, ?, ?)");
foreach ([['A1', 'Mesa 5', 'local', '5', 'pix', 'concluido', 'balcao'], ['A2', 'Carlos', 'viagem', null, 'caixa', 'novo', 'balcao'],
          ['A3', 'Mesa 2', 'local', '2', 'caixa', 'cancelado', 'balcao'], ['A4', 'Cliente', 'retirada', null, 'pix', 'concluido', 'cardapio']] as [$codigoAntigo, $clienteAntigo, $tipoAntigo, $mesaAntiga, $pagamentoAntigo, $statusAntigo, $origemAntiga]) {
    $pedidoAntigo->execute([$codigoAntigo, $clienteAntigo, $tipoAntigo, $mesaAntiga, $pagamentoAntigo, $statusAntigo, $origemAntiga, $agoraAntigo, $agoraAntigo, $agoraAntigo]);
}

$aplicadas = App\Core\Banco::migrar($antigo, $arquivoAntigo);
conferir('Banco da versão 1 recebe a migração 002', $aplicadas, [2]);
conferir('A versão do banco passa a ser a atual', (int) $antigo->query('PRAGMA user_version')->fetchColumn(), App\Core\Banco::VERSAO_DO_ESQUEMA);
conferir('Uma cópia do banco antigo foi guardada antes de mexer', count(glob($pastaMigracao . '/backups/antigo-antes-da-v2-*.sqlite') ?: []), 1);
conferir('Rodar de novo não faz nada', App\Core\Banco::migrar($antigo, $arquivoAntigo), []);

$grupoDe = static fn (string $nome): string => (string) $antigo->query("SELECT grupo FROM ingredientes WHERE nome = '{$nome}'")->fetchColumn();
conferir('Proteínas: carne moída, picadinho de frango, carne seca e camarão', array_map($grupoDe, ['Carne moída', 'Picadinho de frango', 'Carne seca', 'Camarão']), ['Proteínas', 'Proteínas', 'Proteínas', 'Proteínas']);
conferir('Frango desfiado virou Picadinho de frango: é o mesmo ingrediente (mesmo id)', (int) $antigo->query("SELECT id FROM ingredientes WHERE nome = 'Picadinho de frango'")->fetchColumn(), $idDoFrango);
conferir('Carne seca entrou com preço de exemplo', (int) $antigo->query("SELECT preco_extra_centavos FROM ingredientes WHERE nome = 'Carne seca'")->fetchColumn(), 700);
conferir('Bacon e os vegetais ficam em Acompanhamentos; queijo continua em Queijos', array_map($grupoDe, ['Bacon', 'Brócolis', 'Milho', 'Muçarela']), ['Acompanhamentos', 'Acompanhamentos', 'Acompanhamentos', 'Queijos']);
conferir('A ordem da lista é Proteínas, Acompanhamentos, Queijos', array_values(array_unique($antigo->query('SELECT grupo FROM ingredientes ORDER BY ordem')->fetchAll(PDO::FETCH_COLUMN))), ['Proteínas', 'Acompanhamentos', 'Queijos']);

$contas = $antigo->query('SELECT cliente_nome, modalidade, mesa, status, pagamento, total_centavos FROM atendimentos ORDER BY id')->fetchAll();
conferir('Cada pedido antigo do salão virou um atendimento', array_column($contas, 'cliente_nome'), ['Mesa 5', 'Carlos', 'Mesa 2']);
conferir('Concluído → encerrado com pagamento e valor; em andamento → aberto; cancelado → encerrado sem valor', array_map(static fn (array $c): array => [$c['status'], $c['pagamento'], $c['total_centavos']], $contas), [['encerrado', 'pix', 1690], ['aberto', null, null], ['encerrado', null, 0]]);
conferir('Modalidade e mesa vieram do pedido', array_map(static fn (array $c): array => [$c['modalidade'], $c['mesa']], $contas), [['mesa', '5'], ['viagem', null], ['mesa', '2']]);
conferir('Todo pedido do salão ficou ligado a um atendimento; o do cardápio não', [
    (int) $antigo->query("SELECT COUNT(*) FROM pedidos WHERE origem = 'balcao' AND atendimento_id IS NULL")->fetchColumn(),
    (int) $antigo->query("SELECT COUNT(*) FROM pedidos WHERE origem <> 'balcao' AND atendimento_id IS NOT NULL")->fetchColumn(),
], [0, 0]);
conferir('Nenhuma ligação ficou quebrada', $antigo->query('PRAGMA foreign_key_check')->fetchAll(), []);

// Banco antigo aberto por 8 processos ao mesmo tempo (o primeiro acesso depois de publicar uma versão nova): a migração roda uma vez só.
$arquivoAntigo2 = $pastaMigracao . '/antigo-paralelo.sqlite';
$antigo2 = new PDO('sqlite:' . $arquivoAntigo2, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$antigo2->exec((string) file_get_contents(__DIR__ . '/fixtures/schema-v1.sqlite.sql'));
$antigo2->exec('PRAGMA user_version = 1');
$antigo2->exec("INSERT INTO usuarios (nome, login, senha_hash, perfil, ativo, criado_em) VALUES ('Atendimento', 'atendente', 'x', 'atendente', 1, '{$agoraAntigo}')");
$antigo2->exec("INSERT INTO pedidos (codigo, cliente_nome, cliente_telefone, tipo, pagamento, subtotal_centavos, total_centavos, status, origem, criado_por, status_atualizado_em, atualizado_em, criado_em)
                SELECT 'P' || n, 'Cliente', '', 'viagem', 'caixa', 1690, 1690, 'novo', 'balcao', 1, '{$agoraAntigo}', '{$agoraAntigo}', '{$agoraAntigo}' FROM (SELECT 1 AS n UNION SELECT 2 UNION SELECT 3)");
$antigo2 = null;
$respostasAntigo = $abrirEmParalelo(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $arquivoAntigo2]);
conferir('Os 8 acessos simultâneos a um banco antigo funcionam (a migração roda uma vez só)', array_count_values($respostasAntigo), ['ok' => 8]);
$antigo2 = new PDO('sqlite:' . $arquivoAntigo2, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
conferir('Sem duplicar: um atendimento por pedido antigo do salão, e o banco na versão atual', [
    (int) $antigo2->query('SELECT COUNT(*) FROM atendimentos')->fetchColumn(),
    (int) $antigo2->query('PRAGMA user_version')->fetchColumn(),
], [3, App\Core\Banco::VERSAO_DO_ESQUEMA]);
$antigo2 = null;

$muitoAntigo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$muitoAntigo->exec('CREATE TABLE pedidos (id INTEGER PRIMARY KEY)');
try {
    App\Core\Banco::migrar($muitoAntigo);
    $recusou = false;
} catch (RuntimeException) {
    $recusou = true;
}
conferir('Banco anterior à numeração de versões e sem o esquema da rodada é recusado com aviso claro', $recusou, true);

$antigo = null;
$muitoAntigo = null;
foreach (array_merge(glob($pastaMigracao . '/backups/*') ?: [], glob($pastaMigracao . '/*') ?: []) as $restante) {
    if (is_file($restante)) {
        @unlink($restante);
    }
}
@rmdir($pastaMigracao . '/backups');
@rmdir($pastaMigracao);

}

echo "\n" . ($falhas === 0 ? "Todos os {$total} testes passaram." : "{$falhas} de {$total} testes falharam.") . "\n";
exit($falhas === 0 ? 0 : 1);
