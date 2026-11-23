<?php
// Testes do iFood (D76 a D78) e do agente de impressão (D79 a D81).
// Rodar (na raiz do projeto):   php -d extension=pdo_sqlite tests/integracoes.php        (SQLite, segundos)
//                               php -d extension=pdo_sqlite tests/integracoes.php pg     (Postgres/Neon, num schema temporário)
// Usa um banco SQLite temporário e um "iFood de mentira" (transporte HTTP falso): nada sai para a internet.
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Banco;
use App\Core\Env;
use App\Ifood\Ifood;
use App\Ifood\IfoodApi;
use App\Impressao\AgenteImpressao;
use App\Impressao\Instalador;
use App\Impressao\Comanda;
use App\Repositories\ConfigRepository;
use App\Repositories\PedidoRepository;
use App\Services\PedidoService;

// Banco dos testes. Padrão: um SQLite temporário. Com o argumento "pg" e TEST_DATABASE_URL (a connection string do Neon, no .env),
// a mesma suíte roda no Postgres de verdade, num schema que ela cria e apaga no fim: os dados reais não são tocados.
$urlPgTeste = Env::get('TEST_DATABASE_URL');
$emPg = in_array('pg', $argv ?? [], true);
if ($emPg && $urlPgTeste === '') {
    fwrite(STDERR, "Para testar no Postgres, defina TEST_DATABASE_URL no .env (a connection string do Neon).
");
    exit(1);
}
if ($emPg) {
    $urlDireta = (string) preg_replace('/-pooler(?=\.)/', '', $urlPgTeste);   // a criação do schema pede conexão direta, não o pooler
    $schemaDeTeste = 'teste_' . bin2hex(random_bytes(5));
    Env::definir('DATABASE_URL', $urlDireta);
    [$dsn, $usuario, $senha] = Banco::dadosDoPostgres();
    $adminPg = new PDO($dsn, $usuario, $senha, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $adminPg->exec("CREATE SCHEMA \"{$schemaDeTeste}\"");
    register_shutdown_function(static function () use ($adminPg, $schemaDeTeste): void {
        $adminPg->exec("DROP SCHEMA IF EXISTS \"{$schemaDeTeste}\" CASCADE");
    });
    Env::definir('DB_DRIVER', 'pgsql');
    Env::definir('DB_PG_SCHEMA', $schemaDeTeste);
    echo "Rodando no Postgres (schema temporário {$schemaDeTeste}).
";
} else {
    $banco = sys_get_temp_dir() . '/familiamassas-integracoes-' . bin2hex(random_bytes(4)) . '.sqlite';
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
    } catch (App\Core\ErroHttp $e) {
        return $e->status . ' ' . $e->codigo;
    }
}

$pdo = Banco::conexao();
$pedidos = new PedidoRepository();
$config = new ConfigRepository();

/** Pedido comum (cardápio), direto no repositório: os testes não dependem do horário da loja. */
$pedidoComum = static function (string $origem = 'cardapio') use ($pedidos): int {
    return $pedidos->criar([
        'cliente_nome' => 'Ana', 'cliente_telefone' => '5511912345678', 'tipo' => 'retirada', 'mesa' => null,
        'endereco_rua' => null, 'endereco_numero' => null, 'endereco_bairro' => null, 'endereco_complemento' => null, 'endereco_referencia' => null,
        'entrega_lat' => null, 'entrega_lng' => null, 'pagamento' => 'pix', 'troco_para_centavos' => null, 'observacoes' => '',
        'subtotal_centavos' => 2490, 'taxa_entrega_centavos' => 0, 'total_centavos' => 2490, 'origem' => $origem,
    ], [['item_id' => null, 'tipo' => 'simples', 'nome' => 'Carbonara', 'quantidade' => 1, 'preco_unit_centavos' => 2490, 'montagem' => null, 'observacoes' => '']])['id'];
};

/** Pedido no formato do iFood (GET /order/v1.0/orders/{id}). */
$pedidoIfood = static function (string $id, array $mudar = []): array {
    return array_replace_recursive([
        'id' => $id, 'displayId' => '4821', 'orderType' => 'DELIVERY', 'orderTiming' => 'IMMEDIATE', 'salesChannel' => 'IFOOD',
        'createdAt' => '2026-11-16T19:00:00.000Z', 'merchant' => ['id' => 'loja-1', 'name' => 'Família Massas'],
        'customer' => ['id' => 'c1', 'name' => 'Bruno Lima', 'phone' => ['number' => '0800 705 0050', 'localizer' => '12345678']],
        'items' => [
            ['index' => 1, 'name' => 'Penne à bolonhesa', 'externalCode' => '2', 'quantity' => 2, 'unitPrice' => 30.0, 'optionsPrice' => 4.0, 'totalPrice' => 68.0,
             'observations' => 'Sem cebola', 'options' => [['name' => 'Bacon extra', 'quantity' => 1, 'unitPrice' => 4.0, 'price' => 4.0]]],
            ['index' => 2, 'name' => 'Refrigerante lata', 'quantity' => 1, 'unitPrice' => 6.5, 'totalPrice' => 6.5, 'options' => []],
        ],
        'total' => ['subTotal' => 74.5, 'deliveryFee' => 7.99, 'benefits' => 0, 'orderAmount' => 82.49, 'additionalFees' => 0],
        'payments' => ['prepaid' => 82.49, 'pending' => 0, 'methods' => [['value' => 82.49, 'currency' => 'BRL', 'method' => 'CREDIT', 'type' => 'ONLINE', 'prepaid' => true, 'card' => ['brand' => 'VISA']]]],
        'delivery' => ['mode' => 'DEFAULT', 'deliveredBy' => 'IFOOD', 'observations' => 'Interfone 31', 'pickupCode' => '5521',
            'deliveryAddress' => ['streetName' => 'Rua das Laranjeiras', 'streetNumber' => '250', 'neighborhood' => 'Centro', 'complement' => 'Apto 31',
                'reference' => 'Perto da padaria', 'coordinates' => ['latitude' => -23.5505, 'longitude' => -46.6333]]],
    ], $mudar);
};

// ---------- iFood de mentira ----------
// Cada chamada fica em $chamadas; as respostas vêm de $respostas (caminho → função). O padrão é 202 vazio (as ações).
$chamadas = [];
$respostas = [];
$transporte = static function (string $metodo, string $url, array $cabecalhos, ?string $corpo) use (&$chamadas, &$respostas): array {
    $caminho = (string) parse_url($url, PHP_URL_PATH);
    $chamadas[] = ['metodo' => $metodo, 'caminho' => $caminho, 'corpo' => $corpo, 'cabecalhos' => $cabecalhos];
    foreach ($respostas as $padrao => $resposta) {
        if (preg_match($padrao, $caminho) === 1) {
            return $resposta($corpo);
        }
    }
    return ['status' => 202, 'corpo' => '', 'erro' => null];
};
$json = static fn (mixed $dados, int $status = 200): array => ['status' => $status, 'corpo' => (string) json_encode($dados), 'erro' => null];
IfoodApi::trocarTransportePadrao($transporte);   // o PedidoService cria o seu Ifood: também precisa do iFood de mentira
$api = new IfoodApi($transporte);
$ifood = new Ifood($api);
// (Closures com "use (&...)": uma função-seta copiaria a lista no momento em que foi criada.)
$caminhos = static function () use (&$chamadas): array {
    return array_map(static fn (array $c): string => $c['metodo'] . ' ' . $c['caminho'], $chamadas);
};
$zerar = static function () use (&$chamadas): void {
    $chamadas = [];
};
$liberarCiclo = static fn () => $pdo->exec('UPDATE ifood_conexao SET proximo_polling_em = NULL');

echo "\nIFOOD: PEDIDO DO APP VIRA PEDIDO DO KANBAN\n";
[$p, $itens, $extra] = Ifood::converter($pedidoIfood('uuid-1'));
conferir('Entrega com o endereço do iFood', [$p['tipo'], $p['endereco_rua'], $p['endereco_numero'], $p['endereco_bairro'], $p['endereco_complemento']], ['entrega', 'Rua das Laranjeiras', '250', 'Centro', 'Apto 31']);
conferir('Valores do iFood em centavos (subtotal, taxa, total)', [$p['subtotal_centavos'], $p['taxa_entrega_centavos'], $p['total_centavos']], [7450, 799, 8249]);
conferir('Item com complemento: preço por unidade com o complemento', [$itens[0]['quantidade'], $itens[0]['preco_unit_centavos']], [2, 3400]);
conferir('Complementos e observação do item viram a observação', $itens[0]['observacoes'], '1x Bacon extra · Obs: Sem cebola');
conferir('O 0800 do iFood não vira cadastro de cliente', $p['cliente_telefone'], '');
conferir('Pago online, número do app, localizador e código de coleta', [$extra['pago_online'], $extra['display_id'], $extra['localizador'], $extra['codigo_coleta'], $extra['entregue_por']], [true, '4821', '12345678', '5521', 'IFOOD']);
conferir('Texto do pagamento: pago online, com a bandeira do cartão e o valor', $extra['pagamento_texto'], 'Pago online no iFood: Crédito VISA R$ 82,49');
conferir('Pedido pago online: nada a cobrar', Ifood::aCobrarCentavos($pedidoIfood('x')), 0);
[$p2, , $extra2] = Ifood::converter($pedidoIfood('uuid-2', [
    'orderType' => 'TAKEOUT', 'delivery' => null,
    'payments' => ['prepaid' => 0, 'pending' => 82.49, 'methods' => [['value' => 82.49, 'method' => 'CASH', 'type' => 'OFFLINE', 'prepaid' => false, 'cash' => ['changeFor' => 100]]]],
]));
conferir('Retirada em dinheiro: cobrar na hora, com troco', [$p2['tipo'], $p2['pagamento'], $p2['troco_para_centavos'], $extra2['pago_online']], ['retirada', 'dinheiro', 10000, false]);
conferir('Texto do pagamento a cobrar: sem bandeira em dinheiro, com o troco e quanto levar', $extra2['pagamento_texto'], 'Cobrar na entrega: Dinheiro R$ 82,49 · troco para R$ 100,00 (levar R$ 17,51)');
conferir('Valor a cobrar vem de payments.pending', Ifood::detalhesDoPedido($pedidoIfood('x', ['payments' => ['prepaid' => 0, 'pending' => 82.49]]))['a_cobrar_centavos'], 8249);

echo "\nIFOOD: CONEXÃO (aplicativo centralizado e distribuído)\n";
conferir('Sem credenciais: modo simulado', [IfoodApi::configurada(), $ifood->situacao()['modo']], [false, 'simulado']);
conferir('Conectar sem credenciais é recusado', codigoDoErro(fn () => $ifood->conectar()), '409 ifood_sem_credenciais');
Env::definir('IFOOD_CLIENT_ID', 'cliente-teste');
Env::definir('IFOOD_CLIENT_SECRET', 'segredo-que-nao-pode-vazar');
Env::definir('IFOOD_TIPO_APP', 'distribuido');
$respostas = [
    '#/oauth/userCode$#' => fn () => $json(['userCode' => 'ABCD-1234', 'authorizationCodeVerifier' => 'verif-1', 'verificationUrlComplete' => 'https://portal.ifood.com.br/apps/code?c=ABCD-1234', 'expiresIn' => 600]),
    '#/oauth/token$#' => fn (?string $corpo) => str_contains((string) $corpo, 'authorizationCode=COD-OK')
        ? $json(['accessToken' => 'token-1', 'refreshToken' => 'refresh-1', 'expiresIn' => 21600])
        : (str_contains((string) $corpo, 'grantType=refresh_token') ? $json(['accessToken' => 'token-2', 'refreshToken' => 'refresh-2', 'expiresIn' => 21600]) : $json(['error' => ['message' => 'invalid code']], 401)),
    '#/merchant/v1.0/merchants$#' => fn () => $json([['id' => 'loja-1', 'name' => 'Família Massas']]),
];
$s = $ifood->conectar();
conferir('Distribuído: o painel recebe o código para o Portal do Parceiro', [$s['estado'], $s['codigo']['user_code'] ?? null], ['aguardando_codigo', 'ABCD-1234']);
conferir('O pedido de código manda só o clientId', $chamadas[0]['corpo'], 'clientId=cliente-teste');
conferir('Código de autorização errado: erro legível, sem a credencial', codigoDoErro(fn () => $ifood->autorizar('COD-ERRADO')), '502 ifood_autorizacao');
$s = $ifood->autorizar('COD-OK');
conferir('Código certo: conectado e com a loja descoberta', [$s['estado'], $s['loja']], ['conectado', ['id' => 'loja-1', 'nome' => 'Família Massas']]);
$tokenFoiTrocado = $chamadas[array_key_last(array_filter($chamadas, static fn (array $c): bool => str_ends_with($c['caminho'], '/oauth/token')))]['corpo'];
conferir('A troca do código leva o verificador do mesmo pedido', str_contains($tokenFoiTrocado, 'authorizationCodeVerifier=verif-1') && str_contains($tokenFoiTrocado, 'grantType=authorization_code'), true);
$situacaoTexto = json_encode($ifood->situacao());
conferir('A situação do painel não leva token nem segredo', [str_contains($situacaoTexto, 'token-1'), str_contains($situacaoTexto, 'refresh-1'), str_contains($situacaoTexto, 'segredo')], [false, false, false]);
$pdo->exec("UPDATE ifood_conexao SET token_expira_em = '2000-01-01 00:00:00'");
$zerar();
$respostas['#events:polling$#'] = fn () => ['status' => 204, 'corpo' => '', 'erro' => null];
$liberarCiclo();
$ifood->ciclo();
conferir('Token vencido: renova com o refresh token antes do polling', $caminhos(), ['POST /authentication/v1.0/oauth/token', 'GET /events/v1.0/events:polling']);
conferir('O polling usa o token novo e filtra pela loja', [in_array('Authorization: Bearer token-2', $chamadas[1]['cabecalhos'], true), in_array('x-polling-merchants: loja-1', $chamadas[1]['cabecalhos'], true)], [true, true]);

echo "\nIFOOD: CICLO (polling, pedido novo, confirmação e eventos repetidos)\n";
$eventos = [
    ['id' => 'ev-1', 'code' => 'PLC', 'fullCode' => 'PLACED', 'orderId' => 'uuid-10', 'merchantId' => 'loja-1', 'createdAt' => '2026-11-16T19:00:00Z'],
    ['id' => 'ev-1', 'code' => 'PLC', 'fullCode' => 'PLACED', 'orderId' => 'uuid-10', 'merchantId' => 'loja-1', 'createdAt' => '2026-11-16T19:00:00Z'],
];
$respostas['#events:polling$#'] = static function () use (&$eventos, $json): array {
    return $json($eventos);
};
$respostas['#/order/v1.0/orders/uuid-10$#'] = fn () => $json($pedidoIfood('uuid-10'));
$zerar();
$liberarCiclo();
$r = $ifood->cicloSeHora();
conferir('O ciclo rodou e criou um pedido só (evento repetido no mesmo lote)', [$r['rodou'], $r['pedidos']], [true, 1]);
$novo = $pedidos->porId((int) $pdo->query("SELECT pedido_id FROM ifood_pedidos WHERE ifood_id = 'uuid-10'")->fetchColumn());
conferir('Pedido no Kanban com origem iFood, status novo e o bloco do iFood', [$novo['origem'], $novo['status'], $novo['ifood']['display_id'], $novo['ifood']['pago_online']], ['ifood', 'novo', '4821', true]);
conferir('Confirmado no iFood na hora (confirmação automática)', in_array('POST /order/v1.0/orders/uuid-10/confirm', $caminhos(), true), true);
conferir('Depois de confirmar, o pedido mostra a etapa do iFood', [$novo['ifood']['confirmado'], $novo['ifood']['status']], [true, 'CONFIRMED']);
$ack = array_values(array_filter($chamadas, static fn (array $c): bool => str_ends_with($c['caminho'], '/events/acknowledgment')));
conferir('Os eventos lidos são confirmados (acknowledgment) com o id de cada um, sem repetir', json_decode((string) ($ack[0]['corpo'] ?? '[]'), true), [['id' => 'ev-1']]);
conferir('Ciclo de novo antes de 30 s: não roda (trava no banco)', $ifood->cicloSeHora()['rodou'], false);
$liberarCiclo();
$ifood->cicloSeHora();
conferir('O iFood manda o mesmo evento de novo: nenhum pedido duplicado', (int) $pdo->query("SELECT COUNT(*) FROM ifood_pedidos WHERE ifood_id = 'uuid-10'")->fetchColumn(), 1);

// Pedido que o iFood não deixou ler agora: o evento NÃO é confirmado, para voltar no próximo polling.
$eventos = [['id' => 'ev-2', 'fullCode' => 'PLACED', 'orderId' => 'uuid-11']];
$respostas['#/order/v1.0/orders/uuid-11$#'] = fn () => $json(['message' => 'instável'], 503);
$zerar();
$liberarCiclo();
$ifood->ciclo();
conferir('Pedido que não deu para ler: evento sem acknowledgment (volta no próximo polling)', in_array('POST /events/v1.0/events/acknowledgment', $caminhos(), true), false);
$respostas['#/order/v1.0/orders/uuid-11$#'] = fn () => $json($pedidoIfood('uuid-11', ['displayId' => '4822']));
$liberarCiclo();
$ifood->ciclo();
conferir('No polling seguinte o pedido entra', (int) $pdo->query("SELECT COUNT(*) FROM ifood_pedidos WHERE ifood_id = 'uuid-11'")->fetchColumn(), 1);

echo "\nIFOOD: KANBAN → IFOOD E IFOOD → KANBAN\n";
$servico = new PedidoService();
$dono = ['id' => 1, 'perfil' => 'admin'];
$zerar();
$servico->mudarStatus($novo['id'], 'em_preparo', null, $dono);
conferir('"Iniciar preparo" avisa o iFood (startPreparation)', $caminhos(), ['POST /order/v1.0/orders/uuid-10/startPreparation']);
conferir('Ação sem corpo vai sem corpo (com "[]" o iFood real respondeu 400 no readyToPickup)', $chamadas[0]['corpo'], null);
$zerar();
$servico->mudarStatus($novo['id'], 'pronto', null, $dono);
conferir('"Marcar pronto" com entregador do iFood: readyToPickup', $caminhos(), ['POST /order/v1.0/orders/uuid-10/readyToPickup']);
$zerar();
$servico->mudarStatus($novo['id'], 'saiu_entrega', null, $dono);
conferir('"Saiu para entrega" com entregador do iFood: não manda dispatch (o iFood marca sozinho)', $caminhos(), []);

$outro = (int) $pdo->query("SELECT pedido_id FROM ifood_pedidos WHERE ifood_id = 'uuid-11'")->fetchColumn();
$zerar();
$respostas['#/uuid-11/cancellationReasons$#'] = fn () => $json([['cancelCodeId' => '501', 'description' => 'Problemas no sistema'], ['cancelCodeId' => '503', 'description' => 'Item indisponível']]);
$servico->mudarStatus($outro, 'cancelado', 'Item indisponível', $dono, '503');
$cancelamento = array_values(array_filter($chamadas, static fn (array $c): bool => str_ends_with($c['caminho'], '/requestCancellation')))[0] ?? null;
conferir('Cancelar: consulta os motivos aceitos para o pedido (cancellationReasons) antes de cancelar', $caminhos(), ['GET /order/v1.0/orders/uuid-11/cancellationReasons', 'POST /order/v1.0/orders/uuid-11/requestCancellation']);
conferir('Cancelar: usa o código escolhido da lista do iFood, com o motivo escrito', json_decode((string) ($cancelamento['corpo'] ?? ''), true), ['reason' => 'Item indisponível', 'cancellationCode' => '503']);
$ifood->importar($pedidoIfood('uuid-14', ['displayId' => '4825']));
$quatorze = (int) $pdo->query("SELECT pedido_id FROM ifood_pedidos WHERE ifood_id = 'uuid-14'")->fetchColumn();
$respostas['#/uuid-14/cancellationReasons$#'] = fn () => $json([['cancelCodeId' => '501', 'description' => 'Problemas no sistema'], ['cancelCodeId' => '509', 'description' => 'Dificuldades internas']]);
$zerar();
$servico->mudarStatus($quatorze, 'cancelado', 'Qualquer', $dono, '999');
$cancelamento = array_values(array_filter($chamadas, static fn (array $c): bool => str_ends_with($c['caminho'], '/requestCancellation')))[0] ?? null;
conferir('Código fora da lista do pedido: troca pelo 509 (se existir) em vez de mandar um código que o iFood recusa', json_decode((string) ($cancelamento['corpo'] ?? ''), true)['cancellationCode'] ?? null, '509');
$motivos = $ifood->motivosDeCancelamento($quatorze);
conferir('A tela pode pedir a lista de motivos do pedido', [$motivos['origem'], array_column($motivos['motivos'], 'codigo')], ['ifood', ['501', '509']]);

// Cancelado no iFood (pelo cliente ou pelo próprio iFood): o cartão sai do quadro.
$eventos = [['id' => 'ev-3', 'fullCode' => 'CANCELLED', 'orderId' => 'uuid-12']];
$respostas['#/order/v1.0/orders/uuid-12$#'] = fn () => $json($pedidoIfood('uuid-12', ['displayId' => '4823']));
$ifood->importar($pedidoIfood('uuid-12', ['displayId' => '4823']));
$liberarCiclo();
$ifood->ciclo();
$cancelado = $pedidos->porId((int) $pdo->query("SELECT pedido_id FROM ifood_pedidos WHERE ifood_id = 'uuid-12'")->fetchColumn());
conferir('Evento CANCELLED: o pedido é cancelado no Kanban com o motivo', [$cancelado['status'], end($cancelado['historico'])['motivo']], ['cancelado', 'Cancelado no iFood']);
$eventos = [['id' => 'ev-4', 'fullCode' => 'CONCLUDED', 'orderId' => 'uuid-10']];
$liberarCiclo();
$ifood->ciclo();
conferir('Evento CONCLUDED: o pedido é concluído no Kanban', $pedidos->porId($novo['id'])['status'], 'concluido');

// Ação que falha: tenta de novo; erro que não adianta repetir (404) desiste na hora.
$terceiro = $ifood->importar($pedidoIfood('uuid-13', ['displayId' => '4824', 'delivery' => ['deliveredBy' => 'MERCHANT']]))['id'];
$respostas['#/uuid-13/startPreparation$#'] = fn () => $json(['message' => 'fora do ar'], 500);
$servico->mudarStatus($terceiro, 'em_preparo', null, $dono);
$acao = $pdo->query("SELECT estado, tentativas FROM ifood_acoes WHERE pedido_id = {$terceiro} AND acao = 'startPreparation'")->fetch();
conferir('iFood fora do ar: a etapa fica na fila para tentar de novo', [$acao['estado'], (int) $acao['tentativas']], ['pendente', 1]);
$respostas['#/uuid-13/startPreparation$#'] = fn () => $json([], 202);
$pdo->exec("UPDATE ifood_acoes SET proxima_em = '2000-01-01 00:00:00' WHERE pedido_id = {$terceiro}");
$ifood->processarAcoes(5);
conferir('Na nova tentativa a etapa vai', $pdo->query("SELECT estado FROM ifood_acoes WHERE pedido_id = {$terceiro} AND acao = 'startPreparation'")->fetchColumn(), 'feita');
$respostas['#/uuid-13/readyToPickup$#'] = fn () => $json([], 202);
$respostas['#/uuid-13/dispatch$#'] = fn () => $json(['message' => 'order not found'], 404);
$zerar();
$servico->mudarStatus($terceiro, 'pronto', null, $dono);
conferir('Entrega própria: "Marcar pronto" já avisa readyToPickup (o dispatch só vale depois dele)', $caminhos(), ['POST /order/v1.0/orders/uuid-13/readyToPickup']);
$zerar();
$servico->mudarStatus($terceiro, 'saiu_entrega', null, $dono);
conferir('Entrega própria: "Saiu para entrega" manda dispatch, sem repetir o readyToPickup', $caminhos(), ['POST /order/v1.0/orders/uuid-13/dispatch']);
conferir('readyToPickup foi enviado uma vez só', $pdo->query("SELECT COUNT(*) FROM ifood_acoes WHERE pedido_id = {$terceiro} AND acao = 'readyToPickup' AND estado = 'feita'")->fetchColumn(), 1);
conferir('Erro 404 do iFood: desiste na hora e o cartão mostra a falha', [$pdo->query("SELECT estado FROM ifood_acoes WHERE pedido_id = {$terceiro} AND acao = 'dispatch'")->fetchColumn(), $pedidos->porId($terceiro)['ifood']['falhas']], ['falhou', 1]);

echo "\nIFOOD: REGRAS DA DOCUMENTAÇÃO (retry, ordem, outro sistema, token, loja, cupons)\n";
$sucessoPolling = $respostas['#events:polling$#'];

// 404 ao ler o pedido logo depois do evento: tenta de novo por até 10 minutos e só então desiste.
$eventos = [['id' => 'ev-g1', 'fullCode' => 'PLACED', 'orderId' => 'uuid-16', 'createdAt' => '2026-11-16T19:00:00Z']];
$respostas['#/order/v1.0/orders/uuid-16$#'] = fn () => $json(['message' => 'not found'], 404);
$zerar();
$ifood->ciclo();
conferir('Pedido com 404 logo após o evento: sem acknowledgment (o iFood manda retry por até 10 min)', [in_array('POST /events/v1.0/events/acknowledgment', $caminhos(), true), str_starts_with((string) $pdo->query("SELECT resultado FROM ifood_eventos WHERE id = 'ev-g1'")->fetchColumn(), 'buscando:')], [false, true]);
$respostas['#/order/v1.0/orders/uuid-16$#'] = fn () => $json($pedidoIfood('uuid-16', ['displayId' => '4827']));
$ifood->ciclo();
conferir('No polling seguinte o pedido aparece e entra (o evento guardado como "buscando" é reaproveitado)', [(int) $pdo->query("SELECT COUNT(*) FROM ifood_pedidos WHERE ifood_id = 'uuid-16'")->fetchColumn(), $pdo->query("SELECT resultado FROM ifood_eventos WHERE id = 'ev-g1'")->fetchColumn()], [1, 'pedido_criado']);
$eventos = [['id' => 'ev-g2', 'fullCode' => 'PLACED', 'orderId' => 'uuid-17', 'createdAt' => '2026-11-16T19:00:00Z']];
$respostas['#/order/v1.0/orders/uuid-17$#'] = fn () => $json(['message' => 'not found'], 404);
$ifood->ciclo();
$pdo->exec("UPDATE ifood_eventos SET resultado = 'buscando:" . (time() - 700) . "' WHERE id = 'ev-g2'");
$zerar();
$ifood->ciclo();
conferir('Passados 10 minutos: desiste e confirma o evento (evita "strike" por evento sem confirmação)', [in_array('POST /events/v1.0/events/acknowledgment', $caminhos(), true), $pdo->query("SELECT resultado FROM ifood_eventos WHERE id = 'ev-g2'")->fetchColumn()], [true, 'desistiu_busca']);

// Eventos fora de ordem: processados pela hora de criação.
$eventos = [
    ['id' => 'ev-o2', 'code' => 'RTP', 'orderId' => 'uuid-15', 'createdAt' => '2026-11-16T19:00:05Z'],
    ['id' => 'ev-o1', 'fullCode' => 'PLACED', 'orderId' => 'uuid-15', 'createdAt' => '2026-11-16T19:00:00Z'],
];
$respostas['#/order/v1.0/orders/uuid-15$#'] = fn () => $json($pedidoIfood('uuid-15', ['displayId' => '4826']));
$ifood->ciclo();
$id15 = (int) $pdo->query("SELECT pedido_id FROM ifood_pedidos WHERE ifood_id = 'uuid-15'")->fetchColumn();
conferir('Eventos fora de ordem (pronto antes de novo): o pedido entra e depois vai para Pronto', [$id15 > 0, $id15 > 0 ? $pedidos->porId($id15)['status'] : null], [true, 'pronto']);

// Outro sistema (Gestor de Pedidos do iFood) muda a etapa: o quadro acompanha, só para frente, sem avisar o iFood de volta.
$eventos = [['id' => 'ev-d1', 'fullCode' => 'DISPATCHED', 'orderId' => 'uuid-15', 'createdAt' => '2026-11-16T19:00:06Z']];
$zerar();
$ifood->ciclo();
conferir('DISPATCHED feito pelo Gestor de Pedidos: o quadro vai para "Saiu para entrega"', $pedidos->porId($id15)['status'], 'saiu_entrega');
conferir('...e o app não manda nada de volta ao iFood (só polling e confirmação do evento)', $caminhos(), ['GET /events/v1.0/events:polling', 'POST /events/v1.0/events/acknowledgment']);
$eventos = [['id' => 'ev-d2', 'code' => 'PRS', 'orderId' => 'uuid-15', 'createdAt' => '2026-11-16T19:00:07Z']];
$ifood->ciclo();
conferir('Evento de uma etapa anterior (PRS, pelo código curto) não faz o pedido voltar', $pedidos->porId($id15)['status'], 'saiu_entrega');
$eventos = [['id' => 'ev-h1', 'code' => 'HSD', 'orderId' => 'uuid-15', 'createdAt' => '2026-11-16T19:00:08Z']];
$ifood->ciclo();
conferir('Disputa aberta pelo cliente: o cartão avisa para responder no Gestor de Pedidos', [str_contains((string) (Ifood::dadosDosPedidos([$id15])[$id15]['alerta'] ?? ''), 'disputa'), Ifood::dadosDosPedidos([$id15])[$id15]['rotulo']], [true, 'Disputa aberta']);
$eventos = [];

// Token: renova só quando venceu, espera depois de uma falha e renova depois de um 401.
$guardado = $respostas['#/oauth/token$#'];
$conexaoAgora = static fn (): array => $pdo->query('SELECT * FROM ifood_conexao WHERE id = 1')->fetch();
$chamadasDeToken = static function () use (&$chamadas): int {
    return count(array_filter($chamadas, static fn (array $c): bool => str_ends_with($c['caminho'], '/oauth/token')));
};
$config->salvar(['ifood_token_espera_ate' => '0']);
$pdo->exec("UPDATE ifood_conexao SET token_expira_em = '" . gmdate('Y-m-d H:i:s', time() + 120) . "'");
$zerar();
$ifood->ciclo();
conferir('Token ainda válido (faltando 2 minutos): não renova antes da hora', $chamadasDeToken(), 0);
$pdo->exec("UPDATE ifood_conexao SET token_expira_em = '2000-01-01 00:00:00'");
$respostas['#/oauth/token$#'] = fn () => $json(['error' => ['message' => 'instável']], 500);
$zerar();
$ifood->ciclo();
$ifood->ciclo();
conferir('Token vencido e a renovação falhou: espera 2 minutos em vez de insistir a cada polling', $chamadasDeToken(), 1);
$respostas['#/oauth/token$#'] = $guardado;
$config->salvar(['ifood_token_espera_ate' => '0']);
$zerar();
$ifood->ciclo();
conferir('Passada a espera, renova (uma chamada) e o polling segue', [$chamadasDeToken(), in_array('GET /events/v1.0/events:polling', $caminhos(), true)], [1, true]);
$config->salvar(['ifood_token_renovado_em' => (string) (time() - 1000)]);
$respostas['#events:polling$#'] = fn () => $json(['error' => ['message' => 'token expirado']], 401);
$ifood->ciclo();
conferir('O iFood respondeu 401 no polling: o token é dado como vencido', $conexaoAgora()['token_expira_em'] < gmdate('Y-m-d H:i:s'), true);
$respostas['#events:polling$#'] = $sucessoPolling;
$zerar();
$ifood->ciclo();
conferir('...e o ciclo seguinte pede um token novo', $chamadasDeToken(), 1);
$pdo->exec("UPDATE ifood_conexao SET token_expira_em = '2000-01-01 00:00:00'");
$config->salvar(['ifood_token_renovado_em' => (string) time(), 'ifood_token_espera_ate' => '0']);
$respostas['#events:polling$#'] = fn () => $json(['error' => ['message' => 'token expirado']], 401);
$ifood->ciclo();
$respostas['#events:polling$#'] = $sucessoPolling;
$pdo->exec("UPDATE ifood_conexao SET token_expira_em = '" . gmdate('Y-m-d H:i:s', time() + 3600) . "'");
$config->salvar(['ifood_token_renovado_em' => (string) time()]);
$respostas['#events:polling$#'] = fn () => $json(['error' => ['message' => 'token expirado']], 401);
$ifood->ciclo();
conferir('401 logo depois de renovar: não invalida de novo (evita pedir token a cada 30 s)', $conexaoAgora()['token_expira_em'] > gmdate('Y-m-d H:i:s'), true);
$respostas['#events:polling$#'] = $sucessoPolling;

// Conectado sem loja (a permissão do lojista leva até 10 minutos para valer): procura de novo sozinho.
$pdo->exec('UPDATE ifood_conexao SET merchant_id = NULL, merchant_nome = NULL');
$config->salvar(['ifood_busca_loja_em' => '0']);
conferir('Conectado sem loja: a tela avisa', $ifood->situacao()['sem_loja'], true);
$zerar();
$ifood->ciclo();
conferir('O ciclo procura a loja de novo e a encontra', [in_array('GET /merchant/v1.0/merchants', $caminhos(), true), $conexaoAgora()['merchant_id']], [true, 'loja-1']);
$zerar();
$pdo->exec('UPDATE ifood_conexao SET merchant_id = NULL');
$ifood->ciclo();
conferir('Mas só uma vez por minuto', in_array('GET /merchant/v1.0/merchants', $caminhos(), true), false);
$pdo->exec("UPDATE ifood_conexao SET merchant_id = 'loja-1'");

// O que a homologação manda exibir: cupom e quem paga, CPF/CNPJ da nota, observação de retirada, "customization" no singular.
$d = Ifood::detalhesDoPedido($pedidoIfood('x', [
    'benefits' => [['value' => 5.0, 'target' => 'CART', 'sponsorshipValues' => [['name' => 'IFOOD', 'value' => 3.0], ['name' => 'MERCHANT', 'value' => 2.0]]],
                   ['value' => 4.99, 'target' => 'DELIVERY_FEE', 'sponsorshipValues' => [['name' => 'EXTERNAL', 'value' => 4.99], ['name' => 'MERCHANT', 'value' => 0]]]],
    'customer' => ['documentNumber' => '12345678909', 'documentType' => 'CPF'],
]));
conferir('Cupons com quem paga (iFood, loja ou parceiro)', $d['cupons'], ['Cupom de R$ 5,00 (carrinho) · quem paga: iFood R$ 3,00, Loja R$ 2,00', 'Cupom de R$ 4,99 (taxa de entrega) · quem paga: parceiro do iFood R$ 4,99']);
conferir('CPF/CNPJ da nota fiscal', $d['documento'], 'CPF 12345678909');
conferir('Taxas adicionais (additionalFees) são lidas', Ifood::detalhesDoPedido($pedidoIfood('x', ['total' => ['additionalFees' => 1]]))['taxas_adicionais_centavos'], 100);
[$pRetirada] = Ifood::converter($pedidoIfood('y', ['orderType' => 'TAKEOUT', 'delivery' => null, 'takeout' => ['mode' => 'DEFAULT', 'observations' => 'Cliente espera no estacionamento']]));
conferir('Observação da retirada entra nas observações do pedido', str_contains($pRetirada['observacoes'], 'estacionamento'), true);
[, $itensCustom] = Ifood::converter($pedidoIfood('z', ['items' => [['name' => 'Pizza', 'quantity' => 1, 'unitPrice' => 30, 'totalPrice' => 33, 'options' => [['name' => 'Borda', 'quantity' => 1, 'customization' => [['name' => 'Catupiry']]]]]]]));
conferir('Complemento com a lista "customization" (singular) também é lido', str_contains($itensCustom[0]['observacoes'], 'Catupiry'), true);

echo "\nIFOOD: SIMULADOR\n";
Env::definir('IFOOD_CLIENT_ID', '');
$zerar();
$simulado = $ifood->simularPedido('DELIVERY');
$doSimulador = $pedidos->porId($simulado['id']);
conferir('Pedido de teste entra no Kanban como iFood, marcado como teste e já confirmado', [$doSimulador['origem'], $doSimulador['ifood']['simulado'], $doSimulador['ifood']['confirmado']], ['ifood', true, true]);
conferir('O simulador não chama o iFood', $chamadas, []);
$servico->mudarStatus($simulado['id'], 'em_preparo', null, $dono);
conferir('Etapas do pedido de teste também não chamam o iFood', $chamadas, []);
$retirada = $pedidos->porId($ifood->simularPedido('TAKEOUT')['id']);
conferir('Simular retirada', [$retirada['tipo'], $retirada['taxa_entrega_centavos']], ['retirada', 0]);

echo "\nIFOOD: CREDENCIAIS PELOS CAMPOS DA TELA (Client Secret cifrado)\n";
foreach (['IFOOD_CLIENT_ID', 'IFOOD_CLIENT_SECRET', 'IFOOD_TIPO_APP'] as $variavel) {
    Env::definir($variavel, '');
}
Env::definir('APP_KEY', str_repeat('k', 64));
$ifood->removerCredenciais();
$camposDoErro = static function (callable $acao): array {
    try {
        $acao();
        return [];
    } catch (App\Core\ErroHttp $e) {
        return array_keys($e->campos);
    }
};
conferir('Sem credenciais (ambiente e tela vazios): modo simulado', [$ifood->situacao()['modo'], $ifood->situacao()['credenciais']['origem']], ['simulado', 'nenhuma']);
conferir('Validação: Client ID curto e sem secret', $camposDoErro(fn () => $ifood->salvarCredenciais('abc', '', 'centralizado')), ['client_id', 'client_secret']);
conferir('Validação: secret com espaço e tipo inexistente', $camposDoErro(fn () => $ifood->salvarCredenciais('client-painel-123', 'tem espaco', 'outro')), ['client_secret', 'tipo_app']);
$s = $ifood->salvarCredenciais('client-painel-123', 'segredo-do-painel-xyz', 'centralizado');
conferir('Salvou: modo real, origem painel, secret salvo e tipo', [$s['modo'], $s['credenciais']['origem'], $s['credenciais']['client_id'], $s['credenciais']['secret_salvo'], $s['tipo_app']], ['real', 'painel', 'client-painel-123', true, 'centralizado']);
$noBanco = json_encode($config->todas());
conferir('O banco guarda o secret cifrado, nunca em texto', [str_contains($noBanco, 'segredo-do-painel-xyz'), str_contains($noBanco, '"ifood_client_secret":"v1:')], [false, true]);
conferir('A situação do painel não leva o secret', str_contains(json_encode($ifood->situacao()), 'segredo-do-painel-xyz'), false);
conferir('Duas cifragens do mesmo texto saem diferentes e abrem de volta', [App\Core\Segredo::cifrar('a') !== App\Core\Segredo::cifrar('a'), App\Core\Segredo::decifrar(App\Core\Segredo::cifrar('olá'))], [true, 'olá']);

// As credenciais da tela são as que vão na chamada ao iFood.
$respostas['#/oauth/token$#'] = fn () => $json(['accessToken' => 'tok-painel', 'expiresIn' => 21600]);
$respostas['#/merchant/v1.0/merchants$#'] = fn () => $json([['id' => 'loja-p', 'name' => 'Loja do painel']]);
$zerar();
$s = $ifood->conectar();
conferir('Centralizado com credenciais da tela: conecta e acha a loja', [$s['estado'], $s['loja']['id'] ?? null], ['conectado', 'loja-p']);
parse_str((string) $chamadas[0]['corpo'], $enviado);
conferir('A chamada de token leva o Client ID e o secret que foram digitados', [$enviado['grantType'] ?? null, $enviado['clientId'] ?? null, $enviado['clientSecret'] ?? null], ['client_credentials', 'client-painel-123', 'segredo-do-painel-xyz']);

// Secret em branco mantém o salvo e não desconecta; trocar qualquer credencial desconecta.
$s = $ifood->salvarCredenciais('client-painel-123', '', 'centralizado');
conferir('Salvar de novo com o secret em branco: mantém o salvo e continua conectado', [$s['credenciais']['secret_salvo'], $s['estado']], [true, 'conectado']);
$s = $ifood->salvarCredenciais('client-painel-123', '', 'distribuido');
conferir('Trocar o tipo do aplicativo desconecta a loja e esquece a loja antiga', [$s['estado'], $s['loja']], ['desconectado', null]);
$ifood->salvarCredenciais('client-painel-123', '', 'centralizado');
$ifood->conectar();
$s = $ifood->salvarCredenciais('outro-client-9999', 'novo-segredo-abc', 'centralizado');
conferir('Trocar o Client ID e o secret desconecta', [$s['estado'], $s['credenciais']['client_id']], ['desconectado', 'outro-client-9999']);
$s = $ifood->removerCredenciais();
conferir('Remover: volta ao modo simulado e apaga o secret', [$s['modo'], $s['credenciais']['secret_salvo'], str_contains(json_encode($config->todas()), 'v1:')], ['simulado', false, false]);

// As variáveis de ambiente valem primeiro e bloqueiam a edição pela tela.
Env::definir('IFOOD_CLIENT_ID', 'id-do-ambiente-1');
Env::definir('IFOOD_CLIENT_SECRET', 'secret-do-ambiente-1');
$s = $ifood->situacao();
conferir('Com as variáveis de ambiente: origem ambiente e tela só leitura', [$s['credenciais']['origem'], $s['credenciais']['editavel'], $s['credenciais']['client_id']], ['ambiente', false, 'id-do-ambiente-1']);
conferir('Salvar pela tela com credenciais no ambiente é recusado', codigoDoErro(fn () => $ifood->salvarCredenciais('client-painel-123', 'segredo-do-painel-xyz', 'centralizado')), '409 ifood_credenciais_no_ambiente');
conferir('Remover pela tela com credenciais no ambiente é recusado', codigoDoErro(fn () => $ifood->removerCredenciais()), '409 ifood_credenciais_no_ambiente');
Env::definir('IFOOD_CLIENT_ID', '');
Env::definir('IFOOD_CLIENT_SECRET', '');

// Sem APP_KEY não dá para proteger o secret: recusa em vez de guardar em texto.
Env::definir('APP_KEY', '');
conferir('Sem APP_KEY no servidor: não grava o secret', codigoDoErro(fn () => $ifood->salvarCredenciais('client-painel-123', 'segredo-do-painel-xyz', 'centralizado')), '409 ifood_sem_app_key');
// APP_KEY trocada: o secret antigo não abre mais e a tela avisa para digitar de novo.
Env::definir('APP_KEY', str_repeat('k', 64));
$ifood->salvarCredenciais('client-painel-123', 'segredo-do-painel-xyz', 'centralizado');
Env::definir('APP_KEY', str_repeat('z', 64));
$s = $ifood->situacao();
conferir('APP_KEY trocada: secret ilegível, modo simulado e aviso na tela', [$s['credenciais']['secret_ilegivel'], $s['modo']], [true, 'simulado']);
Env::definir('APP_KEY', str_repeat('k', 64));
$ifood->removerCredenciais();

echo "\nCOMANDA EM LINHAS (o que o agente imprime)\n";
$texto = static fn (array $linhas): string => implode("\n", array_map(static fn (array $l): string => $l['t'] . (isset($l['d']) ? ' | ' . $l['d'] : ''), $linhas));
$doIfood = $texto(Comanda::linhas($doSimulador, ['nome_loja' => 'Família Massas', 'com_preco' => false]));
conferir('Comanda do iFood: número do app e "pago online" mesmo sem preços', [str_contains($doIfood, 'iFood nº ' . $doSimulador['ifood']['display_id'] . ' (TESTE)'), str_contains($doIfood, 'PAGO ONLINE - NÃO COBRAR'), str_contains($doIfood, 'TOTAL')], [true, true, false]);
conferir('Comanda do iFood: cupom com quem paga (exigência da homologação) e sem CPF impresso', [str_contains($doIfood, 'quem paga: iFood R$ 3,00, Loja R$ 2,00'), str_contains($doIfood, '12345678909')], [true, false]);
$aCobrar = $doSimulador;
$aCobrar['ifood']['pago_online'] = false;
$aCobrar['ifood']['a_cobrar_centavos'] = 1234;
conferir('Comanda do iFood a cobrar: o valor é o de payments.pending, não o total', str_contains($texto(Comanda::linhas($aCobrar, ['nome_loja' => 'X', 'com_preco' => false])), 'COBRAR R$ 12,34'), true);
conferir('Comanda do iFood: código de coleta e quem entrega', [str_contains($doIfood, 'Código de coleta: '), str_contains($doIfood, 'Entregador do iFood')], [true, true]);
$comum = $pedidos->porId($pedidoComum());
$comPreco = $texto(Comanda::linhas($comum, ['nome_loja' => 'Família Massas', 'com_preco' => true]));
conferir('Comanda comum com preço: total e o pagamento num quadro em destaque (D97); sem o telefone (D122)', [str_contains($comPreco, 'TOTAL | R$ 24,90'), str_contains($comPreco, 'PAGAMENTO: PIX'), str_contains($comPreco, '91234-5678')], [true, true, false]);
$semPreco = Comanda::linhas($comum, ['nome_loja' => 'X', 'com_preco' => false]);
$quadroPagamento = array_values(array_filter($semPreco, static fn (array $l): bool => str_starts_with($l['t'], 'PAGAMENTO')));
conferir('Comanda sem preço por item: o pagamento sai no quadro e o TOTAL sai no resumo mesmo assim (D118)', [$quadroPagamento[0]['t'] ?? null, $quadroPagamento[0]['e'] ?? null, str_contains($texto($semPreco), 'TOTAL | R$ 24,90')], ['PAGAMENTO: PIX', 'caixa', true]);
$aConfirmar = $comum;
$aConfirmar['tipo'] = 'entrega';
$aConfirmar['taxa_entrega_centavos'] = 0;
$aConfirmar['observacoes'] = App\Services\PedidoService::TAXA_A_CONFIRMAR;
conferir('Entrega sem localização: o resumo mostra a taxa a confirmar antes do total (D118)', str_contains($texto(Comanda::linhas($aConfirmar, ['nome_loja' => 'X', 'com_preco' => false])), 'Taxa de entrega | a confirmar'), true);
conferir('Dinheiro com troco, pedido da mesa ("na mesa", D112) e salão pra viagem', [
    Comanda::pagamentoEmDestaque(['pagamento' => 'dinheiro', 'troco_para_centavos' => 10000]), Comanda::pagamentoEmDestaque(['pagamento' => 'caixa', 'tipo' => 'local', 'troco_para_centavos' => null]),
    Comanda::pagamentoEmDestaque(['pagamento' => 'dinheiro', 'troco_para_centavos' => null]), Comanda::pagamentoEmDestaque(['pagamento' => 'caixa', 'tipo' => 'viagem', 'troco_para_centavos' => null]),
], ['PAGAMENTO: DINHEIRO, TROCO PARA R$ 100,00', 'PAGAMENTO NA MESA', 'PAGAMENTO: DINHEIRO, SEM TROCO', 'PAGAMENTO NA CONTA']);

// D122: depois dos pratos, só a taxa, o total e o pagamento (saíram o "CLIENTE:", o telefone e o resumo dos itens).
$linhasTeste = Comanda::teste(['nome_loja' => 'X', 'com_preco' => true]);
$textos = array_column($linhasTeste, 't');
$posPagamento = array_key_first(array_filter($textos, static fn (string $t): bool => str_starts_with($t, 'PAGAMENTO')));
$posUltimoPrato = max(array_keys(array_filter($textos, static fn (string $t): bool => str_starts_with($t, 'Molho: '))));
$posTotal = array_search('TOTAL', $textos, true);
conferir('Fim da comanda: taxa, TOTAL e pagamento, depois dos pratos; nada de resumo, "CLIENTE:" ou telefone (D122)', [
    $posTotal > $posUltimoPrato && $posTotal < $posPagamento, $textos[$posTotal - 1] ?? null, in_array('RESUMO DO PEDIDO', $textos, true),
    count(array_filter($textos, static fn (string $t): bool => str_contains($t, '91234-5678') || str_starts_with($t, 'CLIENTE:'))), $textos[5] ?? null,
], [true, 'Taxa de entrega', false, 0, 'Comanda de teste']);
conferir('Os pratos saem sem valor', array_values(array_filter($linhasTeste, static fn (array $l): bool => $l['t'] === '1x Monte seu prato'))[0]['d'] ?? null, null);
conferir('Comanda de teste tem acentos (o agente precisa imprimi-los)', str_contains($texto(Comanda::teste(['nome_loja' => 'X', 'com_preco' => true])), 'ação, pão'), true);

echo "\nCOMANDA COMO LISTA DE MARCAR (D95)\n";
$catalogoComanda = (new App\Repositories\CardapioRepository())->publico();
$idDe = static fn (string $lista, string $nome): int => array_column($catalogoComanda[$lista], 'id', 'nome')[$nome];
$montagemLista = [
    'tamanho' => ['id' => $idDe('tamanhos', 'Meio'), 'nome' => 'Meio'], 'massa' => ['id' => $idDe('massas', 'Nhoque'), 'nome' => 'Nhoque'],
    'molho' => ['id' => $idDe('molhos', 'Pesto'), 'nome' => 'Pesto'],
    'inclusos' => [['id' => $idDe('ingredientes', 'Milho'), 'nome' => 'Milho']], 'extras' => [['id' => $idDe('ingredientes', 'Carne seca'), 'nome' => 'Carne seca']],
];
$secoes = Comanda::checklist($montagemLista, $catalogoComanda);
$marcadas = static fn (array $secoes): array => array_map(static fn (array $s): array => [$s['titulo'], array_column(array_filter($s['opcoes'], static fn (array $o): bool => $o['marcada']), 'nome'), count($s['opcoes'])], $secoes);
conferir('Só o que foi escolhido (D112), na ordem do panfleto (molho por último); grupo sem escolha (Queijos) não sai', $marcadas($secoes), [
    ['Tamanho', ['Meio'], 1], ['Massa', ['Nhoque'], 1], ['Proteínas', ['Carne seca'], 1], ['Acompanhamentos', ['Milho'], 1], ['Molho', ['Pesto'], 1],
]);
$orfa = $montagemLista;
$orfa['massa'] = ['id' => 99999, 'nome' => 'Rondelli'];
$orfa['extras'] = [['id' => 88888, 'nome' => 'Trufa']];
$secoesOrfas = $marcadas(Comanda::checklist($orfa, $catalogoComanda));
conferir('O que o cliente pediu e saiu do cardápio continua na comanda, marcado (a cozinha nunca perde o pedido)', [$secoesOrfas[1][1], $secoesOrfas[1][2], end($secoesOrfas)[0], $secoesOrfas[count($secoesOrfas) - 2]], [['Rondelli'], 1, 'Molho', ['Outros', ['Trufa'], 1]]);
Banco::conexao()->exec("INSERT INTO ingredientes (nome, grupo, preco_extra_centavos, ordem) VALUES ('Orégano', 'Tempero (quanto quiser)', 0, 999)");
$comOregano = $montagemLista;
$comOregano['inclusos'][] = ['nome' => 'Orégano'];
$comTempero = array_column(Comanda::checklist($comOregano), 'titulo');
conferir('Os grupos saem na ordem do cadastro (D119): o grupo novo, por último no cadastro, vem antes do molho', array_slice($comTempero, -3), ['Acompanhamentos', 'Tempero (quanto quiser)', 'Molho']);
Banco::conexao()->exec("UPDATE ingredientes SET ordem = -1 WHERE nome = 'Orégano'");
conferir('Grupo movido para cima no cadastro sai antes na comanda (D119)', array_column(Comanda::checklist($comOregano), 'titulo')[2] ?? null, 'Tempero (quanto quiser)');
Banco::conexao()->exec("DELETE FROM ingredientes WHERE nome = 'Orégano'");
$linhasLista = Comanda::linhas(['id' => 7, 'tipo' => 'retirada', 'mesa' => null, 'origem' => 'cardapio', 'criado_em' => '2026-11-20T12:00:00Z', 'cliente_nome' => 'Ana', 'cliente_telefone' => '',
    'observacoes' => '', 'taxa_entrega_centavos' => 0, 'total_centavos' => 0, 'pagamento' => 'pix', 'troco_para_centavos' => null,
    'itens' => [['nome' => 'Monte seu prato', 'quantidade' => 2, 'preco_unit_centavos' => 0, 'observacoes' => '', 'montagem' => $montagemLista]]], ['nome_loja' => 'X', 'com_preco' => false]);
$textoLista = $texto($linhasLista);
conferir('Prato na comanda: uma linha por seção, só o escolhido (D112); nada da lista inteira', [
    str_contains($textoLista, '2x Monte seu prato'), str_contains($textoLista, 'Massa: Nhoque'), str_contains($textoLista, 'Proteínas: Carne seca'),
    str_contains($textoLista, 'Molho: Pesto'), str_contains($textoLista, 'Penne'), str_contains($textoLista, 'Queijos'), str_contains($textoLista, '□'),
], [true, true, true, true, false, false, false]);
$posLista = array_search('- Bacon', $textos, true);
conferir('Mais de uma escolha no grupo sai em lista, um por linha, sem vírgula (D115)', [$posLista !== false, str_ends_with((string) $textos[array_key_last(array_filter(array_slice($textos, 0, (int) $posLista, true), static fn (string $t): bool => !str_starts_with($t, '- ')))], ':'), count(array_filter($textos, static fn (string $t): bool => str_contains($t, 'Bacon,')))], [true, true, 0]);
$estilos = array_column($linhasTeste, 'e');
$posPrimeiro = array_search('1x Monte seu prato', $textos, true);
$posSegundo = array_search('1x Lasanha da casa', $textos, true);
conferir('Um traço separa um prato do outro (D112)', [$posSegundo > $posPrimeiro, $estilos[$posSegundo - 1]], [true, 'linha']);

echo "\nAGENTE DE IMPRESSÃO\n";
$agente = new AgenteImpressao();
$antes = $pedidoComum();   // pedido de antes do agente existir: não pode sair impresso de repente
conferir('Sem código, ninguém entra', $agente->codigoValido('qualquer'), false);
$pdo->exec("UPDATE pedidos SET criado_em = '2026-01-01 00:00:00'");
$codigo = $agente->gerarCodigo();
conferir('O código gerado vale e liga o modo agente', [$agente->codigoValido($codigo), $agente->ajustes()['modo']], [true, 'agente']);
conferir('Outro código não vale', $agente->codigoValido($codigo . 'x'), false);
conferir('O banco guarda só o hash do código', str_contains(json_encode($config->todas()), $codigo), false);
conferir('Agente que nunca se conectou: faixa no painel', $agente->alertaParaOPainel()['estado'] ?? null, 'offline');

$info = ['computador' => 'CAIXA', 'versao' => 1, 'pronta' => true, 'impressoras' => [['nome' => 'QIA-8388', 'status' => 'Normal', 'padrao' => false], ['nome' => 'Microsoft Print to PDF', 'status' => 'Normal', 'padrao' => true]]];
$depois = $pedidoComum();
$doIfoodNovo = $ifood->simularPedido('DELIVERY')['id'];
$ciclo = $agente->ciclo($info);
$idsDoCiclo = array_column($ciclo['trabalhos'], 'pedido_id');
conferir('O agente recebe as comandas dos pedidos novos (cardápio e iFood)', $idsDoCiclo, [$depois, $doIfoodNovo]);
conferir('Pedido de antes de instalar o agente não sai', in_array($antes, $idsDoCiclo, true), false);
conferir('Cada comanda vem pronta em linhas', $ciclo['trabalhos'][0]['linhas'][1], ['t' => '#' . $depois, 'e' => 'numero', 'a' => 'c']);
conferir('Os ajustes vão junto (impressora, jeito de enviar, papel, letra, vias)', array_keys($ciclo['ajustes']), ['impressora', 'linguagem', 'largura_mm', 'altura_mm', 'margem_mm', 'fonte', 'vias']);
conferir('Padrão: cupom ESC/POS direto (não depende do driver do Windows)', $ciclo['ajustes']['linguagem'], 'escpos');
conferir('Pedido entregue ao agente fica marcado como impresso por ele', $pedidos->porId($depois)['impresso_por'], 'agente');
conferir('O ciclo seguinte não entrega a mesma comanda de novo', $agente->ciclo($info)['trabalhos'], []);
conferir('Agente conectado: sem faixa', $agente->alertaParaOPainel(), null);

foreach ($ciclo['trabalhos'] as $t) {
    $agente->resultado($t['id'], true, null);
}
conferir('Impressa: o trabalho fica "feito"', array_column($agente->situacao()['trabalhos'], 'estado', 'pedido_id')[$depois], 'feito');

// Navegador e agente ao mesmo tempo: quem pegar primeiro imprime, o outro não.
$disputado = $pedidoComum();
$pedidos->reivindicarImpressao($disputado, 'estacao-do-navegador');
conferir('Pedido que o navegador já pegou não sai de novo no agente', array_column($agente->ciclo($info)['trabalhos'], 'pedido_id'), []);

// Falha: volta para a fila até 3 vezes, depois a faixa do painel avisa.
$falha = $pedidoComum();
$t = $agente->ciclo($info)['trabalhos'][0];
$agente->resultado($t['id'], false, 'Sem papel');
$t2 = $agente->ciclo($info)['trabalhos'][0] ?? null;
conferir('Falhou: a comanda volta e sai no ciclo seguinte', $t2['id'] ?? null, $t['id']);
$agente->resultado($t['id'], false, 'Sem papel');
$agente->ciclo($info);
$agente->resultado($t['id'], false, 'Sem papel');
conferir('Três falhas: desiste e a faixa do painel diz qual pedido', [$agente->alertaParaOPainel()['estado'] ?? null, $agente->alertaParaOPainel()['pedidos'] ?? null], ['falhas', [$falha]]);
$agente->pedirReimpressao($falha);
$reimpressao = $agente->ciclo($info)['trabalhos'][0];
conferir('"Reimprimir" no modo agente vai para a fila do agente (e tira a faixa)', [$reimpressao['tipo'], $reimpressao['pedido_id'], $agente->alertaParaOPainel()], ['reimpressao', $falha, null]);
conferir('A reimpressão sai marcada como reimpressão', str_contains($texto($reimpressao['linhas']), 'REIMPRESSÃO'), true);
$agente->resultado($reimpressao['id'], true, null);

// Impressora pausada no Windows: as comandas esperam (sem gastar tentativas) e o painel avisa.
$config->salvar(['impressora_nome' => 'QIA-8388']);
$pausada = $info;
$pausada['pronta'] = false;
$pausada['impressoras'][0]['status'] = 'Paused';
$esperando = $pedidoComum();
conferir('Impressora pausada: nenhuma comanda entregue', $agente->ciclo($pausada)['trabalhos'], []);
conferir('Impressora pausada: faixa no painel com o motivo', $agente->alertaParaOPainel()['texto'] ?? null, 'A impressora "QIA-8388" está pausada no Windows. As comandas ficam na fila até ela voltar.');
conferir('Quando ela volta, a comanda que esperava sai', array_column($agente->ciclo($info)['trabalhos'], 'pedido_id'), [$esperando]);
$config->salvar(['impressora_nome' => 'Impressora que não existe']);
conferir('Impressora escolhida que sumiu do computador: faixa no painel', $agente->alertaParaOPainel()['estado'] ?? null, 'impressora');
$config->salvar(['impressora_nome' => 'QIA-8388']);

// O que sai sozinho: dá para desligar uma origem.
$agente->salvarAjustes(['modo' => 'agente', 'impressora' => 'QIA-8388', 'linguagem' => 'driver', 'largura_mm' => 100, 'altura_mm' => 150, 'margem_mm' => 3, 'fonte' => 'm', 'vias' => 1, 'com_preco' => false, 'origens' => ['cardapio', 'whatsapp', 'balcao']]);
$semIfood = $ifood->simularPedido('DELIVERY')['id'];
conferir('Origem iFood desligada: o pedido do iFood não sai sozinho', in_array($semIfood, array_column($agente->ciclo($info)['trabalhos'], 'pedido_id'), true), false);
conferir('Parametrização gravada (etiqueta 100 × 150 pelo driver)', array_intersect_key($agente->ajustes(), array_flip(['linguagem', 'largura_mm', 'altura_mm', 'origens'])), ['linguagem' => 'driver', 'largura_mm' => 100, 'altura_mm' => 150, 'origens' => ['cardapio', 'whatsapp', 'balcao']]);
conferir('Validação: largura que não existe e altura fora da faixa', array_keys($agente->salvarAjustes(['modo' => 'agente', 'linguagem' => 'zpl', 'largura_mm' => 90, 'altura_mm' => 10, 'margem_mm' => 3, 'fonte' => 'm', 'vias' => 1, 'origens' => []])), ['linguagem', 'largura_mm', 'altura_mm']);
$agente->pedirTeste();
$teste = $agente->ciclo($info)['trabalhos'][0];
conferir('Comanda de teste pelo agente', [$teste['tipo'], $teste['pedido_id'], $teste['linhas'][1]['t']], ['teste', null, 'TESTE']);

// Modo navegador: o agente continua conectado, mas não imprime nada sozinho.
$agente->salvarAjustes(['modo' => 'navegador', 'largura_mm' => 80, 'altura_mm' => 0, 'margem_mm' => 3, 'fonte' => 'm', 'vias' => 1, 'origens' => AgenteImpressao::ORIGENS]);
$pedidoComum();
conferir('Modo navegador: o agente não pega pedido nenhum e não há faixa', [$agente->ciclo($info)['trabalhos'], $agente->alertaParaOPainel()], [[], null]);
$agente->revogar();
conferir('Desligar o agente: o código para de valer', $agente->codigoValido($codigo), false);

echo "\nPASSO A PASSO DA IMPRESSORA (D86)\n";
$codigo = $agente->gerarCodigo();
$virtuais = ['nome' => 'Microsoft Print to PDF', 'status' => 'Normal', 'padrao' => true, 'porta' => 'PORTPROMPT:', 'driver' => 'Microsoft Print To PDF'];
$agente->ciclo(['computador' => 'CAIXA', 'versao' => 3, 'pronta' => true, 'impressoras' => [
    ['nome' => 'QIA-8388', 'status' => 'Normal', 'padrao' => false, 'porta' => 'USB001', 'driver' => 'LABEL'], $virtuais,
    ['nome' => 'OneNote (Desktop)', 'status' => 'Normal', 'padrao' => false, 'porta' => 'nul:', 'driver' => 'Send to Microsoft OneNote 16 Driver'],
]]);
$lista = $agente->situacao()['agente']['impressoras'];
conferir('PDF e OneNote ficam marcadas como virtuais; a térmica USB não', array_column($lista, 'virtual', 'nome'), ['QIA-8388' => false, 'Microsoft Print to PDF' => true, 'OneNote (Desktop)' => true]);
conferir('Agente antigo (sem porta): reconhece pelo nome', [AgenteImpressao::virtual(['nome' => 'Microsoft XPS Document Writer']), AgenteImpressao::virtual(['nome' => 'Elgin i9'])], [true, false]);
$base = ['modo' => 'agente', 'impressora' => 'QIA-8388', 'linguagem' => 'escpos', 'largura_mm' => 58, 'altura_mm' => 0, 'margem_mm' => 3, 'fonte' => 'm', 'vias' => 1, 'com_preco' => false, 'origens' => AgenteImpressao::ORIGENS];
$agente->salvarAjustes($base);
conferir('Antes do teste, nada confirmado', $agente->situacao()['agente']['confirmada_em'], null);
$agente->confirmar();
conferir('"Saiu certinha": fica confirmado', $agente->situacao()['agente']['confirmada_em'] !== null, true);
$agente->salvarAjustes(['vias' => 2] + $base);
conferir('Mudar vias não desfaz a confirmação', $agente->situacao()['agente']['confirmada_em'] !== null, true);
$agente->salvarAjustes(['linguagem' => 'tspl'] + $base);
conferir('Trocar o jeito de enviar pede um teste novo', $agente->situacao()['agente']['confirmada_em'], null);
$agente->confirmar();
$agente->salvarAjustes(['largura_mm' => 80] + $base);
conferir('Trocar a largura pede um teste novo', $agente->situacao()['agente']['confirmada_em'], null);
$agente->confirmar();
$novo = $agente->gerarCodigo();
$s = $agente->situacao();
conferir('Baixar o programa de novo: confirmação e sinal do antigo zerados (a tela espera o novo ligar)', [$s['agente']['confirmada_em'], $s['agente']['online'], $agente->codigoValido($codigo), $agente->codigoValido($novo)], [null, false, false, true]);

$cmd = Instalador::cmd('https://loja.exemplo.com', $novo, "# agente\r\nWrite-Host 'oi'\r\n");
$marca = strrpos($cmd, Instalador::MARCADOR . "\r\n");
conferir('Instalador: só ASCII e quebra de linha CRLF (o cmd lê assim)', [preg_match('/[^\x00-\x7F]/', $cmd), preg_match("/(?<!\r)\n/", $cmd)], [0, 0]);
conferir('Instalador: já vem com o endereço e o código (nada para digitar)', [str_contains($cmd, "\$servidor = 'https://loja.exemplo.com'"), str_contains($cmd, "\$codigo = '{$novo}'")], [true, true]);
preg_match('/WriteAllBytes\(\$script, \[Convert\]::FromBase64String\(\'([^\']+)\'\)/', $cmd, $b64);
conferir('Instalador: o agente vai inteiro dentro dele', base64_decode($b64[1] ?? ''), "# agente\r\nWrite-Host 'oi'\r\n");
conferir('Instalador: o cmd para antes do PowerShell e o PowerShell começa no marcador', [$marca !== false, strpos($cmd, 'exit /b 1') < $marca, substr_count($cmd, Instalador::MARCADOR)], [true, true, 1]);
conferir('Instalador: abre sozinho com o Windows, escondido', [str_contains($cmd, "GetFolderPath('Startup')"), str_contains($cmd, '-WindowStyle Hidden')], [true, true]);
conferir('Instalador: aspas simples no endereço não quebram o script', str_contains(Instalador::cmd("https://x.com/a'b", 'c', 'x'), "'https://x.com/a''b'"), true);
conferir('Instalador de verdade: leva o agente.ps1 do repositório', str_contains(Instalador::cmd('https://x', 'c'), base64_encode((string) file_get_contents(dirname(__DIR__) . '/impressora/agente.ps1'))), true);
preg_match('/WriteAllBytes\(\$zip, \[Convert\]::FromBase64String\(\'([^\']+)\'\)/', $cmd, $zipDriver);
$arquivoZip = tempnam(sys_get_temp_dir(), 'drv');
file_put_contents($arquivoZip, base64_decode($zipDriver[1] ?? ''));
$listaZip = [];
$zipAberto = new ZipArchive();
if ($zipAberto->open($arquivoZip) === true) {
    for ($i = 0; $i < $zipAberto->numFiles; $i++) {
        $listaZip[] = $zipAberto->getNameIndex($i);
    }
    $zipAberto->close();
}
@unlink($arquivoZip);
sort($listaZip);
conferir('Instalador (D90): leva o driver da QIA-8388 (inf, catálogo assinado e DLLs) e cria a fila só quando nenhuma impressora usa USB', [
    $listaZip, str_contains($cmd, "Add-Printer -Name 'QIA-8388' -DriverName 'LABEL'"), str_contains($cmd, '-Verb RunAs'),
], [['LABELCORE.DLL', 'LABELUI.DLL', 'LabelPrinter.GPD', 'LabelPrinter.INI', 'labelprinter.inf', 'printer.cat'], true, true]);
$agente->revogar();

echo "\nOS DOIS ESQUEMAS TÊM AS TABELAS NOVAS\n";
foreach (['schema.sqlite.sql', 'schema.pgsql.sql'] as $arquivo) {
    $sql = (string) file_get_contents(dirname(__DIR__) . '/data/' . $arquivo);
    conferir("{$arquivo}: ifood_conexao, ifood_pedidos, ifood_eventos, ifood_acoes e impressao_trabalhos", array_map(static fn (string $t): bool => str_contains($sql, "CREATE TABLE {$t} ("), ['ifood_conexao', 'ifood_pedidos', 'ifood_eventos', 'ifood_acoes', 'impressao_trabalhos']), [true, true, true, true, true]);
}

echo "\n" . ($falhas === 0 ? "Todos os {$total} testes passaram." : "{$falhas} de {$total} testes falharam.") . "\n";
exit($falhas === 0 ? 0 : 1);
