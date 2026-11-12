<?php
/**
 * <head> comum a todas as páginas.
 * @var string $tituloPagina
 * @var string $tema
 * @var list<string> $estilos  arquivos de css/ além dos comuns
 * @var string|null $descricao
 * @var string|null $csrf
 */
$estilos ??= [];
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($tituloPagina) ?></title>
<?php if (!empty($descricao)): ?>
<meta name="description" content="<?= e($descricao) ?>">
<?php endif; ?>
<meta name="theme-color" content="<?= e(cor_do_tema($tema)) ?>">
<?php if (!empty($csrf)): ?>
<meta name="csrf" content="<?= e($csrf) ?>">
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%23007a3a'/%3E%3Ccircle cx='16' cy='16' r='6' fill='%23fff'/%3E%3C/svg%3E">
<link rel="preload" href="/assets/fonts/hanken-grotesk.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/young-serif.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('css/tokens.css')) ?>">
<?php foreach (['italia', 'linho', 'pedra', 'grafite'] as $arquivoTema): ?>
<link rel="stylesheet" href="<?= e(asset("css/themes/{$arquivoTema}.css")) ?>">
<?php endforeach; ?>
<link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/components.css')) ?>">
<?php foreach ($estilos as $estilo): ?>
<link rel="stylesheet" href="<?= e(asset("css/{$estilo}.css")) ?>">
<?php endforeach; ?>
