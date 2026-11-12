// Impressão da comanda pelo navegador (Caminho A do briefing).
//   * Este aparelho pode ser marcado como "estação de impressão" (fica guardado no próprio navegador).
//   * Pedido novo: a estação pede o "claim" ao servidor; só quem ganhar imprime (comanda sai uma vez só).
//   * A comanda é montada em HTML num <iframe> oculto e mandada para a impressora padrão.
//     Com o Chrome aberto em --kiosk-printing, sai direto, sem a janela de impressão.
import { esc, guardado } from '../shared/dom.js';
import { reais, hora, dataHora, telefone } from '../shared/formato.js';
import { api } from '../shared/api.js';
import { ORIGENS, PAGAMENTOS, rotuloDoTipo } from '../shared/pedido.js';
import { ajustes, mostrarErro } from './casca.js';

const CHAVE = 'fm_estacao_v1';

function lerEstacao() {
  const salva = guardado.ler(CHAVE);
  if (salva?.id) return salva;
  const nova = { id: crypto.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`, nome: 'Estação de impressão', ativa: false };
  guardado.gravar(CHAVE, nova);
  return nova;
}

export const estacao = lerEstacao();

export function definirEstacaoAtiva(ativa) {
  estacao.ativa = ativa;
  guardado.gravar(CHAVE, estacao);
}

// ---------- Comanda ----------
/** HTML da comanda (só o conteúdo, dentro de <div class="comanda">). */
export function comandaHtml(pedido, { largura = ajustes.comanda_largura_mm, comPreco = ajustes.comanda_mostra_preco, reimpressao = false } = {}) {
  const tipo = rotuloDoTipo(pedido);
  const endereco = pedido.tipo === 'entrega'
    ? `<p>${esc(pedido.endereco_rua)}, ${esc(pedido.endereco_numero)}${pedido.endereco_complemento ? ` · ${esc(pedido.endereco_complemento)}` : ''}</p>
       <p>${esc(pedido.endereco_bairro)}</p>${pedido.endereco_referencia ? `<p>Ref.: ${esc(pedido.endereco_referencia)}</p>` : ''}`
    : '';

  const itens = pedido.itens.map((item) => {
    const m = item.montagem;
    const nomes = (lista) => lista.map((i) => esc(i.nome)).join(', ');
    return `
      <div class="comanda__item">
        <p class="comanda__item-nome"><span>${item.quantidade}x ${esc(item.nome)}</span>${comPreco ? `<span>${reais(item.preco_unit_centavos * item.quantidade)}</span>` : ''}</p>
        ${m ? `
        <ul class="comanda__montagem">
          <li><b>${esc(m.tamanho.nome)}</b>${m.tamanho.descricao ? ` (${esc(m.tamanho.descricao)})` : ''}</li>
          <li>Massa: <b>${esc(m.massa.nome)}</b></li>
          <li>Molho: <b>${esc(m.molho.nome)}</b></li>
          ${m.inclusos.length ? `<li>Ingredientes: ${nomes(m.inclusos)}</li>` : ''}
          ${m.extras.length ? `<li><b>EXTRAS: ${nomes(m.extras)}</b></li>` : ''}
          ${!m.inclusos.length && !m.extras.length ? '<li>Sem ingredientes</li>' : ''}
        </ul>` : ''}
        ${item.observacoes ? `<p class="comanda__obs">OBS: ${esc(item.observacoes)}</p>` : ''}
      </div>`;
  }).join('');

  const troco = pedido.pagamento === 'dinheiro' && pedido.troco_para_centavos ? ` · troco para ${reais(pedido.troco_para_centavos)}` : '';
  return `
    <div class="comanda ${largura === 58 ? 'comanda--58' : ''}">
      <p class="comanda__loja">${esc(ajustes.nome_loja)}</p>
      <p class="comanda__numero">#${pedido.id}</p>
      <p class="comanda__tipo">${esc(tipo)}</p>
      <p class="comanda__meta"><span>${dataHora(pedido.criado_em)}</span><span>${esc(ORIGENS[pedido.origem] ?? pedido.origem)}</span></p>
      <div class="comanda__bloco">
        <p class="comanda__cliente">${esc(pedido.cliente_nome)}</p>
        ${pedido.cliente_telefone ? `<p>${esc(telefone(pedido.cliente_telefone))}</p>` : ''}
        ${endereco}
      </div>
      <div class="comanda__bloco">${itens}</div>
      ${pedido.observacoes ? `<div class="comanda__bloco"><p class="comanda__obs">OBS DO PEDIDO: ${esc(pedido.observacoes)}</p></div>` : ''}
      ${comPreco ? `
      <div class="comanda__bloco">
        ${pedido.taxa_entrega_centavos ? `<p class="comanda__meta"><span>Taxa de entrega</span><span>${reais(pedido.taxa_entrega_centavos)}</span></p>` : ''}
        <p class="comanda__total"><span>TOTAL</span><span>${reais(pedido.total_centavos)}</span></p>
        <p>${PAGAMENTOS[pedido.pagamento]}${troco}</p>
      </div>` : ''}
      ${reimpressao ? `<p class="comanda__via">Reimpressão · ${hora(new Date().toISOString())}</p>` : ''}
    </div>`;
}

// ---------- Fila de impressão (uma comanda por vez) ----------
let fila = Promise.resolve();

function imprimirHtml(html, largura) {
  const trabalho = () => new Promise((pronto) => {
    const quadro = document.createElement('iframe');
    quadro.setAttribute('aria-hidden', 'true');
    quadro.tabIndex = -1;
    quadro.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden';
    let terminou = false;
    const terminar = () => {
      if (terminou) return;
      terminou = true;
      setTimeout(() => quadro.remove(), 500);
      pronto();
    };
    quadro.addEventListener('load', () => {
      const janela = quadro.contentWindow;
      janela.addEventListener('afterprint', terminar);
      janela.focus();
      janela.print();
      // Rede de segurança: se o navegador não avisar que terminou, a fila segue mesmo assim.
      setTimeout(terminar, 15_000);
    });
    // @page define o tamanho do papel; margem zero porque a térmica já tem a sua.
    quadro.srcdoc = `<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Comanda</title>
      <link rel="stylesheet" href="/assets/css/comanda.css">
      <style>@page { size: ${largura}mm auto; margin: 0; } html, body { margin: 0; padding: 0; }</style>
      </head><body>${html}</body></html>`;
    document.body.append(quadro);
  });
  fila = fila.then(trabalho, trabalho);
  return fila;
}

/**
 * Impressão automática de um pedido ainda não impresso. Só imprime se este aparelho for a estação
 * ativa E o servidor confirmar que foi ele quem "pegou" o pedido.
 * @returns {Promise<object|null>} pedido atualizado (com impresso_em) ou null se não era a vez dele
 */
export async function imprimirAutomatico(pedido) {
  if (!estacao.ativa || pedido.impresso_em || pedido.status === 'cancelado') return null;
  try {
    const r = await api(`/api/admin/pedidos/${pedido.id}/impressao`, {
      metodo: 'POST',
      corpo: { estacao_id: estacao.id, estacao_nome: estacao.nome, modo: 'auto' },
    });
    if (r.imprimir) imprimirHtml(comandaHtml(r.pedido), ajustes.comanda_largura_mm);
    return r.pedido;
  } catch (erro) {
    if (erro.status === 401) mostrarErro(erro);
    return null; // falhou agora: o próximo ciclo do polling tenta de novo
  }
}

/** Botão "Reimprimir": sempre imprime, em qualquer aparelho. */
export async function reimprimir(pedido) {
  try {
    const r = await api(`/api/admin/pedidos/${pedido.id}/impressao`, {
      metodo: 'POST',
      corpo: { estacao_id: estacao.id, estacao_nome: estacao.nome, modo: 'manual' },
    });
    imprimirHtml(comandaHtml(r.pedido, { reimpressao: Boolean(pedido.impresso_em) }), ajustes.comanda_largura_mm);
    return r.pedido;
  } catch (erro) {
    mostrarErro(erro);
    return null;
  }
}

/** Comanda de teste (Configurações): confere papel, largura e impressora sem precisar de um pedido. */
export function imprimirTeste(largura, comPreco) {
  const agora = new Date().toISOString();
  const pedido = {
    id: 0, tipo: 'entrega', mesa: null, origem: 'cardapio', criado_em: agora, cliente_nome: 'Comanda de teste', cliente_telefone: '5511912345678',
    endereco_rua: 'Rua de Exemplo', endereco_numero: '100', endereco_bairro: 'Centro', endereco_complemento: 'Apto 12', endereco_referencia: '',
    observacoes: 'Tocar o interfone', pagamento: 'dinheiro', troco_para_centavos: 10000, taxa_entrega_centavos: 600, total_centavos: 5990,
    itens: [
      { nome: 'Monte seu prato', quantidade: 1, preco_unit_centavos: 4090, observacoes: 'Molho à parte',
        montagem: { tamanho: { nome: 'Inteiro', descricao: 'Porção inteira · serve 2 pessoas · 550 g' }, massa: { nome: 'Penne' }, molho: { nome: 'Bolonhesa' },
                    inclusos: [{ nome: 'Frango desfiado' }, { nome: 'Bacon' }, { nome: 'Muçarela' }, { nome: 'Camarão' }], extras: [{ nome: 'Brócolis' }, { nome: 'Milho' }] } },
      { nome: 'Refrigerante lata', quantidade: 2, preco_unit_centavos: 650, observacoes: '', montagem: null },
    ],
  };
  const html = comandaHtml(pedido, { largura, comPreco });
  imprimirHtml(html, largura);
  return html;
}
