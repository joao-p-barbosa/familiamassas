// Serviços do painel que precisam funcionar em QUALQUER tela, por isso vivem na casca e não no quadro de Pedidos:
//   * o fluxo de pedidos (consulta o servidor a cada poucos segundos, só o que mudou);
//   * o som e o aviso de pedido novo;
//   * a impressão automática da comanda (o aparelho marcado como estação imprime mesmo com outra tela aberta);
//   * o aviso "pedido de <cliente> pronto para servir" para a atendente, onde quer que ela esteja.
// As telas só "escutam" o fluxo (ouvir) e desenham o que quiserem.
import { avisar } from '../shared/avisos.js';
import { api } from '../shared/api.js';
import { $, esc } from '../shared/dom.js';
import { hora, plural } from '../shared/formato.js';
import { criarFonte } from './pedidos-vivos.js';
import { imprimirAutomatico, estacao } from './impressao.js';
import { tocarAlerta } from './som.js';
import { iniciarDetalhe, atualizarDetalhe } from './detalhe.js';
import { usuario, ehAdmin, confirmar, mostrarErro } from './casca.js';

const ouvintes = { antes: new Set(), mudou: new Set() };

/**
 * Escuta o fluxo de pedidos. `antes`: vai mudar (a tela guarda a posição dos cartões para animar);
 * `mudou`: mudou ('dados' ou 'conexao'). Devolve a função que para de escutar (chame em desmontar()).
 */
export function ouvir(evento, funcao) {
  ouvintes[evento].add(funcao);
  return () => ouvintes[evento].delete(funcao);
}

/** Pedidos que acabaram de chegar (para o quadro destacar o cartão por um instante). */
export const recemChegados = new Set();

const imprimindo = new Set();
let statusAnterior = null; // id → status, para perceber quem ficou pronto agora

export const fonte = criarFonte({
  antesDeMudar: () => ouvintes.antes.forEach((f) => f()),
  aoWhatsApp: (w) => atualizarFaixaWhatsApp(w),
  aoIA: (ia) => atualizarFaixaIA(ia),
  aoMudar: (motivo) => {
    ouvintes.mudou.forEach((f) => f(motivo));
    if (motivo === 'conexao') return;
    avisarProntosDoSalao();
    atualizarDetalhe();
    imprimirPendentes();
  },
  aoChegar: (todos) => {
    todos.forEach((p) => recemChegados.add(p.id));
    // Quem acabou de registrar o pedido no salão não precisa de aviso (nem de som) do próprio pedido.
    const novos = todos.filter((p) => p.criado_por !== usuario.id);
    if (novos.length === 0) return;
    tocarAlerta();
    avisar(novos.length === 1 ? `Pedido novo: #${novos[0].id}, ${novos[0].cliente_nome}` : `${novos.length} pedidos novos`);
    const antes = document.title;
    document.title = `(${novos.length}) Pedidos novos`;
    setTimeout(() => { if (document.title.startsWith('(')) document.title = antes; }, 8000);
  },
});

// ---------- Faixa do WhatsApp por QR code ----------
// A faixa aparece com o WhatsApp caído (ou sem sinal do conector) e quando ele volta com avisos esperando (a decisão é do
// proprietário). O som toca ao cair e se repete a cada 2 min enquanto durar. A situação vem junto com a consulta de pedidos.
const REPETE_SOM_MS = 2 * 60 * 1000;
let somDaQueda = 0;
let faixaAtual = '';

function atualizarFaixaWhatsApp(w) {
  const faixa = $('#faixa-whatsapp');
  if (!faixa) return;
  const caido = !!w && w.alarme;   // o servidor só marca alarme depois de 2 min fora do ar
  const esperando = !!w && w.pendentes > 0 && w.fila_retida;
  if (!caido && !esperando) {
    somDaQueda = 0;
    faixa.hidden = true;
    faixaAtual = '';
    return;
  }
  if (caido && Date.now() - somDaQueda > REPETE_SOM_MS) {
    somDaQueda = Date.now();
    tocarAlerta();
  }
  const assinatura = JSON.stringify(w);
  if (assinatura === faixaAtual) return;
  faixaAtual = assinatura;
  const fila = w.pendentes > 0 ? ` ${plural(w.pendentes, 'aviso está esperando', 'avisos estão esperando')} para sair.` : '';
  let texto;
  let acoes = '';
  if (caido) {
    const desde = w.desde ? ` desde ${hora(w.desde)}` : '';
    texto = w.estado === 'aguardando_qr'
      ? '<strong>WhatsApp aguardando o QR code.</strong> O bot e os avisos de pedido estão parados.'
      : `<strong>WhatsApp desconectado${esc(desde)}.</strong> O bot e os avisos de pedido estão parados.`;
    texto += esc(fila);
    if (ehAdmin) acoes = '<a href="/painel/whatsapp">Reconectar</a>';
  } else {
    texto = `<strong>O WhatsApp voltou.</strong>${esc(fila)} Avisos com mais de 30 minutos são descartados ao enviar.`;
    acoes = ehAdmin
      ? '<span class="faixa-whatsapp__acoes"><button type="button" class="botao botao--p" data-fila="liberar">Enviar pendentes</button><button type="button" class="botao botao--p" data-fila="descartar">Descartar</button></span>'
      : ' O proprietário decide se envia ou descarta.';
  }
  faixa.innerHTML = `<p class="faixa-whatsapp__texto">${texto}</p>${acoes}`;
  faixa.hidden = false;
}

// ---------- Faixa do crédito do assistente com IA (D72) ----------
// Só o proprietário recebe o dado. "Saldo baixo" é um aviso; "sem crédito" quer dizer que o bot está só no menu de 3 opções.
let faixaIAAtual = '';
const dolar = (v) => `US$ ${Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function atualizarFaixaIA(ia) {
  const faixa = $('#faixa-ia');
  if (!faixa) return;
  if (!ia) {
    faixa.hidden = true;
    faixaIAAtual = '';
    return;
  }
  const assinatura = JSON.stringify(ia);
  if (assinatura === faixaIAAtual) return;
  faixaIAAtual = assinatura;
  const semCredito = ia.estado === 'sem_credito';
  faixa.className = `faixa-ia ${semCredito ? 'faixa-ia--acabou' : 'faixa-ia--baixo'}`;
  faixa.innerHTML = `<p class="faixa-ia__texto">${semCredito
    ? '<strong>O assistente está sem crédito.</strong> O bot responde só com o menu de 3 opções até entrar uma recarga.'
    : `<strong>Crédito do assistente baixo: ${dolar(ia.saldo_usd)}.</strong> Combine uma recarga antes de acabar.`}</p><a href="/painel/assistente">Ver crédito</a>`;
  faixa.hidden = false;
}

// "Enviar pendentes" e "Descartar": valem na faixa e na tela WhatsApp (os dois desenham o mesmo botão com data-fila).
document.addEventListener('click', async (e) => {
  const botao = e.target instanceof Element ? e.target.closest('[data-fila]') : null;
  if (!botao) return;
  const acao = botao.dataset.fila;
  if (acao === 'descartar' && !(await confirmar({
    titulo: 'Descartar os avisos que ficaram esperando?',
    texto: 'Os clientes não vão receber esses avisos de status. Isso não dá para desfazer.',
    confirmar: 'Descartar',
    perigo: true,
  }))) return;
  try {
    const r = await api(`/api/admin/whatsapp/fila/${acao}`, { metodo: 'POST' });
    avisar(acao === 'liberar' ? 'Enviando os avisos pendentes' : plural(r.descartados, 'aviso descartado', 'avisos descartados'));
    faixaAtual = '';
    fonte.sincronizar();
    document.dispatchEvent(new CustomEvent('whatsapp:fila'));
  } catch (erro) {
    mostrarErro(erro);
  }
});

/** Pedido de um atendimento do salão que passou para "Pronto": a atendente é avisada para servir. */
function avisarProntosDoSalao() {
  const lista = fonte.lista();
  const atual = new Map(lista.map((p) => [p.id, p.status]));
  if (statusAnterior) {
    const prontos = lista.filter((p) => p.atendimento_id && p.status === 'pronto' && ['novo', 'em_preparo'].includes(statusAnterior.get(p.id)));
    if (prontos.length > 0) {
      tocarAlerta();
      avisar(prontos.length === 1 ? `Pedido de ${prontos[0].cliente_nome} pronto para servir` : `${prontos.length} pedidos prontos para servir`);
    }
  }
  statusAnterior = atual;
}

/** Imprime as comandas ainda não impressas (só se este aparelho for a estação de impressão). */
export async function imprimirPendentes() {
  if (!estacao.ativa) return;
  for (const p of fonte.lista()) {
    if (p.impresso_em || !['novo', 'em_preparo'].includes(p.status) || imprimindo.has(p.id)) continue;
    imprimindo.add(p.id);
    const atualizado = await imprimirAutomatico(p);
    imprimindo.delete(p.id);
    if (atualizado) fonte.guardar(atualizado);
  }
  atualizarDetalhe();
}

let iniciado = false;
export function iniciarServicos() {
  if (iniciado) return;
  iniciado = true;
  iniciarDetalhe(fonte);
  fonte.iniciar();
}
