<?php
/**
 * Casca do painel: topo com menu + área de conteúdo. Cada tela é desenhada pelo seu módulo em /assets/js/painel/.
 * @var string $tela          pedidos | cozinha | cardapio | configuracoes | whatsapp
 * @var string $titulo
 * @var array<string, array{titulo:string}> $menu  telas que este perfil pode abrir
 * @var array{id:int, nome:string, login:string, perfil:string} $usuario
 * @var string $nomeLoja
 * @var string $tema
 * @var string $csrf
 * @var array $ajustes
 */
$tituloPagina = $titulo . ' · ' . $nomeLoja;
$estilos = ['painel', 'comanda'];
$icones = [
    'pedidos'       => '<rect x="3.5" y="4" width="5" height="16" rx="1"/><rect x="10" y="4" width="5" height="10" rx="1"/><rect x="16.5" y="4" width="4" height="13" rx="1"/>',
    'cozinha'       => '<path d="M4 10h16v6a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4v-6Zm-2 0h20M9 6.5c0-1.5 1.5-1.5 1.5-3M13.5 6.5c0-1.5 1.5-1.5 1.5-3"/>',
    'cardapio'      => '<path d="M5 4.5h11a3 3 0 0 1 3 3V20H8a3 3 0 0 1-3-3V4.5Z"/><path d="M5 17a3 3 0 0 1 3-3h11M9 8.5h6"/>',
    'configuracoes' => '<path d="M4 7h9m4 0h3M4 17h3m4 0h9"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/>',
    'whatsapp'      => '<path d="M20 12a8 8 0 0 1-11.8 7L4 20l1.1-3.9A8 8 0 1 1 20 12Z"/>',
];
$perfis = ['admin' => 'Administrador', 'atendente' => 'Atendimento', 'cozinha' => 'Cozinha'];
?>
<!doctype html>
<html lang="pt-BR" data-theme="<?= e($tema) ?>">
<head>
<?php require dirname(__DIR__) . '/parciais/cabeca.php'; ?>
</head>
<body class="painel" data-tela="<?= e($tela) ?>">
<a class="pular" href="#conteudo">Pular para o conteúdo</a>

<header class="painel-topo">
  <a class="marca" href="/painel"><?= e($nomeLoja) ?></a>

  <nav class="painel-nav" aria-label="Telas do painel">
    <?php foreach ($menu as $chave => $item): ?>
    <a class="painel-nav__item" href="/painel/<?= e($chave) ?>" <?= $chave === $tela ? 'aria-current="page"' : '' ?>>
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $icones[$chave] ?></svg>
      <span><?= e($item['titulo']) ?></span>
    </a>
    <?php endforeach; ?>
  </nav>

  <div class="painel-usuario">
    <span class="painel-usuario__nome"><?= e($usuario['nome']) ?><?php if (($perfis[$usuario['perfil']] ?? '') !== $usuario['nome']): ?><small><?= e($perfis[$usuario['perfil']] ?? $usuario['perfil']) ?></small><?php endif; ?></span>
    <button type="button" class="botao botao--fantasma botao--p" id="botao-sair">Sair</button>
  </div>
</header>

<main class="painel-conteudo" id="conteudo" tabindex="-1">
  <div class="esqueleto esqueleto--linha" style="width: 12rem; height: 2rem;"></div>
  <div class="esqueleto esqueleto--cartao" style="height: 14rem; margin-top: var(--e-6);"></div>
</main>

<!-- Painel lateral (detalhe do pedido, formulários de cadastro) e diálogo de confirmação. -->
<dialog class="folha folha--lateral" id="folha-lateral" aria-labelledby="folha-lateral-titulo"></dialog>
<dialog class="folha folha--central" id="folha-dialogo" aria-labelledby="folha-dialogo-titulo"></dialog>

<script type="application/json" id="dados-painel"><?= json_embutido(['tela' => $tela, 'usuario' => $usuario, 'ajustes' => $ajustes]) ?></script>
<?php if ($tela === 'pedidos'): ?>
<!-- GSAP (via CDN, como pede o briefing): Flip anima o cartão ao mudar de coluna; Draggable permite arrastar.
     Se o CDN falhar, o Kanban funciona igual pelos botões. -->
<script defer src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
<script defer src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/Flip.min.js"></script>
<script defer src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/Draggable.min.js"></script>
<?php endif; ?>
<script type="module" src="<?= e(asset("js/painel/{$tela}.js")) ?>"></script>
</body>
</html>
