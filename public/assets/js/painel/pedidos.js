// Kanban de pedidos. Caminho principal: o botão grande de cada cartão (decisão D4).
// Arrastar pela alça é um bônus (GSAP Draggable); a mesma alça, clicada ou pelo teclado, abre "Mover para…".
import { $, $$, esc, delegar, menosMovimento } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { reais, minutosDesde, duracao, plural } from '../shared/formato.js';
import { avisar } from '../shared/avisos.js';
import { FLUXOS, STATUS, ACAO_PARA, TIPOS, ORIGENS, PAGAMENTOS, encerrado, proximaEtapa, resumoMontagem } from '../shared/pedido.js';
import { conteudo, ajustes } from './casca.js';
import { criarFonte } from './pedidos-vivos.js';
import { ferramentasHtml, ligarFerramentas, atualizarConexao } from './ferramentas.js';
import { imprimirAutomatico, estacao } from './impressao.js';
import { tocarAlerta } from './som.js';
import { iniciarDetalhe, abrirDetalhe, atualizarDetalhe, pedirMovimento, SELO_STATUS } from './detalhe.js';

const COLUNAS = [
  { id: 'novo', titulo: 'Novos', status: ['novo'], vazio: 'Pedido novo aparece aqui sozinho.' },
  { id: 'em_preparo', titulo: 'Em preparo', status: ['em_preparo'], vazio: 'Nada em preparo.' },
  { id: 'pronto', titulo: 'Prontos', status: ['pronto'], vazio: 'Nenhum pedido esperando.' },
  { id: 'saida', titulo: 'Entrega e retirada', status: ['saiu_entrega', 'aguardando_retirada'], vazio: 'Ninguém a caminho.' },
  { id: 'encerrados', titulo: 'Encerrados hoje', status: ['concluido', 'cancelado'], vazio: 'Nenhum pedido encerrado hoje.' },
];

let estadoFlip = null;      // posição dos cartões antes de uma mudança (para animar)
let arrastaveis = [];       // instâncias do Draggable
let arrastando = false;
const recemChegados = new Set();
const imprimindo = new Set();

const fonte = criarFonte({
  antesDeMudar: () => {
    if (window.Flip && !menosMovimento()) estadoFlip = window.Flip.getState('#quadro [data-flip-id]');
  },
  aoMudar: (motivo) => {
    atualizarConexao(fonte);
    if (motivo === 'conexao') return;
    if (!arrastando) desenharQuadro();
    atualizarDetalhe();
    imprimirPendentes();
  },
  aoChegar: (novos) => {
    tocarAlerta();
    novos.forEach((p) => recemChegados.add(p.id));
    avisar(novos.length === 1 ? `Pedido novo: #${novos[0].id}, ${novos[0].cliente_nome}` : `${novos.length} pedidos novos`);
    document.title = `(${novos.length}) Pedidos novos`;
    setTimeout(() => (document.title = 'Pedidos'), 8000);
  },
});

// ---------- Estrutura ----------
function montarTela() {
  conteudo.innerHTML = `
    <div class="cabecalho-tela">
      <h1 class="cabecalho-tela__titulo">Pedidos</h1>
      ${ferramentasHtml()}
    </div>
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

// ---------- Cartões ----------
function nivelDeAtraso(p) {
  if (!['novo', 'em_preparo'].includes(p.status)) return null;
  const minutos = minutosDesde(p.criado_em);
  if (minutos >= ajustes.atraso_minutos * 1.5) return '2';
  if (minutos >= ajustes.atraso_minutos) return '1';
  return null;
}

/** Tempo que importa em cada etapa: desde o pedido (até ficar pronto) ou desde a última mudança. */
const tempoDoPedido = (p) => duracao(minutosDesde(['novo', 'em_preparo'].includes(p.status) ? p.criado_em : p.status_atualizado_em));

function linhasDeItens(p) {
  const linhas = p.itens.slice(0, 4).map((item) => {
    const resumo = item.montagem ? resumoMontagem({ tamanho: { nome: item.montagem.tamanho.sigla }, massa: item.montagem.massa, molho: item.montagem.molho }) : '';
    return `<span><b>${item.quantidade}×</b> ${esc(item.nome)}${resumo ? ` · ${esc(resumo)}` : ''}</span>`;
  });
  if (p.itens.length > 4) linhas.push(`<span>+ ${plural(p.itens.length - 4, 'item', 'itens')}</span>`);
  return linhas.join('');
}

function cartaoHtml(p) {
  const proxima = proximaEtapa(p);
  const atraso = nivelDeAtraso(p);
  return `
    <article class="cartao-pedido" data-id="${p.id}" data-flip-id="pedido-${p.id}" ${atraso ? `data-atraso="${atraso}"` : ''} ${recemChegados.has(p.id) ? 'data-chegou' : ''}>
      <div class="cartao-pedido__topo">
        <span class="cartao-pedido__numero">#${p.id}</span>
        <span class="cartao-pedido__tempo" data-tempo="${p.id}" title="${atraso ? 'Atrasado' : 'Tempo de espera'}">${icone('relogio', 16)}<span>${tempoDoPedido(p)}</span></span>
        <button type="button" class="cartao-pedido__pega" data-mover aria-label="Mover o pedido ${p.id} para outra etapa">${icone('arrastar', 20)}</button>
      </div>
      <button type="button" class="cartao-pedido__abrir" data-abrir aria-label="Ver o pedido ${p.id}, de ${esc(p.cliente_nome)}">
        <span class="cartao-pedido__cliente">${esc(p.cliente_nome)}</span>
        <span class="cartao-pedido__selos">
          <span class="selo">${icone(TIPOS[p.tipo].icone, 14)}${p.tipo === 'local' ? `Mesa ${esc(p.mesa)}` : TIPOS[p.tipo].rotulo}</span>
          <span class="selo">${esc(ORIGENS[p.origem] ?? p.origem)}</span>
          ${p.status === 'aguardando_retirada' ? '<span class="selo selo--acento">No balcão</span>' : ''}
          ${p.observacoes || p.itens.some((i) => i.observacoes) ? '<span class="selo selo--alerta">Tem observação</span>' : ''}
        </span>
        <span class="cartao-pedido__itens">${linhasDeItens(p)}</span>
      </button>
      <div class="cartao-pedido__rodape">
        <span>${PAGAMENTOS[p.pagamento]}</span>
        <span class="cartao-pedido__total">${reais(p.total_centavos)}</span>
      </div>
      ${proxima ? `<button type="button" class="botao botao--bloco cartao-pedido__avancar" data-avancar="${proxima}">${ACAO_PARA[proxima]}${icone('seta-dir', 18)}</button>` : ''}
    </article>`;
}

function encerradoHtml(p) {
  return `
    <li data-flip-id="pedido-${p.id}">
      <button type="button" class="linha-encerrado" data-abrir data-id="${p.id}" aria-label="Ver o pedido ${p.id}, de ${esc(p.cliente_nome)}">
        <span class="linha-encerrado__numero numero">#${p.id}</span>
        <span class="linha-encerrado__nome">${esc(p.cliente_nome)}</span>
        <span class="selo ${SELO_STATUS[p.status]}">${STATUS[p.status].rotulo}</span>
        <span class="numero">${reais(p.total_centavos)}</span>
      </button>
    </li>`;
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
    } else if (coluna.id === 'encerrados') {
      // Encerrados: os mais recentes primeiro, em linhas compactas.
      pedidos.sort((a, b) => b.status_atualizado_em.localeCompare(a.status_atualizado_em));
      lista.innerHTML = `<ul class="encerrados">${pedidos.map(encerradoHtml).join('')}</ul>`;
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
function statusDaColuna(colunaId, pedido) {
  if (colunaId === 'saida') return { entrega: 'saiu_entrega', retirada: 'aguardando_retirada' }[pedido.tipo] ?? null;
  if (colunaId === 'encerrados') return 'concluido';
  return colunaId;
}

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
          const destino = statusDaColuna(coluna.dataset.coluna, pedido);
          if (destino && destino !== pedido.status && FLUXOS[pedido.tipo].includes(destino)) coluna.dataset.alvo = 'valido';
        }
      },
      onDrag() {
        const sob = colunaSobOPonteiro(this);
        for (const coluna of $$('.coluna[data-alvo]')) coluna.dataset.alvo = coluna === sob ? 'sobre' : 'valido';
      },
      async onDragEnd() {
        const alvo = colunaSobOPonteiro(this);
        const destino = alvo ? statusDaColuna(alvo.dataset.coluna, pedido) : null;
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

// ---------- Impressão automática ----------
async function imprimirPendentes() {
  if (!estacao.ativa) return;
  for (const p of fonte.lista()) {
    if (p.impresso_em || !['novo', 'em_preparo'].includes(p.status) || imprimindo.has(p.id)) continue;
    imprimindo.add(p.id);
    const atualizado = await imprimirAutomatico(p);
    imprimindo.delete(p.id);
    if (atualizado) fonte.guardar(atualizado);
  }
  atualizarDetalhe();
}

// ---------- Tempo dos cartões (sem redesenhar tudo) ----------
function atualizarTempos() {
  for (const p of fonte.lista()) {
    const tempo = $(`[data-tempo="${p.id}"]`);
    if (!tempo) continue;
    tempo.lastElementChild.textContent = tempoDoPedido(p);
    const cartao = tempo.closest('.cartao-pedido');
    const atraso = nivelDeAtraso(p);
    if (atraso) cartao.dataset.atraso = atraso;
    else delete cartao.dataset.atraso;
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

montarTela();
iniciarDetalhe(fonte);
fonte.iniciar();
setInterval(atualizarTempos, 20_000);
