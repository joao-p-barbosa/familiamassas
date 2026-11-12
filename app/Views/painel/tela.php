<?php
/**
 * Casca do painel (SPA): menu lateral retrátil + área de conteúdo. O navegador carrega esta página UMA vez; o roteador
 * (/assets/js/painel/app.js) troca a tela sem recarregar, cada uma desenhada pelo seu módulo em /assets/js/painel/.
 * O servidor entrega esta mesma casca para qualquer endereço /painel/{tela} que o perfil possa abrir (link direto e F5 funcionam).
 * O menu segue o conceito do Cantinho de Sabores: cartão à esquerda que recolhe para só ícones no desktop (a escolha
 * fica num cookie, lido aqui para a página já nascer no estado certo) e vira gaveta no celular.
 * @var string $tela          a tela do endereço aberto (pedidos | atendimento | cardapio | usuarios | configuracoes | whatsapp)
 * @var string $telaDoMenu     o item do menu que fica marcado
 * @var string $titulo
 * @var array<string, array{titulo:string, grupo:string}> $menu  telas que este perfil pode abrir, na ordem do menu
 * @var bool   $menuRecolhido
 * @var array{id:int, nome:string, login:string, perfil:string} $usuario
 * @var string $nomeLoja
 * @var string $tema
 * @var string $csrf
 * @var array  $ajustes
 * @var array<string, array{titulo:string, menu_ativo:?string}> $telas  tudo o que este perfil pode abrir (para o roteador)
 * @var string $inicio         tela inicial do perfil
 */
$tituloPagina = $titulo . ' · ' . $nomeLoja;
$estilos = ['painel', 'cardapio', 'comanda'];   // cardapio.css serve ao montador, usado na tela "Produtos do atendimento"
$icones = [
    'pedidos'       => '<rect x="3.5" y="4" width="5" height="16" rx="1"/><rect x="10" y="4" width="5" height="10" rx="1"/><rect x="16.5" y="4" width="4" height="13" rx="1"/>',
    'cardapio'      => '<path d="M5 4.5h11a3 3 0 0 1 3 3V20H8a3 3 0 0 1-3-3V4.5Z"/><path d="M5 17a3 3 0 0 1 3-3h11M9 8.5h6"/>',
    'configuracoes' => '<path d="M4 7h9m4 0h3M4 17h3m4 0h9"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/>',
    'whatsapp'      => '<path d="M20 12a8 8 0 0 1-11.8 7L4 20l1.1-3.9A8 8 0 1 1 20 12Z"/>',
    'atendimento'   => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/>',
    'usuarios'      => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.2a6.5 6.5 0 0 1 3.5 5.8"/>',
    'recolher'      => '<path d="M11 6 5 12l6 6M19 6l-6 6 6 6"/>',
    'menu'          => '<path d="M4 7h16M4 12h16M4 17h16"/>',
    'fechar'        => '<path d="m6 6 12 12M18 6 6 18"/>',
    'sair'          => '<path d="M14 4h4.5a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H14M10 8l-4 4 4 4m-4-4h10"/>',
];
$svg = static fn (string $nome, int $tamanho = 20): string =>
    '<svg width="' . $tamanho . '" height="' . $tamanho . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icones[$nome] . '</svg>';
$perfis = ['admin' => 'Proprietário', 'atendente' => 'Atendente'];
$perfilRotulo = $perfis[$usuario['perfil']] ?? $usuario['perfil'];
$iniciais = mb_strtoupper(mb_substr(implode('', array_map(static fn (string $p): string => mb_substr($p, 0, 1), array_slice(preg_split('/\s+/', trim($usuario['nome'])) ?: [], 0, 2))), 0, 2));
$rotuloRecolher = $menuRecolhido ? 'Expandir o menu' : 'Recolher o menu';
$grupos = [];
foreach ($menu as $chave => $item) {
    $grupos[$item['grupo']][$chave] = $item;
}
$inicio = \App\Controllers\PainelController::telaInicial($usuario['perfil']);
?>
<!doctype html>
<html lang="pt-BR" data-theme="<?= e($tema) ?>">
<head>
<?php require dirname(__DIR__) . '/parciais/cabeca.php'; ?>
</head>
<body class="painel<?= $menuRecolhido ? ' painel--recolhido' : '' ?>" data-tela="<?= e($tela) ?>">
<a class="pular" href="#conteudo">Pular para o conteúdo</a>

<!-- Fundo escuro que fecha a gaveta no celular. -->
<button type="button" class="menu-fundo" id="menu-fundo" tabindex="-1" aria-hidden="true"></button>

<aside class="lateral" id="menu-lateral" data-aberta="false">
  <div class="lateral__topo">
    <a class="marca lateral__marca" href="<?= e($inicio) ?>" title="<?= e($nomeLoja) ?>"><span class="lateral__marca-texto"><?= e($nomeLoja) ?></span><span class="lateral__marca-inicial" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($nomeLoja, 0, 1))) ?></span></a>
    <button type="button" class="lateral__botao lateral__recolher" id="menu-recolher" aria-controls="menu-lateral" aria-expanded="<?= $menuRecolhido ? 'false' : 'true' ?>" aria-label="<?= e($rotuloRecolher) ?>" title="<?= e($rotuloRecolher) ?>"><?= $svg('recolher') ?></button>
    <button type="button" class="lateral__botao lateral__fechar" id="menu-fechar" aria-label="Fechar o menu"><?= $svg('fechar') ?></button>
  </div>

  <nav class="lateral__nav" aria-label="Telas do painel">
    <?php foreach ($grupos as $nomeGrupo => $itens): ?>
    <p class="lateral__grupo"><?= e($nomeGrupo) ?></p>
    <ul class="lateral__lista">
      <?php foreach ($itens as $chave => $item): ?>
      <li>
        <a class="lateral__item" href="/painel/<?= e($chave) ?>" title="<?= e($item['titulo']) ?>" <?= $chave === $telaDoMenu ? 'aria-current="page"' : '' ?>>
          <?= $svg($chave) ?>
          <span class="lateral__rotulo"><?= e($item['titulo']) ?></span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endforeach; ?>
  </nav>

  <div class="lateral__rodape">
    <div class="lateral__usuario" title="<?= e($usuario['nome']) ?>">
      <span class="lateral__avatar" aria-hidden="true"><?= e($iniciais) ?></span>
      <span class="lateral__usuario-texto">
        <span class="lateral__usuario-nome"><?= e($usuario['nome']) ?></span>
        <span class="lateral__usuario-perfil"><?= e($perfilRotulo) ?></span>
      </span>
    </div>
    <button type="button" class="botao botao--secundario botao--p lateral__sair" id="botao-sair" title="Sair"><?= $svg('sair', 18) ?><span class="lateral__rotulo">Sair</span></button>
  </div>
</aside>

<div class="area" id="area">
  <!-- Barra de cima: só no celular, para abrir o menu. -->
  <header class="barra-topo">
    <button type="button" class="lateral__botao" id="menu-abrir" aria-controls="menu-lateral" aria-expanded="false" aria-label="Abrir o menu"><?= $svg('menu', 22) ?></button>
    <span class="marca barra-topo__marca"><?= e($nomeLoja) ?></span>
  </header>

  <!-- Aqui o roteador mantém uma "tela" por módulo (a atual visível, as já abertas escondidas). -->
  <main class="painel-conteudo" id="conteudo" tabindex="-1">
    <div class="tela-carregando" id="tela-carregando">
      <div class="esqueleto esqueleto--linha" style="width: 12rem; height: 2rem;"></div>
      <div class="esqueleto esqueleto--cartao" style="height: 14rem; margin-top: var(--e-6);"></div>
    </div>
  </main>
</div>

<!-- Painel lateral (detalhe do pedido, formulários de cadastro) e diálogo de confirmação. -->
<dialog class="folha folha--lateral" id="folha-lateral" aria-labelledby="folha-lateral-titulo"></dialog>
<dialog class="folha folha--central" id="folha-dialogo" aria-labelledby="folha-dialogo-titulo"></dialog>

<!-- Janelas do fluxo do Salão (novo atendimento, produtos e envio). Ficam na casca porque os módulos as procuram ao carregar. -->
<dialog class="folha folha--central" id="folha-novo" aria-labelledby="novo-titulo"></dialog>
<dialog class="folha folha--central" id="folha-enviar" aria-labelledby="enviar-titulo"></dialog>
<dialog class="folha folha--cheia folha--central folha--alta folha--montador" id="folha-montador" aria-labelledby="montador-titulo"></dialog>
<dialog class="folha folha--central" id="folha-item" aria-labelledby="item-titulo"></dialog>
<dialog class="folha" id="folha-carrinho" aria-labelledby="carrinho-titulo">
  <header class="folha__topo">
    <h2 class="folha__titulo" id="carrinho-titulo">Pedido do salão</h2>
    <button type="button" class="botao botao--fantasma botao--icone" data-fechar aria-label="Fechar o carrinho"><?= $svg('fechar') ?></button>
  </header>
  <div class="folha__corpo" id="carrinho-folha"></div>
</dialog>

<!-- Anuncia a tela nova para leitor de tela (a página não recarrega, então ninguém avisa que mudou). -->
<p class="so-leitor" id="anuncio-rota" role="status" aria-live="polite"></p>

<script type="application/json" id="dados-painel"><?= json_embutido(['tela' => $tela, 'usuario' => $usuario, 'ajustes' => $ajustes, 'telas' => $telas, 'inicio' => $inicio, 'nomeLoja' => $nomeLoja]) ?></script>
<script type="module" src="<?= e(asset('js/painel/app.js')) ?>"></script>
</body>
</html>
