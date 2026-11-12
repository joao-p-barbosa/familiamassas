// Modo cozinha: letra grande, só o que a cozinha faz (Novo → Em preparo → Pronto),
// montagem completa de cada prato na tela e alerta sonoro e visual quando entra pedido.
import { $, esc, delegar } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { minutosDesde, duracao } from '../shared/formato.js';
import { avisar } from '../shared/avisos.js';
import { TIPOS } from '../shared/pedido.js';
import { conteudo, ajustes } from './casca.js';
import { criarFonte } from './pedidos-vivos.js';
import { ferramentasHtml, ligarFerramentas, atualizarConexao } from './ferramentas.js';
import { imprimirAutomatico, reimprimir, estacao } from './impressao.js';
import { tocarAlerta } from './som.js';

const FILAS = [
  { status: 'novo', titulo: 'Para fazer', vazio: 'Nenhum pedido esperando. Quando entrar um, ele aparece aqui e toca o aviso.', acao: 'Começar', para: 'em_preparo' },
  { status: 'em_preparo', titulo: 'Fazendo', vazio: 'Nada no fogo agora.', acao: 'Pronto', para: 'pronto' },
];
const imprimindo = new Set();
const recemChegados = new Set();

const fonte = criarFonte({
  aoMudar: (motivo) => {
    atualizarConexao(fonte);
    if (motivo === 'conexao') return;
    desenhar();
    imprimirPendentes();
  },
  aoChegar: (novos) => {
    tocarAlerta();
    novos.forEach((p) => recemChegados.add(p.id));
    desenhar();
    // Alerta visual: a tela pisca a faixa do topo (para quem está longe ou com o som desligado).
    document.body.dataset.alerta = '';
    setTimeout(() => delete document.body.dataset.alerta, 2400);
  },
});

function montarTela() {
  conteudo.innerHTML = `
    <div class="cabecalho-tela">
      <h1 class="cabecalho-tela__titulo">Cozinha</h1>
      ${ferramentasHtml()}
    </div>
    <div class="cozinha" id="cozinha">
      ${FILAS.map((f) => `
        <section class="fila" aria-labelledby="fila-${f.status}">
          <header class="coluna__topo">
            <h2 class="fila__titulo" id="fila-${f.status}">${f.titulo}</h2>
            <span class="coluna__conta numero" data-conta="${f.status}">0</span>
          </header>
          <div class="fila__lista" data-lista="${f.status}"><div class="esqueleto esqueleto--cartao" style="height: 14rem;"></div></div>
        </section>`).join('')}
    </div>`;
  ligarFerramentas(fonte, imprimirPendentes);
}

function atraso(p) {
  const minutos = minutosDesde(p.criado_em);
  if (minutos >= ajustes.atraso_minutos * 1.5) return '2';
  return minutos >= ajustes.atraso_minutos ? '1' : null;
}

function itemHtml(item) {
  const m = item.montagem;
  const nomes = (lista) => lista.map((i) => esc(i.nome)).join(', ');
  return `
    <li class="comida">
      <p class="comida__nome"><span class="numero">${item.quantidade}×</span> ${esc(item.nome)}</p>
      ${m ? `
      <ul class="comida__montagem">
        <li><strong>${esc(m.tamanho.nome)}</strong> · ${esc(m.massa.nome)} · ${esc(m.molho.nome)}</li>
        ${m.inclusos.length ? `<li>${nomes(m.inclusos)}</li>` : ''}
        ${m.extras.length ? `<li><strong>Extras:</strong> ${nomes(m.extras)}</li>` : ''}
        ${!m.inclusos.length && !m.extras.length ? '<li>Sem ingredientes</li>' : ''}
        <li>${m.queijo_ralado ? 'Com queijo ralado' : '<strong>Sem queijo ralado</strong>'}</li>
      </ul>` : ''}
      ${item.observacoes ? `<p class="comida__obs">${icone('alerta', 20)}<span>${esc(item.observacoes)}</span></p>` : ''}
    </li>`;
}

function cartaoHtml(p, fila) {
  const nivel = atraso(p);
  return `
    <article class="cartao-cozinha" data-id="${p.id}" ${nivel ? `data-atraso="${nivel}"` : ''} ${recemChegados.has(p.id) ? 'data-chegou' : ''}>
      <header class="cartao-cozinha__topo">
        <span class="cartao-cozinha__numero numero">#${p.id}</span>
        <span class="cartao-cozinha__tipo">${icone(TIPOS[p.tipo].icone, 22)}${p.tipo === 'local' ? `Mesa ${esc(p.mesa)}` : TIPOS[p.tipo].rotulo}</span>
        <span class="cartao-cozinha__tempo numero" data-tempo="${p.id}">${icone('relogio', 20)}<span>${duracao(minutosDesde(p.criado_em))}</span></span>
      </header>
      <p class="cartao-cozinha__cliente">${esc(p.cliente_nome)}</p>
      <ul class="cartao-cozinha__itens">${p.itens.map(itemHtml).join('')}</ul>
      ${p.observacoes ? `<p class="comida__obs">${icone('alerta', 20)}<span><strong>Pedido:</strong> ${esc(p.observacoes)}</span></p>` : ''}
      <div class="cartao-cozinha__acoes">
        <button type="button" class="botao botao--secundario botao--g botao--icone" data-reimprimir aria-label="${p.impresso_em ? 'Reimprimir' : 'Imprimir'} a comanda do pedido ${p.id}">${icone('impressora', 22)}</button>
        <button type="button" class="botao botao--g cartao-cozinha__avancar" data-para="${fila.para}">${fila.acao}${icone(fila.para === 'pronto' ? 'check' : 'seta-dir', 24)}</button>
      </div>
    </article>`;
}

function desenhar() {
  if (!fonte.carregado) return;
  const todos = fonte.lista();
  for (const fila of FILAS) {
    const pedidos = todos.filter((p) => p.status === fila.status).sort((a, b) => a.id - b.id);
    $(`[data-conta="${fila.status}"]`).textContent = pedidos.length;
    $(`[data-lista="${fila.status}"]`).innerHTML = pedidos.length
      ? pedidos.map((p) => cartaoHtml(p, fila)).join('')
      : `<p class="coluna__vazio">${fila.vazio}</p>`;
  }
  recemChegados.clear();
}

async function imprimirPendentes() {
  if (!estacao.ativa) return;
  for (const p of fonte.lista()) {
    if (p.impresso_em || !['novo', 'em_preparo'].includes(p.status) || imprimindo.has(p.id)) continue;
    imprimindo.add(p.id);
    const atualizado = await imprimirAutomatico(p);
    imprimindo.delete(p.id);
    if (atualizado) fonte.guardar(atualizado);
  }
}

function atualizarTempos() {
  for (const p of fonte.lista()) {
    const tempo = $(`[data-tempo="${p.id}"]`);
    if (!tempo) continue;
    tempo.lastElementChild.textContent = duracao(minutosDesde(p.criado_em));
    const nivel = atraso(p);
    const cartao = tempo.closest('.cartao-cozinha');
    if (nivel) cartao.dataset.atraso = nivel;
    else delete cartao.dataset.atraso;
  }
}

delegar(conteudo, 'click', '[data-para]', async (e, botao) => {
  const id = Number(botao.closest('[data-id]').dataset.id);
  const anterior = fonte.pedidos.get(id).status;
  botao.setAttribute('aria-busy', 'true');
  const pedido = await fonte.mudarStatus(id, botao.dataset.para);
  if (!pedido) {
    botao.removeAttribute('aria-busy');
    return;
  }
  // Toque errado acontece com a mão ocupada: dá para desfazer por alguns segundos.
  avisar(pedido.status === 'pronto' ? `Pedido #${id} pronto` : `Pedido #${id} em preparo`, {
    duracao: 6000,
    acao: { rotulo: 'Desfazer', aoClicar: () => fonte.mudarStatus(id, anterior) },
  });
});
delegar(conteudo, 'click', '[data-reimprimir]', async (e, botao) => {
  const pedido = fonte.pedidos.get(Number(botao.closest('[data-id]').dataset.id));
  botao.setAttribute('aria-busy', 'true');
  const atualizado = await reimprimir(pedido);
  botao.removeAttribute('aria-busy');
  if (atualizado) {
    fonte.guardar(atualizado);
    avisar('Comanda enviada para a impressora');
  }
});

montarTela();
fonte.iniciar();
setInterval(atualizarTempos, 20_000);
