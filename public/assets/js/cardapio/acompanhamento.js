// Acompanhamento do pedido na Home: o pedido ativo (e os que acabaram de encerrar) aparece no topo do cardápio,
// mesmo depois de atualizar a página ou fechar e abrir o navegador. Os pedidos vêm do servidor (cookie), então a
// Home já nasce com eles. A área se atualiza sozinha a cada 10 s enquanto algum pedido estiver andando.
import { $, esc, delegar, dadosDaPagina } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { api } from '../shared/api.js';
import { STATUS, TIPOS, encerrado } from '../shared/pedido.js';
import { animacaoStatus } from './animacao-status.js';
import { previsaoDoPedido, trilhaHtml } from './status-pedido.js';

const INTERVALO = 10_000;
const raiz = $('#acompanhamento');
const anuncio = $('#acompanhamento-anuncio');
let pedidos = dadosDaPagina('dados-acompanhamento') ?? [];

function cartaoHtml(p) {
  const fim = encerrado(p.status);
  const detalhe = p.status === 'cancelado'
    ? (p.motivo_cancelamento ? `Motivo: ${p.motivo_cancelamento}` : 'A loja cancelou este pedido.')
    : previsaoDoPedido(p) || (fim ? 'Obrigado por pedir!' : 'Fique à vontade: avisamos aqui.');
  return `
    <article class="pedido-vivo" data-codigo="${esc(p.codigo)}" data-status="${esc(p.status)}">
      <a class="pedido-vivo__link" href="/pedido/${esc(p.codigo)}">
        <span class="pedido-vivo__arte">${animacaoStatus(p.status)}</span>
        <span class="pedido-vivo__texto">
          <span class="pedido-vivo__titulo">${esc(STATUS[p.status].cliente)}</span>
          <span class="pedido-vivo__detalhe">Pedido #${p.numero} · ${esc(TIPOS[p.tipo]?.rotulo ?? p.tipo)}</span>
          <span class="pedido-vivo__detalhe">${esc(detalhe)}</span>
          ${trilhaHtml(p)}
        </span>
        <span class="pedido-vivo__ir">${icone('divisa-dir', 22)}<span class="so-leitor">Acompanhar o pedido ${p.numero}</span></span>
      </a>
      ${fim ? `<button type="button" class="botao botao--fantasma botao--icone pedido-vivo__dispensar" data-dispensar aria-label="Dispensar o aviso do pedido ${p.numero}">${icone('fechar', 18)}</button>` : ''}
    </article>`;
}

function desenhar() {
  raiz.hidden = pedidos.length === 0;
  raiz.innerHTML = pedidos.map(cartaoHtml).join('');
}

async function consultar() {
  if (document.visibilityState !== 'visible') return;
  const andando = pedidos.filter((p) => !encerrado(p.status));
  if (andando.length === 0) return;
  let mudou = false;
  await Promise.all(andando.map(async (p) => {
    try {
      const { itens, ...novo } = await api(`/api/pedidos/${encodeURIComponent(p.codigo)}`);
      if (novo.status !== p.status) {
        Object.assign(p, novo);
        mudou = true;
        anuncio.textContent = `Pedido ${p.numero}: ${STATUS[p.status].cliente}`;
      }
    } catch (erro) {
      if (erro.status === 404) {
        pedidos = pedidos.filter((x) => x !== p);
        mudou = true;
      }
      // Sem internet: mantém o que está na tela e tenta de novo no próximo ciclo.
    }
  }));
  if (mudou) desenhar();
}

delegar(raiz, 'click', '[data-dispensar]', async (e, botao) => {
  const codigo = botao.closest('[data-codigo]').dataset.codigo;
  pedidos = pedidos.filter((p) => p.codigo !== codigo);
  desenhar();
  try {
    await api(`/api/pedidos/${encodeURIComponent(codigo)}/dispensar`, { metodo: 'POST' });
  } catch {
    /* o aviso já saiu da tela; se o servidor não soube, ele some sozinho depois de algumas horas */
  }
});

export function iniciarAcompanhamento() {
  desenhar();
  setInterval(consultar, INTERVALO);
  document.addEventListener('visibilitychange', consultar);
}
