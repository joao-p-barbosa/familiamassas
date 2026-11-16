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
        <span class="selo">${esc(ORIGENS[p.origem] ?? p.origem)}${p.ifood ? ` nº ${esc(p.ifood.display_id)}` : ''}</span>
        ${p.ifood ? `<span class="selo">iFood: ${esc(p.ifood.rotulo)}${p.ifood.simulado ? ' (teste)' : ''}</span>` : ''}
        <span class="selo">${p.impresso_em ? `Impresso às ${hora(p.impresso_em)}` : 'Comanda não impressa'}</span>
      </div>

      ${p.ifood?.falhas ? `<p class="detalhe__obs">${icone('alerta', 18)}<span><strong>O iFood não recebeu uma etapa deste pedido.</strong> Confira no Gestor de Pedidos do iFood (o motivo está na tela iFood).</span></p>` : ''}
      ${p.ifood?.alerta ? `<p class="detalhe__obs">${icone('alerta', 18)}<span><strong>Atenção no iFood:</strong> ${esc(p.ifood.alerta)}</span></p>` : ''}
      ${p.ifood && !p.ifood.confirmado && p.status === 'novo' ? `<p class="detalhe__obs">${icone('relogio', 18)}<span>Este pedido ainda não foi confirmado no iFood.${p.ifood.simulado ? '' : ' Sem confirmação em 8 minutos, o iFood cancela o pedido sozinho.'}</span></p>` : ''}
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
        ${(p.ifood?.cupons ?? []).map((cupom) => `<p class="detalhe__obs detalhe__obs--item">${icone('info', 16)}<span>${esc(cupom)}</span></p>`).join('')}
        <dl class="totais">
          <div><dt>Subtotal</dt><dd class="numero">${reais(p.subtotal_centavos)}</dd></div>
          ${p.tipo === 'entrega' ? `<div><dt>Taxa de entrega</dt><dd class="numero">${reais(p.taxa_entrega_centavos)}</dd></div>` : ''}
          ${p.ifood?.taxas_adicionais_centavos ? `<div><dt>Taxas adicionais</dt><dd class="numero">${reais(p.ifood.taxas_adicionais_centavos)}</dd></div>` : ''}
          <div class="totais__total"><dt>Total</dt><dd class="numero">${reais(p.total_centavos)}</dd></div>
        </dl>
      </section>

      <section class="detalhe__bloco">
        <h3 class="detalhe__titulo">Cliente</h3>
        <dl class="resumo-dados">
          <div><dt>Nome</dt><dd>${esc(p.cliente_nome)}</dd></div>
          ${zap ? `<div><dt>WhatsApp</dt><dd><a href="${esc(zap)}" target="_blank" rel="noopener">${esc(telefone(p.cliente_telefone))}</a></dd></div>` : ''}
          ${p.tipo === 'entrega' ? `<div><dt>Endereço</dt><dd>${esc(p.endereco_rua)}, ${esc(p.endereco_numero)}${p.endereco_complemento ? ` · ${esc(p.endereco_complemento)}` : ''}<br>${esc(p.endereco_bairro)}${p.endereco_referencia ? `<br>Ref.: ${esc(p.endereco_referencia)}` : ''}${p.entrega_lat !== null && p.entrega_lng !== null ? `<br><a href="${esc(linkDoPonto(p.entrega_lat, p.entrega_lng))}" target="_blank" rel="noopener">${icone('mapa', 14)} Ver o local marcado no mapa</a>` : ''}</dd></div>` : ''}
          ${p.ifood?.telefone ? `<div><dt>Telefone (iFood)</dt><dd>${esc(p.ifood.telefone)}${p.ifood.localizador ? `, localizador ${esc(p.ifood.localizador)}` : ''}</dd></div>` : ''}
          ${p.ifood?.codigo_coleta ? `<div><dt>Código de coleta</dt><dd>${esc(p.ifood.codigo_coleta)}</dd></div>` : ''}
          ${p.ifood?.documento ? `<div><dt>CPF/CNPJ na nota</dt><dd>${esc(p.ifood.documento)}</dd></div>` : ''}
          ${p.ifood ? `<div><dt>Pagamento</dt><dd>${esc(p.ifood.pagamento_texto)}<br><strong>${p.ifood.pago_online ? 'Não cobrar: já pago no iFood' : `Cobrar ${reais(p.ifood.a_cobrar_centavos ?? p.total_centavos)}`}</strong></dd></div>` : `<div><dt>Pagamento</dt><dd>${PAGAMENTOS[p.pagamento]}${p.troco_para_centavos ? `, troco para ${reais(p.troco_para_centavos)} (levar ${reais(p.troco_para_centavos - p.total_centavos)})` : ''}</dd></div>`}
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
export async function pedirCancelamento(id) {
  // Pedido do iFood: o iFood diz quais motivos aceita para ESTE pedido (GET cancellationReasons) e a pessoa escolhe um da lista.
  let motivosIfood = null;
  if (fonte.pedidos.get(id)?.ifood) {
    try {
      const r = await api(`/api/admin/pedidos/${id}/ifood/motivos`);
      motivosIfood = r.motivos?.length ? r.motivos : null;
    } catch { /* sem a lista do iFood: cai nos motivos de sempre e o servidor escolhe um código válido */ }
  }
  const opcoes = motivosIfood ? motivosIfood.map((m) => ({ valor: m.codigo, rotulo: m.descricao })) : MOTIVOS.map((m) => ({ valor: m, rotulo: m }));
  dialogo.innerHTML = `
    <header class="folha__topo">
      <h2 class="folha__titulo" id="folha-dialogo-titulo">Cancelar o pedido #${id}?</h2>
      <button type="button" class="botao botao--fantasma botao--icone" data-fechar aria-label="Fechar">${icone('fechar')}</button>
    </header>
    <form class="folha__corpo cancelar" id="form-cancelar" novalidate>
      <fieldset class="grupo-opcoes">
        <legend class="campo__rotulo">${motivosIfood ? 'Motivos aceitos pelo iFood para este pedido. O cliente vê este motivo.' : 'Por quê? O cliente vê este motivo.'}</legend>
        ${opcoes.map((op) => `
          <label class="opcao">
            <input class="opcao__entrada" type="radio" name="motivo" value="${esc(op.valor)}">
            <span class="opcao__marca">${icone('check', 14)}</span>
            <span class="opcao__corpo"><span class="opcao__titulo">${esc(op.rotulo)}</span></span>
          </label>`).join('')}
      </fieldset>
      ${motivosIfood ? '' : `
      <label class="campo">
        <span class="campo__rotulo">Outro motivo</span>
        <input class="entrada" type="text" name="outro" maxlength="200" autocomplete="off">
      </label>`}
      <p class="campo__erro" id="erro-motivo" role="alert" hidden>Escolha ${motivosIfood ? 'um dos motivos' : 'ou escreva o motivo'}.</p>
    </form>
    <footer class="folha__rodape folha__rodape--acoes">
      <button type="button" class="botao botao--secundario" data-fechar>Manter o pedido</button>
      <button type="submit" form="form-cancelar" class="botao botao--perigo">Cancelar pedido</button>
    </footer>`;
  const form = $('#form-cancelar');
  form.outro?.addEventListener('input', () => {
    if (form.outro.value.trim()) form.querySelectorAll('[name="motivo"]').forEach((r) => (r.checked = false));
  });
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const escolhido = form.motivo.value;
    const motivo = motivosIfood ? opcoes.find((op) => op.valor === escolhido)?.rotulo : (form.outro?.value.trim() || escolhido);
    if (!motivo) {
      $('#erro-motivo').hidden = false;
      return;
    }
    const botao = dialogo.querySelector('button[type="submit"]');
    botao.setAttribute('aria-busy', 'true');
    const pedido = await fonte.mudarStatus(id, 'cancelado', motivo, motivosIfood ? escolhido : null);
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
        avisar(atualizado.agente_offline ? 'Comanda na fila: o agente de impressão está desligado' : 'Comanda enviada para a impressora');
      }
      desenhar();
      break;
    }
    case 'cancelar':
      pedirCancelamento(p.id);
      break;
  }
});
