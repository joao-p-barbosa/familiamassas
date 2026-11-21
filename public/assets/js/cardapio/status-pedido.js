// O que a Home e a página de acompanhamento mostram igual sobre um pedido: previsão e trilha de etapas.
import { hora } from '../shared/formato.js';
import { encerrado } from '../shared/pedido.js';

/**
 * "Fica pronto por volta das 20:15." A previsão é só de PREPARO (D100): a hora de chegada na casa do cliente não é conhecida.
 * Some quando o pedido sai da cozinha (a etapa "Saiu para entrega" diz o resto) ou quando não há previsão.
 */
export function previsaoDoPedido(pedido) {
  if (!['novo', 'em_preparo'].includes(pedido.status) || !pedido.previsao_minutos) return '';
  const ate = new Date(new Date(pedido.criado_em).getTime() + pedido.previsao_minutos * 60_000);
  return `Fica pronto por volta das ${hora(ate.toISOString())}${pedido.tipo === 'entrega' ? ' e então sai para entrega' : ''}.`;
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
