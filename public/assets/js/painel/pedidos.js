// Quadro de pedidos (Kanban). Caminho principal: o botão grande de cada cartão (decisão D4).
// Arrastar pela alça é um bônus (GSAP Draggable); a mesma alça, clicada ou pelo teclado, abre "Mover para…".
//
// Três colunas: Novos · Em preparo · Prontos. A coluna "Prontos" também guarda o que já saiu da cozinha
// (saiu para entrega, aguardando retirada): cada cartão mostra a modalidade e o status, então não precisa de quadro à parte.
// O que foi concluído ou cancelado sai do quadro e entra nos números do fechamento do dia.
import { $, $$, esc, delegar, menosMovimento } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { reais, minutosDesde, duracao, plural } from '../shared/formato.js';
import { avisar } from '../shared/avisos.js';
import { FLUXOS, STATUS, ACAO_PARA, TIPOS, ORIGENS, PAGAMENTOS, encerrado, proximaEtapa, resumoMontagem, rotuloDoTipo } from '../shared/pedido.js';
import { segundosDeProducao, minutosDe, faixaDeTempo, emProducao } from '../shared/tempo.js';
import { ehAdmin, recipienteDaTela } from './casca.js';
import { fonte, ouvir, recemChegados, imprimirPendentes } from './servicos.js';
import { ferramentasHtml, ligarFerramentas, atualizarConexao } from './ferramentas.js';
import { abrirDetalhe, pedirMovimento, SELO_STATUS } from './detalhe.js';

const conteudo = recipienteDaTela();   // o contêiner desta tela (criado pelo roteador)

const COLUNAS = [
  { id: 'novo', titulo: 'Novos', status: ['novo'], vazio: 'Pedido novo aparece aqui sozinho.' },
  { id: 'em_preparo', titulo: 'Em preparo', status: ['em_preparo'], vazio: 'Nada em preparo.' },
  { id: 'pronto', titulo: 'Prontos', status: ['pronto', 'saiu_entrega', 'aguardando_retirada'], vazio: 'Nenhum pedido esperando.' },
];
const ROTULO_FAIXA = { atencao: 'Atenção', atrasado: 'Atrasado' };

let estadoFlip = null;      // posição dos cartões antes de uma mudança (para animar)
let arrastaveis = [];       // instâncias do Draggable
let arrastando = false;
let parar = [];             // o que montar() ligou e desmontar() desliga
let relogio = 0;

// GSAP (via CDN, como pede o briefing): Flip anima o cartão ao mudar de coluna; Draggable permite arrastar.
// Só é carregado quando esta tela abre. Se o CDN falhar, o quadro funciona igual pelos botões.
let gsapPronto = null;
function carregarScript(endereco) {
  return new Promise((resolver, rejeitar) => {
    const script = document.createElement('script');
    script.src = endereco;
    script.onload = resolver;
    script.onerror = rejeitar;
    document.head.append(script);
  });
}
function carregarGsap() {
  if (window.gsap && window.Flip && window.Draggable) return Promise.resolve();
  gsapPronto ??= (async () => {
    for (const arquivo of ['gsap', 'Flip', 'Draggable']) await carregarScript(`https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/${arquivo}.min.js`);
  })().catch(() => {});
  return gsapPronto;
}

/** A tela abriu (a primeira vez ou de novo): desenha, liga a escuta do fluxo de pedidos e o cronômetro. */
export async function montar() {
  await carregarGsap();
  montarTela();
  desenharQuadro();
  desenharFechamento();
  atualizarConexao(fonte);
  parar = [
    ouvir('antes', () => {
      if (window.Flip && !menosMovimento()) estadoFlip = window.Flip.getState('#quadro [data-flip-id]');
    }),
    ouvir('mudou', (motivo) => {
      atualizarConexao(fonte);
      if (motivo === 'conexao') return;
      if (!arrastando) desenharQuadro();
      desenharFechamento();
    }),
  ];
  relogio = setInterval(atualizarTempos, 1000);
}

/** A tela fechou: para de escutar e de contar (o fluxo de pedidos, o som e a impressão continuam na casca). */
export function desmontar() {
  parar.forEach((f) => f());
  parar = [];
  clearInterval(relogio);
  arrastaveis.forEach((d) => d.kill());
  arrastaveis = [];
}

// ---------- Estrutura ----------
function montarTela() {
  conteudo.innerHTML = `
    <div class="cabecalho-tela">
      <h1 class="cabecalho-tela__titulo">Pedidos</h1>
      <a class="botao" href="/painel/salao?novo=1">${icone('mais', 18)}Novo pedido</a>
    </div>
    <div id="fechamento"></div>
    <div class="cabecalho-tela">${ferramentasHtml()}</div>
    <div class="quadro" id="quadro">
      ${COLUNAS.map((c) => `
        <section class="coluna" data-coluna="${c.id}" aria-labelledby="coluna-${c.id}">
          <header class="coluna__topo">
            <h2 class="coluna__titulo" id="coluna-${c.id}">${c.titulo}</h2>
            <span class="coluna__conta numero" data-conta="${c.id}">0</span>
          </header>
          <div class="coluna__lista" data-lista="${c.id}">
            <div class="esqueleto esqueleto--cartao" style="height: 9rem;"></div>
          </div>
        </section>`).join('')}
    </div>`;
  ligarFerramentas(fonte, imprimirPendentes);
}

// ---------- Fechamento do dia ----------
function desenharFechamento() {
  const f = fonte.fechamento;
  const alvo = $('#fechamento');
  if (!f || !alvo) return;
  alvo.innerHTML = `
    <section class="fechamento" aria-label="Fechamento de hoje">
      <h2 class="fechamento__titulo">Hoje</h2>
      <dl class="fechamento__lista">
        <div><dt>Pedidos</dt><dd>${f.pedidos}</dd></div>
        <div><dt>Em andamento</dt><dd>${f.em_andamento}</dd></div>
        <div><dt>Concluídos</dt><dd>${f.concluidos}</dd></div>
        <div><dt>Cancelados</dt><dd>${f.cancelados}</dd></div>
        ${ehAdmin && f.faturamento_centavos !== undefined ? `<div><dt>Vendido</dt><dd>${reais(f.faturamento_centavos)}</dd></div>` : ''}
      </dl>
    </section>`;
}

// ---------- Tempo e cor do cartão ----------
/**
 * Em Novo e Em preparo corre o tempo de produção (soma dos dois, nunca zera ao mudar de etapa) e o cartão muda de cor.
 * Depois de pronto, o cartão volta ao neutro e mostra há quanto tempo está esperando.
 */
function tempoDoCartao(p, agora) {
  if (emProducao(p)) {
    const segundos = segundosDeProducao(p, agora);
    return { texto: duracao(minutosDe(segundos)), faixa: faixaDeTempo(segundos), dica: 'Tempo em Novo + Em preparo' };
  }
  return { texto: `há ${duracao(minutosDesde(p.status_atualizado_em, agora))}`, faixa: 'neutro', dica: 'Tempo nesta etapa' };
}

function linhasDeItens(p) {
  const linhas = p.itens.slice(0, 4).map((item) => {
    const resumo = item.montagem ? resumoMontagem({ tamanho: item.montagem.tamanho, massa: item.montagem.massa, molho: item.montagem.molho }) : '';
    return `<span><b>${item.quantidade}×</b> ${esc(item.nome)}${resumo ? ` · ${esc(resumo)}` : ''}</span>`;
  });
  if (p.itens.length > 4) linhas.push(`<span>+ ${plural(p.itens.length - 4, 'item', 'itens')}</span>`);
  return linhas.join('');
}

function cartaoHtml(p) {
  const proxima = proximaEtapa(p);
  const tempo = tempoDoCartao(p, fonte.agora());
  const preparo = !emProducao(p) ? ` · Preparo ${duracao(minutosDe(p.producao_acumulado_s))}` : '';
  return `
    <article class="cartao-pedido" data-id="${p.id}" data-flip-id="pedido-${p.id}" ${tempo.faixa !== 'neutro' ? `data-faixa="${tempo.faixa}"` : ''} ${recemChegados.has(p.id) ? 'data-chegou' : ''}>
      <div class="cartao-pedido__topo">
        <span class="cartao-pedido__numero">#${p.id}</span>
        <span class="cartao-pedido__tempo" data-tempo="${p.id}" title="${tempo.dica}">${icone('relogio', 16)}<span>${tempo.texto}</span></span>
        <span class="cartao-pedido__faixa" data-faixa-texto="${p.id}" ${tempo.faixa === 'neutro' ? 'hidden' : ''}>${ROTULO_FAIXA[tempo.faixa] ?? ''}</span>
        <button type="button" class="cartao-pedido__pega" data-mover aria-label="Mover o pedido ${p.id} para outra etapa">${icone('arrastar', 20)}</button>
      </div>
      <button type="button" class="cartao-pedido__abrir" data-abrir aria-label="Ver o pedido ${p.id}, de ${esc(p.cliente_nome)}">
        <span class="cartao-pedido__cliente">${esc(p.cliente_nome)}</span>
        <span class="cartao-pedido__selos">
          <span class="selo ${SELO_STATUS[p.status]}">${STATUS[p.status].rotulo}</span>
          <span class="selo cartao-pedido__modalidade">${icone(TIPOS[p.tipo].icone, 14)}${esc(rotuloDoTipo(p))}</span>
          <span class="selo">${esc(ORIGENS[p.origem] ?? p.origem)}</span>
          ${p.observacoes || p.itens.some((i) => i.observacoes) ? '<span class="selo selo--alerta">Tem observação</span>' : ''}
        </span>
        <span class="cartao-pedido__itens">${linhasDeItens(p)}</span>
      </button>
      <div class="cartao-pedido__rodape">
        <span>${PAGAMENTOS[p.pagamento]}${preparo}</span>
        <span class="cartao-pedido__total">${reais(p.total_centavos)}</span>
      </div>
      ${proxima ? `<button type="button" class="botao botao--bloco cartao-pedido__avancar" data-avancar="${proxima}">${ACAO_PARA[proxima]}${icone('seta-dir', 18)}</button>` : ''}
    </article>`;
}

function desenharQuadro() {
  if (!fonte.carregado) return;
  arrastaveis.forEach((d) => d.kill());
  arrastaveis = [];
  const todos = fonte.lista();

  for (const coluna of COLUNAS) {
    const pedidos = todos.filter((p) => coluna.status.includes(p.status));
    const lista = $(`[data-lista="${coluna.id}"]`);
    $(`[data-conta="${coluna.id}"]`).textContent = pedidos.length;
    if (pedidos.length === 0) {
      lista.innerHTML = `<p class="coluna__vazio">${coluna.vazio}</p>`;
    } else {
      // Fila: o mais antigo em cima (é o próximo a sair).
      pedidos.sort((a, b) => a.id - b.id);
      lista.innerHTML = pedidos.map(cartaoHtml).join('');
    }
  }
  recemChegados.clear();
  animarMudanca();
  prepararArrasto();
}

// ---------- Animação (GSAP Flip): o cartão desliza da coluna antiga para a nova ----------
function animarMudanca() {
  const anterior = estadoFlip;
  estadoFlip = null;
  if (!anterior || !window.Flip || menosMovimento()) return;
  window.Flip.from(anterior, {
    targets: '#quadro [data-flip-id]',
    duration: 0.35,
    ease: 'power3.out',
    onEnter: (elementos) => window.gsap.fromTo(elementos, { opacity: 0, y: -10 }, { opacity: 1, y: 0, duration: 0.3, ease: 'power3.out' }),
  });
}

// ---------- Arrastar (bônus) ----------
const colunaDoPedido = (pedido) => COLUNAS.find((c) => c.status.includes(pedido.status))?.id ?? null;

/** Etapa em que o pedido fica ao soltá-lo numa coluna (a coluna "Prontos" leva para "Pronto"). */
const statusDaColuna = (colunaId) => (colunaId === 'pronto' ? 'pronto' : colunaId);

/** Coluna válida que está embaixo do ponteiro (vale onde o dedo/mouse está, não o centro do cartão). */
function colunaSobOPonteiro(arrastavel) {
  const x = arrastavel.pointerX - window.scrollX;
  const y = arrastavel.pointerY - window.scrollY;
  return $$('.coluna[data-alvo]').find((coluna) => {
    const r = coluna.getBoundingClientRect();
    return x >= r.left && x <= r.right && y >= r.top && y <= r.bottom;
  }) ?? null;
}

function prepararArrasto() {
  const { gsap, Draggable } = window;
  if (!gsap || !Draggable) return; // CDN fora do ar: segue só com botões
  gsap.registerPlugin(Draggable);
  for (const cartao of $$('#quadro .cartao-pedido')) {
    const pedido = fonte.pedidos.get(Number(cartao.dataset.id));
    const [arrastavel] = Draggable.create(cartao, {
      type: 'x,y',
      trigger: cartao.querySelector('[data-mover]'),
      zIndexBoost: true,
      minimumMovement: 6,
      onDragStart() {
        arrastando = true;
        cartao.dataset.arrastando = '';
        for (const coluna of $$('.coluna')) {
          const destino = statusDaColuna(coluna.dataset.coluna);
          // A coluna onde o cartão já está não é destino (soltar ali não muda nada).
          if (coluna.dataset.coluna !== colunaDoPedido(pedido) && FLUXOS[pedido.tipo].includes(destino)) coluna.dataset.alvo = 'valido';
        }
      },
      onDrag() {
        const sob = colunaSobOPonteiro(this);
        for (const coluna of $$('.coluna[data-alvo]')) coluna.dataset.alvo = coluna === sob ? 'sobre' : 'valido';
      },
      async onDragEnd() {
        const alvo = colunaSobOPonteiro(this);
        const destino = alvo ? statusDaColuna(alvo.dataset.coluna) : null;
        $$('.coluna[data-alvo]').forEach((coluna) => delete coluna.dataset.alvo);
        delete cartao.dataset.arrastando;
        arrastando = false;
        if (!destino) {
          gsap.to(cartao, { x: 0, y: 0, duration: 0.3, ease: 'power3.out' });
          return;
        }
        const movido = await fonte.mudarStatus(pedido.id, destino);
        if (!movido) desenharQuadro(); // não deu: volta ao lugar
      },
    });
    arrastaveis.push(arrastavel);
  }
}

// ---------- Cronômetro dos cartões (sem redesenhar tudo) ----------
// Roda a cada segundo, mas só mexe no que mudou: o texto (a cada minuto) e a cor (nos 20 e 30 minutos).
function atualizarTempos() {
  const agora = fonte.agora();
  for (const p of fonte.lista()) {
    const tempo = $(`[data-tempo="${p.id}"]`);
    if (!tempo) continue;
    const { texto, faixa } = tempoDoCartao(p, agora);
    const rotulo = tempo.lastElementChild;
    if (rotulo.textContent !== texto) rotulo.textContent = texto;
    const cartao = tempo.closest('.cartao-pedido');
    const atual = cartao.dataset.faixa ?? 'neutro';
    if (atual === faixa) continue;
    if (faixa === 'neutro') delete cartao.dataset.faixa;
    else cartao.dataset.faixa = faixa;
    const aviso = $(`[data-faixa-texto="${p.id}"]`);
    aviso.hidden = faixa === 'neutro';
    aviso.textContent = ROTULO_FAIXA[faixa] ?? '';
  }
}

// ---------- Eventos ----------
delegar(conteudo, 'click', '[data-avancar]', async (e, botao) => {
  const id = Number(botao.closest('[data-id]').dataset.id);
  botao.setAttribute('aria-busy', 'true');
  const pedido = await fonte.mudarStatus(id, botao.dataset.avancar);
  if (!pedido) botao.removeAttribute('aria-busy');
  else if (encerrado(pedido.status)) avisar(`Pedido #${id} concluído`);
});
delegar(conteudo, 'click', '[data-abrir]', (e, botao) => abrirDetalhe(Number(botao.closest('[data-id]').dataset.id)));
delegar(conteudo, 'click', '[data-mover]', (e, botao) => pedirMovimento(Number(botao.closest('[data-id]').dataset.id)));

