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
    const nomes = (lista) => lista.map((i) => esc(((i.quantidade ?? 1) > 1 ? `${i.quantidade}x ` : '') + i.nome)).join(', ');
    return `
      <div class="comanda__item">
        <p class="comanda__item-nome"><span>${item.quantidade}x ${esc(item.nome)}</span></p>
        ${m && item.checklist ? `
        <div class="comanda__lista">${item.checklist.map((secao) => `
          ${secao.opcoes.length === 1
            ? `<p class="comanda__escolha"><b>${esc(secao.titulo)}:</b> ${esc(secao.opcoes[0].nome)}</p>`
            : `<p class="comanda__escolha"><b>${esc(secao.titulo)}:</b></p>${secao.opcoes.map((o) => `<p class="comanda__escolha comanda__escolha--lista">- ${esc(o.nome)}</p>`).join('')}`}`).join('')}
        </div>` : m ? `
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

  const troco = pedido.pagamento !== 'dinheiro' ? '' : pedido.troco_para_centavos ? `, troco para ${reais(pedido.troco_para_centavos)}` : ', sem troco';
  // Resumo do pedido (D111): uma linha por item, com o valor; o nome curto vem do servidor (Comanda::nomeNoResumo).
  const resumo = pedido.itens.map((item) => `<p class="comanda__meta"><span>${item.quantidade}x ${esc(item.nome_resumo ?? item.nome)}</span>${comPreco ? `<span>${reais(item.preco_unit_centavos * item.quantidade)}</span>` : ''}</p>`).join('');
  // Pedido do iFood: número do app, quem entrega, código de coleta e "pago ou cobrar" (sai mesmo na comanda sem preço).
  const ifood = pedido.ifood;
  const doIfood = ifood ? `
      <p class="comanda__tipo">iFood nº ${esc(ifood.display_id)}${ifood.simulado ? ' (teste)' : ''}</p>
      ${ifood.agendado_para ? `<p class="comanda__tipo">Agendado para ${dataHora(ifood.agendado_para)}</p>` : ''}` : '';
  const ifoodCliente = ifood ? `
        ${pedido.tipo === 'entrega' ? `<p><b>${ifood.entregue_por === 'IFOOD' ? 'Entregador do iFood' : 'Entrega da loja'}</b></p>` : ''}
        ${ifood.codigo_coleta ? `<p><b>Código de coleta: ${esc(ifood.codigo_coleta)}</b></p>` : ''}` : '';
  // D119: o telefone sai no fim, grande, perto do resumo (lá em cima fica só o nome).
  const fone = ifood ? ifood.telefone : pedido.cliente_telefone ? telefone(pedido.cliente_telefone) : '';
  const ifoodPagamento = ifood ? `
      <div class="comanda__bloco">
        <p class="comanda__tipo">${ifood.pago_online ? 'Pago online - não cobrar' : `Cobrar ${reais(ifood.a_cobrar_centavos ?? pedido.total_centavos)}`}</p>
        <p class="comanda__via">${esc(ifood.pagamento_texto)}</p>
        ${(ifood.cupons ?? []).map((cupom) => `<p class="comanda__via">${esc(cupom)}</p>`).join('')}
      </div>` : '';
  return `
    <div class="comanda ${largura === 58 ? 'comanda--58' : ''} ${['gg', 'xg'].includes(ajustes.comanda_fonte) ? `comanda--${ajustes.comanda_fonte}` : ''}">
      <p class="comanda__loja">${esc(ajustes.nome_loja)}</p>
      <p class="comanda__numero">#${pedido.id}</p>
      ${doIfood}
      <p class="comanda__tipo">${esc(tipo)}</p>
      <p class="comanda__meta"><span>${dataHora(pedido.criado_em)}</span><span>${esc(ORIGENS[pedido.origem] ?? pedido.origem)}</span></p>
      <div class="comanda__bloco">
        <p class="comanda__cliente">${esc(pedido.cliente_nome)}</p>
        ${endereco}${ifoodCliente}
      </div>
      <div class="comanda__bloco">${itens}</div>
      ${pedido.observacoes ? `<div class="comanda__bloco"><p class="comanda__obs">OBS DO PEDIDO: ${esc(pedido.observacoes)}</p></div>` : ''}
      <div class="comanda__bloco">
        <p class="comanda__cliente">Cliente: ${esc(pedido.cliente_nome)}</p>
        ${fone ? `<p class="comanda__tipo">${esc(fone)}</p>` : ''}
        ${ifood?.localizador ? `<p class="comanda__via">Localizador ${esc(ifood.localizador)}</p>` : ''}
      </div>
      <div class="comanda__bloco">
        <p class="comanda__secao">Resumo do pedido</p>
        ${resumo}
      </div>
      ${comPreco || !ifood ? `
      <div class="comanda__bloco">
        ${pedido.taxa_entrega_centavos ? `<p class="comanda__meta"><span>Taxa de entrega</span><span>${reais(pedido.taxa_entrega_centavos)}</span></p>`
          : (pedido.observacoes ?? '').startsWith('TAXA DE ENTREGA A CONFIRMAR') ? '<p class="comanda__meta"><span>Taxa de entrega</span><span>a confirmar</span></p>' : ''}
        <p class="comanda__total"><span>TOTAL</span><span>${reais(pedido.total_centavos)}</span></p>
      </div>` : ''}
      ${ifood ? '' : `<p class="comanda__tipo">${pedido.pagamento === 'caixa' ? (pedido.tipo === 'local' ? 'Pagamento na mesa' : 'Pagamento na conta') : `Pagamento: ${esc(PAGAMENTOS[pedido.pagamento] ?? pedido.pagamento)}${troco}`}</p>`}
      ${ifoodPagamento}
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

/** Botão "Reimprimir": sempre imprime. No modo agente, a comanda vai para a fila do computador da loja (D79). */
export async function reimprimir(pedido) {
  try {
    const r = await api(`/api/admin/pedidos/${pedido.id}/impressao`, {
      metodo: 'POST',
      corpo: { estacao_id: estacao.id, estacao_nome: estacao.nome, modo: 'manual' },
    });
    if (r.agente) return { ...r.pedido, agente_offline: !r.agente_online };
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
