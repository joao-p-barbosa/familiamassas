// WhatsApp (Etapa 4): situação da integração, simulador do bot, caixa de entrada do atendimento humano, atividade recente,
// fila de avisos de status e o link de teste do cardápio. Só o proprietário abre esta tela.
// Nenhum segredo chega aqui: a API só diz se cada credencial está preenchida.
import { $, esc, delegar, guardado } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { telefone as formatarTelefone, hora, dataHora } from '../shared/formato.js';
import { api } from '../shared/api.js';
import { avisar } from '../shared/avisos.js';
import { recipienteDaTela, mostrarErro } from './casca.js';

const conteudo = recipienteDaTela();   // o contêiner desta tela (criado pelo roteador)
const CHAVE_SIMULADOR = 'fm_simulador_whatsapp';
const ATUALIZAR_MS = 4000;

let situacao = null;
let simulador = guardado.ler(CHAVE_SIMULADOR) ?? { telefone: '(11) 98765-0001', nome: 'Cliente Teste' };
let atendendo = null;        // telefone da conversa aberta na caixa de entrada
let ultimaAssinatura = {};   // por quadro de conversa: só redesenha quando chega mensagem nova
let relogio = 0;

const DIRECAO = { in: 'Recebida', out: 'Enviada' };

// ---------- Conversa (balões) ----------
function balaoHtml(m, { clicavel }) {
  if (m.direcao === 'in') {
    return `
      <li class="balao balao--cliente">
        ${m.nome ? `<span class="balao__quem">${esc(m.nome)}</span>` : ''}
        <p>${esc(m.texto || `(${m.tipo})`)}</p>
        <time class="balao__hora">${hora(m.em)}</time>
      </li>`;
  }
  if (m.tipo === 'falha' || m.tipo === 'falha_entrega') {
    return `
      <li class="balao balao--falha">
        <p>${icone('alerta', 14)} Não enviada${m.erro ? `: ${esc(m.erro)}` : ''}</p>
        ${m.texto ? `<p class="balao__apagado">${esc(m.texto)}</p>` : ''}
        <time class="balao__hora">${hora(m.em)}</time>
      </li>`;
  }
  const botoes = Object.entries(m.botoes ?? {});
  return `
    <li class="balao balao--loja">
      <p>${esc(m.texto)}</p>
      ${m.link ? `<a class="balao__acao" href="${esc(m.link.url)}" target="_blank" rel="noopener">${icone('externo', 14)}${esc(m.link.rotulo)}</a>` : ''}
      ${botoes.length ? `<div class="balao__botoes">${botoes.map(([id, titulo]) => clicavel
        ? `<button type="button" class="balao__acao" data-botao="${esc(id)}">${esc(titulo)}</button>`
        : `<span class="balao__acao">${esc(titulo)}</span>`).join('')}</div>` : ''}
      <time class="balao__hora">${hora(m.em)}</time>
    </li>`;
}

function desenharConversa(alvo, conversa, opcoes) {
  const lista = $(alvo, conteudo);
  if (!lista) return;
  const assinatura = `${conversa.mensagens.length}:${conversa.mensagens.at(-1)?.id ?? 0}:${conversa.modo}`;
  if (ultimaAssinatura[alvo] === assinatura) return;
  ultimaAssinatura[alvo] = assinatura;
  lista.innerHTML = conversa.mensagens.length
    ? conversa.mensagens.map((m) => balaoHtml(m, opcoes)).join('')
    : '<li class="conversa__vazia">Nenhuma mensagem ainda.</li>';
  lista.scrollTop = lista.scrollHeight;
}

// ---------- Tela ----------
function situacaoHtml(s) {
  const item = (ok, texto) => `<li ${ok ? 'data-ok' : ''}><span class="checagem__marca">${icone(ok ? 'check' : 'menos', 14)}</span><span>${texto}</span></li>`;
  return `
    <section class="bloco">
      <h2 class="bloco__titulo">${s.simulado ? 'Modo simulado' : 'Ligado na Meta'} <span class="selo ${s.simulado ? 'selo--alerta' : 'selo--sucesso'}">${s.simulado ? 'sem envio real' : 'envio real'}</span></h2>
      <p class="bloco__nota">${icone('info', 16)}<span>${s.simulado
        ? 'Sem o token e o ID do número da Meta, nada sai para o WhatsApp: o bot responde no simulador abaixo. Para ligar de verdade, preencha as credenciais nas variáveis de ambiente do servidor (veja docs/DEPLOY.md).'
        : 'As mensagens vão de verdade pela Cloud API da Meta. O simulador fica desligado para não misturar conversas.'}</span></p>
      <ul class="checagem">
        ${s.chaves.map((c) => item(c.preenchida, `${esc(c.rotulo)} <span class="campo__ajuda">(${esc(c.chave)})</span>`)).join('')}
        ${item(s.https, s.https ? 'Site em HTTPS' : 'Site em HTTPS (a Meta exige para o webhook; em desenvolvimento local não há)')}
      </ul>
      <div class="campo">
        <span class="campo__rotulo">Endereço do webhook (cadastre na Meta, com o mesmo verify token)</span>
        <code class="codigo">${esc(s.webhook)}</code>
      </div>
      <p class="campo__ajuda">Avisos fora da janela de 24 h usam o template <strong>${s.modelo_status ? esc(s.modelo_status) : '(desligado)'}</strong>. “Falar com atendente” silencia o bot por ${s.humano_horas} h.</p>
    </section>`;
}

function simuladorHtml() {
  return `
    <section class="bloco" aria-labelledby="titulo-simulador">
      <h2 class="bloco__titulo" id="titulo-simulador">Simulador do bot</h2>
      <p class="bloco__nota">${icone('conversa', 16)}<span>Você é o cliente. Mande “oi”, toque nos botões e abra o link do cardápio: o pedido chega no quadro com origem WhatsApp e cada avanço no Kanban aparece aqui como aviso.</span></p>
      <div class="bloco__grade">
        <label class="campo"><span class="campo__rotulo">Número do cliente</span><input class="entrada" name="sim-telefone" inputmode="numeric" value="${esc(simulador.telefone)}"></label>
        <label class="campo"><span class="campo__rotulo">Nome no WhatsApp</span><input class="entrada" name="sim-nome" value="${esc(simulador.nome)}" maxlength="60"></label>
      </div>
      <ol class="conversa" id="conversa-simulador" role="log" aria-live="polite" aria-label="Conversa simulada"></ol>
      <form class="conversa__envio" id="form-simulador">
        <input class="entrada" name="texto" placeholder="Mensagem do cliente" autocomplete="off" maxlength="1000" aria-label="Mensagem do cliente">
        <button type="submit" class="botao">Enviar</button>
      </form>
    </section>`;
}

function atendimentoHtml(s) {
  return `
    <section class="bloco" aria-labelledby="titulo-atendimento">
      <h2 class="bloco__titulo" id="titulo-atendimento">Atendimento humano <span class="coluna__conta numero">${s.atendimento.length}</span></h2>
      ${s.atendimento.length === 0
        ? '<p class="campo__ajuda">Ninguém pediu para falar com atendente agora. Quando alguém tocar em “Falar com atendente”, a conversa aparece aqui e o bot fica em silêncio.</p>'
        : `<ul class="caixa-entrada">${s.atendimento.map((c) => `
            <li><button type="button" class="caixa-entrada__item" data-atender="${esc(c.telefone)}" ${atendendo === c.telefone ? 'aria-current="true"' : ''}>
              <strong>${esc(c.nome ?? 'Cliente')}</strong>
              <span>${esc(formatarTelefone(c.telefone))} · até ${hora(c.humano_ate)}${c.janela_aberta ? '' : ' · janela fechada'}</span>
            </button></li>`).join('')}</ul>`}
      <div id="painel-atendimento" ${atendendo ? '' : 'hidden'}>
        <ol class="conversa" id="conversa-atendimento" role="log" aria-live="polite" aria-label="Conversa em atendimento"></ol>
        <form class="conversa__envio" id="form-resposta">
          <input class="entrada" name="texto" placeholder="Responder ao cliente" autocomplete="off" maxlength="4096" aria-label="Resposta ao cliente">
          <button type="submit" class="botao">Responder</button>
        </form>
        <p class="campo__erro" id="erro-resposta" hidden></p>
        <button type="button" class="botao botao--secundario botao--p" data-devolver>${icone('desfazer', 16)}Devolver ao bot</button>
      </div>
    </section>`;
}

function atividadeHtml(s) {
  const linhaEvento = (e) => `
    <tr ${e.erro ? 'data-erro' : ''}>
      <td class="numero">${dataHora(e.em)}</td>
      <td>${e.tipo === 'falha' || e.tipo === 'falha_entrega' ? 'Falha' : DIRECAO[e.direcao]}</td>
      <td>${esc(e.motivo ?? e.tipo)}</td>
      <td class="numero">${esc(e.telefone)}</td>
      <td>${e.erro ? esc(e.erro) : ''}</td>
    </tr>`;
  return `
    <section class="bloco">
      <h2 class="bloco__titulo">Últimas 24 horas</h2>
      <dl class="numeros">
        <div><dt>Mensagens recebidas</dt><dd class="numero">${s.recebidas_24h}</dd></div>
        <div><dt>Mensagens enviadas</dt><dd class="numero">${s.enviadas_24h}</dd></div>
        <div><dt>Falhas de envio</dt><dd class="numero">${s.falhas_24h}</dd></div>
        <div><dt>Avisos na fila</dt><dd class="numero">${s.envios_pendentes}</dd></div>
        <div><dt>Pedidos pelo WhatsApp hoje</dt><dd class="numero">${s.pedidos_whatsapp_hoje}</dd></div>
      </dl>
    </section>
    <section class="bloco">
      <h2 class="bloco__titulo">Atividade recente</h2>
      <p class="campo__ajuda">Sem o texto das mensagens e com o número mascarado.</p>
      ${s.recentes.length ? `<div class="tabela-rolagem"><table class="tabela">
        <thead><tr><th>Quando</th><th>Direção</th><th>O quê</th><th>Número</th><th>Erro</th></tr></thead>
        <tbody>${s.recentes.map(linhaEvento).join('')}</tbody></table></div>` : '<p class="campo__ajuda">Nada por aqui ainda.</p>'}
      ${s.fila.length ? `
        <h3 class="detalhe__titulo" style="margin-top: var(--e-4);">Fila de avisos de status</h3>
        <ul class="fila-avisos">${s.fila.map((a) => `
          <li ${a.desistiu ? 'data-erro' : ''}><strong>Pedido #${a.pedido_id}</strong> · ${esc(a.tipo)} · ${esc(a.telefone)} ·
            ${a.desistiu ? 'a fila desistiu' : `tentativa ${a.tentativas + 1} às ${hora(a.proxima_tentativa_em)}`}${a.ultimo_erro ? ` · ${esc(a.ultimo_erro)}` : ''}</li>`).join('')}</ul>` : ''}
    </section>`;
}

function linkHtml() {
  return `
    <form class="bloco" id="form-link" novalidate>
      <h2 class="bloco__titulo">Link de teste do cardápio</h2>
      <p class="bloco__nota">${icone('ligacao', 16)}<span>É o link que o bot manda no botão “Fazer pedido”. Quem abre já entra com o WhatsApp preenchido e o pedido chega com origem “WhatsApp”.</span></p>
      <label class="campo">
        <span class="campo__rotulo">Número do cliente</span>
        <input class="entrada" type="tel" name="telefone" inputmode="numeric" placeholder="(11) 91234-5678" aria-describedby="erro-telefone">
        <span class="campo__erro" id="erro-telefone" hidden></span>
      </label>
      <div><button type="submit" class="botao botao--secundario">Gerar link</button></div>
      <div id="link-gerado" hidden>
        <code class="codigo" id="link-url"></code>
        <p class="campo__ajuda" id="link-validade" style="margin-top: var(--e-2);"></p>
        <div style="display: flex; gap: var(--e-2); margin-top: var(--e-3);">
          <button type="button" class="botao botao--p" data-copiar>Copiar link</button>
          <a class="botao botao--secundario botao--p" id="link-abrir" target="_blank" rel="noopener">Abrir o cardápio</a>
        </div>
      </div>
    </form>`;
}

function desenhar(s) {
  situacao = s;
  ultimaAssinatura = {};
  if (atendendo && !s.atendimento.some((c) => c.telefone === atendendo)) atendendo = null;
  conteudo.innerHTML = `
    <div class="cabecalho-tela"><h1 class="cabecalho-tela__titulo">WhatsApp</h1></div>
    <div class="config whatsapp">
      ${situacaoHtml(s)}
      ${s.simulado ? simuladorHtml() : ''}
      ${atendimentoHtml(s)}
      ${atividadeHtml(s)}
      ${linkHtml()}
    </div>`;
}

// ---------- Dados ----------
const digitosDoSimulador = () => simulador.telefone.replace(/\D/g, '');

async function atualizarSimulador() {
  if (!situacao?.simulado || digitosDoSimulador().length < 10) return;
  try {
    desenharConversa('#conversa-simulador', await api(`/api/admin/whatsapp/conversa?telefone=${encodeURIComponent(digitosDoSimulador())}`), { clicavel: true });
  } catch { /* sem conexão agora: tenta no próximo ciclo */ }
}

async function atualizarAtendimento() {
  if (!atendendo) return;
  try {
    const conversa = await api(`/api/admin/whatsapp/conversa?telefone=${encodeURIComponent(atendendo)}`);
    if (conversa.modo !== 'humano') {   // o prazo acabou ou alguém devolveu ao bot
      atendendo = null;
      desenhar(await api('/api/admin/whatsapp'));
      atualizarSimulador();
      return;
    }
    desenharConversa('#conversa-atendimento', conversa, { clicavel: false });
    const form = $('#form-resposta', conteudo);
    form.texto.disabled = !conversa.janela_aberta;
    form.texto.placeholder = conversa.janela_aberta ? 'Responder ao cliente' : 'Janela de 24 h fechada: espere o cliente escrever de novo';
  } catch { /* tenta no próximo ciclo */ }
}

async function simular(corpo) {
  try {
    const conversa = await api('/api/admin/whatsapp/simular', { metodo: 'POST', corpo: { telefone: simulador.telefone, nome: simulador.nome, ...corpo } });
    desenharConversa('#conversa-simulador', conversa, { clicavel: true });
    // Pode ter entrado (ou saído) do atendimento humano: a caixa de entrada acompanha.
    const nova = await api('/api/admin/whatsapp');
    if (nova.atendimento.length !== situacao.atendimento.length) {
      desenhar(nova);
      atualizarSimulador();
      atualizarAtendimento();
    }
  } catch (erro) {
    mostrarErro(erro);
  }
}

// ---------- Eventos ----------
delegar(conteudo, 'input', 'input[name="telefone"], input[name="sim-telefone"]', (e, entrada) => {
  entrada.value = formatarTelefone(entrada.value);
});
delegar(conteudo, 'change', 'input[name="sim-telefone"], input[name="sim-nome"]', () => {
  simulador = { telefone: $('[name="sim-telefone"]', conteudo).value, nome: $('[name="sim-nome"]', conteudo).value };
  guardado.gravar(CHAVE_SIMULADOR, simulador);
  ultimaAssinatura['#conversa-simulador'] = '';
  atualizarSimulador();
});
delegar(conteudo, 'click', '[data-botao]', (e, botao) => simular({ botao: botao.dataset.botao }));
delegar(conteudo, 'click', '[data-atender]', (e, botao) => {
  atendendo = botao.dataset.atender;
  conteudo.querySelectorAll('[data-atender]').forEach((b) => b.toggleAttribute('aria-current', b === botao));
  $('#painel-atendimento', conteudo).hidden = false;
  ultimaAssinatura['#conversa-atendimento'] = '';
  atualizarAtendimento();
});
delegar(conteudo, 'click', '[data-devolver]', async () => {
  if (!atendendo) return;
  try {
    await api(`/api/admin/whatsapp/conversas/${encodeURIComponent(atendendo)}/bot`, { metodo: 'POST' });
    avisar('Conversa devolvida ao bot');
    atendendo = null;
    desenhar(await api('/api/admin/whatsapp'));
    atualizarSimulador();
  } catch (erro) {
    mostrarErro(erro);
  }
});
delegar(conteudo, 'click', '[data-copiar]', async () => {
  try {
    await navigator.clipboard.writeText($('#link-url', conteudo).textContent);
    avisar('Link copiado');
  } catch {
    avisar('Não deu para copiar. Selecione o link e copie à mão.', { tipo: 'erro' });
  }
});

conteudo.addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.target;
  if (form.id === 'form-simulador') {
    const texto = form.texto.value.trim();
    if (!texto) return;
    form.texto.value = '';
    await simular({ texto });
    form.texto.focus();
    return;
  }
  if (form.id === 'form-resposta') {
    const texto = form.texto.value.trim();
    const aviso = $('#erro-resposta', conteudo);
    aviso.hidden = true;
    if (!texto || !atendendo) return;
    try {
      const conversa = await api(`/api/admin/whatsapp/conversas/${encodeURIComponent(atendendo)}/responder`, { metodo: 'POST', corpo: { texto } });
      form.texto.value = '';
      desenharConversa('#conversa-atendimento', conversa, { clicavel: false });
      atualizarSimulador();
    } catch (erro) {
      aviso.textContent = erro.message;
      aviso.hidden = false;
    }
    return;
  }
  if (form.id === 'form-link') {
    const erro = $('#erro-telefone', conteudo);
    erro.hidden = true;
    const botao = form.querySelector('button[type="submit"]');
    botao.setAttribute('aria-busy', 'true');
    try {
      const r = await api('/api/admin/whatsapp/link', { metodo: 'POST', corpo: { telefone: form.telefone.value } });
      $('#link-url', conteudo).textContent = r.url;
      $('#link-abrir', conteudo).href = r.url;
      $('#link-validade', conteudo).textContent = `Vale por ${r.validade_minutos / 60} horas. Depois disso o cardápio abre normal, sem o número preenchido.`;
      $('#link-gerado', conteudo).hidden = false;
    } catch (falha) {
      if (falha.codigo === 'validacao') {
        erro.textContent = falha.campos.telefone;
        erro.hidden = false;
        form.telefone.focus();
      } else {
        mostrarErro(falha);
      }
    }
    botao.removeAttribute('aria-busy');
  }
});

/** A tela abriu (a primeira vez ou de novo): busca a situação, desenha e acompanha as conversas abertas. */
export async function montar() {
  try {
    desenhar(await api('/api/admin/whatsapp'));
  } catch (erro) {
    mostrarErro(erro);
    return;
  }
  atualizarSimulador();
  atualizarAtendimento();
  clearInterval(relogio);
  relogio = setInterval(() => {
    if (document.visibilityState !== 'visible') return;
    atualizarSimulador();
    atualizarAtendimento();
  }, ATUALIZAR_MS);
}

export function desmontar() {
  clearInterval(relogio);
}
