// Vocabulário do pedido, usado no cardápio e no painel: etapas, rótulos e textos de montagem.
// (O servidor tem a mesma tabela em app/Services/PedidoService.php e é ele quem valida.)
import { esc } from './dom.js';
import { reais } from './formato.js';

/** Caminho de etapas de cada tipo de pedido. */
export const FLUXOS = {
  entrega: ['novo', 'em_preparo', 'pronto', 'saiu_entrega', 'concluido'],
  retirada: ['novo', 'em_preparo', 'pronto', 'aguardando_retirada', 'concluido'],
  local: ['novo', 'em_preparo', 'pronto', 'concluido'],
};

export const STATUS = {
  novo: { rotulo: 'Novo', cliente: 'Recebemos seu pedido' },
  em_preparo: { rotulo: 'Em preparo', cliente: 'Seu pedido está em preparo' },
  pronto: { rotulo: 'Pronto', cliente: 'Seu pedido está pronto' },
  saiu_entrega: { rotulo: 'Saiu para entrega', cliente: 'Seu pedido saiu para entrega' },
  aguardando_retirada: { rotulo: 'Aguardando retirada', cliente: 'Pode vir buscar: está no balcão' },
  concluido: { rotulo: 'Concluído', cliente: 'Pedido concluído. Bom apetite!' },
  cancelado: { rotulo: 'Cancelado', cliente: 'Pedido cancelado' },
};

/** Texto do botão que leva o pedido para a etapa `para`. O botão diz a ação, não "Avançar". */
export const ACAO_PARA = {
  em_preparo: 'Iniciar preparo',
  pronto: 'Marcar pronto',
  saiu_entrega: 'Saiu para entrega',
  aguardando_retirada: 'Chamar para retirada',
  concluido: 'Concluir',
};

export const TIPOS = {
  entrega: { rotulo: 'Entrega', icone: 'moto' },
  retirada: { rotulo: 'Retirada', icone: 'loja' },
  local: { rotulo: 'No local', icone: 'talheres' },
};

export const ORIGENS = { cardapio: 'Cardápio', whatsapp: 'WhatsApp', balcao: 'Balcão', ifood: 'iFood' };
export const PAGAMENTOS = { pix: 'Pix', cartao: 'Cartão', dinheiro: 'Dinheiro' };

export const encerrado = (status) => status === 'concluido' || status === 'cancelado';

/** Próxima etapa do pedido (ou null se não há). */
export function proximaEtapa(pedido) {
  const fluxo = FLUXOS[pedido.tipo] ?? [];
  const i = fluxo.indexOf(pedido.status);
  return i >= 0 && i < fluxo.length - 1 ? fluxo[i + 1] : null;
}

/** Etapa anterior (para "voltar etapa"). */
export function etapaAnterior(pedido) {
  const fluxo = FLUXOS[pedido.tipo] ?? [];
  const i = fluxo.indexOf(pedido.status);
  return i > 0 ? fluxo[i - 1] : null;
}

/** "Médio · Penne · Bolonhesa" */
export function resumoMontagem(m) {
  return m ? [m.tamanho?.nome, m.massa?.nome, m.molho?.nome].filter(Boolean).join(' · ') : '';
}

/** Linhas da montagem completa de um item (HTML já escapado), para revisão, detalhe e acompanhamento. */
export function linhasMontagem(m, { comPrecos = true } = {}) {
  if (!m) return '';
  const nomes = (lista) => lista.map((i) => esc(i.nome)).join(', ');
  const extras = m.extras.map((i) => esc(i.nome) + (comPrecos ? ` <span class="numero">(+ ${reais(i.preco_extra_centavos)})</span>` : '')).join(', ');
  return [
    `<li><span>Tamanho</span> ${esc(m.tamanho.nome)}${m.tamanho.descricao ? ` (${esc(m.tamanho.descricao)})` : ''}</li>`,
    `<li><span>Massa</span> ${esc(m.massa.nome)}</li>`,
    `<li><span>Molho</span> ${esc(m.molho.nome)}</li>`,
    m.inclusos.length ? `<li><span>Ingredientes</span> ${nomes(m.inclusos)}</li>` : '',
    m.extras.length ? `<li><span>Extras</span> ${extras}</li>` : '',
    !m.inclusos.length && !m.extras.length ? '<li><span>Ingredientes</span> Nenhum</li>' : '',
    `<li><span>Queijo ralado</span> ${m.queijo_ralado ? 'Sim' : 'Não'}</li>`,
  ].join('');
}
