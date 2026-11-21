// Vendas (D101), adaptada do Cantinho de Sabores: indicadores do período, quando mais vende (dia × hora), ticket médio por canal
// ou pagamento e os lançamentos, com cancelamento total ou parcial. Venda = pedido concluído (cardápio, WhatsApp, iFood) ou
// conta do Salão encerrada. Os números vêm prontos do servidor (GET /api/admin/vendas).
import { $, $$, esc, delegar } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { reais, dataHora, plural } from '../shared/formato.js';
import { api } from '../shared/api.js';
import { avisar } from '../shared/avisos.js';
import { abrirFolha, fecharFolha } from '../shared/folha.js';
import { recipienteDaTela, mostrarErro } from './casca.js';

const conteudo = recipienteDaTela();
const dialogo = document.getElementById('folha-dialogo');

const PERIODOS = [['hoje', 'Hoje'], ['ontem', 'Ontem'], ['7', '7 dias'], ['30', '30 dias'], ['mes', 'Este mês'], ['outro', 'Outro período']];
const SITUACAO = { ativa: ['selo--sucesso', 'Ativa'], parcial: ['selo--alerta', 'Cancelada em parte'], cancelada: ['selo--perigo', 'Cancelada'] };

let periodo = 'hoje';
let datas = { de: '', ate: '' };
let filtros = { canal: '', pagamento: '', situacao: '' };
let pagina = 1;
let agruparPor = 'canal';
let dados = null;
let cancelando = null;   // a venda aberta no diálogo de cancelamento

// ---------- Datas ----------
const somarDias = (data, n) => {
  const d = new Date(`${data}T12:00:00Z`);
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
};
function intervalo(chave, hoje) {
  switch (chave) {
    case 'ontem': return { de: somarDias(hoje, -1), ate: somarDias(hoje, -1) };
    case '7': return { de: somarDias(hoje, -6), ate: hoje };
    case '30': return { de: somarDias(hoje, -29), ate: hoje };
    case 'mes': return { de: `${hoje.slice(0, 8)}01`, ate: hoje };
    case 'outro': return datas;
    default: return { de: hoje, ate: hoje };
  }
}
const dataCurta = (data) => new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${data}T12:00:00Z`));

// ---------- Carregar ----------
async function carregar() {
  const consulta = new URLSearchParams({ ...datas, ...filtros, pagina: String(pagina) });
  for (const [chave, valor] of [...consulta]) if (!valor) consulta.delete(chave);
  try {
    dados = await api(`/api/admin/vendas?${consulta}`);
    datas = { ...dados.periodo };
    desenhar();
  } catch (erro) {
    if (erro.codigo === 'validacao') avisar(Object.values(erro.campos)[0], { tipo: 'erro' });
    else mostrarErro(erro);
  }
}

// ---------- Desenho ----------
function desenhar() {
  const d = dados;
  const r = d.resumo;
  const semVendas = r.vendas === 0;
  const textoPeriodo = d.periodo.de === d.periodo.ate ? dataCurta(d.periodo.de) : `${dataCurta(d.periodo.de)} a ${dataCurta(d.periodo.ate)}`;
  conteudo.innerHTML = `
    <div class="cabecalho-tela">
      <div>
        <h1 class="cabecalho-tela__titulo">Vendas</h1>
        <p class="cabecalho-tela__apoio">${esc(textoPeriodo)}</p>
      </div>
    </div>
    <form class="vendas__periodo" id="form-periodo">
      <fieldset class="segmentado" aria-label="Período">
        ${PERIODOS.map(([chave, rotulo]) => `<label class="segmentado__item"><input type="radio" name="periodo" value="${chave}" ${periodo === chave ? 'checked' : ''}><span>${rotulo}</span></label>`).join('')}
      </fieldset>
      <div class="vendas__datas" ${periodo === 'outro' ? '' : 'hidden'}>
        <label class="campo"><span class="campo__rotulo">De</span><input class="entrada" type="date" name="de" value="${esc(datas.de)}" max="${esc(d.hoje)}"></label>
        <label class="campo"><span class="campo__rotulo">Até</span><input class="entrada" type="date" name="ate" value="${esc(datas.ate)}" max="${esc(d.hoje)}"></label>
        <button type="submit" class="botao botao--secundario">Ver</button>
      </div>
    </form>

    <section class="indicadores" aria-label="Resumo do período">
      <article class="ind ind--destaque">
        <p class="ind__rotulo">${icone('dinheiro', 16)}Faturamento</p>
        <p class="ind__valor">${reais(r.faturamento_centavos)}</p>
        <p class="ind__sub">${r.canceladas ? `${plural(r.canceladas, 'venda cancelada', 'vendas canceladas')} (${reais(r.cancelado_centavos)})` : 'Nenhum cancelamento'}</p>
      </article>
      <article class="ind">
        <p class="ind__rotulo">${icone('sacola', 16)}Vendas</p>
        <p class="ind__valor">${r.vendas}</p>
        <p class="ind__sub">${semVendas ? 'Nenhuma venda no período' : plural(r.itens, 'item vendido', 'itens vendidos')}</p>
      </article>
      <article class="ind">
        <p class="ind__rotulo">${icone('relogio', 16)}Ticket médio</p>
        <p class="ind__valor">${r.ticket_centavos === null ? '—' : reais(r.ticket_centavos)}</p>
        <p class="ind__sub">${r.ticket_centavos === null ? 'Sem vendas no período' : 'Faturamento ÷ vendas'}</p>
      </article>
      <article class="ind">
        <p class="ind__rotulo">${icone('prato', 16)}Mais vendido</p>
        ${r.mais_vendido === null ? '<p class="ind__valor">—</p><p class="ind__sub">Sem vendas no período</p>' : `
          <p class="ind__valor ind__valor--texto" title="${esc(r.mais_vendido.nome)}">${esc(r.mais_vendido.nome)}</p>
          <p class="ind__sub">${r.mais_vendido.quantidade} un. · <strong>${reais(r.mais_vendido.valor_centavos)}</strong></p>`}
      </article>
    </section>

    <div class="vendas__dupla">
      <section class="bloco" aria-labelledby="t-quando">
        <h2 class="bloco__titulo" id="t-quando">Quando você mais vende</h2>
        ${semVendas ? vazio('relogio', 'Sem vendas neste período', 'Escolha outro período acima.') : calorHtml(d.calor)}
      </section>
      <section class="bloco" aria-labelledby="t-ticket">
        <div class="vendas__cab">
          <h2 class="bloco__titulo" id="t-ticket">Ticket médio por ${agruparPor === 'canal' ? 'canal' : 'pagamento'}</h2>
          <fieldset class="segmentado segmentado--p" aria-label="Agrupar por">
            <label class="segmentado__item"><input type="radio" name="agrupar" value="canal" ${agruparPor === 'canal' ? 'checked' : ''}><span>Canal</span></label>
            <label class="segmentado__item"><input type="radio" name="agrupar" value="pagamento" ${agruparPor === 'pagamento' ? 'checked' : ''}><span>Pagamento</span></label>
          </fieldset>
        </div>
        ${rankHtml(agruparPor === 'canal' ? d.por_canal : d.por_pagamento)}
      </section>
    </div>

    <section class="bloco" aria-labelledby="t-lanc">
      <div class="vendas__cab">
        <h2 class="bloco__titulo" id="t-lanc">Lançamentos <span class="selo">${d.total_lancamentos}</span></h2>
        <form class="vendas__filtros" id="form-filtros">
          ${filtro('canal', 'Canal', Object.entries(d.canais))}
          ${filtro('pagamento', 'Pagamento', Object.entries(d.pagamentos))}
          ${filtro('situacao', 'Situação', [['ativas', 'Ativas'], ['canceladas', 'Com cancelamento']], 'Todas')}
        </form>
      </div>
      ${d.lancamentos.length === 0 ? vazio('sacola', 'Nenhuma venda encontrada', 'Mude o período ou os filtros.') : `
        <div class="tabela-rolagem">
          <table class="tabela tabela--vendas">
            <thead><tr><th scope="col">Data/hora</th><th scope="col">Venda</th><th scope="col">Itens</th><th scope="col" class="numero">Valor</th><th scope="col">Pagamento</th><th scope="col">Canal</th><th scope="col">Situação</th><th scope="col"><span class="so-leitor">Ações</span></th></tr></thead>
            <tbody>${d.lancamentos.map(linhaHtml).join('')}</tbody>
          </table>
        </div>
        ${d.paginas > 1 ? `
          <nav class="vendas__paginas" aria-label="Páginas">
            <button type="button" class="botao botao--secundario botao--p" data-pagina="${d.pagina - 1}" ${d.pagina <= 1 ? 'disabled' : ''}>${icone('seta-esq', 16)}Mais novas</button>
            <span>Página ${d.pagina} de ${d.paginas}</span>
            <button type="button" class="botao botao--secundario botao--p" data-pagina="${d.pagina + 1}" ${d.pagina >= d.paginas ? 'disabled' : ''}>Mais antigas${icone('seta-dir', 16)}</button>
          </nav>` : ''}`}
    </section>`;
}

const vazio = (figura, titulo, texto) => `
  <div class="vazio-painel"><span class="vazio-painel__bola">${icone(figura, 22)}</span><strong>${titulo}</strong><span>${texto}</span></div>`;

const filtro = (nome, rotulo, opcoes, todos = 'Todos') => `
  <label class="campo vendas__filtro"><span class="campo__rotulo">${rotulo}</span>
    <select class="entrada" name="${nome}"><option value="">${todos}</option>${opcoes.map(([v, t]) => `<option value="${esc(v)}" ${filtros[nome] === v ? 'selected' : ''}>${esc(t)}</option>`).join('')}</select>
  </label>`;

const faixaHora = (h) => `${String(h).padStart(2, '0')}h às ${String((h + 1) % 24).padStart(2, '0')}h`;

function calorHtml(c) {
  return `
    <p class="calor__resumo">${c.pico ? `Horário mais forte: <strong>${esc(c.pico.dia)}, ${faixaHora(c.pico.hora)}</strong> (${reais(c.pico.total)} em ${plural(c.pico.vendas, 'venda', 'vendas')}).` : ''}
      ${c.melhor_dia ? ` Melhor dia: <strong>${esc(c.melhor_dia)}</strong>.` : ''}</p>
    <div class="calor__rolagem">
      <table class="calor">
        <caption class="so-leitor">Vendas por dia da semana e hora. O número é a quantidade de vendas; quanto mais escura a célula, maior o valor vendido.</caption>
        <thead><tr><td></td>${c.horas.map((h) => `<th scope="col">${String(h).padStart(2, '0')}<span class="so-leitor"> horas</span></th>`).join('')}<th scope="col" class="calor__total">Total</th></tr></thead>
        <tbody>${c.dias.map((dia) => `
          <tr><th scope="row"><abbr title="${esc(dia.nome)}">${esc(dia.rotulo)}</abbr></th>
            ${dia.celulas.map((cel, i) => cel.vendas === 0
              ? '<td class="calor__cel" data-nivel="0"><span class="so-leitor">sem vendas</span></td>'
              : `<td class="calor__cel" data-nivel="${cel.nivel}" title="${esc(`${dia.nome}, ${faixaHora(c.horas[i])}: ${reais(cel.total)} em ${plural(cel.vendas, 'venda', 'vendas')}`)}">${cel.vendas}</td>`).join('')}
            <td class="calor__total">${dia.vendas === 0 ? '—' : reais(dia.total)}</td></tr>`).join('')}
        </tbody>
      </table>
    </div>
    <p class="calor__legenda" aria-hidden="true">Menos <i data-nivel="1"></i><i data-nivel="2"></i><i data-nivel="3"></i><i data-nivel="4"></i> Mais vendido · o número é a quantidade de vendas</p>`;
}

function rankHtml(lista) {
  if (lista.length === 0) return '<p class="campo__ajuda">Nenhuma venda neste período.</p>';
  const maior = Math.max(...lista.map((g) => g.ticket_centavos), 1);
  return `<ol class="rank">${lista.map((g) => `
    <li class="rank__item">
      <span class="rank__nome">${esc(g.rotulo)}</span>
      <span class="rank__valor">${reais(g.ticket_centavos)}</span>
      <span class="rank__trilho" aria-hidden="true"><span style="width: ${Math.max(2, (g.ticket_centavos / maior) * 100)}%"></span></span>
      <span class="rank__nota">${plural(g.vendas, 'venda', 'vendas')} · ${reais(g.total_centavos)} no total</span>
    </li>`).join('')}</ol>`;
}

function linhaHtml(v) {
  const [classe, rotulo] = SITUACAO[v.situacao];
  const visiveis = v.itens.slice(0, 3);
  const resto = v.itens.length - visiveis.length;
  return `
    <tr ${v.situacao === 'cancelada' ? 'data-cancelada' : ''}>
      <td data-rotulo="Data/hora">${dataHora(v.em)}</td>
      <td data-rotulo="Venda"><strong>${esc(v.numero)}</strong><br><span class="campo__ajuda">${esc(v.cliente)}</span></td>
      <td data-rotulo="Itens"><ul class="vendas__itens">
        ${visiveis.map((i) => `<li>${i.cancelada ? `<s>${i.quantidade}×</s> ${i.quantidade - i.cancelada}×` : `${i.quantidade}×`} ${esc(i.nome)}</li>`).join('')}
        ${resto > 0 ? `<li class="campo__ajuda">e mais ${plural(resto, 'item', 'itens')}</li>` : ''}
      </ul></td>
      <td data-rotulo="Valor" class="numero">${v.cancelado_centavos && v.situacao !== 'cancelada' ? `<s class="campo__ajuda">${reais(v.total_centavos)}</s><br>` : ''}<strong>${reais(v.situacao === 'cancelada' ? v.total_centavos : v.valor_centavos)}</strong></td>
      <td data-rotulo="Pagamento">${esc(v.pagamento_rotulo)}</td>
      <td data-rotulo="Canal">${esc(v.canal_rotulo)}</td>
      <td data-rotulo="Situação"><span class="selo ${classe}">${rotulo}</span></td>
      <td>${v.situacao === 'cancelada' ? '' : `<button type="button" class="botao botao--fantasma botao--p" data-cancelar="${esc(v.chave)}" aria-label="Cancelar a venda ${esc(v.numero)}">${icone('lixeira', 16)}Cancelar</button>`}</td>
    </tr>`;
}

// ---------- Cancelamento (total ou parcial) ----------
function abrirCancelamento(venda) {
  cancelando = venda;
  const restam = venda.itens.filter((i) => i.quantidade > i.cancelada);
  dialogo.innerHTML = `
    <header class="folha__topo">
      <h2 class="folha__titulo" id="folha-dialogo-titulo">Cancelar a venda ${esc(venda.numero)}</h2>
      <button type="button" class="botao botao--fantasma botao--icone" data-fechar aria-label="Fechar">${icone('fechar')}</button>
    </header>
    <form class="folha__corpo formulario" id="form-cancelar" novalidate>
      <p class="campo__ajuda">${esc(venda.cliente)} · ${dataHora(venda.em)} · ${esc(venda.pagamento_rotulo)} · vale hoje ${reais(venda.valor_centavos)}</p>
      <fieldset class="grupo-opcoes grupo-opcoes--duas">
        <legend class="campo__rotulo">O que cancelar</legend>
        <label class="opcao"><input class="opcao__entrada" type="radio" name="tipo" value="total" checked><span class="opcao__marca">${icone('check', 14)}</span>
          <span class="opcao__corpo"><span class="opcao__titulo">A venda inteira</span><span class="opcao__descricao">${reais(venda.valor_centavos)}, tudo o que falta (inclui a taxa de entrega, se houver)</span></span></label>
        <label class="opcao"><input class="opcao__entrada" type="radio" name="tipo" value="parcial"><span class="opcao__marca">${icone('check', 14)}</span>
          <span class="opcao__corpo"><span class="opcao__titulo">Só alguns itens</span><span class="opcao__descricao">Escolha os itens e as quantidades</span></span></label>
      </fieldset>
      <div class="cancelar__itens" id="cancelar-itens" hidden>
        ${restam.map((i) => `
          <label class="cancelar__item">
            <span><strong>${esc(i.nome)}</strong><br><span class="campo__ajuda">${i.quantidade - i.cancelada} de ${i.quantidade} · ${reais(i.preco_unit_centavos)} cada</span></span>
            <input class="entrada" type="number" name="item-${i.id}" data-item="${i.id}" data-preco="${i.preco_unit_centavos}" min="0" max="${i.quantidade - i.cancelada}" value="0" inputmode="numeric" aria-label="Quantas unidades de ${esc(i.nome)} cancelar">
          </label>`).join('')}
        <p class="cancelar__soma" id="cancelar-soma" aria-live="polite">Nada escolhido ainda.</p>
      </div>
      <label class="campo">
        <span class="campo__rotulo">Motivo</span>
        <textarea class="entrada" name="motivo" rows="2" maxlength="200" aria-describedby="erro-motivo" placeholder="Ex.: prato voltou, cliente desistiu"></textarea>
        <span class="campo__erro" id="erro-motivo" hidden></span>
      </label>
      <span class="campo__erro" id="erro-itens" hidden></span>
      <p class="campo__ajuda">O valor cancelado sai do faturamento e do fechamento do dia. A venda continua na lista, marcada.</p>
    </form>
    <footer class="folha__rodape folha__rodape--acoes">
      <button type="button" class="botao botao--secundario" data-fechar>Voltar</button>
      <button type="submit" form="form-cancelar" class="botao botao--perigo">Cancelar a venda</button>
    </footer>`;
  abrirFolha(dialogo, { aoFechar: () => (cancelando = null) });
}

function somaParcial() {
  return $$('[data-item]', dialogo).reduce((total, campo) => total + Math.min(Number(campo.max), Math.max(0, Number(campo.value) || 0)) * Number(campo.dataset.preco), 0);
}

function erroNoDialogo(campo, mensagem) {
  const el = $(`#erro-${campo}`, dialogo);
  el.hidden = false;
  el.textContent = mensagem;
}

delegar(dialogo, 'change', '#form-cancelar input[name="tipo"]', (e, entrada) => {
  $('#cancelar-itens', dialogo).hidden = entrada.value !== 'parcial';
  $('[type="submit"]', dialogo).textContent = entrada.value === 'parcial' ? 'Cancelar os itens' : 'Cancelar a venda';
});
delegar(dialogo, 'input', '[data-item]', () => {
  const soma = somaParcial();
  $('#cancelar-soma', dialogo).textContent = soma ? `Sai da venda: ${reais(soma)}` : 'Nada escolhido ainda.';
});
dialogo.addEventListener('submit', async (e) => {
  if (e.target.id !== 'form-cancelar' || !cancelando) return;
  e.preventDefault();
  const form = e.target;
  $$('.campo__erro', dialogo).forEach((el) => (el.hidden = true));
  const tipo = form.tipo.value;
  const itens = $$('[data-item]', dialogo).map((c) => ({ id: Number(c.dataset.item), quantidade: Number(c.value) || 0 })).filter((i) => i.quantidade > 0);
  if (tipo === 'parcial' && itens.length === 0) return erroNoDialogo('itens', 'Escolha pelo menos um item para cancelar.');
  if (!form.motivo.value.trim()) return erroNoDialogo('motivo', 'Diga o motivo do cancelamento.');
  const botao = $('[type="submit"]', dialogo);
  botao.setAttribute('aria-busy', 'true');
  try {
    await api('/api/admin/vendas/cancelar', { metodo: 'POST', corpo: { venda: cancelando.chave, tipo, itens, motivo: form.motivo.value } });
    await fecharFolha(dialogo);
    avisar(tipo === 'total' ? 'Venda cancelada' : 'Itens cancelados');
    carregar();
  } catch (erro) {
    botao.removeAttribute('aria-busy');
    if (erro.codigo === 'validacao') Object.entries(erro.campos).forEach(([campo, msg]) => erroNoDialogo(campo === 'tipo' ? 'itens' : campo, msg));
    else erroNoDialogo('itens', erro.message);
  }
});

// ---------- Eventos da tela ----------
delegar(conteudo, 'change', 'input[name="periodo"]', (e, entrada) => {
  periodo = entrada.value;
  if (periodo === 'outro') {
    $('.vendas__datas', conteudo).hidden = false;
    $('input[name="de"]', conteudo).focus();
    return;
  }
  datas = intervalo(periodo, dados.hoje);
  pagina = 1;
  carregar();
});
delegar(conteudo, 'submit', '#form-periodo', (e, form) => {
  e.preventDefault();
  datas = { de: form.de.value, ate: form.ate.value };
  pagina = 1;
  carregar();
});
delegar(conteudo, 'change', '#form-filtros select', (e, campo) => {
  filtros[campo.name] = campo.value;
  pagina = 1;
  carregar();
});
delegar(conteudo, 'change', 'input[name="agrupar"]', (e, entrada) => {
  agruparPor = entrada.value;
  desenhar();
});
delegar(conteudo, 'click', '[data-pagina]', (e, botao) => {
  pagina = Number(botao.dataset.pagina);
  carregar();
});
delegar(conteudo, 'click', '[data-cancelar]', (e, botao) => {
  const venda = dados.lancamentos.find((v) => v.chave === botao.dataset.cancelar);
  if (venda) abrirCancelamento(venda);
});

/** A tela abriu: busca o período escolhido (na primeira vez, hoje). */
export async function montar() {
  await carregar();
}
