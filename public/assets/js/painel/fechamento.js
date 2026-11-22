// Fechamento do dia (D101, simplificado na D104): calendário do mês com o estado de cada dia e, ao abrir um dia, o que ele
// vendeu por forma de pagamento (valor e quantidade) e o caixa inicial. Reabrir um dia não apaga o fechamento: fica no histórico.
import { $, esc, delegar } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { reais, dataHora, plural, centavosDeTexto, textoDeCentavos } from '../shared/formato.js';
import { api } from '../shared/api.js';
import { avisar } from '../shared/avisos.js';
import { abrirFolha, fecharFolha } from '../shared/folha.js';
import { recipienteDaTela, mostrarErro } from './casca.js';

const conteudo = recipienteDaTela();
const lateral = document.getElementById('folha-lateral');
const SEMANA = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
const ESTADOS = {
  fechado: ['✓', 'Fechado', 'selo--sucesso'],
  pendente: ['!', 'Pendente', 'selo--alerta'],
  hoje: ['●', 'Hoje', 'selo--acento'],
};

let mes = '';
let calendario = null;
let dia = null;   // o dia aberto na lateral

const dataDe = (data, opcoes) => new Intl.DateTimeFormat('pt-BR', { timeZone: 'UTC', ...opcoes }).format(new Date(`${data}T12:00:00Z`));
const dataLonga = (data) => dataDe(data, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
const dataCurta = (data) => dataDe(data, { day: '2-digit', month: '2-digit' });
const tituloMes = (m) => { const t = dataDe(`${m}-01`, { month: 'long', year: 'numeric' }); return t.charAt(0).toUpperCase() + t.slice(1); };

// ---------- Calendário ----------
async function carregar() {
  try {
    calendario = await api(`/api/admin/fechamento${mes ? `?mes=${mes}` : ''}`);
    mes = calendario.mes;
    desenhar();
  } catch (erro) {
    mostrarErro(erro);
  }
}

function desenhar() {
  const c = calendario;
  const vazios = c.dias[0].semana;
  conteudo.innerHTML = `
    <div class="cabecalho-tela">
      <div>
        <h1 class="cabecalho-tela__titulo">Fechamento do dia</h1>
        <p class="cabecalho-tela__apoio">O que cada dia vendeu por forma de pagamento e o caixa inicial.</p>
      </div>
      <button type="button" class="botao botao--g" data-dia="${c.hoje}">${icone('dinheiro', 18)}Fechar o caixa de hoje</button>
    </div>
    ${c.pendentes.length ? `
      <div class="faixa-aviso" role="status">
        ${icone('alerta', 20)}
        <p><strong>${plural(c.pendentes.length, 'dia sem fechamento', 'dias sem fechamento')}.</strong>
          Mais antigos: ${c.pendentes.slice(0, 5).map((d) => `<button type="button" class="link" data-dia="${d}">${dataCurta(d)}</button>`).join(', ')}${c.pendentes.length > 5 ? ' e outros.' : '.'}</p>
      </div>` : ''}
    <section class="bloco" aria-labelledby="titulo-mes">
      <div class="vendas__cab">
        <h2 class="bloco__titulo" id="titulo-mes">${esc(tituloMes(c.mes))}</h2>
        <nav class="impressora__acoes" aria-label="Navegar entre meses">
          <button type="button" class="botao botao--secundario botao--p" data-mes="${c.anterior}">${icone('seta-esq', 16)}Anterior</button>
          <button type="button" class="botao botao--secundario botao--p" data-mes="${c.hoje.slice(0, 7)}">Este mês</button>
          ${c.proximo ? `<button type="button" class="botao botao--secundario botao--p" data-mes="${c.proximo}">Próximo${icone('seta-dir', 16)}</button>` : ''}
        </nav>
      </div>
      <div class="calendario" role="grid" aria-label="${esc(tituloMes(c.mes))}">
        ${SEMANA.map((s) => `<div class="calendario__semana" role="columnheader">${s}</div>`).join('')}
        ${'<div class="dia dia--fora" aria-hidden="true"></div>'.repeat(vazios)}
        ${c.dias.map(diaHtml).join('')}
      </div>
      <ul class="legenda-estados" aria-label="Legenda">
        <li><span class="selo selo--sucesso">✓ Fechado</span> caixa conferido</li>
        <li><span class="selo selo--alerta">! Pendente</span> dia passado sem fechamento</li>
        <li><span class="selo selo--acento">● Hoje</span> caixa aberto</li>
        <li><span class="selo">Futuro</span></li>
      </ul>
      <p class="campo__ajuda">Um dia só fica pendente a partir da primeira venda do sistema; antes dela não havia caixa para fechar. O valor de cada dia é o que ele vendeu (no dia fechado, o valor do fechamento).</p>
    </section>`;
}

function diaHtml(d) {
  const [simbolo, rotulo] = ESTADOS[d.estado] ?? [];
  const miolo = `<span class="dia__numero">${d.dia}</span>
    ${rotulo ? `<span class="dia__estado"><span aria-hidden="true">${simbolo}</span> ${rotulo}</span>` : ''}
    ${d.valor_centavos !== null ? `<span class="dia__valor">${reais(d.valor_centavos)}</span>` : ''}`;
  const nome = `${dataLonga(d.data)}${rotulo ? `, ${rotulo.toLowerCase()}` : ''}`;
  return d.estado === 'futuro'
    ? `<div class="dia dia--futuro" role="gridcell" aria-label="${esc(nome)}, dia futuro">${miolo}</div>`
    : `<button type="button" class="dia dia--${d.estado}" role="gridcell" data-dia="${d.data}" aria-label="${esc(nome)}">${miolo}</button>`;
}

// ---------- O dia (painel lateral) ----------
async function abrirDia(data) {
  try {
    dia = await api(`/api/admin/fechamento/${data}`);
  } catch (erro) {
    mostrarErro(erro);
    return;
  }
  desenharDia();
  if (!lateral.open) abrirFolha(lateral, { aoFechar: () => (dia = null) });
}

function desenharDia() {
  const d = dia;
  const [simbolo, rotulo, classe] = ESTADOS[d.estado] ?? ['', 'Futuro', ''];
  let corpo;
  if (d.estado === 'futuro') {
    corpo = `<p class="bloco__nota">${icone('info', 16)}<span>O caixa só pode ser fechado em dias que já começaram.</span></p>`;
  } else {
    const r = d.resumo;
    corpo = `
      <div class="caixa__resumo">
        <div class="mini-kpi"><p class="mini-kpi__rotulo">Faturamento do dia</p><p class="mini-kpi__valor">${reais(r.faturamento_centavos)}</p></div>
        <div class="mini-kpi"><p class="mini-kpi__rotulo">Vendas</p><p class="mini-kpi__valor">${r.vendas}</p></div>
      </div>
      <dl class="caixa__formas" aria-label="Por forma de pagamento">
        ${r.por_pagamento.map((p) => `<div><dt>${icone(p.chave, 16)}${esc(p.rotulo)} <span class="campo__ajuda">${plural(p.vendas, 'venda', 'vendas')}</span></dt><dd>${reais(p.total_centavos)}</dd></div>`).join('')}
      </dl>
      ${d.fechamento ? fechadoHtml(d.fechamento) : formularioHtml(d)}
      ${d.historico.length ? `
        <details class="caixa__historico">
          <summary>${plural(d.historico.length, 'fechamento reaberto neste dia', 'fechamentos reabertos neste dia')}</summary>
          <ul>${d.historico.map((h) => `<li>Fechado em ${dataHora(h.fechado_em)} (caixa inicial ${reais(h.caixa_inicial_centavos)}), reaberto em ${dataHora(h.cancelado_em)}${h.motivo_cancelamento ? `: ${esc(h.motivo_cancelamento)}` : ''}.</li>`).join('')}</ul>
        </details>` : ''}`;
  }
  lateral.innerHTML = `
    <header class="folha__topo">
      <div>
        <p class="caixa__sobretitulo">Fechamento do caixa</p>
        <h2 class="folha__titulo" id="folha-lateral-titulo">${esc(dataLonga(d.data))}</h2>
      </div>
      <span class="selo ${classe}">${simbolo} ${d.estado === 'hoje' ? 'Caixa aberto' : rotulo}</span>
      <button type="button" class="botao botao--fantasma botao--icone" data-fechar aria-label="Fechar">${icone('fechar')}</button>
    </header>
    <div class="folha__corpo caixa">${corpo}</div>`;
}

function fechadoHtml(f) {
  return `
    <dl class="gaveta" aria-label="Caixa">
      <div class="gaveta__linha gaveta__linha--total"><dt>Caixa inicial</dt><dd>${reais(f.caixa_inicial_centavos)}</dd></div>
    </dl>
    <p class="campo__ajuda">Fechado por <strong>${esc(f.fechado_por_nome ?? 'alguém')}</strong> em ${dataHora(f.fechado_em)}.${f.observacao ? `<br>Observação: ${esc(f.observacao)}` : ''}</p>
    <form class="caixa__reabrir" id="form-reabrir">
      <label class="campo"><span class="campo__rotulo">Reabrir o dia <span class="campo__opcional">(o fechamento fica no histórico)</span></span>
        <input class="entrada" name="motivo" maxlength="200" placeholder="Motivo (opcional)"></label>
      <button type="submit" class="botao botao--perigo-suave">Reabrir o dia</button>
    </form>`;
}

function formularioHtml(d) {
  const sugestao = d.anterior ? textoDeCentavos(d.anterior.caixa_inicial_centavos) : '';
  return `
    <form class="caixa__form" id="form-caixa" novalidate>
      <div class="gaveta gaveta--form">
        <label class="gaveta__campo"><span>Caixa inicial<span class="campo__ajuda">${d.anterior ? `No fechamento de ${dataCurta(d.anterior.data)}: ${reais(d.anterior.caixa_inicial_centavos)}.` : 'Quanto havia na gaveta ao abrir.'}</span></span>
          <input class="entrada" name="caixa_inicial" inputmode="decimal" value="${sugestao}" placeholder="0,00" aria-describedby="erro-caixa_inicial_centavos"></label>
      </div>
      <span class="campo__erro" id="erro-caixa_inicial_centavos" hidden></span>
      <div class="caixa__acoes">
        <button type="button" class="botao botao--secundario" data-fechar>Cancelar</button>
        <button type="submit" class="botao">Fechar o caixa</button>
      </div>
    </form>`;
}

delegar(lateral, 'focusout', '#form-caixa input[inputmode="decimal"]', (e, entrada) => {
  const valor = centavosDeTexto(entrada.value);
  if (valor !== null) entrada.value = textoDeCentavos(valor);
});
lateral.addEventListener('submit', async (e) => {
  if (!dia || !['form-caixa', 'form-reabrir'].includes(e.target.id)) return;
  e.preventDefault();
  const form = e.target;
  const botao = form.querySelector('[type="submit"]');
  botao.setAttribute('aria-busy', 'true');
  try {
    if (form.id === 'form-reabrir') {
      dia = await api(`/api/admin/fechamento/${dia.fechamento.id}/reabrir`, { metodo: 'POST', corpo: { motivo: form.motivo.value } });
      avisar('Dia reaberto');
    } else {
      dia = await api(`/api/admin/fechamento/${dia.data}`, { metodo: 'POST', corpo: {
        caixa_inicial_centavos: centavosDeTexto(form.caixa_inicial.value || '0'),
      } });
      avisar('Caixa fechado');
    }
    desenharDia();
    carregar();
  } catch (erro) {
    botao.removeAttribute('aria-busy');
    if (erro.codigo === 'validacao') {
      for (const [campo, mensagem] of Object.entries(erro.campos)) {
        const el = $(`#erro-${campo}`, lateral);
        if (el) { el.hidden = false; el.textContent = mensagem; } else avisar(mensagem, { tipo: 'erro' });
      }
    } else {
      avisar(erro.message, { tipo: 'erro' });
    }
  }
});

delegar(conteudo, 'click', '[data-dia]', (e, botao) => abrirDia(botao.dataset.dia));
delegar(conteudo, 'click', '[data-mes]', (e, botao) => {
  mes = botao.dataset.mes;
  carregar();
});

export async function montar() {
  await carregar();
}

export function desmontar() {
  if (lateral.open) fecharFolha(lateral);
}
