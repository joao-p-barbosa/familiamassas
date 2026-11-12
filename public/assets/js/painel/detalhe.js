// Detalhe do pedido (painel lateral): montagem completa, dados do cliente, histórico e ações.
import { $, esc, delegar } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { reais, hora, telefone } from '../shared/formato.js';
import { api } from '../shared/api.js';
import { abrirFolha, fecharFolha } from '../shared/folha.js';
import { avisar } from '../shared/avisos.js';
import { FLUXOS, STATUS, ACAO_PARA, TIPOS, ORIGENS, PAGAMENTOS, encerrado, proximaEtapa, etapaAnterior, linhasMontagem, rotuloDoTipo, linkDoPonto } from '../shared/pedido.js';
import { mostrarErro } from './casca.js';
import { reimprimir } from './impressao.js';

const lateral = $('#folha-lateral');
const dialogo = $('#folha-dialogo');
const MOTIVOS = ['Cliente desistiu', 'Item em falta', 'Fora da área de entrega', 'Pedido duplicado'];

let fonte = null;       // de onde vêm os pedidos (pedidos-vivos)
let aberto = null;      // id do pedido aberto
let historico = [];     // histórico do pedido aberto (vem de uma consulta à parte)

export const SELO_STATUS = { novo: 'selo--info', em_preparo: 'selo--alerta', pronto: 'selo--sucesso', saiu_entrega: 'selo--acento', aguardando_retirada: 'selo--acento', concluido: '', cancelado: 'selo--perigo' };

export function iniciarDetalhe(fonteDePedidos) {
  fonte = fonteDePedidos;
}

export async function abrirDetalhe(id) {
  aberto = id;
  historico = [];
  desenhar();
  abrirFolha(lateral, { aoFechar: () => (aberto = null) });
  try {
    const completo = await api(`/api/admin/pedidos/${id}`);
    if (aberto !== id) return;
    historico = completo.historico;
    desenhar();
  } catch (erro) {
    mostrarErro(erro);
  }
}

/** Chamado pela tela quando os pedidos mudam: mantém o painel aberto em dia. */
export function atualizarDetalhe() {
  if (aberto === null || !lateral.open) return;
  const pedido = fonte.pedidos.get(aberto);
  if (!pedido) return;
  // O histórico só muda quando o status muda: busca de novo nesse caso.
  if (historico.length && historico.at(-1).status !== pedido.status) {
    api(`/api/admin/pedidos/${aberto}`).then((completo) => {
      if (aberto === completo.id) {
        historico = completo.historico;
        desenhar();
      }
    }).catch(() => {});
  }
  desenhar();
}

function desenhar() {
  const p = fonte.pedidos.get(aberto);
  if (!p) return;
  const proxima = proximaEtapa(p);
  const anterior = etapaAnterior(p);
  const fechado = encerrado(p.status);
  const zap = p.cliente_telefone ? `https://wa.me/${encodeURIComponent(p.cliente_telefone)}` : null;
  const rolagem = $('.folha__corpo', lateral)?.scrollTop ?? 0;

  lateral.innerHTML = `
    <header class="folha__topo">
      <h2 class="folha__titulo" id="folha-lateral-titulo">Pedido #${p.id}</h2>
      <button type="button" class="botao botao--fantasma botao--icone" data-fechar aria-label="Fechar">${icone('fechar')}</button>
    </header>
    <div class="folha__corpo detalhe">
      <div class="detalhe__selos">
        <span class="selo ${SELO_STATUS[p.status]}">${STATUS[p.status].rotulo}</span>
        <span class="selo">${icone(TIPOS[p.tipo].icone, 14)}${esc(rotuloDoTipo(p))}</span>
        <span class="selo">${esc(ORIGENS[p.origem] ?? p.origem)}</span>
        <span class="selo">${p.impresso_em ? `Impresso às ${hora(p.impresso_em)}` : 'Comanda não impressa'}</span>
      </div>

      ${p.observacoes ? `<p class="detalhe__obs">${icone('alerta', 18)}<span><strong>Observação do pedido:</strong> ${esc(p.observacoes)}</span></p>` : ''}

      <section class="detalhe__bloco">
        <h3 class="detalhe__titulo">Itens</h3>
        <ul class="revisao-itens">
          ${p.itens.map((item) => `
            <li class="revisao-itens__item">
              <div class="revisao-itens__topo"><p><span class="numero">${item.quantidade}×</span> ${esc(item.nome)}</p><p class="numero">${reais(item.preco_unit_centavos * item.quantidade)}</p></div>
              ${item.montagem ? `<ul class="montagem">${linhasMontagem(item.montagem)}</ul>` : ''}
              ${item.observacoes ? `<p class="detalhe__obs detalhe__obs--item">${icone('alerta', 16)}<span>${esc(item.observacoes)}</span></p>` : ''}
            </li>`).join('')}
        </ul>
        <dl class="totais">
          <div><dt>Subtotal</dt><dd class="numero">${reais(p.subtotal_centavos)}</dd></div>
          ${p.tipo === 'entrega' ? `<div><dt>Taxa de entrega</dt><dd class="numero">${reais(p.taxa_entrega_centavos)}</dd></div>` : ''}
          <div class="totais__total"><dt>Total</dt><dd class="numero">${reais(p.total_centavos)}</dd></div>
        </dl>
      </section>

      <section class="detalhe__bloco">
        <h3 class="detalhe__titulo">Cliente</h3>
        <dl class="resumo-dados">
          <div><dt>Nome</dt><dd>${esc(p.cliente_nome)}</dd></div>
          ${zap ? `<div><dt>WhatsApp</dt><dd><a href="${esc(zap)}" target="_blank" rel="noopener">${esc(telefone(p.cliente_telefone))}</a></dd></div>` : ''}
          ${p.tipo === 'entrega' ? `<div><dt>Endereço</dt><dd>${esc(p.endereco_rua)}, ${esc(p.endereco_numero)}${p.endereco_complemento ? ` · ${esc(p.endereco_complemento)}` : ''}<br>${esc(p.endereco_bairro)}${p.endereco_referencia ? `<br>Ref.: ${esc(p.endereco_referencia)}` : ''}${p.entrega_lat !== null && p.entrega_lng !== null ? `<br><a href="${esc(linkDoPonto(p.entrega_lat, p.entrega_lng))}" target="_blank" rel="noopener">${icone('mapa', 14)} Ver o local marcado no mapa</a>` : ''}</dd></div>` : ''}
          <div><dt>Pagamento</dt><dd>${PAGAMENTOS[p.pagamento]}${p.troco_para_centavos ? `, troco para ${reais(p.troco_para_centavos)} (levar ${reais(p.troco_para_centavos - p.total_centavos)})` : ''}</dd></div>
        </dl>
      </section>

      <section class="detalhe__bloco">
        <h3 class="detalhe__titulo">Histórico</h3>
        ${historico.length ? `
        <ol class="historico">
          ${historico.map((h) => `
            <li><span class="numero">${hora(h.em)}</span><span><strong>${STATUS[h.status]?.rotulo ?? esc(h.status)}</strong>${h.usuario ? ` · ${esc(h.usuario)}` : h.status === 'novo' ? ' · pelo cliente' : ''}${h.motivo ? `<br>Motivo: ${esc(h.motivo)}` : ''}</span></li>`).join('')}
        </ol>` : '<div class="esqueleto esqueleto--linha" style="width: 60%"></div>'}
      </section>
    </div>
    <footer class="folha__rodape">
      ${proxima ? `<button type="button" class="botao botao--g botao--bloco" data-detalhe="avancar" data-para="${proxima}">${ACAO_PARA[proxima]}${icone('seta-dir', 20)}</button>` : ''}
      <div class="detalhe__acoes">
        <button type="button" class="botao botao--secundario" data-detalhe="reimprimir">${icone('impressora', 18)}${p.impresso_em ? 'Reimprimir' : 'Imprimir'}</button>
        ${!fechado && anterior ? `<button type="button" class="botao botao--secundario" data-detalhe="voltar" data-para="${anterior}">${icone('desfazer', 18)}Voltar etapa</button>` : ''}
        ${!fechado ? `<button type="button" class="botao botao--perigo-suave" data-detalhe="cancelar">Cancelar pedido</button>` : ''}
      </div>
    </footer>`;
  $('.folha__corpo', lateral).scrollTop = rolagem;
}

// ---------- Cancelar com motivo ----------
export function pedirCancelamento(id) {
  dialogo.innerHTML = `
    <header class="folha__topo">
      <h2 class="folha__titulo" id="folha-dialogo-titulo">Cancelar o pedido #${id}?</h2>
      <button type="button" class="botao botao--fantasma botao--icone" data-fechar aria-label="Fechar">${icone('fechar')}</button>
    </header>
    <form class="folha__corpo cancelar" id="form-cancelar" novalidate>
      <fieldset class="grupo-opcoes">
        <legend class="campo__rotulo">Por quê? O cliente vê este motivo.</legend>
        ${MOTIVOS.map((motivo) => `
          <label class="opcao">
            <input class="opcao__entrada" type="radio" name="motivo" value="${esc(motivo)}">
            <span class="opcao__marca">${icone('check', 14)}</span>
            <span class="opcao__corpo"><span class="opcao__titulo">${esc(motivo)}</span></span>
          </label>`).join('')}
      </fieldset>
      <label class="campo">
        <span class="campo__rotulo">Outro motivo</span>
        <input class="entrada" type="text" name="outro" maxlength="200" autocomplete="off">
      </label>
      <p class="campo__erro" id="erro-motivo" role="alert" hidden>Escolha ou escreva o motivo.</p>
    </form>
    <footer class="folha__rodape folha__rodape--acoes">
      <button type="button" class="botao botao--secundario" data-fechar>Manter o pedido</button>
      <button type="submit" form="form-cancelar" class="botao botao--perigo">Cancelar pedido</button>
    </footer>`;
  const form = $('#form-cancelar');
  form.outro.addEventListener('input', () => {
    if (form.outro.value.trim()) form.querySelectorAll('[name="motivo"]').forEach((r) => (r.checked = false));
  });
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const motivo = form.outro.value.trim() || form.motivo.value;
    if (!motivo) {
      $('#erro-motivo').hidden = false;
      return;
    }
    const botao = dialogo.querySelector('button[type="submit"]');
    botao.setAttribute('aria-busy', 'true');
    const pedido = await fonte.mudarStatus(id, 'cancelado', motivo);
    botao.removeAttribute('aria-busy');
    if (pedido) {
      await fecharFolha(dialogo);
      avisar(`Pedido #${id} cancelado`);
    }
  });
  abrirFolha(dialogo);
}

/** "Mover para…": a alternativa de teclado (e de toque) ao arrastar. */
export function pedirMovimento(id) {
  const p = fonte.pedidos.get(id);
  if (!p || encerrado(p.status)) return;
  const destinos = FLUXOS[p.tipo].filter((s) => s !== p.status);
  dialogo.innerHTML = `
    <header class="folha__topo">
      <h2 class="folha__titulo" id="folha-dialogo-titulo">Mover o pedido #${p.id} para…</h2>
      <button type="button" class="botao botao--fantasma botao--icone" data-fechar aria-label="Fechar">${icone('fechar')}</button>
    </header>
    <div class="folha__corpo mover">
      <p class="mover__agora">Agora está em <strong>${STATUS[p.status].rotulo}</strong>.</p>
      ${destinos.map((s) => `<button type="button" class="botao botao--secundario botao--bloco" data-mover-para="${s}">${STATUS[s].rotulo}</button>`).join('')}
    </div>`;
  dialogo.dataset.pedido = p.id;
  abrirFolha(dialogo);
}

delegar(dialogo, 'click', '[data-mover-para]', async (e, botao) => {
  const id = Number(dialogo.dataset.pedido);
  botao.setAttribute('aria-busy', 'true');
  await fecharFolha(dialogo);
  await fonte.mudarStatus(id, botao.dataset.moverPara);
});

delegar(lateral, 'click', '[data-detalhe]', async (e, botao) => {
  const p = fonte.pedidos.get(aberto);
  if (!p) return;
  switch (botao.dataset.detalhe) {
    case 'avancar':
    case 'voltar':
      botao.setAttribute('aria-busy', 'true');
      await fonte.mudarStatus(p.id, botao.dataset.para);
      break;
    case 'reimprimir': {
      botao.setAttribute('aria-busy', 'true');
      const atualizado = await reimprimir(p);
      if (atualizado) {
        fonte.guardar(atualizado);
        avisar('Comanda enviada para a impressora');
      }
      desenhar();
      break;
    }
    case 'cancelar':
      pedirCancelamento(p.id);
      break;
  }
});
