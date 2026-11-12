<?php
/**
 * Guia de estilo (Etapa 1): todos os componentes do MVP, com troca de tema ao vivo.
 * @var array $loja
 */
$tema = $loja['tema'];
$tituloPagina = 'Guia de estilo · ' . $loja['nome'];
$estilos = ['styleguide'];
$check = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>';
$cores = [
    'Superfícies' => ['fundo', 'superficie', 'superficie-2', 'superficie-forte'],
    'Texto' => ['texto', 'texto-2', 'texto-desabilitado'],
    'Bordas' => ['borda', 'borda-forte'],
    'Acento' => ['acento', 'acento-hover', 'acento-suave', 'acento-tinta', 'destaque'],
    'Estados' => ['sucesso', 'sucesso-suave', 'alerta', 'alerta-suave', 'perigo', 'perigo-suave', 'info', 'info-suave'],
];
?>
<!doctype html>
<html lang="pt-BR" data-theme="<?= e($tema) ?>">
<head>
<?php require __DIR__ . '/parciais/cabeca.php'; ?>
<meta name="robots" content="noindex, nofollow">
</head>
<body>
<a class="pular" href="#conteudo">Pular para os componentes</a>

<header class="guia-topo">
  <a class="marca" href="/"><?= e($loja['nome']) ?></a>
  <fieldset class="segmentado guia-temas" aria-label="Tema">
    <?php foreach (['italia' => 'Itália', 'linho' => 'Linho', 'pedra' => 'Pedra', 'grafite' => 'Grafite'] as $id => $nome): ?>
    <label class="segmentado__item"><input type="radio" name="tema" value="<?= $id ?>" <?= $tema === $id ? 'checked' : '' ?>><span><?= $nome ?></span></label>
    <?php endforeach; ?>
  </fieldset>
</header>

<main class="guia" id="conteudo">
  <h1 class="guia__titulo">Guia de estilo</h1>
  <p class="guia__intro">Tudo aqui usa só os tokens de <code>tokens.css</code> e do tema ativo. Troque o tema acima: nada recarrega e nada quebra.</p>

  <section class="guia__secao" aria-labelledby="g-marca">
    <h2 id="g-marca">Marca e tipografia</h2>
    <div class="guia__linha">
      <span class="marca" style="font-size: var(--texto-9)"><?= e($loja['nome']) ?></span>
    </div>
    <div class="guia__tipos">
      <p style="font-family: var(--fonte-titulo); font-size: var(--texto-8)">Young Serif: títulos e nome da loja</p>
      <p style="font-size: var(--texto-6); font-weight: 800">Hanken Grotesk 800: números e títulos do painel</p>
      <p style="font-weight: 650">Hanken Grotesk 650: rótulos e botões</p>
      <p>Hanken Grotesk 400: texto corrido. Penne, bolonhesa, frango desfiado, muçarela e manjericão.</p>
      <p style="font-size: var(--texto-3); color: var(--cor-texto-2)">Texto secundário (14 px): 400 g · 4 ingredientes inclusos</p>
      <p class="numero" style="font-weight: 700">R$ 31,90 · R$ 4,00 · 12 min (algarismos de largura igual)</p>
    </div>
  </section>

  <section class="guia__secao" aria-labelledby="g-cores">
    <h2 id="g-cores">Cores do tema</h2>
    <?php foreach ($cores as $grupo => $lista): ?>
    <h3><?= e($grupo) ?></h3>
    <div class="guia__cores">
      <?php foreach ($lista as $cor): ?>
      <div class="guia__cor"><span style="background: var(--cor-<?= $cor ?>)"></span><code>--cor-<?= $cor ?></code></div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </section>

  <section class="guia__secao" aria-labelledby="g-botoes">
    <h2 id="g-botoes">Botões</h2>
    <div class="guia__linha">
      <button type="button" class="botao">Principal</button>
      <button type="button" class="botao botao--secundario">Secundário</button>
      <button type="button" class="botao botao--suave">Suave</button>
      <button type="button" class="botao botao--fantasma">Fantasma</button>
      <button type="button" class="botao botao--perigo">Perigo</button>
      <button type="button" class="botao botao--perigo-suave">Perigo suave</button>
    </div>
    <div class="guia__linha">
      <button type="button" class="botao botao--g">Grande</button>
      <button type="button" class="botao">Médio</button>
      <button type="button" class="botao botao--p">Pequeno</button>
      <button type="button" class="botao" disabled>Desabilitado</button>
      <button type="button" class="botao" aria-busy="true">Carregando</button>
      <button type="button" class="botao botao--secundario botao--icone" aria-label="Fechar"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
    </div>
    <div class="guia__estreito">
      <button type="button" class="botao botao--g botao--bloco botao--com-valor"><span>Adicionar</span><span class="botao__valor">R$ 40,90</span></button>
    </div>
  </section>

  <section class="guia__secao" aria-labelledby="g-campos">
    <h2 id="g-campos">Campos</h2>
    <div class="guia__grade">
      <label class="campo"><span class="campo__rotulo">Nome</span><input class="entrada" type="text" value="Maria"><span class="campo__ajuda">Como chamamos você na retirada.</span></label>
      <label class="campo"><span class="campo__rotulo">WhatsApp</span><input class="entrada" type="tel" placeholder="(11) 91234-5678" aria-invalid="true"><span class="campo__erro">Informe o WhatsApp com DDD.</span></label>
      <label class="campo"><span class="campo__rotulo">Situação</span><select class="entrada"><option>Disponível</option><option>Esgotado</option></select></label>
      <label class="campo"><span class="campo__rotulo">Desabilitado</span><input class="entrada" type="text" value="Não editável" disabled></label>
      <label class="campo"><span class="campo__rotulo">Observações <span class="campo__opcional">(opcional)</span></span><textarea class="entrada" rows="2" placeholder="Ex.: molho à parte"></textarea></label>
    </div>
  </section>

  <section class="guia__secao" aria-labelledby="g-opcoes">
    <h2 id="g-opcoes">Opções: escolha única e múltipla</h2>
    <div class="guia__grade">
      <fieldset class="grupo-opcoes">
        <legend class="campo__rotulo">Escolha única</legend>
        <label class="opcao"><input class="opcao__entrada" type="radio" name="g-massa" checked><span class="opcao__marca"><?= $check ?></span><span class="opcao__corpo"><span class="opcao__titulo">Penne</span><span class="opcao__descricao">Tubinho que segura bem o molho</span></span><span class="opcao__lado"></span></label>
        <label class="opcao"><input class="opcao__entrada" type="radio" name="g-massa"><span class="opcao__marca"><?= $check ?></span><span class="opcao__corpo"><span class="opcao__titulo">Nhoque</span><span class="opcao__descricao">De batata, feito na casa</span></span><span class="opcao__lado">+ R$ 4,00</span></label>
        <label class="opcao"><input class="opcao__entrada" type="radio" name="g-massa" disabled><span class="opcao__marca"><?= $check ?></span><span class="opcao__corpo"><span class="opcao__titulo">Sem glúten</span></span><span class="opcao__lado"><span class="selo">Esgotado</span></span></label>
      </fieldset>
      <fieldset class="grupo-opcoes">
        <legend class="campo__rotulo">Escolha múltipla</legend>
        <label class="opcao opcao--caixa"><input class="opcao__entrada" type="checkbox" checked><span class="opcao__marca"><?= $check ?></span><span class="opcao__corpo"><span class="opcao__titulo">Bacon</span></span><span class="opcao__lado">incluso</span></label>
        <label class="opcao opcao--caixa"><input class="opcao__entrada" type="checkbox"><span class="opcao__marca"><?= $check ?></span><span class="opcao__corpo"><span class="opcao__titulo">Brócolis</span></span><span class="opcao__lado">+ R$ 3,00</span></label>
        <label class="opcao opcao--caixa"><input class="opcao__entrada" type="checkbox" disabled><span class="opcao__marca"><?= $check ?></span><span class="opcao__corpo"><span class="opcao__titulo">Palmito</span></span><span class="opcao__lado"><span class="selo">Esgotado</span></span></label>
      </fieldset>
    </div>
    <div class="guia__linha">
      <fieldset class="segmentado" aria-label="Tipo de pedido" style="min-width: min(22rem, 100%)">
        <label class="segmentado__item"><input type="radio" name="g-tipo" checked><span>Entrega</span></label>
        <label class="segmentado__item"><input type="radio" name="g-tipo"><span>Retirada</span></label>
        <label class="segmentado__item"><input type="radio" name="g-tipo"><span>Comer aqui</span></label>
      </fieldset>
      <label class="interruptor"><input type="checkbox" checked><span class="interruptor__trilho"></span><span>Disponível</span></label>
      <label class="interruptor"><input type="checkbox"><span class="interruptor__trilho"></span><span>Imprimir comandas neste aparelho</span></label>
    </div>
  </section>

  <section class="guia__secao" aria-labelledby="g-pequenos">
    <h2 id="g-pequenos">Chips, selos e quantidade</h2>
    <div class="guia__linha">
      <button type="button" class="chip" aria-pressed="true">Monte seu prato</button>
      <button type="button" class="chip" aria-pressed="false">Pratos da casa</button>
      <button type="button" class="chip" aria-pressed="false">Bebidas</button>
    </div>
    <div class="guia__linha">
      <span class="selo">Neutro</span>
      <span class="selo selo--sucesso"><span class="selo__ponto"></span>Aberto até 23:00</span>
      <span class="selo selo--alerta">Tem observação</span>
      <span class="selo selo--perigo">Cancelado</span>
      <span class="selo selo--info">Novo</span>
      <span class="selo selo--acento">No balcão</span>
    </div>
    <div class="guia__linha">
      <div class="quantidade" role="group" aria-label="Quantidade">
        <button type="button" class="quantidade__botao" data-passo="-1" aria-label="Diminuir"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14"/></svg></button>
        <output class="quantidade__valor">2</output>
        <button type="button" class="quantidade__botao" data-passo="1" aria-label="Aumentar"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></button>
      </div>
    </div>
  </section>

  <section class="guia__secao" aria-labelledby="g-produto">
    <h2 id="g-produto">Produto</h2>
    <div class="guia__grade">
      <button type="button" class="produto"><span class="produto__texto"><span class="produto__nome">Carbonara da casa</span><span class="produto__descricao">Espaguete, molho branco, bacon e parmesão</span><span class="produto__preco"><small>a partir de</small> R$ 24,90</span></span><span class="produto__acao"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></span></button>
      <button type="button" class="produto" disabled><span class="produto__texto"><span class="produto__nome">Chá gelado de limão</span><span class="produto__descricao">Feito na casa, 400 ml</span><span class="produto__preco"><span class="selo">Esgotado</span></span></span></button>
    </div>
  </section>

  <section class="guia__secao" aria-labelledby="g-avisos">
    <h2 id="g-avisos">Folha, aviso, carregando e vazio</h2>
    <div class="guia__linha">
      <button type="button" class="botao botao--secundario" id="g-abrir-folha">Abrir folha</button>
      <button type="button" class="botao botao--secundario" id="g-aviso">Mostrar aviso</button>
      <button type="button" class="botao botao--secundario" id="g-aviso-erro">Mostrar aviso de erro</button>
    </div>
    <div class="guia__grade">
      <div>
        <div class="esqueleto esqueleto--linha" style="width: 45%"></div>
        <div class="esqueleto esqueleto--cartao" style="margin-top: var(--e-3)"></div>
        <div class="esqueleto esqueleto--cartao" style="margin-top: var(--e-3)"></div>
      </div>
      <div class="vazio" style="border: 1px dashed var(--cor-borda-forte); border-radius: var(--raio-2)">
        <p class="vazio__titulo">Seu pedido está vazio</p>
        <p>Monte um prato ou escolha um item do cardápio.</p>
        <button type="button" class="botao">Montar meu prato</button>
      </div>
    </div>
  </section>

  <section class="guia__secao" aria-labelledby="g-kanban">
    <h2 id="g-kanban">Cartão de pedido (Kanban)</h2>
    <div class="guia__grade guia__grade--tres">
      <?php foreach ([['12', '7 min', null], ['9', '24 min', 'atencao'], ['7', '41 min', 'atrasado']] as [$n, $tempo, $faixa]): ?>
      <article class="cartao-pedido" <?= $faixa ? 'data-faixa="' . $faixa . '"' : '' ?>>
        <div class="cartao-pedido__topo">
          <span class="cartao-pedido__numero">#<?= $n ?></span>
          <span class="cartao-pedido__tempo"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg><span><?= $tempo ?></span></span>
          <?php if ($faixa): ?><span class="cartao-pedido__faixa"><?= $faixa === 'atencao' ? 'Atenção' : 'Atrasado' ?></span><?php endif; ?>
        </div>
        <span class="cartao-pedido__cliente">Maria Teste</span>
        <span class="cartao-pedido__selos"><span class="selo selo--alerta">Em preparo</span><span class="selo cartao-pedido__modalidade">Entrega</span><span class="selo">WhatsApp</span></span>
        <span class="cartao-pedido__itens"><span><b>1×</b> Monte seu prato · Meio · Penne · Bolonhesa</span><span><b>2×</b> Refrigerante lata</span></span>
        <div class="cartao-pedido__rodape"><span>Pix</span><span class="cartao-pedido__total">R$ 59,90</span></div>
        <button type="button" class="botao botao--bloco">Iniciar preparo</button>
      </article>
      <?php endforeach; ?>
    </div>
  </section>
</main>

<dialog class="folha folha--central" id="g-folha" aria-labelledby="g-folha-titulo">
  <header class="folha__topo">
    <h2 class="folha__titulo" id="g-folha-titulo">Folha</h2>
    <button type="button" class="botao botao--fantasma botao--icone" data-fechar aria-label="Fechar"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
  </header>
  <div class="folha__corpo"><p>No celular ela sobe de baixo e fecha arrastando o topo, tocando fora ou no botão voltar. Em tela larga vira um diálogo central.</p></div>
  <footer class="folha__rodape"><button type="button" class="botao botao--bloco" data-fechar>Entendi</button></footer>
</dialog>

<script type="module" src="<?= e(asset('js/styleguide.js')) ?>"></script>
</body>
</html>
