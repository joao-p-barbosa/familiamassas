// Acompanhamento do pedido: mostra a etapa atual e consulta o servidor a cada 10 s até o pedido encerrar.
import { $, esc, dadosDaPagina, guardado } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { reais, hora } from '../shared/formato.js';
import { api } from '../shared/api.js';
import { STATUS, PAGAMENTOS, encerrado, linhasMontagem } from '../shared/pedido.js';

const INTERVALO = 10_000;
const raiz = $('#acompanhar');
const { pedido: inicial, loja } = dadosDaPagina('dados-pedido');
let pedido = inicial;
let semConexao = false;
let itensAbertos = false;

function previsao() {
  if (encerrado(pedido.status) || pedido.status === 'pronto' || pedido.status === 'aguardando_retirada') return '';
  const ate = new Date(new Date(pedido.criado_em).getTime() + pedido.previsao_minutos * 60_000);
  const oQue = { entrega: 'Chega', retirada: 'Fica pronto', local: 'Vai para a mesa' }[pedido.tipo];
  return `${oQue} por volta das ${hora(ate.toISOString())}.`;
}

function desenhar() {
  const cancelado = pedido.status === 'cancelado';
  const atual = pedido.status;
  const zap = loja.whatsapp
    ? `https://wa.me/${encodeURIComponent(loja.whatsapp)}?text=${encodeURIComponent(`Olá! É sobre o pedido #${pedido.numero}.`)}`
    : null;

  raiz.innerHTML = `
    <p class="acompanhar__numero">Pedido #${pedido.numero} · ${esc(pedido.primeiro_nome)}</p>
    <h1 class="acompanhar__titulo">${esc(STATUS[atual].cliente)}</h1>
    ${previsao() ? `<p class="acompanhar__previsao">${previsao()}</p>` : ''}
    ${pedido.status === 'aguardando_retirada' && loja.endereco ? `<p class="acompanhar__previsao">${esc(loja.endereco)}</p>` : ''}

    ${cancelado ? `<p class="acompanhar__cancelado">${pedido.motivo_cancelamento ? `Motivo: ${esc(pedido.motivo_cancelamento)}` : 'A loja cancelou este pedido.'}</p>` : `
    <section class="bloco" aria-label="Etapas do pedido">
      <ol class="etapas">
        ${pedido.etapas.map((etapa) => `
          <li class="etapas__item" ${etapa.feita ? 'data-feita' : ''} ${etapa.status === atual ? 'data-atual aria-current="step"' : ''}>
            <span class="etapas__marca">${icone('check', 14)}</span>
            <span class="etapas__rotulo">${esc(etapa.rotulo)}</span>
            <span class="etapas__hora numero">${etapa.em ? hora(etapa.em) : ''}</span>
          </li>`).join('')}
      </ol>
    </section>`}

    <details class="bloco" ${itensAbertos ? 'open' : ''}>
      <summary><span>Itens do pedido · <span class="numero">${reais(pedido.total_centavos)}</span></span>${icone('divisa-dir', 20)}</summary>
      <ul class="revisao-itens">
        ${pedido.itens.map((item) => `
          <li class="revisao-itens__item">
            <div class="revisao-itens__topo">
              <p><span class="numero">${item.quantidade}×</span> ${esc(item.nome)}</p>
              <p class="numero">${reais(item.preco_unit_centavos * item.quantidade)}</p>
            </div>
            ${item.montagem ? `<ul class="montagem">${linhasMontagem(item.montagem)}</ul>` : ''}
            ${item.observacoes ? `<p class="revisao-itens__obs">Obs.: ${esc(item.observacoes)}</p>` : ''}
          </li>`).join('')}
      </ul>
      <dl class="totais">
        <div><dt>Subtotal</dt><dd class="numero">${reais(pedido.subtotal_centavos)}</dd></div>
        ${pedido.tipo === 'entrega' ? `<div><dt>Taxa de entrega</dt><dd class="numero">${pedido.taxa_entrega_centavos ? reais(pedido.taxa_entrega_centavos) : 'Grátis'}</dd></div>` : ''}
        <div class="totais__total"><dt>Total · ${PAGAMENTOS[pedido.pagamento]}</dt><dd class="numero">${reais(pedido.total_centavos)}</dd></div>
      </dl>
    </details>

    <div class="acompanhar__acoes">
      ${zap ? `<a class="botao botao--secundario botao--g" href="${esc(zap)}" target="_blank" rel="noopener">${icone('conversa', 20)}Falar com a loja no WhatsApp</a>` : ''}
      <a class="botao ${encerrado(atual) ? '' : 'botao--fantasma'} botao--g" href="/">${encerrado(atual) ? 'Fazer outro pedido' : 'Voltar ao cardápio'}</a>
    </div>
    ${encerrado(atual) ? '' : `<p class="acompanhar__vivo" role="status">${icone(semConexao ? 'alerta' : 'recarregar', 16)}<span>${semConexao ? 'Sem conexão. Tentando de novo…' : 'Esta página atualiza sozinha.'}</span></p>`}`;

  document.title = `Pedido #${pedido.numero} · ${pedido.rotulo}`;
}

raiz.addEventListener('toggle', (e) => {
  if (e.target instanceof HTMLDetailsElement) itensAbertos = e.target.open;
}, true);

async function consultar() {
  if (document.visibilityState !== 'visible' || encerrado(pedido.status)) return;
  try {
    const novo = await api(`/api/pedidos/${encodeURIComponent(pedido.codigo)}`);
    const mudou = novo.status !== pedido.status || semConexao;
    pedido = novo;
    semConexao = false;
    if (mudou) desenhar();
    if (encerrado(pedido.status)) guardado.apagar('fm_ultimo_pedido');
  } catch {
    if (!semConexao) {
      semConexao = true;
      desenhar();
    }
  }
}

if (!encerrado(pedido.status)) guardado.gravar('fm_ultimo_pedido', { codigo: pedido.codigo, numero: pedido.numero });
desenhar();
setInterval(consultar, INTERVALO);
document.addEventListener('visibilitychange', consultar);
