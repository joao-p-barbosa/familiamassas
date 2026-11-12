<?php
// Testes das regras do servidor: preço do "Monte seu prato", pedido, token do WhatsApp e horários.
// Rodar (na raiz do projeto):   php -d extension=pdo_sqlite tests/regras.php
// Usa um banco temporário com os dados iniciais; não toca no banco de desenvolvimento.
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Env;
use App\Core\ErroHttp;
use App\Repositories\CardapioRepository;
use App\Repositories\ConfigRepository;
use App\Services\LojaService;
use App\Services\PedidoService;
use App\Services\PrecoService;
use App\Services\TokenWhatsApp;

$banco = sys_get_temp_dir() . '/familiamassas-teste-' . bin2hex(random_bytes(4)) . '.sqlite';
Env::definir('DB_DRIVER', 'sqlite');
Env::definir('DB_SQLITE_PATH', $banco);
register_shutdown_function(static function () use ($banco): void {
    foreach ([$banco, $banco . '-wal', $banco . '-shm'] as $arquivo) {
        @unlink($arquivo);
    }
});

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
    ['Pequeno sem ingredientes custa só a base', 'Pequeno', 'Penne', 'Sugo', [], 2490, []],
    ['Pequeno com 3 ingredientes (o limite): nada extra', 'Pequeno', 'Penne', 'Sugo', ['Frango desfiado', 'Bacon', 'Brócolis'], 2490, []],
    ['Pequeno com 4: o mais barato vira extra', 'Pequeno', 'Penne', 'Sugo', ['Frango desfiado', 'Bacon', 'Brócolis', 'Milho'], 2790, ['Milho']],
    ['Médio, bolonhesa (+3,00), 6 ingredientes: 2 extras', 'Médio', 'Penne', 'Bolonhesa', ['Frango desfiado', 'Bacon', 'Brócolis', 'Milho', 'Muçarela', 'Camarão'], 4090, ['Brócolis', 'Milho']],
    ['Mesma montagem em outra ordem de clique: mesmo preço', 'Médio', 'Penne', 'Bolonhesa', ['Milho', 'Camarão', 'Brócolis', 'Muçarela', 'Bacon', 'Frango desfiado'], 4090, ['Brócolis', 'Milho']],
    ['Grande, nhoque (+4,00), quatro queijos (+5,00), 6 ingredientes: tudo incluso', 'Grande', 'Nhoque', 'Quatro queijos', ['Frango desfiado', 'Bacon', 'Brócolis', 'Milho', 'Muçarela', 'Camarão'], 4890, []],
    ['Camarão (o mais caro) nunca é o extra quando há mais baratos', 'Pequeno', 'Espaguete', 'Sugo', ['Camarão', 'Milho', 'Ervilha', 'Brócolis'], 2790, ['Ervilha']],
    ['Ingrediente repetido conta uma vez só', 'Pequeno', 'Penne', 'Sugo', ['Bacon', 'Bacon', 'Bacon', 'Bacon'], 2490, []],
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

conferir('Ingrediente esgotado (Palmito) é recusado', codigoDoErro(fn () => $montar('Pequeno', 'Penne', 'Sugo', $ing('Palmito'))), '409 esgotado');
conferir('Montagem sem tamanho é recusada', codigoDoErro(fn () => $preco->montar($cardapio, ['massa_id' => 1, 'molho_id' => 1])), '422 montagem_invalida');

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

echo "\nSTATUS\n";
$admin = ['id' => 1, 'perfil' => 'admin'];
$cozinha = ['id' => 3, 'perfil' => 'cozinha'];
$idPedido = $criado['id'];
conferir('Retirada não aceita "saiu para entrega"', codigoDoErro(fn () => $pedidos->mudarStatus($idPedido, 'saiu_entrega', null, $admin)), '422 status_invalido');
conferir('Cozinha leva até "pronto"', $pedidos->mudarStatus($idPedido, 'pronto', null, $cozinha)['status'], 'pronto');
conferir('Cozinha não conclui', codigoDoErro(fn () => $pedidos->mudarStatus($idPedido, 'concluido', null, $cozinha)), '403 sem_permissao');
conferir('Cancelar exige motivo', codigoDoErro(fn () => $pedidos->mudarStatus($idPedido, 'cancelado', '  ', $admin)), '422 validacao');
conferir('Cancelar com motivo funciona', $pedidos->mudarStatus($idPedido, 'cancelado', 'Cliente desistiu', $admin)['status'], 'cancelado');
conferir('Pedido encerrado não muda mais', codigoDoErro(fn () => $pedidos->mudarStatus($idPedido, 'novo', null, $admin)), '409 pedido_encerrado');

echo "\nIMPRESSÃO (claim atômico)\n";
$repo = new App\Repositories\PedidoRepository();
$outro = $pedidos->criar($base)['id'];
conferir('Primeira estação fica com a impressão', $repo->reivindicarImpressao($outro, 'estacao-a'), true);
conferir('Segunda estação não imprime o mesmo pedido', $repo->reivindicarImpressao($outro, 'estacao-b'), false);
conferir('Fica registrado quem imprimiu', $repo->porId($outro)['impresso_por'], 'estacao-a');

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

echo "\n" . ($falhas === 0 ? "Todos os {$total} testes passaram." : "{$falhas} de {$total} testes falharam.") . "\n";
exit($falhas === 0 ? 0 : 1);
