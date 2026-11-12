// Carrinho: o mesmo HTML serve o painel lateral (desktop) e a folha de baixo (celular).
import { $, esc, delegar } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { reais, plural } from '../shared/formato.js';
import { abrirFolha, fecharFolha } from '../shared/folha.js';
import { avisar } from '../shared/avisos.js';
import { estado, totaisDoCarrinho, atualizarLinha, removerLinha, devolverLinha } from './estado.js';
import { abrirMontador } from './montador.js';

const lateral = $('#carrinho-lateral');
const folha = $('#folha-carrinho');
const corpoFolha = $('#carrinho-folha');
const barra = $('#barra-carrinho');

let aoFecharPedido = () => {};
export const definirAoFecharPedido = (fn) => (aoFecharPedido = fn);

function linhaHtml(l) {
  const extras = l.montagem ? [...l.montagem.inclusos, ...l.montagem.extras].map((i) => i.nome).join(', ') : '';
  return `
    <li class="linha-carrinho" data-uid="${l.uid}" ${l.problema ? 'data-problema' : ''}>
      <div class="linha-carrinho__texto">
        <p class="linha-carrinho__nome">${esc(l.nome)}</p>
        ${l.resumo ? `<p class="linha-carrinho__detalhe">${esc(l.resumo)}</p>` : ''}
        ${extras ? `<p class="linha-carrinho__detalhe">${esc(extras)}</p>` : ''}
        ${l.observacoes ? `<p class="linha-carrinho__detalhe">Obs.: ${esc(l.observacoes)}</p>` : ''}
        ${l.problema ? `<p class="linha-carrinho__problema">${icone('alerta', 16)}<span>${esc(l.problema)}. Remova ou troque para continuar.</span></p>` : ''}
      </div>
      <p class="linha-carrinho__preco numero">${reais(l.total)}</p>
      <div class="linha-carrinho__acoes">
        <div class="quantidade quantidade--p" role="group" aria-label="Quantidade de ${esc(l.nome)}">
          <button type="button" class="quantidade__botao" data-acao="menos" aria-label="${l.quantidade === 1 ? 'Remover' : 'Diminuir'}">${icone(l.quantidade === 1 ? 'lixeira' : 'menos', 16)}</button>
          <output class="quantidade__valor">${l.quantidade}</output>
          <button type="button" class="quantidade__botao" data-acao="mais" aria-label="Aumentar" ${l.quantidade >= 20 ? 'disabled' : ''}>${icone('mais', 16)}</button>
        </div>
        ${l.tipo === 'montado' ? '<button type="button" class="botao botao--fantasma botao--p" data-acao="editar">Editar</button>' : ''}
      </div>
    </li>`;
}

function carrinhoHtml() {
  const t = totaisDoCarrinho();
  if (t.linhas.length === 0) {
    return `
      <div class="vazio">
        <span class="vazio__icone">${icone('sacola', 40)}</span>
        <p class="vazio__titulo">Seu pedido está vazio</p>
        <p>Monte um prato ou escolha um item do cardápio.</p>
      </div>`;
  }
  const salao = estado.modo === 'salao';
  const fechada = !salao && !estado.loja.aberta;   // quem está no salão atende mesmo com a loja "fechada" para o cardápio
  const motivo = fechada ? `${estado.loja.mensagem}. Seu carrinho fica guardado.` : t.temProblema ? 'Resolva os itens marcados para continuar.' : '';
  return `
    <ul class="linhas-carrinho">${t.linhas.map(linhaHtml).join('')}</ul>
    <div class="carrinho__fim">
      <p class="carrinho__subtotal"><span>Subtotal</span><strong class="numero">${reais(t.subtotal)}</strong></p>
      ${motivo ? `<p class="carrinho__motivo">${icone('info', 16)}<span>${esc(motivo)}</span></p>` : ''}
      <button type="button" class="botao botao--g botao--bloco" data-acao="fechar-pedido" ${fechada || t.temProblema ? 'disabled' : ''}>${salao ? 'Registrar pedido' : 'Fechar pedido'}</button>
    </div>`;
}

export function desenharCarrinho() {
  if (!estado.cardapio) return;
  const html = carrinhoHtml();
  lateral.innerHTML = `<h2 class="painel-carrinho__titulo">${estado.modo === 'salao' ? 'Pedido do salão' : 'Seu pedido'}</h2>${html}`;
  corpoFolha.innerHTML = html;

  const t = totaisDoCarrinho();
  barra.hidden = t.quantidade === 0;
  document.body.classList.toggle('tem-barra-carrinho', t.quantidade > 0);
  barra.innerHTML = t.quantidade === 0 ? '' : `
    <button type="button" class="barra-carrinho__botao" data-acao="abrir-carrinho">
      <span class="barra-carrinho__conta">${icone('sacola', 20)}<span>${plural(t.quantidade, 'item', 'itens')}</span></span>
      <span>Ver pedido</span>
      <span class="numero">${reais(t.subtotal)}</span>
    </button>`;
  if (t.quantidade === 0 && folha.open) fecharFolha(folha);
}

function tratarClique(e, botao) {
  const uid = botao.closest('[data-uid]')?.dataset.uid;
  const linha = estado.carrinho.find((l) => l.uid === uid);
  switch (botao.dataset.acao) {
    case 'mais':
      if (linha) atualizarLinha(uid, { quantidade: Math.min(20, linha.quantidade + 1) });
      break;
    case 'menos':
      if (!linha) break;
      if (linha.quantidade > 1) {
        atualizarLinha(uid, { quantidade: linha.quantidade - 1 });
      } else {
        const desfeita = removerLinha(uid);
        if (desfeita) avisar('Item removido', { acao: { rotulo: 'Desfazer', aoClicar: () => devolverLinha(desfeita.removida, desfeita.posicao) } });
      }
      break;
    case 'editar':
      if (!linha) break;
      // Espera a folha do carrinho fechar (e sair do histórico) antes de abrir a do montador.
      fecharFolha(folha).then(() => abrirMontador({ linha }));
      break;
    case 'fechar-pedido':
      fecharFolha(folha).then(() => aoFecharPedido());
      break;
    case 'abrir-carrinho':
      abrirFolha(folha);
      break;
  }
}

for (const raiz of [lateral, corpoFolha, barra]) {
  delegar(raiz, 'click', '[data-acao]', tratarClique);
}
