// Salão: o quadro dos atendimentos (a conta de cada cliente). É a tela inicial da atendente.
//
// Cada cartão é um atendimento, identificado pelo nome do cliente, com os pedidos dele e o status de cada um.
// A coluna vem da FASE que o servidor calcula (AtendimentoService):
//   Na cozinha · Prontos para servir · Aguardando a conta · Encerrados hoje.
// O pedido individual e a conta são coisas separadas: um atendimento com todos os pedidos servidos continua aberto
// (em "Aguardando a conta") até alguém fechar a conta com o pagamento.
import { $, $$, esc, delegar } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { reais, minutosDesde, duracao, hora, plural } from '../shared/formato.js';
import { api } from '../shared/api.js';
import { avisar } from '../shared/avisos.js';
import { STATUS } from '../shared/pedido.js';
import { MODALIDADES, FORMAS_DE_PAGAMENTO, rotuloDaModalidade, itensEmTexto } from '../shared/atendimento.js';
import { somLigado, alternarSom } from './som.js';
import { recipienteDaTela, ehAdmin, ajustes, mostrarErro } from './casca.js';
import { navegar, limparConsulta } from './roteador.js';
import { ouvir } from './servicos.js';
import { SELO_STATUS } from './detalhe.js';
import { abrirNovoAtendimento } from './salao-novo.js';
import { abrirConta, abrirFechamento, atualizarContaAberta, servir } from './salao-conta.js';

const raiz = recipienteDaTela();   // o contêiner desta tela (criado pelo roteador)

const COLUNAS = [
  { id: 'cozinha', titulo: 'Na cozinha', vazio: 'Nenhum pedido em preparo.' },
  { id: 'pronto', titulo: 'Prontos para servir', vazio: 'Nada esperando para servir.' },
  { id: 'conta', titulo: 'Aguardando a conta', vazio: 'Nenhuma conta para fechar.' },
  { id: 'encerrado', titulo: 'Encerrados hoje', vazio: 'Nenhuma conta fechada hoje.' },
];
const CHAVE_DESTAQUE = 'fm_salao_destaque';

let quadro = null;        // { atendimentos, resumo } do servidor
let conectado = true;
let ultimaSincronia = null;
let buscando = false;
let assinatura = '';      // o que está desenhado: só redesenha quando algo mudou (o foco do teclado não se perde)
let destaque = null;      // atendimento recém-enviado
let relogio = 0;
let parar = [];

// ---------- Dados ----------
async function atualizar() {
  if (buscando) return;
  buscando = true;
  try {
    quadro = await api('/api/admin/atendimentos');
    conectado = true;
    ultimaSincronia = new Date();
  } catch (erro) {
    if (erro.status === 401) mostrarErro(erro);
    conectado = false;
  } finally {
    buscando = false;
  }
  desenhar();
  if (conectado) atualizarContaAberta();
}

// ---------- Desenho ----------
function estruturaHtml() {
  return `
    <div class="cabecalho-tela">
      <h1 class="cabecalho-tela__titulo">Salão</h1>
      <button type="button" class="botao botao--g" data-acao="novo">${icone('mais', 20)}Novo pedido</button>
    </div>
    <div id="salao-resumo"></div>
    <div class="cabecalho-tela">
      <div class="ferramentas">
        <span class="conexao" id="salao-conexao" role="status"></span>
        <button type="button" class="botao botao--secundario botao--p" id="salao-som" aria-pressed="${somLigado()}">${icone(somLigado() ? 'som' : 'som-mudo', 18)}<span>Som de pedido pronto</span></button>
      </div>
    </div>
    <div class="quadro quadro--quatro" id="quadro-salao">
      ${COLUNAS.map((c) => `
        <section class="coluna" data-coluna="${c.id}" aria-labelledby="salao-coluna-${c.id}">
          <header class="coluna__topo">
            <h2 class="coluna__titulo" id="salao-coluna-${c.id}">${c.titulo}</h2>
            <span class="coluna__conta numero" data-conta="${c.id}">0</span>
          </header>
          <div class="coluna__lista" data-lista="${c.id}"><div class="esqueleto esqueleto--cartao" style="height: 9rem;"></div></div>
        </section>`).join('')}
    </div>`;
}

function resumoHtml(r) {
  return `
    <section class="fechamento" aria-label="Situação do salão">
      <h2 class="fechamento__titulo">Agora</h2>
      <dl class="fechamento__lista">
        <div><dt>Atendimentos abertos</dt><dd>${r.abertos}</dd></div>
        <div><dt>Em aberto</dt><dd>${reais(r.valor_em_aberto_centavos)}</dd></div>
        <div><dt>Encerrados hoje</dt><dd>${r.encerrados_hoje}</dd></div>
        ${ehAdmin && r.vendido_hoje_centavos !== undefined ? `<div><dt>Contas pagas hoje</dt><dd>${reais(r.vendido_hoje_centavos)}</dd></div>` : ''}
      </dl>
    </section>`;
}

function cartaoHtml(a) {
  const aberto = a.status === 'aberto';
  const visiveis = a.pedidos.slice(-3);
  const escondidos = a.pedidos.length - visiveis.length;
  const modalidade = MODALIDADES[a.modalidade];
  const principal = {
    pronto: `<button type="button" class="botao botao--bloco" data-acao="servir">Marcar servido${icone('check', 18)}</button>`,
    conta: `<button type="button" class="botao botao--bloco" data-acao="fechar">Fechar conta · ${reais(a.total_centavos)}</button>`,
  }[a.fase] ?? '';
  return `
    <article class="cartao-atendimento" data-id="${a.id}" data-fase="${a.fase}" ${destaque === a.id ? 'data-chegou' : ''}>
      <div class="cartao-atendimento__topo">
        <span class="selo cartao-pedido__modalidade">${icone(modalidade.icone, 14)}${esc(rotuloDaModalidade(a))}</span>
        <span class="cartao-atendimento__tempo numero">${aberto ? `aberto há ${duracao(minutosDesde(a.aberto_em))}` : `fechado às ${hora(a.encerrado_em)}`}</span>
      </div>
      <button type="button" class="cartao-atendimento__abrir" data-acao="abrir" aria-label="Ver a conta de ${esc(a.cliente_nome)}">
        <span class="cartao-atendimento__cliente">${esc(a.cliente_nome)}</span>
        <span class="cartao-atendimento__conta"><strong class="numero">${reais(a.total_centavos)}</strong> · ${plural(a.quantidade_itens, 'item', 'itens')}</span>
        <span class="cartao-atendimento__aberto-as">Atendimento ${a.id} · aberto às ${hora(a.aberto_em)}</span>
      </button>
      <ul class="cartao-atendimento__pedidos" aria-label="Pedidos do atendimento">
        ${escondidos > 0 ? `<li class="cartao-atendimento__mais">+ ${plural(escondidos, 'pedido anterior', 'pedidos anteriores')}</li>` : ''}
        ${visiveis.map((p) => `
          <li data-status="${p.status}">
            <span class="selo ${SELO_STATUS[p.status]}">${STATUS[p.status].rotulo}</span>
            <span class="cartao-atendimento__itens">${itensEmTexto(p)}</span>
          </li>`).join('')}
      </ul>
      ${aberto ? `
        ${principal}
        <div class="cartao-atendimento__acoes">
          <button type="button" class="botao botao--secundario botao--p" data-acao="adicionar">${icone('mais', 16)}Adicionar produtos</button>
          ${a.fase === 'conta' ? '' : '<button type="button" class="botao botao--secundario botao--p" data-acao="fechar">Fechar conta</button>'}
        </div>` : `<p class="cartao-atendimento__pago">${icone('check', 16)}<span>${a.pagamento ? `Pago em ${FORMAS_DE_PAGAMENTO[a.pagamento]}` : 'Sem valor a cobrar'}</span></p>`}
    </article>`;
}

function desenhar() {
  const conexao = $('#salao-conexao', raiz);
  if (conexao) {
    conexao.dataset.estado = conectado ? 'ok' : 'fora';
    conexao.innerHTML = conectado
      ? `<span class="conexao__ponto"></span><span>Atualizado às ${ultimaSincronia ? hora(ultimaSincronia.toISOString()) : '--:--'}</span>`
      : `${icone('alerta', 16)}<span>Sem conexão. Tentando de novo…</span>`;
  }
  if (!quadro) return;
  // Só redesenha quando algo mudou (a cada minuto também, por causa do "aberto há N min").
  const nova = JSON.stringify(quadro) + Math.floor(Date.now() / 60_000) + String(destaque);
  if (nova === assinatura) return;
  assinatura = nova;

  const foco = document.activeElement?.closest?.('#quadro-salao [data-id]');
  const lembrar = foco ? { id: foco.dataset.id, acao: document.activeElement.dataset.acao } : null;

  $('#salao-resumo', raiz).innerHTML = resumoHtml(quadro.resumo);
  for (const coluna of COLUNAS) {
    const atendimentos = quadro.atendimentos.filter((a) => a.fase === coluna.id);
    // Abertos: o que está esperando há mais tempo primeiro. Encerrados: os mais recentes primeiro.
    atendimentos.sort((a, b) => (coluna.id === 'encerrado' ? b.encerrado_em.localeCompare(a.encerrado_em) : a.id - b.id));
    $(`[data-conta="${coluna.id}"]`, raiz).textContent = atendimentos.length;
    $(`[data-lista="${coluna.id}"]`, raiz).innerHTML = atendimentos.length === 0
      ? `<p class="coluna__vazio">${coluna.vazio}</p>`
      : atendimentos.map(cartaoHtml).join('');
  }
  if (destaque !== null) destaque = null;   // o realce vale uma vez só
  if (lembrar) $(`#quadro-salao [data-id="${lembrar.id}"] [data-acao="${lembrar.acao}"]`, raiz)?.focus({ preventScroll: true });
}

// ---------- Eventos (ligados uma vez, neste contêiner) ----------
delegar(raiz, 'click', '[data-acao]', async (e, botao) => {
  const id = Number(botao.closest('[data-id]')?.dataset.id);
  switch (botao.dataset.acao) {
    case 'novo':
      abrirNovoAtendimento();
      break;
    case 'abrir':
      abrirConta(id, { aoMudar: atualizar });
      break;
    case 'adicionar':
      navegar(`/painel/atendimento?id=${id}`);
      break;
    case 'fechar':
      abrirFechamento(id, { aoMudar: atualizar });
      break;
    case 'servir':
      botao.setAttribute('aria-busy', 'true');
      await servir(id);
      botao.removeAttribute('aria-busy');
      atualizar();
      break;
  }
});

delegar(raiz, 'click', '#salao-som', (e, botao) => {
  const ligado = alternarSom();
  botao.setAttribute('aria-pressed', String(ligado));
  botao.firstElementChild.outerHTML = icone(ligado ? 'som' : 'som-mudo', 18);
  avisar(ligado ? 'Som ligado: você vai ouvir quando um pedido ficar pronto' : 'Som desligado');
});

// ---------- Ciclo de vida da tela ----------
export async function montar({ consulta }) {
  raiz.innerHTML = estruturaHtml();
  assinatura = '';
  try {
    destaque = Number(sessionStorage.getItem(CHAVE_DESTAQUE)) || null;
    sessionStorage.removeItem(CHAVE_DESTAQUE);
  } catch {
    destaque = null;
  }
  await atualizar();
  relogio = setInterval(atualizar, Math.max(3, ajustes.polling_segundos) * 1000);
  parar = [ouvir('mudou', (motivo) => { if (motivo !== 'conexao') atualizar(); })];   // um pedido mudou de etapa: o quadro acompanha na hora
  if (consulta.has('novo')) {
    limparConsulta();
    abrirNovoAtendimento();   // veio do botão "Novo pedido" do quadro de Pedidos
  }
}

export function desmontar() {
  clearInterval(relogio);
  parar.forEach((f) => f());
  parar = [];
}
