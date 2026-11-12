// Histórico de atendimentos (só o proprietário): todas as contas do período, abertas e encerradas, com a forma de pagamento,
// quem abriu e quem fechou, e o total cobrado por forma de pagamento. O detalhe abre em modo leitura (sem botões de ação).
import { $, esc, delegar } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { reais, dataHora, plural } from '../shared/formato.js';
import { api } from '../shared/api.js';
import { MODALIDADES, FORMAS_DE_PAGAMENTO, rotuloDaModalidade } from '../shared/atendimento.js';
import { recipienteDaTela, mostrarErro } from './casca.js';
import { abrirConta } from './salao-conta.js';

const raiz = recipienteDaTela();   // o contêiner desta tela (criado pelo roteador)

const PERIODOS = { hoje: 'Hoje', '7d': 'Últimos 7 dias', '30d': 'Últimos 30 dias' };
const STATUS = { '': 'Todos', encerrado: 'Encerrados', aberto: 'Em aberto' };

let filtro = { periodo: 'hoje', status: '' };
let dados = null;
let carregando = false;

function estruturaHtml() {
  return `
    <div class="cabecalho-tela">
      <div>
        <h1 class="cabecalho-tela__titulo">Histórico</h1>
        <p class="cabecalho-tela__apoio">Todas as contas do salão, com o pagamento de cada uma.</p>
      </div>
    </div>
    <div class="historico-filtros">
      <div class="abas" role="group" aria-label="Período">${Object.entries(PERIODOS).map(([valor, rotulo]) => `<button type="button" class="chip" data-periodo="${valor}">${rotulo}</button>`).join('')}</div>
      <div class="abas" role="group" aria-label="Situação da conta">${Object.entries(STATUS).map(([valor, rotulo]) => `<button type="button" class="chip" data-status="${valor}">${rotulo}</button>`).join('')}</div>
    </div>
    <div id="historico-resumo" aria-live="polite"></div>
    <div id="historico-lista"><div class="esqueleto esqueleto--cartao" style="height: 12rem;"></div></div>`;
}

function marcarFiltros() {
  for (const botao of raiz.querySelectorAll('[data-periodo]')) botao.setAttribute('aria-pressed', String(botao.dataset.periodo === filtro.periodo));
  for (const botao of raiz.querySelectorAll('[data-status]')) botao.setAttribute('aria-pressed', String(botao.dataset.status === filtro.status));
}

function resumoHtml(r) {
  return `
    <section class="fechamento" aria-label="Resumo do período">
      <h2 class="fechamento__titulo">${PERIODOS[dados.periodo]}</h2>
      <dl class="fechamento__lista">
        <div><dt>Contas</dt><dd>${r.quantidade}</dd></div>
        <div><dt>Encerradas</dt><dd>${r.encerrados}</dd></div>
        ${r.abertos ? `<div><dt>Em aberto</dt><dd>${r.abertos}</dd></div>` : ''}
        <div><dt>Total cobrado</dt><dd>${reais(r.total_cobrado_centavos)}</dd></div>
        ${Object.entries(FORMAS_DE_PAGAMENTO).map(([forma, rotulo]) => `<div><dt>${rotulo}</dt><dd>${reais(r.por_pagamento[forma] ?? 0)}</dd></div>`).join('')}
      </dl>
    </section>`;
}

function linhaHtml(a) {
  const aberta = a.status === 'aberto';
  return `
    <li>
      <button type="button" class="historico-conta" data-id="${a.id}" aria-label="Ver a conta de ${esc(a.cliente_nome)}">
        <span class="historico-conta__cliente">
          <strong>${esc(a.cliente_nome)}</strong>
          <small>Atendimento ${a.id} · ${icone(MODALIDADES[a.modalidade].icone, 14)}${esc(rotuloDaModalidade(a))}</small>
        </span>
        <span class="historico-conta__quando numero">
          ${aberta ? `aberto ${dataHora(a.aberto_em)}` : dataHora(a.encerrado_em)}
          <small>${aberta ? '' : `aberto ${dataHora(a.aberto_em)}`}</small>
        </span>
        <span class="historico-conta__itens">${plural(a.quantidade_itens, 'item', 'itens')} · ${plural(a.pedidos_ativos, 'pedido', 'pedidos')}</span>
        <span class="historico-conta__pago">
          ${aberta ? '<span class="selo selo--alerta">Em aberto</span>' : `<span class="selo">${a.pagamento ? FORMAS_DE_PAGAMENTO[a.pagamento] : 'Sem cobrança'}</span>`}
        </span>
        <strong class="historico-conta__valor numero">${reais(a.total_centavos)}</strong>
      </button>
    </li>`;
}

function desenhar() {
  marcarFiltros();
  if (!dados) return;
  $('#historico-resumo', raiz).innerHTML = resumoHtml(dados.resumo);
  $('#historico-lista', raiz).innerHTML = dados.atendimentos.length === 0
    ? `<div class="vazio"><span class="vazio__icone">${icone('livro', 40)}</span><p class="vazio__titulo">Nenhuma conta neste período</p><p>Tente outro período ou outra situação.</p></div>`
    : `<ul class="historico-contas">${dados.atendimentos.map(linhaHtml).join('')}</ul>`;
}

async function carregar() {
  if (carregando) return;
  carregando = true;
  raiz.setAttribute('aria-busy', 'true');
  try {
    const consulta = new URLSearchParams({ periodo: filtro.periodo });
    if (filtro.status) consulta.set('status', filtro.status);
    dados = await api(`/api/admin/historico?${consulta}`);
  } catch (erro) {
    mostrarErro(erro);
  } finally {
    carregando = false;
    raiz.removeAttribute('aria-busy');
  }
  desenhar();
}

delegar(raiz, 'click', '[data-periodo]', (e, botao) => {
  filtro.periodo = botao.dataset.periodo;
  carregar();
});
delegar(raiz, 'click', '[data-status]', (e, botao) => {
  filtro.status = botao.dataset.status;
  carregar();
});
delegar(raiz, 'click', '.historico-conta', (e, botao) => abrirConta(Number(botao.dataset.id), { somenteLeitura: true, aoMudar: carregar }));

export async function montar() {
  raiz.innerHTML = estruturaHtml();
  dados = null;
  marcarFiltros();
  await carregar();
}
