// Estado do cardápio no navegador: dados da loja, cardápio e carrinho.
// O carrinho guarda só as ESCOLHAS (ids e quantidades). Nome e preço são sempre lidos do cardápio atual,
// então preço alterado ou item esgotado aparece na hora, sem carrinho "velho".
import { api } from '../shared/api.js';
import { dadosDaPagina, guardado } from '../shared/dom.js';
import { calcularMontagem } from '../shared/preco.js';
import { resumoMontagem } from '../shared/pedido.js';

const inicial = dadosDaPagina('dados-cardapio') ?? {};
// Modo "salao": a atendente monta os produtos de um atendimento (painel). Usa o mesmo preço e o mesmo montador, mas
// cada atendimento tem o seu carrinho (o de um atendimento novo e o de cada conta aberta; também não se mistura com
// o de um cliente no mesmo aparelho) e não depende do horário da loja.
const chaveDoCarrinho = (modo, contexto) => (modo === 'salao' ? `fm_carrinho_salao_${contexto || 'novo'}_v1` : 'fm_carrinho_v1');
let CHAVE_CARRINHO = chaveDoCarrinho(inicial.modo === 'salao' ? 'salao' : 'cliente', inicial.contexto);
const lerCarrinho = () => (Array.isArray(guardado.ler(CHAVE_CARRINHO)) ? guardado.ler(CHAVE_CARRINHO) : []);

export const estado = {
  modo: inicial.modo === 'salao' ? 'salao' : 'cliente',
  loja: inicial.loja ?? null,
  cardapio: inicial.loja ? inicial : null,
  carrinho: lerCarrinho(),
  whatsapp: lerSessaoWhatsApp(),
};

// ---------- Link do WhatsApp (?t=token) ----------
function lerSessaoWhatsApp() {
  const daPagina = dadosDaPagina('dados-whatsapp');
  try {
    if (daPagina) {
      sessionStorage.setItem('fm_whatsapp', JSON.stringify(daPagina));
    }
    // Tira o token da barra de endereço: se a pessoa compartilhar o link, o telefone dela não vai junto.
    if (new URLSearchParams(location.search).has('t')) {
      history.replaceState(history.state, '', location.pathname);
    }
    return daPagina ?? JSON.parse(sessionStorage.getItem('fm_whatsapp') ?? 'null');
  } catch {
    return daPagina;
  }
}

// ---------- Assinantes (quem redesenha quando algo muda) ----------
const assinantes = new Set();
export const assinar = (fn) => assinantes.add(fn);
/** oQue: 'cardapio' (preços, itens, loja) ou 'carrinho'. Quem assina decide o que redesenhar. */
export const notificar = (oQue) => assinantes.forEach((fn) => fn(oQue));

/**
 * Troca o carrinho em uso (a tela de produtos do atendimento muda de conta sem recarregar a página).
 * @param {'cliente'|'salao'} modo  @param {string} contexto  no salão: "novo" ou o número do atendimento
 */
export function usarCarrinho(modo, contexto = '') {
  estado.modo = modo;
  CHAVE_CARRINHO = chaveDoCarrinho(modo, contexto);
  estado.carrinho = lerCarrinho();
  notificar('carrinho');
}

// ---------- Cardápio ----------
export async function recarregarCardapio() {
  const dados = await api('/api/cardapio');
  estado.cardapio = dados;
  estado.loja = dados.loja;
  notificar('cardapio');
  return dados;
}

export const itemPorId = (id) => estado.cardapio?.itens.find((i) => i.id === id) ?? null;

// ---------- Carrinho ----------
const salvar = () => {
  guardado.gravar(CHAVE_CARRINHO, estado.carrinho);
  notificar('carrinho');
};
const novoUid = () => `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 7)}`;

export function adicionarAoCarrinho(linha) {
  estado.carrinho.push({ ...linha, uid: novoUid() });
  salvar();
}
export function atualizarLinha(uid, mudancas) {
  const linha = estado.carrinho.find((l) => l.uid === uid);
  if (!linha) return;
  Object.assign(linha, mudancas);
  salvar();
}
export function removerLinha(uid) {
  const posicao = estado.carrinho.findIndex((l) => l.uid === uid);
  if (posicao < 0) return null;
  const [removida] = estado.carrinho.splice(posicao, 1);
  salvar();
  return { removida, posicao };
}
export function devolverLinha(linha, posicao) {
  estado.carrinho.splice(Math.min(posicao, estado.carrinho.length), 0, linha);
  salvar();
}
export function esvaziarCarrinho() {
  estado.carrinho = [];
  salvar();
}

/**
 * Carrinho pronto para mostrar: cada linha com nome, resumo, preço atual e, se houver, o problema
 * (item esgotado ou removido do cardápio) que impede fechar o pedido.
 */
export function linhasDoCarrinho() {
  const c = estado.cardapio;
  if (!c) return [];
  return estado.carrinho.map((linha) => {
    const item = linha.item_id ? itemPorId(linha.item_id) : null;
    let problema = null;
    if (linha.item_id && !item) problema = 'Saiu do cardápio';
    else if (item && item.estado !== 'disponivel') problema = 'Esgotado';

    if (linha.tipo === 'montado') {
      const m = calcularMontagem(c, linha);
      const partes = [m.tamanho, m.massa, m.molho, ...m.inclusos, ...m.extras];
      const faltando = !m.completa || m.inclusos.length + m.extras.length !== new Set(linha.ingrediente_ids).size;
      const esgotada = partes.find((p) => p && p.estado !== 'disponivel');
      if (!problema && faltando) problema = 'Uma opção deste prato saiu do cardápio';
      if (!problema && esgotada) problema = `${esgotada.nome} esgotou`;
      return {
        ...linha,
        nome: item?.nome ?? 'Monte seu prato',
        resumo: resumoMontagem({ tamanho: m.tamanho, massa: m.massa, molho: m.molho }),
        montagem: m,
        precoUnit: m.preco,
        total: m.preco * linha.quantidade,
        problema,
      };
    }
    return {
      ...linha,
      nome: item?.nome ?? 'Item indisponível',
      resumo: '',
      montagem: null,
      precoUnit: item?.preco_centavos ?? 0,
      total: (item?.preco_centavos ?? 0) * linha.quantidade,
      problema,
    };
  });
}

export function totaisDoCarrinho(tipoPedido = null) {
  const linhas = linhasDoCarrinho();
  const subtotal = linhas.reduce((soma, l) => soma + l.total, 0);
  const taxa = tipoPedido === 'entrega' ? estado.loja?.taxa_entrega_centavos ?? 0 : 0;
  return {
    linhas,
    subtotal,
    taxa,
    total: subtotal + taxa,
    quantidade: linhas.reduce((soma, l) => soma + l.quantidade, 0),
    temProblema: linhas.some((l) => l.problema),
  };
}

// Outra aba mexeu no carrinho? Mantém as duas iguais.
window.addEventListener('storage', (e) => {
  if (e.key === CHAVE_CARRINHO) {
    const outro = guardado.ler(CHAVE_CARRINHO);
    estado.carrinho = Array.isArray(outro) ? outro : [];
    notificar('carrinho');
  }
});
