<?php
/**
 * Acompanhamento do pedido. O estado inicial vem embutido; /assets/js/cardapio/acompanhar.js consulta
 * GET /api/pedidos/{codigo} de tempos em tempos e redesenha.
 * @var array $loja
 * @var array $pedido
 */
$tema = $loja['tema'];
$tituloPagina = 'Pedido #' . $pedido['numero'] . ' · ' . $loja['nome'];
$estilos = ['cardapio'];
?>
<!doctype html>
<html lang="pt-BR" data-theme="<?= e($tema) ?>">
<head>
<?php require __DIR__ . '/parciais/cabeca.php'; ?>
<meta name="robots" content="noindex, nofollow">
</head>
<body>
<header class="topo">
  <div class="topo__dentro">
    <a class="marca" href="/"><?= e($loja['nome']) ?></a>
  </div>
</header>

<main class="acompanhar" id="acompanhar">
  <h1 class="acompanhar__titulo"><?= e($pedido['rotulo']) ?></h1>
  <p class="acompanhar__numero">Pedido #<?= e($pedido['numero']) ?></p>
</main>

<script type="application/json" id="dados-pedido"><?= json_embutido(['pedido' => $pedido, 'loja' => ['nome' => $loja['nome'], 'whatsapp' => $loja['whatsapp'], 'endereco' => $loja['endereco']]]) ?></script>
<script type="module" src="<?= e(asset('js/cardapio/acompanhar.js')) ?>"></script>
</body>
</html>
