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
let editando = false;   // o dono está digitando nas credenciais: a atualização automática não apaga

function estadoHtml(s) {
  const item = (ok, texto) => `<li ${ok ? 'data-ok' : ''}><span class="checagem__marca">${icone(ok ? 'check' : 'menos', 14)}</span><span>${texto}</span></li>`;
  if (s.modo === 'simulado') {
    return `
      <section class="bloco">
        <h2 class="bloco__titulo">Modo simulado <span class="selo selo--alerta">sem iFood de verdade</span></h2>
        <p class="bloco__nota">${icone('info', 16)}<span>Faltam o Client ID e o Client Secret do aplicativo (você os recebe ao criar o aplicativo no Portal do Desenvolvedor do iFood). Preencha em <strong>Credenciais do aplicativo</strong>, logo abaixo. Enquanto isso, use os botões de pedido de teste mais adiante: o pedido entra no Kanban com origem iFood, é confirmado e sai na impressora como um pedido real.</span></p>
        <ul class="checagem">
          ${item(false, 'Credenciais do aplicativo (preencha abaixo)')}
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
        <li>Abra o <a href="${esc(s.codigo.url)}" target="_blank" rel="noopener">Portal do Parceiro do iFood ${icone('externo', 14)}</a> com o login da loja. Se preferir, entre em <strong>Serviços e soluções › Integrações › Ativar integração por código</strong>.</li>
        <li>Digite este código: <code class="codigo ifood__codigo">${esc(s.codigo.user_code)}</code> (vale até ${hora(s.codigo.expira_em)}, 10 minutos).</li>
        <li>No quadro <strong>Autorizar aplicativo</strong>, clique em <strong>Autorizar</strong>. O iFood mostra um <strong>código de autorização</strong> (vale só 5 minutos). Cole aqui:</li>
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
        ? 'Aplicativo centralizado: a conexão usa o Client ID e o Client Secret acima, sem código. A loja precisa já estar liberada para o aplicativo (no aplicativo de teste, a loja de teste já vem liberada).'
        : 'Você vai receber um código para digitar no Portal do Parceiro do iFood, como nas plataformas de cardápio.'}</p>`;
  }
  return `
    <section class="bloco">
      <h2 class="bloco__titulo">Conexão ${selo}</h2>
      <ul class="checagem">
        ${item(true, `Credenciais do aplicativo ${s.credenciais.origem === 'ambiente' ? '(vêm do servidor)' : '(salvas aqui)'}`)}
        ${item(conectado, s.loja ? `Loja: <strong>${esc(s.loja.nome || s.loja.id)}</strong>` : 'Loja autorizada')}
        ${item(conectado && !s.polling_atrasado, s.ultimo_polling_em ? `Último polling às ${hora(s.ultimo_polling_em)} (a cada 30 s; é o que mantém a loja aberta no app)` : 'Polling de pedidos')}
      </ul>
      ${s.sem_loja ? `<p class="detalhe__obs">${icone('info', 18)}<span><strong>Nenhuma loja liberada para este aplicativo ainda.</strong> ${s.tipo_app === 'centralizado' ? 'No Portal do Desenvolvedor, em Meus Apps › Permissões, peça acesso à loja pelo ID ou CNPJ; o responsável aprova no Portal do Parceiro.' : 'Confira se o lojista concluiu a autorização.'} A permissão pode levar até 10 minutos para valer; o sistema procura de novo sozinho a cada minuto.</span></p>` : ''}
      ${s.ultimo_erro ? `<p class="detalhe__obs">${icone('alerta', 18)}<span><strong>Último erro (${dataHora(s.ultimo_erro.em)}):</strong> ${esc(s.ultimo_erro.mensagem)}</span></p>` : ''}
      ${acoes}
    </section>`;
}

function credenciaisHtml(s) {
  const c = s.credenciais;
  const tipos = [
    ['distribuido', 'Distribuído (o lojista autoriza com um código)'],
    ['centralizado', 'Centralizado (a loja é liberada no Portal do Desenvolvedor)'],
  ];
  if (!c.editavel) {
    return `
      <section class="bloco">
        <h2 class="bloco__titulo">Credenciais do aplicativo <span class="selo selo--sucesso">vêm do servidor</span></h2>
        <p class="campo__ajuda">O Client ID <code class="codigo ifood__id">${esc(c.client_id)}</code> e o Client Secret estão nas variáveis de ambiente do servidor (IFOOD_CLIENT_ID e IFOOD_CLIENT_SECRET) e valem primeiro. Para trocá-los por esta tela, apague essas duas variáveis na Render.</p>
      </section>`;
  }
  return `
    <section class="bloco">
      <h2 class="bloco__titulo">Credenciais do aplicativo ${c.secret_ilegivel ? '<span class="selo selo--perigo">secret ilegível</span>' : c.secret_salvo && c.client_id ? '<span class="selo selo--sucesso">salvas</span>' : '<span class="selo selo--alerta">não preenchidas</span>'}</h2>
      <p class="bloco__nota">${icone('info', 16)}<span>No <a href="https://developer.ifood.com.br" target="_blank" rel="noopener">Portal do Desenvolvedor do iFood</a>, em <strong>Meus Apps</strong>, copie (ou crie o aplicativo e copie) e copie o <strong>Client ID</strong> e o <strong>Client Secret</strong>. O secret é guardado cifrado e nunca volta para esta tela.</span></p>
      ${c.app_key_ok ? '' : `<p class="detalhe__obs">${icone('alerta', 18)}<span>O servidor está sem <strong>APP_KEY</strong>, que protege o Client Secret. Defina a variável APP_KEY na Render antes de salvar.</span></p>`}
      ${c.secret_ilegivel ? `<p class="detalhe__obs">${icone('alerta', 18)}<span>O Client Secret salvo não abre mais (a APP_KEY do servidor mudou). Digite o secret de novo.</span></p>` : ''}
      <form class="config" id="form-credenciais" novalidate autocomplete="off">
        <div class="bloco__grade">
          <label class="campo">
            <span class="campo__rotulo">Client ID</span>
            <input class="entrada" name="client_id" value="${esc(c.client_id)}" maxlength="120" spellcheck="false" autocomplete="off" aria-describedby="erro-client_id">
            <span class="campo__erro" id="erro-client_id" hidden></span>
          </label>
          <label class="campo">
            <span class="campo__rotulo">Client Secret</span>
            <input class="entrada" type="password" name="client_secret" maxlength="300" spellcheck="false" autocomplete="new-password"
                   placeholder="${c.secret_salvo && !c.secret_ilegivel ? '•••••••• (salvo; deixe em branco para manter)' : ''}" aria-describedby="erro-client_secret">
            <span class="campo__erro" id="erro-client_secret" hidden></span>
          </label>
        </div>
        <label class="campo">
          <span class="campo__rotulo">Tipo do aplicativo</span>
          <select class="entrada" name="tipo_app" aria-describedby="erro-tipo_app">
            ${tipos.map(([valor, rotulo]) => `<option value="${valor}" ${s.tipo_app === valor ? 'selected' : ''}>${esc(rotulo)}</option>`).join('')}
          </select>
          <span class="campo__ajuda"><strong>Distribuído</strong>: o lojista digita um código no Portal do Parceiro e autoriza o aplicativo (modelo da Brendi). <strong>Centralizado</strong>: o aplicativo usa só o Client ID e o Client Secret, e a loja é liberada em Meus Apps › Permissões. Tem de ser o mesmo tipo escolhido ao criar o aplicativo no Portal do Desenvolvedor. O aplicativo de teste que o iFood cria na sua conta de desenvolvedor usa as credenciais direto, sem código: use <strong>Centralizado</strong> para testar. Trocar o tipo, o Client ID ou o secret desconecta a loja: conecte de novo depois.</span>
          <span class="campo__erro" id="erro-tipo_app" hidden></span>
        </label>
        <div class="ifood__acoes">
          <button type="submit" class="botao" ${c.app_key_ok ? '' : 'disabled'}>${icone('check', 18)}Salvar credenciais</button>
          ${c.client_id || c.secret_salvo ? '<button type="button" class="botao botao--perigo-suave" data-remover-credenciais>Remover credenciais</button>' : ''}
        </div>
      </form>
    </section>`;
}

function ajustesHtml(s) {
  return `
    <section class="bloco">
      <h2 class="bloco__titulo">Como os pedidos entram</h2>
      <label class="interruptor"><input type="checkbox" name="confirmar_auto" ${s.confirmar_auto ? 'checked' : ''}><span class="interruptor__trilho"></span><span>Confirmar no iFood assim que o pedido chegar</span></label>
      <p class="campo__ajuda">Ligado: o cliente vê "pedido confirmado" na hora e a comanda já sai. Desligado: o pedido é confirmado quando alguém clicar em "Iniciar preparo". <strong>Atenção: sem confirmação em ${s.prazo_confirmacao_min ?? 8} minutos o iFood cancela o pedido sozinho.</strong></p>
      <p class="bloco__nota">${icone('info', 16)}<span>Cada etapa do Kanban volta para o iFood: <strong>Iniciar preparo</strong> → em preparo; <strong>Marcar pronto</strong> → pronto para retirar (<code>readyToPickup</code>, em qualquer tipo de entrega); <strong>Saiu para entrega</strong> → <code>dispatch</code> (só quando a entrega é da loja); <strong>Cancelar</strong> → você escolhe um dos motivos que o próprio iFood aceita para aquele pedido. Quando o Gestor de Pedidos do iFood (ou o iFood) mudar a etapa, o quadro acompanha.</span></p>
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
  editando = false;
  conteudo.innerHTML = `
    <div class="cabecalho-tela"><h1 class="cabecalho-tela__titulo">iFood</h1></div>
    <div class="config">
      ${estadoHtml(s)}
      ${credenciaisHtml(s)}
      ${ajustesHtml(s)}
      ${testeHtml(s)}
      ${ultimosHtml(s)}
    </div>`;
}

async function atualizar() {
  try {
    const nova = await api('/api/admin/ifood');
    // Não apaga o que o dono está digitando (código de autorização ou credenciais).
    if (editando || document.activeElement?.closest('#form-autorizar, #form-credenciais')) return;
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

delegar(conteudo, 'input', '#form-credenciais', () => { editando = true; });

delegar(conteudo, 'click', '[data-remover-credenciais]', async (e, botao) => {
  if (!(await confirmar({
    titulo: 'Remover as credenciais do iFood?',
    texto: 'A loja é desconectada e o Client ID e o Client Secret salvos são apagados. Os pedidos que já entraram continuam no quadro.',
    confirmar: 'Remover',
    perigo: true,
  }))) return;
  botao.setAttribute('aria-busy', 'true');
  try {
    desenhar(await api('/api/admin/ifood/credenciais', { metodo: 'DELETE' }));
    avisar('Credenciais removidas');
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
  if (e.target.id === 'form-credenciais') {
    e.preventDefault();
    const form = e.target;
    const botao = form.querySelector('button[type="submit"]');
    for (const el of form.querySelectorAll('.campo__erro')) el.hidden = true;
    botao.setAttribute('aria-busy', 'true');
    try {
      const era = situacao.estado;
      const nova = await api('/api/admin/ifood/credenciais', {
        metodo: 'PUT',
        corpo: { client_id: form.client_id.value, client_secret: form.client_secret.value, tipo_app: form.tipo_app.value },
      });
      desenhar(nova);
      avisar(era === 'conectado' && nova.estado !== 'conectado' ? 'Credenciais salvas. A loja foi desconectada: conecte de novo.' : 'Credenciais salvas');
    } catch (erro) {
      botao.removeAttribute('aria-busy');
      if (erro.codigo === 'validacao') {
        for (const [campo, mensagem] of Object.entries(erro.campos)) {
          const el = $(`#erro-${campo}`, form);
          if (el) { el.textContent = mensagem; el.hidden = false; }
        }
        form.querySelector('.campo__erro:not([hidden])')?.scrollIntoView({ block: 'center' });
      } else {
        mostrarErro(erro);
      }
    }
    return;
  }
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
