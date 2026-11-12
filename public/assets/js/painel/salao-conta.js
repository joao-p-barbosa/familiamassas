// A conta de um atendimento do salão: o detalhe (pedidos, produtos e personalizações) e o fechamento (resumo, forma de
// pagamento e confirmação). Usado pela coluna "Salão" do quadro de Pedidos.
// O valor da conta é sempre o do servidor; ao fechar, o total que a atendente conferiu volta junto: se um pedido entrou
// ou saiu nesse meio-tempo, o servidor recusa e a tela mostra a conta atualizada.
import { $, esc, delegar } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { reais, hora, dataHora, plural } from '../shared/formato.js';
import { api } from '../shared/api.js';
import { abrirFolha, fecharFolha } from '../shared/folha.js';
import { avisar } from '../shared/avisos.js';
import { STATUS, linhasMontagem, resumoMontagem } from '../shared/pedido.js';
import { MODALIDADES, FORMAS_DE_PAGAMENTO, rotuloDaModalidade } from '../shared/atendimento.js';
import { mostrarErro } from './casca.js';
import { navegar } from './roteador.js';
import { SELO_STATUS, pedirCancelamento } from './detalhe.js';

const lateral = $('#folha-lateral');
const dialogo = $('#folha-dialogo');

let conta = null;            // o atendimento aberto no detalhe
let aoAlterar = () => {};    // a tela que abriu quer saber quando algo mudou (para redesenhar o quadro)
let fechamento = null;       // { conta, pagamento, enviando }

const aberta = (c) => c.status === 'aberto';

// ---------- Detalhe ----------
/** Abre o detalhe do atendimento no painel lateral. `somenteLeitura`: sem botões de ação (histórico do proprietário). */
export async function abrirConta(id, { aoMudar = () => {}, somenteLeitura = false } = {}) {
  aoAlterar = aoMudar;
  try {
    conta = await api(`/api/admin/atendimentos/${id}`);
  } catch (erro) {
    mostrarErro(erro);
    return;
  }
  desenharConta(somenteLeitura);
  abrirFolha(lateral, { aoFechar: () => (conta = null) });
}

/** O quadro consulta o servidor de tempos em tempos: com o detalhe aberto, ele se atualiza junto. */
export async function atualizarContaAberta() {
  if (!conta || !lateral.open || fechamento?.enviando) return;
  try {
    conta = await api(`/api/admin/atendimentos/${conta.id}`);
    desenharConta();
  } catch {
    /* sem conexão agora: o quadro já avisa; tenta de novo no próximo ciclo */
  }
}

function pedidoHtml(p, contaAberta, somenteLeitura) {
  const cancelavel = contaAberta && !somenteLeitura && !['concluido', 'cancelado'].includes(p.status);
  return `
    <li class="conta-pedido" ${p.status === 'cancelado' ? 'data-cancelado' : ''}>
      <div class="conta-pedido__topo">
        <strong>Pedido #${p.id}</strong>
        <span class="selo ${SELO_STATUS[p.status]}">${STATUS[p.status].rotulo}</span>
        <span class="conta-pedido__hora numero">${hora(p.criado_em)}</span>
      </div>
      <ul class="revisao-itens">
        ${p.itens.map((item) => `
          <li class="revisao-itens__item">
            <div class="revisao-itens__topo"><p><span class="numero">${item.quantidade}×</span> ${esc(item.nome)}</p><p class="numero">${reais(item.preco_unit_centavos * item.quantidade)}</p></div>
            ${item.montagem ? `<ul class="montagem">${linhasMontagem(item.montagem)}</ul>` : ''}
            ${item.observacoes ? `<p class="detalhe__obs detalhe__obs--item">${icone('alerta', 16)}<span>${esc(item.observacoes)}</span></p>` : ''}
          </li>`).join('')}
      </ul>
      ${!somenteLeitura && contaAberta && p.status === 'pronto' ? `<button type="button" class="botao botao--p" data-conta="servir">Marcar servido${icone('check', 16)}</button>` : ''}
      ${cancelavel ? `<button type="button" class="botao botao--perigo-suave botao--p" data-conta="cancelar-pedido" data-pedido="${p.id}">Cancelar este pedido</button>` : ''}
    </li>`;
}

function desenharConta(somenteLeitura = lateral.dataset.somenteLeitura === 'sim') {
  if (!conta) return;
  lateral.dataset.somenteLeitura = somenteLeitura ? 'sim' : 'nao';
  const c = conta;
  const rolagem = $('.folha__corpo', lateral)?.scrollTop ?? 0;
  lateral.innerHTML = `
    <header class="folha__topo">
      <h2 class="folha__titulo" id="folha-lateral-titulo">${esc(c.cliente_nome)}</h2>
      <button type="button" class="botao botao--fantasma botao--icone" data-fechar aria-label="Fechar">${icone('fechar')}</button>
    </header>
    <div class="folha__corpo detalhe">
      <div class="detalhe__selos">
        <span class="selo">${icone(MODALIDADES[c.modalidade].icone, 14)}${esc(rotuloDaModalidade(c))}</span>
        <span class="selo ${aberta(c) ? 'selo--sucesso' : ''}">${aberta(c) ? 'Conta aberta' : 'Conta encerrada'}</span>
        <span class="selo">Atend. ${c.id}</span>
      </div>
      <p class="conta-quando">Aberto às ${hora(c.aberto_em)}${c.aberto_por_nome ? ` por ${esc(c.aberto_por_nome)}` : ''}.</p>

      <section class="detalhe__bloco">
        <h3 class="detalhe__titulo">Pedidos (${c.pedidos.length})</h3>
        <ol class="conta-pedidos">${c.pedidos.map((p) => pedidoHtml(p, aberta(c), somenteLeitura)).join('')}</ol>
      </section>

      <section class="detalhe__bloco">
        <dl class="totais">
          <div><dt>${plural(c.quantidade_itens, 'item', 'itens')} em ${plural(c.pedidos_ativos, 'pedido', 'pedidos')}</dt><dd></dd></div>
          <div class="totais__total"><dt>${aberta(c) ? 'Total da conta até agora' : 'Total cobrado'}</dt><dd class="numero">${reais(c.total_centavos)}</dd></div>
        </dl>
        ${aberta(c) ? '' : `<p class="conta-pago">${icone('check', 16)}<span>${c.pagamento ? `Pago em ${FORMAS_DE_PAGAMENTO[c.pagamento]}` : 'Sem valor a cobrar'}, em ${dataHora(c.encerrado_em)}${c.encerrado_por_nome ? ` (${esc(c.encerrado_por_nome)})` : ''}.</span></p>`}
      </section>
    </div>
    ${aberta(c) && !somenteLeitura ? `
    <footer class="folha__rodape">
      <button type="button" class="botao botao--g botao--bloco" data-conta="fechar">Fechar conta · ${reais(c.total_centavos)}</button>
      <button type="button" class="botao botao--secundario" data-conta="adicionar">${icone('mais', 18)}Adicionar produtos</button>
    </footer>` : ''}`;
  $('.folha__corpo', lateral).scrollTop = rolagem;
}

delegar(lateral, 'click', '[data-conta]', async (e, botao) => {
  if (!conta) return;
  switch (botao.dataset.conta) {
    case 'adicionar': {
      const id = conta.id;
      await fecharFolha(lateral);
      navegar(`/painel/atendimento?id=${id}`);
      break;
    }
    case 'fechar':
      abrirFechamento(conta.id);
      break;
    case 'servir':
      botao.setAttribute('aria-busy', 'true');
      await servir(conta.id);
      break;
    case 'cancelar-pedido':
      pedirCancelamento(Number(botao.dataset.pedido));
      break;
  }
});

/** "Marcar servido": passa para Concluído os pedidos prontos do atendimento. */
export async function servir(id) {
  try {
    const atualizada = await api(`/api/admin/atendimentos/${id}/servir`, { metodo: 'POST' });
    if (conta?.id === id) {
      conta = atualizada;
      if (lateral.open) desenharConta();
    }
    avisar(`Pedidos de ${atualizada.cliente_nome} servidos`);
    aoAlterar();
  } catch (erro) {
    mostrarErro(erro);
  }
}

// ---------- Fechamento ----------
/** Abre o resumo da conta com a escolha da forma de pagamento. */
export async function abrirFechamento(id, { aoMudar } = {}) {
  if (aoMudar) aoAlterar = aoMudar;
  let atual;
  try {
    atual = await api(`/api/admin/atendimentos/${id}`);
  } catch (erro) {
    mostrarErro(erro);
    return;
  }
  if (!aberta(atual)) {
    avisar('Esta conta já foi fechada.', { tipo: 'erro' });
    aoAlterar();
    return;
  }
  fechamento = { conta: atual, pagamento: null, enviando: false };
  desenharFechamento();
  if (!dialogo.open) abrirFolha(dialogo, { aoFechar: () => (fechamento = null) });
}

function desenharFechamento(mensagem = '') {
  const c = fechamento.conta;
  const ativos = c.pedidos.filter((p) => p.status !== 'cancelado');
  const cancelados = c.pedidos.filter((p) => p.status === 'cancelado');
  const emProducao = ativos.filter((p) => ['novo', 'em_preparo'].includes(p.status));
  const cobrar = c.total_centavos > 0;
  dialogo.innerHTML = `
    <header class="folha__topo">
      <h2 class="folha__titulo" id="folha-dialogo-titulo">Fechar a conta de ${esc(c.cliente_nome)}</h2>
      <button type="button" class="botao botao--fantasma botao--icone" data-fechar aria-label="Fechar">${icone('fechar')}</button>
    </header>
    <form class="folha__corpo fechar-conta" id="form-fechar-conta" novalidate>
      <p class="conta-quando">${esc(rotuloDaModalidade(c))} · atendimento ${c.id} · ${plural(ativos.length, 'pedido', 'pedidos')}</p>
      ${ativos.length ? `
      <ul class="conta-linhas" aria-label="Produtos da conta">
        ${ativos.flatMap((p) => p.itens.map((item) => `
          <li class="conta-linha">
            <span class="conta-linha__nome"><span class="numero">${item.quantidade}×</span> ${esc(item.nome)}
              ${item.montagem ? `<small>${esc(resumoMontagem({ tamanho: item.montagem.tamanho, massa: item.montagem.massa, molho: item.montagem.molho }))}</small>` : ''}</span>
            <span class="conta-linha__unit numero">${reais(item.preco_unit_centavos)} cada</span>
            <strong class="conta-linha__total numero">${reais(item.preco_unit_centavos * item.quantidade)}</strong>
          </li>`)).join('')}
      </ul>` : '<p class="conta-vazia">Todos os pedidos desta conta foram cancelados.</p>'}
      ${cancelados.length ? `<p class="conta-cancelados">${plural(cancelados.length, 'pedido cancelado não entra', 'pedidos cancelados não entram')} na conta.</p>` : ''}
      ${emProducao.length ? `<p class="faixa-aviso">${icone('relogio', 20)}<span>Ainda ${emProducao.length === 1 ? 'há 1 pedido' : `há ${emProducao.length} pedidos`} na cozinha. Você pode fechar a conta mesmo assim, e o pedido continua no quadro até ficar pronto.</span></p>` : ''}
      <dl class="totais"><div class="totais__total"><dt>Total da conta</dt><dd class="numero">${reais(c.total_centavos)}</dd></div></dl>
      ${cobrar ? `
      <fieldset class="grupo-opcoes grupo-opcoes--tres">
        <legend class="campo__rotulo">Como o cliente pagou?</legend>
        ${Object.entries(FORMAS_DE_PAGAMENTO).map(([forma, rotulo]) => `
          <label class="opcao opcao--figura">
            <input class="opcao__entrada" type="radio" name="pagamento" value="${forma}" ${fechamento.pagamento === forma ? 'checked' : ''}>
            <span class="opcao__marca">${icone('check', 14)}</span>
            <span class="opcao__figura">${icone(forma, 22)}</span>
            <span class="opcao__corpo"><span class="opcao__titulo">${rotulo}</span></span>
          </label>`).join('')}
      </fieldset>` : '<p class="conta-vazia">Não há valor a cobrar. O atendimento será encerrado sem pagamento.</p>'}
      <p class="campo__erro" id="erro-fechar-conta" role="alert" ${mensagem ? '' : 'hidden'}>${esc(mensagem)}</p>
    </form>
    <footer class="folha__rodape folha__rodape--acoes">
      <button type="button" class="botao botao--secundario" data-fechar>Voltar</button>
      <button type="submit" form="form-fechar-conta" class="botao botao--g botao--com-valor" id="confirmar-fechamento"><span>${cobrar ? 'Confirmar pagamento' : 'Encerrar'}</span><span class="botao__valor">${reais(c.total_centavos)}</span></button>
    </footer>`;
}

delegar(dialogo, 'change', 'input[name="pagamento"]', (e, entrada) => {
  if (!fechamento) return;
  fechamento.pagamento = entrada.value;
  $('#erro-fechar-conta').hidden = true;
});

dialogo.addEventListener('submit', async (e) => {
  if (!fechamento || e.target.id !== 'form-fechar-conta') return;
  e.preventDefault();
  if (fechamento.enviando) return;
  const c = fechamento.conta;
  if (c.total_centavos > 0 && !fechamento.pagamento) {
    const erro = $('#erro-fechar-conta');
    erro.hidden = false;
    erro.textContent = 'Escolha a forma de pagamento para fechar a conta.';
    return;
  }
  fechamento.enviando = true;
  const botao = $('#confirmar-fechamento');
  botao.setAttribute('aria-busy', 'true');
  try {
    const fechada = await api(`/api/admin/atendimentos/${c.id}/fechar`, {
      metodo: 'POST',
      corpo: { pagamento: fechamento.pagamento ?? '', total_esperado_centavos: c.total_centavos },
    });
    fechamento.enviando = false;
    await fecharFolha(dialogo);
    avisar(`Conta de ${fechada.cliente_nome} fechada${fechada.pagamento ? ` · ${FORMAS_DE_PAGAMENTO[fechada.pagamento]}` : ''} · ${reais(fechada.total_centavos)}`);
    if (conta?.id === fechada.id) {
      conta = fechada;
      if (lateral.open) desenharConta();
    }
    aoAlterar();
  } catch (erro) {
    fechamento.enviando = false;
    if (erro.status === 401) {
      mostrarErro(erro);
      return;
    }
    if (['total_mudou', 'ja_encerrado'].includes(erro.codigo)) {
      // A conta mudou enquanto era conferida: mostra a conta como está agora.
      aoAlterar();
      if (erro.codigo === 'ja_encerrado') {
        await fecharFolha(dialogo);
        avisar(erro.message, { tipo: 'erro' });
        return;
      }
      try {
        fechamento.conta = await api(`/api/admin/atendimentos/${c.id}`);
        desenharFechamento(erro.message);
      } catch (falha) {
        mostrarErro(falha);
      }
      return;
    }
    botao.removeAttribute('aria-busy');
    const aviso = $('#erro-fechar-conta');
    aviso.hidden = false;
    aviso.textContent = Object.values(erro.campos ?? {})[0] ?? erro.message;
  }
});
