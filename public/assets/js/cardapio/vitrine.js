// Vitrine do cardápio: busca, destaque "Monte seu prato", categorias com scroll spy e lista de itens.
// É usada pela página do cliente (principal.js) e pela tela do salão no painel (painel/salao.js): o mesmo
// preço, o mesmo montador e o mesmo carrinho. Quem a usa liga o fechamento do pedido (definirAoFecharPedido).
import { $, $$, esc, delegar, menosMovimento } from '../shared/dom.js';
import { icone, iconeMassa } from '../shared/icones.js';
import { reais, semAcento } from '../shared/formato.js';
import { precoMinimo } from '../shared/preco.js';
import { estado, assinar, recarregarCardapio, itemPorId } from './estado.js';
import { abrirMontador } from './montador.js';
import { abrirItem } from './item.js';
import { desenharCarrinho } from './carrinho.js';

const nav = $('#categorias');
const lista = $('#lista-cardapio');
let busca = '';
let espiao = null;
let comLoja = true;

// ---------- Estrutura fixa (desenhada uma vez; a busca não perde o foco ao digitar) ----------
function montarEstrutura() {
  lista.removeAttribute('aria-busy');
  lista.innerHTML = `
    ${comLoja ? '<div class="loja-info" id="loja-info"></div>' : ''}
    <label class="busca">
      ${icone('busca', 20)}
      <span class="so-leitor">Buscar no cardápio</span>
      <input class="busca__entrada" type="search" id="busca" placeholder="Buscar no cardápio" autocomplete="off" enterkeyhint="search">
    </label>
    <div id="avisos-topo"></div>
    <div id="secoes"></div>`;
}

function desenharLoja() {
  if (!comLoja) return;
  const loja = estado.loja;
  const selo = $('#estado-loja');
  selo.className = `selo ${loja.aberta ? 'selo--sucesso' : 'selo--perigo'}`;
  // "Fechado · abre hoje às 11:00": no celular o topo mostra só "Fechado" (o resto está na faixa logo abaixo).
  const [curto, resto] = loja.mensagem.split(' · ');
  selo.lastElementChild.innerHTML = `${esc(curto)}${resto ? `<span class="selo__resto"> · ${esc(resto)}</span>` : ''}`;

  const partes = [];
  if (loja.tipos_pedido.includes('entrega')) {
    partes.push(`<span>${icone('moto', 18)}Entrega em ${loja.tempo_entrega_min} min · ${loja.taxa_entrega_centavos ? reais(loja.taxa_entrega_centavos) : 'grátis'}</span>`);
  }
  if (loja.tipos_pedido.includes('retirada')) {
    partes.push(`<span>${icone('loja', 18)}Retirada em ${loja.tempo_retirada_min} min</span>`);
  }
  $('#loja-info').innerHTML = partes.join('');

  $('#avisos-topo').innerHTML = loja.aberta ? '' : `
    <p class="faixa-aviso">${icone('relogio', 20)}<span><strong>${esc(loja.mensagem)}.</strong> Você pode montar o carrinho agora e enviar quando a loja abrir.</span></p>`;
}

// ---------- Seções ----------
function produtoHtml(item) {
  const fora = item.estado !== 'disponivel';
  const casa = item.tipo === 'prato_casa';
  const minimo = casa ? precoMinimo(estado.cardapio, item) : null;
  const preco = casa
    ? minimo === null ? '' : `<small>a partir de</small> ${reais(minimo)}`
    : reais(item.preco_centavos);
  return `
    <li>
      <button type="button" class="produto" data-item="${item.id}" ${fora ? 'disabled' : ''}>
        <span class="produto__texto">
          <span class="produto__nome">${esc(item.nome)}</span>
          ${item.descricao ? `<span class="produto__descricao">${esc(item.descricao)}</span>` : ''}
          <span class="produto__preco">${fora ? '<span class="selo">Esgotado</span>' : preco}</span>
        </span>
        ${item.imagem
          ? `<img class="produto__foto" src="${esc(item.imagem)}" alt="" width="88" height="88" loading="lazy" decoding="async">`
          : fora ? '' : `<span class="produto__acao">${icone('mais', 18)}</span>`}
      </button>
    </li>`;
}

function destaqueHtml() {
  const minimo = precoMinimo(estado.cardapio);
  const massas = estado.cardapio.massas.slice(0, 5);
  return `
    <section class="destaque" id="secao-monte" data-secao="monte" aria-labelledby="titulo-monte">
      <div class="destaque__texto">
        <${comLoja ? 'h1' : 'h2'} class="destaque__titulo" id="titulo-monte">Monte seu prato</${comLoja ? 'h1' : 'h2'}>
        <p class="destaque__passos">Tamanho, massa, molho e os ingredientes que você quiser.</p>
        ${minimo !== null ? `<p class="destaque__preco">A partir de <strong class="numero">${reais(minimo)}</strong></p>` : ''}
      </div>
      <div class="destaque__formas" aria-hidden="true">${massas.map((x) => iconeMassa(x.nome, 44)).join('')}</div>
      <button type="button" class="botao botao--g destaque__botao" data-acao="montar" ${minimo === null ? 'disabled' : ''}>${minimo === null ? 'Indisponível agora' : 'Montar meu prato'}${icone('seta-dir', 20)}</button>
    </section>`;
}

function desenharSecoes() {
  const c = estado.cardapio;
  const termo = semAcento(busca.trim());
  const casa = (item) => (item.tipo === 'prato_casa' ? 'Escolha o tamanho e troque o que quiser.' : '');
  const secoes = c.categorias
    .map((categoria) => ({
      categoria,
      itens: c.itens.filter((i) => i.categoria_id === categoria.id && (!termo || semAcento(`${i.nome} ${i.descricao}`).includes(termo))),
    }))
    .filter((s) => s.itens.length > 0);

  nav.innerHTML = termo ? '' : `
    <ul class="categorias__lista">
      <li><a class="chip" href="#secao-monte" data-alvo="monte">Monte seu prato</a></li>
      ${secoes.map((s) => `<li><a class="chip" href="#secao-${s.categoria.id}" data-alvo="${s.categoria.id}">${esc(s.categoria.nome)}</a></li>`).join('')}
    </ul>`;

  let html = termo ? '' : destaqueHtml();
  html += secoes.map((s) => `
    <section class="secao" id="secao-${s.categoria.id}" data-secao="${s.categoria.id}" aria-labelledby="titulo-${s.categoria.id}">
      <h2 class="secao__titulo" id="titulo-${s.categoria.id}">${esc(s.categoria.nome)}</h2>
      ${casa(s.itens[0]) ? `<p class="secao__nota">${casa(s.itens[0])}</p>` : ''}
      <ul class="lista-produtos">${s.itens.map(produtoHtml).join('')}</ul>
    </section>`).join('');

  if (termo && secoes.length === 0) {
    html = `
      <div class="vazio">
        <span class="vazio__icone">${icone('busca', 40)}</span>
        <p class="vazio__titulo">Nada com “${esc(busca.trim())}”</p>
        <p>Tente outro nome ou veja o cardápio inteiro.</p>
        <button type="button" class="botao botao--secundario" data-acao="limpar-busca">Limpar busca</button>
      </div>`;
  }
  $('#secoes').innerHTML = html;
  espiar();
}

// ---------- Scroll spy: marca no topo a categoria que está na tela ----------
function espiar() {
  espiao?.disconnect();
  const chips = $$('.chip', nav);
  if (chips.length === 0) return;
  const marcar = (alvo) => {
    for (const chip of chips) {
      if (chip.dataset.alvo === alvo) {
        if (chip.getAttribute('aria-current') === 'true') return;
        chip.setAttribute('aria-current', 'true');
        // Traz o chip ativo para dentro da faixa (só rola a faixa, não a página).
        const faixa = $('.categorias__lista', nav);
        faixa.scrollTo({ left: chip.offsetLeft - faixa.clientWidth / 2 + chip.clientWidth / 2, behavior: menosMovimento() ? 'auto' : 'smooth' });
      } else {
        chip.removeAttribute('aria-current');
      }
    }
  };
  // "Na tela" = cruzou a faixa entre 25% e 35% da altura da janela.
  espiao = new IntersectionObserver((entradas) => {
    const visivel = entradas.filter((e) => e.isIntersecting).at(-1);
    if (visivel) marcar(visivel.target.dataset.secao);
  }, { rootMargin: '-25% 0px -65% 0px' });
  $$('[data-secao]').forEach((secao) => espiao.observe(secao));
  marcar('monte');
}

// ---------- Eventos ----------
delegar(lista, 'click', '[data-item]', (e, botao) => {
  const item = itemPorId(Number(botao.dataset.item));
  if (!item) return;
  if (item.tipo === 'prato_casa') abrirMontador({ itemCasa: item });
  else abrirItem(item);
});
delegar(lista, 'click', '[data-acao="montar"]', () => abrirMontador());
delegar(lista, 'click', '[data-acao="limpar-busca"]', () => {
  busca = '';
  $('#busca').value = '';
  desenharSecoes();
  $('#busca').focus();
});
delegar(lista, 'input', '#busca', (e, entrada) => {
  busca = entrada.value;
  desenharSecoes();
});
delegar(nav, 'click', '.chip', (e, chip) => {
  const secao = document.getElementById(chip.getAttribute('href').slice(1));
  if (!secao) return;
  e.preventDefault();
  secao.scrollIntoView({ behavior: menosMovimento() ? 'auto' : 'smooth', block: 'start' });
});

// ---------- Início ----------
function desenharTudo() {
  desenharLoja();
  desenharSecoes();
  desenharCarrinho();
}

function mostrarErroDeCarga() {
  lista.removeAttribute('aria-busy');
  lista.innerHTML = `
    <div class="vazio">
      <span class="vazio__icone">${icone('alerta', 40)}</span>
      <p class="vazio__titulo">Não conseguimos carregar o cardápio</p>
      <p>Confira sua internet e tente de novo.</p>
      <button type="button" class="botao" id="tentar-de-novo">Tentar de novo</button>
    </div>`;
  $('#tentar-de-novo').addEventListener('click', iniciar);
}

async function iniciar({ atualizar = false } = {}) {
  if (!estado.cardapio || atualizar) {
    try {
      await recarregarCardapio();
    } catch {
      mostrarErroDeCarga();
      return;
    }
  }
  montarEstrutura();
  desenharTudo();
}

let iniciada = false;

/**
 * Liga a vitrine. Pode ser chamada de novo (a tela de produtos do atendimento reabre dentro da SPA): os eventos são
 * ligados só na primeira vez; nas outras, o cardápio é buscado de novo e a lista redesenhada.
 * @param {{ comLoja?: boolean }} opcoes  comLoja: mostra horário, taxa e aviso de loja fechada (só a página do cliente).
 */
export function iniciarVitrine({ comLoja: mostrarLoja = true } = {}) {
  comLoja = mostrarLoja;
  if (iniciada) {
    return iniciar({ atualizar: true });
  }
  iniciada = true;

  // Mudou só o carrinho? Redesenha só ele (a lista fica como está e o foco não se perde).
  assinar((oQue) => {
    if (!$('#secoes')) return;
    if (oQue === 'carrinho') desenharCarrinho();
    else desenharTudo();
  });

  // Mantém preços e esgotados em dia: ao voltar para a aba e a cada minuto.
  const atualizarQuieto = () => {
    // Tela escondida (a SPA mantém as telas já abertas) não precisa ficar buscando o cardápio.
    if (document.visibilityState === 'visible' && estado.cardapio && lista.offsetParent !== null && !document.querySelector('dialog[open]')) {
      recarregarCardapio().catch(() => {});
    }
  };
  document.addEventListener('visibilitychange', atualizarQuieto);
  setInterval(atualizarQuieto, 60_000);

  // A página pode ter sido recarregada no meio do checkout: o histórico volta ao começo.
  if (history.state?.visao || history.state?.folha) history.replaceState(null, '');

  return iniciar();
}
