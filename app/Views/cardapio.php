<?php
/**
 * Cardápio digital. O HTML abaixo é o esqueleto; o conteúdo é desenhado por /assets/js/cardapio/principal.js
 * a partir dos dados embutidos no fim da página (os mesmos de GET /api/cardapio).
 * @var array $dados
 * @var array{t:string, telefone:string}|null $whatsapp
 * @var list<array<string,mixed>> $acompanhamento  pedidos deste navegador (cookie), no formato público
 */
$loja = $dados['loja'];
$tema = $loja['tema'];
$tituloPagina = $loja['nome'] . ' · Cardápio';
$descricao = 'Monte seu prato de massa do seu jeito e peça por aqui: ' . $loja['nome'] . '.';
$estilos = ['cardapio'];
?>
<!doctype html>
<html lang="pt-BR" data-theme="<?= e($tema) ?>">
<head>
<?php require __DIR__ . '/parciais/cabeca.php'; ?>
</head>
<body>
<a class="pular" href="#conteudo">Pular para o cardápio</a>

<header class="topo">
  <div class="topo__dentro">
    <a class="marca" href="/"><?= e($loja['nome']) ?></a>
    <span class="selo <?= $loja['aberta'] ? 'selo--sucesso' : 'selo--perigo' ?>" id="estado-loja">
      <?php [$curto, $resto] = array_pad(explode(' · ', $loja['mensagem'], 2), 2, ''); ?>
      <span class="selo__ponto"></span><span><?= e($curto) ?><?php if ($resto !== ''): ?><span class="selo__resto"> · <?= e($resto) ?></span><?php endif; ?></span>
    </span>
  </div>
</header>

<!-- ============ Visão 1: cardápio ============ -->
<div class="cardapio" id="visao-cardapio">
  <nav class="cardapio__nav" id="categorias" aria-label="Categorias"></nav>

  <main class="cardapio__itens" id="conteudo" tabindex="-1">
    <!-- Pedidos deste navegador (ativos e recém-encerrados). Desenhado por acompanhamento.js a partir de #dados-acompanhamento. -->
    <section class="meus-pedidos" id="acompanhamento" aria-label="Seus pedidos" hidden></section>
    <p class="so-leitor" id="acompanhamento-anuncio" role="status"></p>

    <div id="lista-cardapio" aria-busy="true">
      <!-- Estado de carregamento: aparece até o JavaScript desenhar o cardápio. -->
      <div class="esqueleto esqueleto--cartao" style="height: 11rem;"></div>
      <div class="esqueleto esqueleto--linha" style="width: 40%; margin-top: var(--e-8);"></div>
      <div class="esqueleto esqueleto--cartao" style="margin-top: var(--e-4);"></div>
      <div class="esqueleto esqueleto--cartao" style="margin-top: var(--e-3);"></div>
      <div class="esqueleto esqueleto--cartao" style="margin-top: var(--e-3);"></div>
    </div>
  </main>

  <aside class="cardapio__carrinho" aria-label="Seu pedido">
    <div class="painel-carrinho" id="carrinho-lateral"></div>
  </aside>
</div>

<!-- ============ Visão 2: fechar pedido ============ -->
<main class="checkout" id="visao-checkout" hidden tabindex="-1"></main>

<!-- Barra do carrinho (celular): aparece quando há itens. -->
<div class="barra-carrinho" id="barra-carrinho" hidden></div>

<!-- Folhas -->
<dialog class="folha folha--cheia folha--central folha--alta folha--montador" id="folha-montador" aria-labelledby="montador-titulo"></dialog>
<dialog class="folha folha--central" id="folha-item" aria-labelledby="item-titulo"></dialog>
<dialog class="folha folha--cheia folha--central folha--alta folha--mapa" id="folha-mapa" aria-labelledby="mapa-titulo"></dialog>
<dialog class="folha" id="folha-carrinho" aria-labelledby="carrinho-titulo">
  <header class="folha__topo">
    <h2 class="folha__titulo" id="carrinho-titulo">Seu pedido</h2>
    <button type="button" class="botao botao--fantasma botao--icone" data-fechar aria-label="Fechar o carrinho">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
    </button>
  </header>
  <div class="folha__corpo" id="carrinho-folha"></div>
</dialog>

<noscript>
  <p style="padding: 2rem; text-align: center;">Para ver o cardápio e pedir, ative o JavaScript do navegador.</p>
</noscript>

<script type="application/json" id="dados-cardapio"><?= json_embutido($dados) ?></script>
<script type="application/json" id="dados-whatsapp"><?= json_embutido($whatsapp) ?></script>
<script type="application/json" id="dados-acompanhamento"><?= json_embutido($acompanhamento) ?></script>
<script type="module" src="<?= e(asset('js/cardapio/principal.js')) ?>"></script>
</body>
</html>
