// iFood (D76 a D78): conectar a loja, ver se o polling está em dia, os últimos pedidos que vieram do app e o que falhou ao
// devolver uma etapa. Sem credenciais, a tela fica no modo simulado: um botão cria um pedido no formato do iFood, que passa pelo
// mesmo caminho de um pedido real (entra no Kanban, é confirmado e impresso). Só o proprietário abre esta tela.
// Nenhum token nem credencial chega aqui.
import { $, esc, delegar } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { reais, dataHora, hora } from '../shared/formato.js';
import { api } from '../shared/api.js';
import { avisar } from '../shared/avisos.js';
import { STATUS } from '../shared/pedido.js';
import { recipienteDaTela, mostrarErro, confirmar } from './casca.js';
import { fonte } from './servicos.js';

const conteudo = recipienteDaTela();
const ATUALIZAR_MS = 10_000;
const ACOES = { confirm: 'Confirmar', startPreparation: 'Iniciar preparo', readyToPickup: 'Pronto', dispatch: 'Saiu para entrega', requestCancellation: 'Cancelar' };

let situacao = null;
let relogio = 0;

function estadoHtml(s) {
  const item = (ok, texto) => `<li ${ok ? 'data-ok' : ''}><span class="checagem__marca">${icone(ok ? 'check' : 'menos', 14)}</span><span>${texto}</span></li>`;
  if (s.modo === 'simulado') {
    return `
      <section class="bloco">
        <h2 class="bloco__titulo">Modo simulado <span class="selo selo--alerta">sem iFood de verdade</span></h2>
        <p class="bloco__nota">${icone('info', 16)}<span>Faltam as credenciais do aplicativo no servidor (variáveis <strong>IFOOD_CLIENT_ID</strong> e <strong>IFOOD_CLIENT_SECRET</strong>, criadas no Portal do Desenvolvedor do iFood). Enquanto isso, use os botões abaixo: o pedido de teste entra no Kanban com origem iFood, é confirmado e sai na impressora como um pedido real.</span></p>
        <ul class="checagem">
          ${item(false, 'Credenciais do aplicativo do iFood no servidor')}
          ${item(false, 'Loja autorizada no Portal do Parceiro')}
        </ul>
      </section>`;
  }
  const conectado = s.estado === 'conectado';
  const selo = conectado ? (s.polling_atrasado ? '<span class="selo selo--alerta">sem polling recente</span>' : '<span class="selo selo--sucesso">recebendo pedidos</span>')
    : s.estado === 'aguardando_codigo' ? '<span class="selo selo--alerta">aguardando autorização</span>'
    : '<span class="selo selo--perigo">desconectado</span>';
  let acoes = '';
  if (conectado) {
    acoes = `
      <div class="ifood__acoes">
        <button type="button" class="botao botao--secundario" data-ifood="sincronizar">${icone('relogio', 18)}Buscar pedidos agora</button>
        <button type="button" class="botao botao--perigo-suave" data-ifood="desconectar">Desconectar</button>
      </div>`;
  } else if (s.codigo) {
    acoes = `
      <ol class="ifood__passos">
        <li>Abra o <a href="${esc(s.codigo.url)}" target="_blank" rel="noopener">Portal do Parceiro do iFood ${icone('externo', 14)}</a> com o login da loja.</li>
        <li>Digite este código: <code class="codigo ifood__codigo">${esc(s.codigo.user_code)}</code> (vale até ${hora(s.codigo.expira_em)}).</li>
        <li>O iFood mostra um <strong>código de autorização</strong>. Cole aqui:</li>
      </ol>
      <form class="ifood__autorizar" id="form-autorizar">
        <label class="campo">
          <span class="campo__rotulo">Código de autorização</span>
          <input class="entrada" name="codigo" autocomplete="off" maxlength="200" required aria-describedby="erro-codigo">
          <span class="campo__erro" id="erro-codigo" hidden></span>
        </label>
        <button type="submit" class="botao">Concluir conexão</button>
      </form>`;
  } else {
    acoes = `<div><button type="button" class="botao" data-ifood="conectar">${icone('externo', 18)}Conectar loja do iFood</button></div>
      <p class="campo__ajuda">${s.tipo_app === 'centralizado'
        ? 'Aplicativo centralizado: a conexão usa as credenciais do servidor, sem código.'
        : 'Você vai receber um código para digitar no Portal do Parceiro do iFood, como nas plataformas de cardápio.'}</p>`;
  }
  return `
    <section class="bloco">
      <h2 class="bloco__titulo">Conexão ${selo}</h2>
      <ul class="checagem">
        ${item(true, 'Credenciais do aplicativo no servidor')}
        ${item(conectado, s.loja ? `Loja: <strong>${esc(s.loja.nome || s.loja.id)}</strong>` : 'Loja autorizada')}
        ${item(conectado && !s.polling_atrasado, s.ultimo_polling_em ? `Último polling às ${hora(s.ultimo_polling_em)} (a cada 30 s; é o que mantém a loja aberta no app)` : 'Polling de pedidos')}
      </ul>
      ${s.ultimo_erro ? `<p class="detalhe__obs">${icone('alerta', 18)}<span><strong>Último erro (${dataHora(s.ultimo_erro.em)}):</strong> ${esc(s.ultimo_erro.mensagem)}</span></p>` : ''}
      ${acoes}
    </section>`;
}

function ajustesHtml(s) {
  return `
    <section class="bloco">
      <h2 class="bloco__titulo">Como os pedidos entram</h2>
      <label class="interruptor"><input type="checkbox" name="confirmar_auto" ${s.confirmar_auto ? 'checked' : ''}><span class="interruptor__trilho"></span><span>Confirmar no iFood assim que o pedido chegar</span></label>
      <p class="campo__ajuda">Ligado: o cliente vê "pedido confirmado" na hora e a comanda já sai. Desligado: o pedido é confirmado quando alguém clicar em "Iniciar preparo". O iFood cancela pedidos que ficam muito tempo sem confirmação.</p>
      <p class="bloco__nota">${icone('info', 16)}<span>Cada etapa do Kanban volta para o iFood: <strong>Iniciar preparo</strong> → em preparo; <strong>Marcar pronto</strong> → pronto para retirar (retirada ou entregador do iFood); <strong>Saiu para entrega</strong> → saiu (só quando a entrega é da loja); <strong>Cancelar</strong> → pedido de cancelamento com o motivo escrito.</span></p>
    </section>`;
}

function testeHtml(s) {
  return `
    <section class="bloco">
      <h2 class="bloco__titulo">Pedido de teste</h2>
      <p class="campo__ajuda">Cria um pedido no formato do iFood com itens do seu cardápio, marcado como teste. Não chama o iFood: serve para treinar a equipe e conferir a comanda.${s.modo === 'real' ? ' Os pedidos de teste do próprio iFood (loja de teste do Portal do Desenvolvedor) chegam sozinhos, pelo polling.' : ''}</p>
      <div class="ifood__acoes">
        <button type="button" class="botao" data-simular="DELIVERY">${icone('mais', 18)}Simular entrega</button>
        <button type="button" class="botao botao--secundario" data-simular="TAKEOUT">${icone('mais', 18)}Simular retirada</button>
      </div>
    </section>`;
}

function ultimosHtml(s) {
  const linha = (u) => `
    <tr>
      <td class="numero">#${u.pedido_id}</td>
      <td class="numero">${esc(u.display_id)}${u.simulado ? ' <span class="selo selo--alerta">teste</span>' : ''}</td>
      <td>${esc(u.cliente_nome)}</td>
      <td>${STATUS[u.status]?.rotulo ?? esc(u.status)}</td>
      <td>${esc(u.rotulo_ifood)}</td>
      <td class="numero">${reais(u.total_centavos)}${u.pago_online ? '' : ' (cobrar)'}</td>
      <td class="numero">${dataHora(u.criado_em)}</td>
    </tr>`;
  return `
    <section class="bloco">
      <h2 class="bloco__titulo">Últimos pedidos do iFood</h2>
      ${s.ultimos.length ? `<div class="tabela-rolagem"><table class="tabela">
        <thead><tr><th>Pedido</th><th>Nº iFood</th><th>Cliente</th><th>No quadro</th><th>No iFood</th><th>Total</th><th>Quando</th></tr></thead>
        <tbody>${s.ultimos.map(linha).join('')}</tbody></table></div>` : '<p class="campo__ajuda">Nenhum pedido do iFood ainda.</p>'}
      ${s.acoes_pendentes ? `<p class="campo__ajuda">${s.acoes_pendentes} etapa(s) esperando para ir ao iFood (nova tentativa sozinha).</p>` : ''}
      ${s.acoes_com_falha.length ? `
        <h3 class="detalhe__titulo" style="margin-top: var(--e-4);">Etapas que o iFood não recebeu</h3>
        <ul class="fila-avisos">${s.acoes_com_falha.map((f) => `
          <li data-erro><strong>Pedido #${f.pedido_id}</strong> · ${esc(ACOES[f.acao] ?? f.acao)} · ${dataHora(f.em)}${f.erro ? ` · ${esc(f.erro)}` : ''}</li>`).join('')}</ul>
        <p class="campo__ajuda">Faça essa etapa no Gestor de Pedidos do iFood para o cliente não ficar sem resposta.</p>` : ''}
    </section>`;
}

function desenhar(s) {
  situacao = s;
  conteudo.innerHTML = `
    <div class="cabecalho-tela"><h1 class="cabecalho-tela__titulo">iFood</h1></div>
    <div class="config">
      ${estadoHtml(s)}
      ${ajustesHtml(s)}
      ${testeHtml(s)}
      ${ultimosHtml(s)}
    </div>`;
}

async function atualizar() {
  try {
    const nova = await api('/api/admin/ifood');
    // Não apaga o código que o dono está digitando.
    if (document.activeElement?.closest('#form-autorizar')) return;
    if (JSON.stringify(nova) !== JSON.stringify(situacao)) desenhar(nova);
  } catch { /* tenta no próximo ciclo */ }
}

// ---------- Eventos ----------
delegar(conteudo, 'click', '[data-ifood]', async (e, botao) => {
  const acao = botao.dataset.ifood;
  if (acao === 'desconectar' && !(await confirmar({
    titulo: 'Desconectar o iFood?',
    texto: 'Os pedidos do iFood param de chegar neste painel até você conectar de novo. Os pedidos que já entraram continuam no quadro.',
    confirmar: 'Desconectar',
    perigo: true,
  }))) return;
  botao.setAttribute('aria-busy', 'true');
  try {
    const r = await api(`/api/admin/ifood/${acao}`, { metodo: 'POST' });
    desenhar(r.situacao ?? r);
    if (acao === 'sincronizar') {
      avisar(r.resultado.pedidos ? `${r.resultado.pedidos} pedido(s) novo(s) do iFood` : 'Nenhum pedido novo no iFood');
      fonte.sincronizar();
    }
  } catch (erro) {
    botao.removeAttribute('aria-busy');
    mostrarErro(erro);
  }
});

delegar(conteudo, 'click', '[data-simular]', async (e, botao) => {
  botao.setAttribute('aria-busy', 'true');
  try {
    const r = await api('/api/admin/ifood/simular', { metodo: 'POST', corpo: { tipo: botao.dataset.simular } });
    desenhar(r.situacao);
    avisar(`Pedido de teste do iFood criado: #${r.pedido_id}`);
    fonte.sincronizar();
  } catch (erro) {
    botao.removeAttribute('aria-busy');
    mostrarErro(erro);
  }
});

delegar(conteudo, 'change', 'input[name="confirmar_auto"]', async (e, entrada) => {
  try {
    desenhar(await api('/api/admin/ifood/ajustes', { metodo: 'PUT', corpo: { confirmar_auto: entrada.checked } }));
    avisar(entrada.checked ? 'Pedidos do iFood serão confirmados na hora' : 'Pedidos do iFood serão confirmados no "Iniciar preparo"');
  } catch (erro) {
    entrada.checked = !entrada.checked;
    mostrarErro(erro);
  }
});

conteudo.addEventListener('submit', async (e) => {
  if (e.target.id !== 'form-autorizar') return;
  e.preventDefault();
  const form = e.target;
  const botao = form.querySelector('button[type="submit"]');
  const erroEl = $('#erro-codigo', form);
  botao.setAttribute('aria-busy', 'true');
  try {
    desenhar(await api('/api/admin/ifood/autorizar', { metodo: 'POST', corpo: { codigo: form.codigo.value } }));
    avisar('Loja do iFood conectada');
  } catch (erro) {
    botao.removeAttribute('aria-busy');
    if (erro.codigo === 'validacao' || erro.status === 502) {
      erroEl.textContent = erro.campos?.codigo ?? erro.message;
      erroEl.hidden = false;
      form.codigo.setAttribute('aria-invalid', 'true');
      form.codigo.focus();
    } else {
      mostrarErro(erro);
    }
  }
});

export async function montar() {
  try {
    desenhar(await api('/api/admin/ifood'));
  } catch (erro) {
    mostrarErro(erro);
    return;
  }
  clearInterval(relogio);
  relogio = setInterval(atualizar, ATUALIZAR_MS);
}

export function desmontar() {
  clearInterval(relogio);
}
