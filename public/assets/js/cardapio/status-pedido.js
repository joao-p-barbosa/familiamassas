// O que a Home e a página de acompanhamento mostram igual sobre um pedido: previsão e trilha de etapas.
import { hora } from '../shared/formato.js';
import { encerrado } from '../shared/pedido.js';

/** "Chega por volta das 20:15." (vazio quando o pedido já está pronto, encerrado ou não tem previsão) */
export function previsaoDoPedido(pedido) {
  if (encerrado(pedido.status) || pedido.status === 'pronto' || pedido.status === 'aguardando_retirada') return '';
  const ate = new Date(new Date(pedido.criado_em).getTime() + pedido.previsao_minutos * 60_000);
  const oQue = { entrega: 'Chega', retirada: 'Fica pronto' }[pedido.tipo] ?? 'Fica pronto';
  return `${oQue} por volta das ${hora(ate.toISOString())}.`;
}

/** Um segmento por etapa do tipo de pedido; o da etapa atual pulsa. Decorativa: o texto ao lado diz a mesma coisa. */
export function trilhaHtml(pedido) {
  if (pedido.status === 'cancelado') return '';
  return `<div class="trilha" aria-hidden="true">${pedido.etapas.map((etapa) =>
    `<span class="trilha__passo" ${etapa.feita ? 'data-feita' : ''} ${etapa.status === pedido.status && !encerrado(pedido.status) ? 'data-atual' : ''}></span>`).join('')}</div>`;
}

/** "Etapa 2 de 5" (null quando o pedido foi cancelado). */
export function posicaoDoPedido(pedido) {
  if (pedido.status === 'cancelado') return null;
  const atual = pedido.etapas.findIndex((etapa) => etapa.status === pedido.status);
  return atual < 0 ? null : { atual: atual + 1, total: pedido.etapas.length, faltam: pedido.etapas.length - atual - 1 };
}
