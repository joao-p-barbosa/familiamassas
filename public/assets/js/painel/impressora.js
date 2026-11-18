// Impressora (D79 a D81): onde a comanda sai e como ela sai.
//   * Computador da loja (agente, recomendado): um programa pequeno no computador ligado à impressora USB busca as comandas e
//     imprime sozinho, sem janela de impressão e com o painel fechado. A tela mostra se ele está conectado e as impressoras
//     que ele achou no Windows; a impressora, o papel, a letra e as vias são escolhidos aqui e valem na hora.
//   * Este navegador: o caminho antigo (D6/D22), com o Chrome em --kiosk-printing.
// Só o proprietário abre esta tela. O código do agente aparece uma vez só, quando é gerado.
import { $, $$, esc, delegar } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { dataHora, hora } from '../shared/formato.js';
import { api } from '../shared/api.js';
import { avisar } from '../shared/avisos.js';
import { recipienteDaTela, mostrarErro, confirmar, ajustes as ajustesDoPainel } from './casca.js';
import { imprimirTeste } from './impressao.js';

const conteudo = recipienteDaTela();
const ATUALIZAR_MS = 5000;
const ORIGENS = [['cardapio', 'Cardápio'], ['whatsapp', 'WhatsApp'], ['ifood', 'iFood'], ['balcao', 'Salão (mesa e pra viagem)']];
const LARGURAS = [[58, '58 mm (cupom pequeno)'], [72, '72 mm'], [80, '80 mm (cupom padrão)'], [100, '100 mm (etiqueta 4")'], [104, '104 mm']];
const PAPEIS = [[0, 'Rolo contínuo (cupom)'], [150, 'Etiqueta de 150 mm (100 × 150)'], [100, 'Etiqueta de 100 mm'], [-1, 'Outra altura…']];
const FONTES = [['p', 'Pequena'], ['m', 'Média'], ['g', 'Grande']];
const LINGUAGENS = [['escpos', 'Impressora de cupom (ESC/POS direto)'], ['tspl', 'Impressora de etiqueta (TSPL direto, também com rolo contínuo)'], ['driver', 'Pelo driver do Windows']];
const ESTADOS = { pendente: 'Na fila', enviado: 'Imprimindo', feito: 'Impressa', falhou: 'Falhou' };
const TIPOS = { auto: 'Automática', reimpressao: 'Reimpressão', teste: 'Teste' };
const STATUS_WINDOWS = {
  Normal: 'pronta', Printing: 'imprimindo', Paused: 'pausada', Error: 'com erro', Offline: 'desligada', PaperOut: 'sem papel',
  PaperJam: 'papel enroscado', NotAvailable: 'indisponível', UserIntervention: 'precisa de atenção', DoorOpen: 'tampa aberta',
};

let situacao = null;
let teste = null;          // teste de conexão em andamento ou o último resultado: { rodando, passos: [{ estado, texto, dica }] }
let codigoGerado = null;   // { codigo, servidor }: só nesta tela, até sair dela
let editando = false;      // o dono mexeu no formulário: a atualização automática não desfaz
let relogio = 0;

const opcoes = (lista, atual) => lista.map(([valor, rotulo]) => `<option value="${valor}" ${String(valor) === String(atual) ? 'selected' : ''}>${esc(rotulo)}</option>`).join('');

function agenteHtml(s) {
  const a = s.agente;
  const selo = !a.tem_codigo ? '<span class="selo">não instalado</span>'
    : a.online ? '<span class="selo selo--sucesso">conectado</span>'
    : '<span class="selo selo--perigo">desconectado</span>';
  const impressoras = a.impressoras.length ? `
      <label class="campo">
        <span class="campo__rotulo">Impressora</span>
        <select class="entrada" name="impressora">
          <option value="" ${s.ajustes.impressora === '' ? 'selected' : ''}>A padrão do Windows${a.impressoras.find((i) => i.padrao) ? ` (${esc(a.impressoras.find((i) => i.padrao).nome)})` : ''}</option>
          ${a.impressoras.map((i) => `<option value="${esc(i.nome)}" ${s.ajustes.impressora === i.nome ? 'selected' : ''}>${esc(i.nome)} · ${esc(STATUS_WINDOWS[i.status] ?? i.status)}</option>`).join('')}
        </select>
        <span class="campo__ajuda">Lista que o agente achou no computador ${a.computador ? `“${esc(a.computador)}”` : ''}. Escolha a térmica USB.</span>
      </label>` : '';
  const codigo = codigoGerado ? `
      <div class="impressora__codigo" role="status">
        <p><strong>Código do agente</strong> (aparece só agora; guarde até terminar a instalação):</p>
        <code class="codigo" id="codigo-agente">${esc(codigoGerado.codigo)}</code>
        <p class="campo__ajuda">Endereço do servidor: <code class="codigo">${esc(codigoGerado.servidor)}</code></p>
        <button type="button" class="botao botao--secundario botao--p" data-copiar>${icone('check', 16)}Copiar código</button>
      </div>` : '';
  return `
    <section class="bloco" data-bloco="agente">
      <h2 class="bloco__titulo">Computador da loja ${selo}</h2>
      ${a.tem_codigo ? `
      <ul class="checagem">
        <li ${a.online ? 'data-ok' : ''}><span class="checagem__marca">${icone(a.online ? 'check' : 'menos', 14)}</span><span>${a.contato_em ? `Último sinal às ${hora(a.contato_em)}${a.computador ? ` · ${esc(a.computador)}` : ''}` : 'O agente ainda não se conectou'}</span></li>
      </ul>` : ''}
      ${s.alerta ? `<p class="detalhe__obs">${icone('alerta', 18)}<span>${esc(s.alerta.texto)}</span></p>` : ''}
      ${impressoras}
      ${codigo}
      <details class="impressora__instalar" ${!a.tem_codigo || codigoGerado ? 'open' : ''}>
        <summary>Como instalar no computador da impressora</summary>
        <ol>
          <li>No computador onde a impressora está ligada pelo USB, baixe os 3 arquivos para uma pasta (por exemplo, <code>C:\\Impressora</code>):
            <a href="/api/admin/impressora/agente/agente.ps1" download>agente.ps1</a>,
            <a href="/api/admin/impressora/agente/iniciar-impressora.bat" download>iniciar-impressora.bat</a> e
            <a href="/api/admin/impressora/agente/LEIA-ME.txt" download>LEIA-ME.txt</a>.</li>
          <li>Clique em <strong>Gerar código do agente</strong> aqui embaixo.</li>
          <li>Dê dois cliques em <strong>iniciar-impressora.bat</strong>. Na primeira vez ele pede o endereço do servidor e o código; cole os dois.</li>
          <li>Pronto: este quadro fica “conectado” e mostra as impressoras do computador. Escolha a térmica, salve e imprima a comanda de teste.</li>
        </ol>
        <p class="campo__ajuda">Deixe a janela do agente aberta (pode minimizar). Para ele abrir sozinho ao ligar o computador, rode <code>iniciar-impressora.bat instalar</code> uma vez.</p>
      </details>
      <div class="impressora__acoes">
        <button type="button" class="botao ${a.tem_codigo ? 'botao--secundario' : ''}" data-impressora="codigo">${icone('ajustes', 18)}${a.tem_codigo ? 'Gerar código novo' : 'Gerar código do agente'}</button>
        ${a.tem_codigo ? '<button type="button" class="botao botao--perigo-suave" data-impressora="revogar">Desligar o agente</button>' : ''}
      </div>
    </section>`;
}

function formHtml(s) {
  const j = s.ajustes;
  const altura = PAPEIS.some(([v]) => v === j.altura_mm) ? j.altura_mm : -1;
  return `
    <form class="config" id="form-impressora" novalidate>
      <section class="bloco">
        <h2 class="bloco__titulo">Onde a comanda sai</h2>
        <fieldset class="segmentado" aria-label="Onde a comanda é impressa">
          <label class="segmentado__item"><input type="radio" name="modo" value="agente" ${j.modo === 'agente' ? 'checked' : ''}><span>Computador da loja (agente)</span></label>
          <label class="segmentado__item"><input type="radio" name="modo" value="navegador" ${j.modo === 'navegador' ? 'checked' : ''}><span>Navegador deste painel</span></label>
        </fieldset>
        <p class="campo__ajuda">${j.modo === 'agente'
          ? 'O agente imprime sozinho, sem janela de impressão, mesmo com o painel fechado. É o recomendado para a impressora USB.'
          : 'O aparelho com “Imprimir comandas neste aparelho” ligado (no quadro de Pedidos) imprime. Para sair sem a janela de impressão, o Chrome precisa abrir com --kiosk-printing.'}</p>
      </section>

      ${j.modo === 'agente' ? agenteHtml(s) : ''}

      <section class="bloco">
        <h2 class="bloco__titulo">Papel e comanda</h2>
        <div class="bloco__grade">
          ${j.modo === 'agente' ? `<label class="campo"><span class="campo__rotulo">Como enviar à impressora</span><select class="entrada" name="linguagem">${opcoes(LINGUAGENS, j.linguagem)}</select>
            <span class="campo__ajuda">Os dois primeiros falam direto com a impressora e não dependem do driver instalado no Windows. Se sair texto estranho (como “SIZE”, “GAP”) ou nada, troque aqui.</span></label>` : ''}
          <label class="campo"><span class="campo__rotulo">Largura do papel</span><select class="entrada" name="largura_mm">${opcoes(LARGURAS, j.largura_mm)}</select></label>
          <label class="campo"><span class="campo__rotulo">Tipo de papel</span><select class="entrada" name="papel">${opcoes(PAPEIS, altura)}</select>
            <span class="campo__ajuda">Etiqueta: a comanda comprida continua na etiqueta seguinte.</span></label>
          <label class="campo" ${altura === -1 ? '' : 'hidden'} data-altura><span class="campo__rotulo">Altura da etiqueta (mm)</span>
            <input class="entrada" name="altura_mm" inputmode="numeric" value="${j.altura_mm || ''}" aria-describedby="erro-altura_mm"><span class="campo__erro" id="erro-altura_mm" hidden></span></label>
          <label class="campo"><span class="campo__rotulo">Margem (mm)</span><input class="entrada" name="margem_mm" inputmode="numeric" value="${j.margem_mm}" aria-describedby="erro-margem_mm"><span class="campo__erro" id="erro-margem_mm" hidden></span></label>
          <label class="campo"><span class="campo__rotulo">Tamanho da letra</span><select class="entrada" name="fonte">${opcoes(FONTES, j.fonte)}</select></label>
          <label class="campo"><span class="campo__rotulo">Vias de cada comanda</span><select class="entrada" name="vias">${opcoes([[1, '1 via'], [2, '2 vias'], [3, '3 vias']], j.vias)}</select></label>
        </div>
        <label class="interruptor"><input type="checkbox" name="com_preco" ${j.com_preco ? 'checked' : ''}><span class="interruptor__trilho"></span><span>Imprimir preços e pagamento na comanda</span></label>
        <p class="campo__ajuda">No pedido do iFood, “pago online” ou “cobrar” sai sempre, mesmo sem os preços.</p>
      </section>

      <section class="bloco">
        <h2 class="bloco__titulo">Imprimir sozinho os pedidos de</h2>
        <div style="display: grid;">
          ${ORIGENS.map(([valor, rotulo]) => `<label class="interruptor"><input type="checkbox" name="origens" value="${valor}" ${j.origens.includes(valor) ? 'checked' : ''}><span class="interruptor__trilho"></span><span>${rotulo}</span></label>`).join('')}
        </div>
        <p class="campo__ajuda">Desligado: o pedido entra no quadro sem comanda (dá para imprimir pelo botão “Imprimir” do pedido).</p>
      </section>

      <section class="bloco">
        <h2 class="bloco__titulo">Teste</h2>
        ${j.modo === 'agente'
          ? '<p class="campo__ajuda">O site fica na internet e não enxerga a impressora USB sozinho: quem imprime é o agente, aberto no computador da impressora. “Testar a conexão” confere cada elo dessa ligação e imprime uma comanda de teste.</p>'
          : '<p class="campo__ajuda">No modo “Navegador deste painel” o site só consegue imprimir pela janela de impressão do navegador. Para imprimir sozinho na impressora USB, use o computador da loja (agente).</p>'}
        <div class="impressora__acoes">
          ${j.modo === 'agente' ? `<button type="button" class="botao" data-impressora="conexao" ${teste?.rodando ? 'disabled' : ''}>${icone('ligacao', 18)}Testar a conexão com a impressora</button>` : ''}
          <button type="button" class="botao botao--secundario" data-impressora="teste">${icone('impressora', 18)}Imprimir comanda de teste</button>
        </div>
        <div id="teste-conexao" aria-live="polite">${testeHtml()}</div>
        <div class="comanda-previa" id="comanda-previa" hidden></div>
        ${j.modo === 'agente' ? trabalhosHtml(s) : ''}
      </section>

      <div class="barra-salvar">
        <span class="barra-salvar__nota" id="nota-impressora" role="status"></span>
        <button type="submit" class="botao botao--g">Salvar impressão</button>
      </div>
    </form>`;
}

/** Resultado do "Testar a conexão": um passo por elo da ligação site → agente → impressora → papel. */
function testeHtml() {
  if (!teste) return '';
  const marca = { ok: 'check', erro: 'fechar', espera: 'relogio', pendente: 'menos' };
  return `<ul class="checagem" style="margin-top: var(--e-3);">${teste.passos.map((p) => `
    <li ${p.estado === 'ok' ? 'data-ok' : ''} ${p.estado === 'erro' ? 'data-erro' : ''}>
      <span class="checagem__marca">${icone(marca[p.estado] ?? 'menos', 14)}</span>
      <span class="checagem__texto"><span>${esc(p.texto)}</span>${p.dica ? `<span class="campo__ajuda">${esc(p.dica)}</span>` : ''}</span>
    </li>`).join('')}</ul>`;
}

function pintarTeste() {
  const alvo = $('#teste-conexao');
  if (alvo) alvo.innerHTML = testeHtml();
  const botao = $('[data-impressora="conexao"]');
  if (botao) botao.disabled = Boolean(teste?.rodando);
}

const esperar = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Confere a ligação inteira e diz onde ela quebra:
 *   1. o agente do computador da loja está falando com o site?
 *   2. a impressora escolhida existe nele e está pronta no Windows?
 *   3. uma comanda de teste sai de verdade (e quando falha, o motivo que o agente deu)?
 */
async function testarConexao() {
  const passos = [
    { estado: 'espera', texto: 'Agente no computador da loja', dica: '' },
    { estado: 'pendente', texto: 'Impressora no Windows', dica: '' },
    { estado: 'pendente', texto: 'Comanda de teste', dica: '' },
  ];
  teste = { rodando: true, passos };
  pintarTeste();
  const fim = (indice, estado, texto, dica = '') => {
    passos[indice] = { estado, texto, dica };
    for (let i = indice + 1; i < passos.length; i += 1) {
      if (estado === 'erro') passos[i] = { ...passos[i], estado: 'pendente' };
    }
    if (estado !== 'erro' && passos[indice + 1]) passos[indice + 1].estado = 'espera';
    pintarTeste();
  };
  try {
    let s = await api('/api/admin/impressora');
    const a = s.agente;

    if (!a.tem_codigo) {
      fim(0, 'erro', 'O agente ainda não foi instalado', 'Abra “Como instalar no computador da impressora” aqui em cima, gere o código e rode o iniciar-impressora.bat nesse computador.');
      return;
    }
    if (!a.online) {
      fim(0, 'erro', a.contato_em ? `O agente parou de responder (último sinal às ${hora(a.contato_em)})` : 'O agente nunca se conectou',
        'Vá ao computador da impressora e abra o iniciar-impressora.bat (janela preta; pode minimizar). Se ela já está aberta, confira a internet desse computador. Para abrir sozinho ao ligar o computador, rode “iniciar-impressora.bat instalar” uma vez.');
      return;
    }
    fim(0, 'ok', `Agente conectado${a.computador ? ` (${a.computador})` : ''}`);

    const nome = s.ajustes.impressora || a.impressoras.find((i) => i.padrao)?.nome || '';
    if (a.impressoras.length === 0) {
      fim(1, 'erro', 'O agente não achou nenhuma impressora no Windows', 'Ligue a impressora no USB, confira se o Windows a mostra em Impressoras e escanners, e espere alguns segundos.');
      return;
    }
    if (!nome) {
      fim(1, 'erro', 'Nenhuma impressora escolhida', 'Escolha a térmica na lista “Impressora” aqui em cima e clique em Salvar impressão (não há impressora padrão no Windows).');
      return;
    }
    if (s.alerta?.estado === 'impressora') {
      fim(1, 'erro', s.alerta.texto, 'Resolva isso no computador da impressora (papel, tampa, cabo USB ou fila pausada) e teste de novo.');
      return;
    }
    fim(1, 'ok', `Impressora “${nome}” pronta no Windows`);

    s = await api('/api/admin/impressora/teste', { metodo: 'POST' });
    const id = Math.max(0, ...s.trabalhos.filter((t) => t.tipo === 'teste').map((t) => t.id));
    const limite = Date.now() + 30_000;
    while (Date.now() < limite) {
      await esperar(1500);
      s = await api('/api/admin/impressora');
      const t = s.trabalhos.find((x) => x.id === id);
      if (t?.estado === 'feito') {
        fim(2, 'ok', 'A comanda de teste foi enviada à impressora', 'Se o papel não saiu, confira se há bobina, se a tampa está fechada e se a largura e o “Como enviar” estão certos.');
        return;
      }
      if (t?.estado === 'falhou') {
        fim(2, 'erro', `A impressora recusou a comanda${t.erro ? `: ${t.erro}` : ''}`, 'Troque o “Como enviar” (cupom ou etiqueta) ou a largura do papel, desligue e ligue a impressora e teste de novo.');
        return;
      }
      passos[2] = { estado: 'espera', texto: t?.estado === 'enviado' ? 'Imprimindo…' : 'Comanda de teste na fila do agente…', dica: '' };
      pintarTeste();
    }
    fim(2, 'erro', 'O agente não respondeu em 30 segundos', 'A comanda ficou na fila. Veja se a janela do agente continua aberta e se a internet do computador está estável.');
  } catch (erro) {
    const i = passos.findIndex((p) => p.estado === 'espera');
    fim(i === -1 ? 0 : i, 'erro', erro.message || 'Não deu para fazer o teste', 'Tente de novo em alguns segundos.');
  } finally {
    teste.rodando = false;
    pintarTeste();
    atualizar();
  }
}

function trabalhosHtml(s) {
  if (!s.trabalhos.length) return '<p class="campo__ajuda">Nenhuma comanda passou pelo agente ainda.</p>';
  return `
    <h3 class="detalhe__titulo" style="margin-top: var(--e-4);">Últimas comandas do agente</h3>
    <div class="tabela-rolagem"><table class="tabela">
      <thead><tr><th>Quando</th><th>Pedido</th><th>Tipo</th><th>Situação</th><th>Erro</th></tr></thead>
      <tbody>${s.trabalhos.map((t) => `
        <tr ${t.estado === 'falhou' ? 'data-erro' : ''}>
          <td class="numero">${dataHora(t.criado_em)}</td>
          <td>${t.pedido_id ? `#${t.pedido_id}${t.cliente_nome ? ` · ${esc(t.cliente_nome)}` : ''}` : 'Comanda de teste'}</td>
          <td>${TIPOS[t.tipo] ?? esc(t.tipo)}</td>
          <td>${ESTADOS[t.estado] ?? esc(t.estado)}${t.estado === 'feito' && t.feito_em ? ` às ${hora(t.feito_em)}` : ''}</td>
          <td>${t.erro ? esc(t.erro) : ''}</td>
        </tr>`).join('')}</tbody></table></div>`;
}

function desenhar(s) {
  situacao = s;
  editando = false;
  conteudo.innerHTML = `
    <div class="cabecalho-tela"><h1 class="cabecalho-tela__titulo">Impressora</h1></div>
    ${formHtml(s)}`;
}

/** Atualiza só o que o agente muda (situação, impressoras, trabalhos) sem apagar o que o dono está editando. */
async function atualizar() {
  try {
    const nova = await api('/api/admin/impressora');
    if (JSON.stringify(nova) === JSON.stringify(situacao)) return;
    if (editando) {
      situacao = { ...nova, ajustes: situacao.ajustes };
      return;
    }
    const rolagem = window.scrollY;
    desenhar(nova);
    window.scrollTo(0, rolagem);
  } catch { /* tenta no próximo ciclo */ }
}

function lerFormulario(form) {
  const papel = Number(form.papel.value);
  return {
    modo: form.modo.value,
    impressora: form.impressora?.value ?? situacao.ajustes.impressora,
    linguagem: form.linguagem?.value ?? situacao.ajustes.linguagem,
    largura_mm: Number(form.largura_mm.value),
    altura_mm: papel === -1 ? Number(form.altura_mm.value || 0) : papel,
    margem_mm: Number(form.margem_mm.value),
    fonte: form.fonte.value,
    vias: Number(form.vias.value),
    com_preco: form.com_preco.checked,
    origens: $$('input[name="origens"]:checked', form).map((el) => el.value),
  };
}

async function salvar(form, { silencioso = false } = {}) {
  const dados = lerFormulario(form);
  try {
    const nova = await api('/api/admin/impressora', { metodo: 'PUT', corpo: dados });
    // A casca usa o modo para saber se o navegador imprime sozinho (servicos.js).
    ajustesDoPainel.impressao_modo = nova.ajustes.modo;
    ajustesDoPainel.impressao_origens = nova.ajustes.origens;
    ajustesDoPainel.comanda_largura_mm = nova.ajustes.largura_mm;
    ajustesDoPainel.comanda_mostra_preco = nova.ajustes.com_preco;
    const rolagem = window.scrollY;
    desenhar(nova);
    window.scrollTo(0, rolagem);
    if (!silencioso) avisar('Impressão salva');
    return true;
  } catch (erro) {
    if (erro.codigo === 'validacao') {
      for (const [campo, mensagem] of Object.entries(erro.campos)) {
        const el = $(`#erro-${campo}`, form);
        if (el) { el.textContent = mensagem; el.hidden = false; }
      }
      avisar(Object.values(erro.campos)[0], { tipo: 'erro' });
    } else {
      mostrarErro(erro);
    }
    return false;
  }
}

// ---------- Eventos ----------
delegar(conteudo, 'input', '#form-impressora', () => {
  editando = true;
  $('#nota-impressora').textContent = 'Alterações não salvas';
});
delegar(conteudo, 'change', 'select[name="papel"]', (e, select) => {
  $('[data-altura]', conteudo).hidden = select.value !== '-1';
});
// Trocar o modo muda a tela inteira: salva na hora.
delegar(conteudo, 'change', 'input[name="modo"]', (e, entrada) => salvar(entrada.form));

delegar(conteudo, 'click', '[data-impressora]', async (e, botao) => {
  const acao = botao.dataset.impressora;
  const form = $('#form-impressora');
  if (acao === 'codigo') {
    if (situacao.agente.tem_codigo && !(await confirmar({
      titulo: 'Gerar um código novo?',
      texto: 'O agente que já está instalado para de funcionar até receber o código novo.',
      confirmar: 'Gerar código novo',
    }))) return;
    try {
      const r = await api('/api/admin/impressora/codigo', { metodo: 'POST' });
      codigoGerado = { codigo: r.codigo, servidor: r.servidor };
      ajustesDoPainel.impressao_modo = 'agente';
      desenhar(r.situacao);
      $('#codigo-agente')?.scrollIntoView({ block: 'center' });
    } catch (erro) {
      mostrarErro(erro);
    }
    return;
  }
  if (acao === 'revogar') {
    if (!(await confirmar({
      titulo: 'Desligar o agente de impressão?',
      texto: 'O código dele para de valer e a impressão volta para o navegador. Para usar o agente de novo, gere outro código.',
      confirmar: 'Desligar',
      perigo: true,
    }))) return;
    try {
      codigoGerado = null;
      const nova = await api('/api/admin/impressora/revogar', { metodo: 'POST' });
      ajustesDoPainel.impressao_modo = nova.ajustes.modo;
      desenhar(nova);
    } catch (erro) {
      mostrarErro(erro);
    }
    return;
  }
  if (acao === 'conexao') {
    if (teste?.rodando) return;
    if (editando && !(await salvar(form, { silencioso: true }))) return;   // o agente lê a parametrização do servidor
    testarConexao();
    return;
  }
  if (acao === 'teste') {
    // Teste com o que está na tela: salva antes (o agente lê a parametrização do servidor).
    if (editando && !(await salvar(form, { silencioso: true }))) return;
    const atual = $('#form-impressora');
    if (situacao.ajustes.modo === 'navegador') {
      const previa = $('#comanda-previa', atual);
      previa.innerHTML = imprimirTeste(situacao.ajustes.largura_mm >= 80 ? 80 : 58, situacao.ajustes.com_preco);
      previa.hidden = false;
      avisar('Comanda de teste enviada para a impressora padrão deste aparelho');
      return;
    }
    try {
      desenhar(await api('/api/admin/impressora/teste', { metodo: 'POST' }));
      avisar('Comanda de teste enviada para o computador da loja');
    } catch (erro) {
      mostrarErro(erro);
    }
  }
});

delegar(conteudo, 'click', '[data-copiar]', async () => {
  try {
    await navigator.clipboard.writeText(codigoGerado.codigo);
    avisar('Código copiado');
  } catch {
    avisar('Selecione o código e copie com Ctrl+C', { tipo: 'erro' });
  }
});

conteudo.addEventListener('submit', (e) => {
  if (e.target.id !== 'form-impressora') return;
  e.preventDefault();
  salvar(e.target);
});

export async function montar() {
  try {
    desenhar(await api('/api/admin/impressora'));
  } catch (erro) {
    mostrarErro(erro);
    return;
  }
  clearInterval(relogio);
  relogio = setInterval(atualizar, ATUALIZAR_MS);
}

export function desmontar() {
  clearInterval(relogio);
  codigoGerado = null;
}
