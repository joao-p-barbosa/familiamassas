<?php
$config = new App\Repositories\ConfigRepository();
$tema = $config->get('tema', 'italia');
$tituloPagina = 'Página não encontrada';
?>
<!doctype html>
<html lang="pt-BR" data-theme="<?= e($tema) ?>">
<head>
<?php require __DIR__ . '/parciais/cabeca.php'; ?>
</head>
<body>
<main class="vazio" style="min-height: 100dvh; align-content: center;">
  <a class="marca" href="/"><?= e($config->get('nome_loja', 'Nome do Restaurante')) ?></a>
  <h1 class="vazio__titulo" style="margin-top: var(--e-6);">Este endereço não existe</h1>
  <p>O link pode estar incompleto ou o pedido pode ser de outro endereço.</p>
  <a class="botao" href="/">Ver o cardápio</a>
</main>
</body>
</html>
