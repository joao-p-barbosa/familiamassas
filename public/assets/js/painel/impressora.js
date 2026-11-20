// Impressora (D79 a D81, D86): um passo a passo de 3 passos que serve para qualquer impressora.
//   1. Baixar o programa da impressora: um arquivo só, já configurado (sem código para digitar). Dois cliques no computador
//      da impressora e ele instala, liga e passa a abrir sozinho com o Windows.
//   2. Escolher a impressora (a lista que o programa achou naquele computador) e o tamanho do papel.
//   3. Imprimir um teste e responder "saiu certinho?". Se saiu errado, a tela troca sozinha o jeito de falar com a impressora
//      (cupom ESC/POS, etiqueta TSPL ou driver do Windows) ou a largura e pede um teste novo.
// Pronto: a tela mostra o resumo e o "Testar a conexão", que confere cada elo da ligação site → programa → impressora → papel.
// O caminho pelo navegador (D6/D22, Chrome em --kiosk-printing) continua em "Mais opções".
// Só o proprietário abre esta tela.
import { $, $$, esc, delegar } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { dataHora, hora } from '../shared/formato.js';
import { api, ErroApi } from '../shared/api.js';
import { avisar } from '../shared/avisos.js';
import { recipienteDaTela, mostrarErro, confirmar, ajustes as ajustesDoPainel } from './casca.js';
import { imprimirTeste } from './impressao.js';

const conteudo = recipienteDaTela();
const ATUALIZAR_MS = 4000;
const ORIGENS = [['cardapio', 'Cardápio'], ['whatsapp', 'WhatsApp'], ['ifood', 'iFood'], ['balcao', 'Salão (mesa e pra viagem)']];
const LARGURAS = [[58, '58 mm (cupom pequeno)'], [72, '72 mm'], [80, '80 mm (cupom padrão)'], [100, '100 mm (etiqueta 4")'], [104, '104 mm']];
const PAPEIS = [[0, 'Rolo contínuo (cupom)'], [150, 'Etiqueta de 150 mm (100 × 150)'], [100, 'Etiqueta de 100 mm'], [-1, 'Outra altura…']];
const FONTES = [['p', 'Pequena'], ['m', 'Média'], ['g', 'Grande'], ['gg', 'Muito grande'], ['xg', 'Enorme']];
// ESC/POS é o que funciona na impressora da loja (QIA-8388, D88/D97): fica como o principal; os outros só no "Avançado".
const LINGUAGENS = [['escpos', 'Cupom (ESC/POS) - o principal'], ['tspl', 'Etiqueta (TSPL)'], ['driver', 'Pelo driver do Windows']];
/** Ordem das tentativas quando o teste sai estranho: o mais comum primeiro. */
const ORDEM_LINGUAGENS = ['escpos', 'tspl', 'driver'];
const ESTADOS = { pendente: 'Na fila', enviado: 'Imprimindo', feito: 'Impressa', falhou: 'Falhou' };
const TIPOS = { auto: 'Automática', reimpressao: 'Reimpressão', teste: 'Teste' };
const STATUS_WINDOWS = {
  Normal: 'pronta', Printing: 'imprimindo', Paused: 'pausada', Error: 'com erro', Offline: 'desligada', PaperOut: 'sem papel',
  PaperJam: 'papel enroscado', NotAvailable: 'indisponível', UserIntervention: 'precisa de atenção', DoorOpen: 'tampa aberta',
};
const PROBLEMAS = ['Paused', 'Error', 'PaperJam', 'PaperOut', 'Offline', 'NotAvailable', 'UserIntervention', 'DoorOpen'];

let situacao = null;
let editando = false;      // o dono mexeu num formulário: a atualização automática não desfaz
let relogio = 0;
let baixou = false;        // passo 1: o arquivo foi baixado nesta visita (a tela passa a esperar o programa ligar)
let trocando = false;      // passo 2 reaberto pelo "Trocar a impressora"
let prova = null;          // passo 3: { estado: 'imprimindo'|'pergunta'|'falhou'|'esgotou', erro, aviso }
let tentadas = new Set();  // passo 3: jeitos de falar com a impressora já tentados
let trocasDeLargura = 0;
let teste = null;          // "Testar a conexão": { rodando, passos: [{ estado, texto, dica }] }

const opcoes = (lista, atual) => lista.map(([valor, rotulo]) => `<option value="${valor}" ${String(valor) === String(atual) ? 'selected' : ''}>${esc(rotulo)}</option>`).join('');
const esperar = (ms) => new Promise((r) => setTimeout(r, ms));
const statusPt = (i) => STATUS_WINDOWS[i.status] ?? i.status;
const fisicas = (s) => s.agente.impressoras.filter((i) => !i.virtual);
const escolhida = (s) => s.agente.impressoras.find((i) => i.nome === s.ajustes.impressora) ?? null;

/** Em que passo o dono está: 1 instalar, 2 escolher, 3 testar, 4 pronto. */
function passoAtual(s) {
  if (!s.agente.online) return 1;
  if (trocando || !escolhida(s)) return 2;
  if (!s.agente.confirmada_em) return 3;
  return 4;
}

// ---------- Desenho ----------

function passoHtml(numero, atual, titulo, resumo, corpo) {
  const estado = numero < atual ? 'feito' : numero === atual ? 'atual' : 'depois';
  return `
    <li class="passo" data-estado="${estado}" ${estado === 'atual' ? 'aria-current="step"' : ''}>
      <div class="passo__cabeca">
        <span class="passo__numero">${estado === 'feito' ? icone('check', 16) : numero}</span>
        <div class="passo__titulos">
          <h2 class="passo__titulo">${titulo}</h2>
          ${estado === 'feito' && resumo ? `<p class="passo__resumo">${resumo}</p>` : ''}
        </div>
      </div>
      ${estado === 'atual' ? `<div class="passo__corpo" aria-live="polite">${corpo}</div>` : ''}
    </li>`;
}

function passo1Html(s) {
  const a = s.agente;
  if (a.tem_codigo && a.contato_em && !baixou) {
    return `
      <p class="detalhe__obs">${icone('alerta', 18)}<span>O programa parou de responder. Último sinal às ${hora(a.contato_em)}${a.computador ? `, no computador “${esc(a.computador)}”` : ''}.</span></p>
      <p>Confira se o computador da impressora está <strong>ligado</strong> e <strong>com internet</strong>. Assim que ele voltar, esta tela segue sozinha.</p>
      <p class="campo__ajuda">Continua sem voltar? Nesse computador, baixe o programa de novo e abra o arquivo.</p>
      <div class="impressora__acoes"><button type="button" class="botao botao--secundario" data-impressora="baixar">${icone('recarregar', 18)}Baixar o programa de novo</button></div>`;
  }
  if (baixou || (a.tem_codigo && !a.contato_em)) {
    return `
      <p class="espera"><span class="espera__giro" aria-hidden="true"></span>Esperando o programa ligar…</p>
      <ol class="passo__lista">
        <li>Abra o arquivo <strong>instalar-impressora.cmd</strong> que acabou de baixar (fica na pasta Downloads).</li>
        <li>Se o Windows perguntar se quer executar, clique em <strong>Executar</strong>. Se aparecer “O Windows protegeu o computador”, clique em <strong>Mais informações</strong> e depois em <strong>Executar assim mesmo</strong>.</li>
        <li>Uma janela aparece por alguns segundos e diz <strong>Pronto</strong>. Esta tela passa para o próximo passo sozinha.</li>
      </ol>
      <div class="impressora__acoes"><button type="button" class="botao botao--fantasma" data-impressora="baixar">Baixar de novo</button></div>`;
  }
  return `
    <p>Faça este passo <strong>no computador onde a impressora está ligada</strong> (pelo cabo USB). Abra este painel nele e clique no botão:</p>
    <div class="impressora__acoes"><button type="button" class="botao botao--g" data-impressora="baixar">${icone('impressora', 18)}Baixar o programa da impressora</button></div>
    <p class="campo__ajuda">É um arquivo pequeno, já configurado para a sua loja. Ele imprime as comandas sozinho, sem janela, e abre sozinho quando o computador liga. Se o navegador perguntar se quer manter o arquivo, clique em <strong>Manter</strong>.</p>`;
}

function passo2Html(s) {
  const lista = fisicas(s);
  if (lista.length === 0) {
    return `
      <p class="detalhe__obs">${icone('alerta', 18)}<span>O programa não achou nenhuma impressora no computador “${esc(s.agente.computador)}”.</span></p>
      <p>Ligue a impressora, confira o cabo USB e espere até 1 minuto: o Windows precisa reconhecê-la. Se ela não aparecer, instale o programa (driver) que veio com a impressora. Esta tela atualiza sozinha.</p>`;
  }
  const atual = escolhida(s)?.nome ?? (lista.length === 1 ? lista[0].nome : lista.find((i) => i.padrao)?.nome ?? '');
  const j = s.ajustes;
  // Primeira vez: nada marcado (a largura de fábrica induziria ao erro). Trocando de impressora: o que já estava valendo.
  const papel = !escolhida(s) ? '' : j.largura_mm === 100 && j.altura_mm > 0 ? 'etiqueta' : j.largura_mm === 58 ? '58' : j.largura_mm === 80 ? '80' : '';
  return `
    <form id="form-escolha" class="passo__form" novalidate>
      <fieldset class="escolha-perfil">
        <legend class="campo__rotulo">Qual é a impressora das comandas?</legend>
        ${lista.map((i) => `
          <label class="escolha-perfil__opcao">
            <input type="radio" name="impressora" value="${esc(i.nome)}" ${i.nome === atual ? 'checked' : ''} required>
            <span><strong>${esc(i.nome)}</strong>
              <span class="campo__ajuda" ${PROBLEMAS.includes(i.status) ? 'data-perigo' : ''}>${esc(statusPt(i))}${i.padrao ? ' · padrão do Windows' : ''}</span></span>
          </label>`).join('')}
      </fieldset>
      <fieldset class="escolha-perfil">
        <legend class="campo__rotulo">Qual a largura do papel?</legend>
        <label class="escolha-perfil__opcao"><input type="radio" name="papel" value="58" ${papel === '58' ? 'checked' : ''} required>
          <span><strong>Pequeno: 5,8 cm</strong><span class="campo__ajuda">O mais comum nas impressoras pequenas e portáteis.</span></span></label>
        <label class="escolha-perfil__opcao"><input type="radio" name="papel" value="80" ${papel === '80' ? 'checked' : ''}>
          <span><strong>Grande: 8 cm</strong><span class="campo__ajuda">O cupom de mercado e de loja.</span></span></label>
        <label class="escolha-perfil__opcao"><input type="radio" name="papel" value="etiqueta" ${papel === 'etiqueta' ? 'checked' : ''}>
          <span><strong>Etiqueta de 10 × 15 cm</strong><span class="campo__ajuda">Impressora de etiqueta (de envio).</span></span></label>
        <p class="campo__ajuda">Não sabe? Olhe a etiqueta embaixo da impressora (“Largura 57,5 mm” ou “58 mm” é 5,8 cm) ou meça o papel com uma régua.</p>
      </fieldset>
      <div class="impressora__acoes">
        <button type="submit" class="botao botao--g">Continuar</button>
        ${trocando && escolhida(s) && s.agente.confirmada_em ? '<button type="button" class="botao botao--fantasma" data-impressora="cancelar-troca">Cancelar</button>' : ''}
      </div>
    </form>`;
}

function passo3Html(s) {
  const nome = esc(s.ajustes.impressora);
  const aviso = prova?.aviso ? `<p class="detalhe__obs detalhe__obs--info">${icone('info', 18)}<span>${esc(prova.aviso)}</span></p>` : '';
  if (prova?.estado === 'imprimindo') {
    return `<p class="espera"><span class="espera__giro" aria-hidden="true"></span>Mandando o teste para a <strong>${nome}</strong>…</p>`;
  }
  if (prova?.estado === 'pergunta') {
    return `
      <p><strong>Olhe a impressora: a comanda de teste saiu certinha?</strong> Ela tem o nome da loja, um pedido de exemplo e o total.</p>
      <div class="respostas">
        <button type="button" class="botao botao--g" data-resposta="certo">${icone('check', 18)}Sim, saiu certinha</button>
        <button type="button" class="botao botao--secundario" data-resposta="cortada">Saiu cortada, torta ou estreita</button>
        <button type="button" class="botao botao--secundario" data-resposta="estranha">Saiu estranha ou não saiu nada</button>
      </div>`;
  }
  if (prova?.estado === 'falhou') {
    return `
      <p class="detalhe__obs">${icone('alerta', 18)}<span>A impressora não aceitou o teste${prova.erro ? `: ${esc(prova.erro)}` : '.'}</span></p>
      <p>Confira se ela está ligada, com papel e com a tampa fechada, e tente de novo.</p>
      <div class="impressora__acoes"><button type="button" class="botao" data-impressora="provar">${icone('impressora', 18)}Imprimir o teste de novo</button>
      <button type="button" class="botao botao--fantasma" data-impressora="trocar">Trocar a impressora</button></div>`;
  }
  if (prova?.estado === 'esgotou') {
    return `
      <p class="detalhe__obs">${icone('alerta', 18)}<span>Tentamos os 3 jeitos de falar com a impressora e nenhum saiu certinho.</span></p>
      <ul class="passo__lista">
        <li>Confira se a impressora imprime sozinha. Na maioria, segurar o botão de papel enquanto liga imprime uma página de teste.</li>
        <li>Confira se escolheu a impressora certa e a largura do papel certa.</li>
        <li>Tire uma foto da comanda que saiu e mande para quem cuida do sistema.</li>
      </ul>
      <div class="impressora__acoes"><button type="button" class="botao" data-impressora="recomecar">Começar o teste de novo</button>
      <button type="button" class="botao botao--fantasma" data-impressora="trocar">Trocar a impressora</button></div>`;
  }
  return `
    ${aviso}
    <p>Vamos imprimir uma comanda de teste na <strong>${nome}</strong>${s.ajustes.largura_mm ? ` (papel de ${s.ajustes.largura_mm === 100 ? '10 cm' : `${String(s.ajustes.largura_mm / 10).replace('.', ',')} cm`})` : ''}.</p>
    <div class="impressora__acoes"><button type="button" class="botao botao--g" data-impressora="provar">${icone('impressora', 18)}Imprimir o teste</button>
    <button type="button" class="botao botao--fantasma" data-impressora="trocar">Trocar a impressora</button></div>`;
}

function prontoHtml(s) {
  const i = escolhida(s);
  return `
    <section class="bloco pronto">
      <h2 class="bloco__titulo">${icone('check', 20)} Impressão automática ligada</h2>
      <p>Os pedidos novos saem sozinhos na <strong>${esc(i.nome)}</strong>, no computador <strong>${esc(s.agente.computador)}</strong>. Não precisa deixar o painel aberto.</p>
      <ul class="checagem">
        <li data-ok><span class="checagem__marca">${icone('check', 14)}</span><span>Programa da impressora ligado</span></li>
        <li ${PROBLEMAS.includes(i.status) ? 'data-erro' : 'data-ok'}><span class="checagem__marca">${icone(PROBLEMAS.includes(i.status) ? 'fechar' : 'check', 14)}</span><span>Impressora ${esc(statusPt(i))}</span></li>
        <li data-ok><span class="checagem__marca">${icone('check', 14)}</span><span>Teste conferido em ${dataHora(s.agente.confirmada_em)}</span></li>
      </ul>
      <div class="impressora__acoes">
        <button type="button" class="botao" data-impressora="conexao" ${teste?.rodando ? 'disabled' : ''}>${icone('ligacao', 18)}Testar a conexão com a impressora</button>
        <button type="button" class="botao botao--fantasma" data-impressora="trocar">Trocar a impressora</button>
      </div>
      <div id="teste-conexao" aria-live="polite">${testeHtml()}</div>
    </section>
    ${letraHtml(s)}
    <section class="bloco">
      <h2 class="bloco__titulo">${icone('impressora', 20)} Imprimir por outro computador</h2>
      <p>O computador da loja deu problema? Abra este painel no outro computador (com a impressora ligada nele), baixe o programa e abra o arquivo. A impressão passa para ele.</p>
      <div class="impressora__acoes"><button type="button" class="botao botao--secundario" data-impressora="baixar">${icone('impressora', 18)}Baixar o programa da impressora</button></div>
      <p class="campo__ajuda">Só um computador imprime por vez: assim que você baixar, o “${esc(s.agente.computador)}” para de imprimir.</p>
    </section>`;
}

/** Tamanho da letra da comanda (D97): à vista, com um teste na hora. */
function letraHtml(s) {
  return `
    <section class="bloco">
      <h2 class="bloco__titulo">Tamanho da letra da comanda</h2>
      <form id="form-letra" class="letra-comanda">
        <fieldset class="segmentado" aria-label="Tamanho da letra">
          ${FONTES.map(([valor, rotulo]) => `
            <label class="segmentado__item"><input type="radio" name="fonte" value="${valor}" ${s.ajustes.fonte === valor ? 'checked' : ''}><span>${rotulo}</span></label>`).join('')}
        </fieldset>
        <div class="impressora__acoes"><button type="submit" class="botao">${icone('impressora', 18)}Salvar e imprimir um teste</button></div>
        <p class="campo__ajuda">“Muito grande” e “Enorme” precisam do programa da impressora atualizado (baixe de novo se a letra não mudar).</p>
      </form>
    </section>`;
}

function ajustesHtml(s) {
  const j = s.ajustes;
  const altura = PAPEIS.some(([v]) => v === j.altura_mm) ? j.altura_mm : -1;
  const agente = j.modo === 'agente';
  return `
    <details class="bloco impressora__mais">
      <summary>Ajustes da comanda <span class="campo__ajuda">(vias, papel, preços e o que imprimir sozinho)</span></summary>
      <form class="config" id="form-impressora" novalidate>
        <div class="bloco__grade">
          <label class="campo"><span class="campo__rotulo">Vias de cada comanda</span><select class="entrada" name="vias">${opcoes([[1, '1 via'], [2, '2 vias'], [3, '3 vias']], j.vias)}</select></label>
          <label class="campo"><span class="campo__rotulo">Largura do papel</span><select class="entrada" name="largura_mm">${opcoes(LARGURAS, j.largura_mm)}</select></label>
          <label class="campo"><span class="campo__rotulo">Margem (mm)</span><input class="entrada" name="margem_mm" inputmode="numeric" value="${j.margem_mm}" aria-describedby="erro-margem_mm"><span class="campo__erro" id="erro-margem_mm" hidden></span></label>
        </div>
        <details class="impressora__avancado" ${j.linguagem !== 'escpos' || j.altura_mm > 0 ? 'open' : ''}>
          <summary>Avançado: outra impressora (etiqueta ou driver)</summary>
          <p class="campo__ajuda">A sua impressora funciona com “Cupom (ESC/POS)” e rolo contínuo. Só mexa aqui se trocar por uma impressora de etiqueta.</p>
          <div class="bloco__grade">
            ${agente ? `<label class="campo"><span class="campo__rotulo">Jeito de falar com a impressora</span><select class="entrada" name="linguagem">${opcoes(LINGUAGENS, j.linguagem)}</select></label>` : ''}
            <label class="campo"><span class="campo__rotulo">Tipo de papel</span><select class="entrada" name="papel">${opcoes(PAPEIS, altura)}</select>
              <span class="campo__ajuda">Etiqueta: a comanda comprida continua na etiqueta seguinte.</span></label>
            <label class="campo" ${altura === -1 ? '' : 'hidden'} data-altura><span class="campo__rotulo">Altura da etiqueta (mm)</span>
              <input class="entrada" name="altura_mm" inputmode="numeric" value="${j.altura_mm || ''}" aria-describedby="erro-altura_mm"><span class="campo__erro" id="erro-altura_mm" hidden></span></label>
          </div>
        </details>
        <label class="interruptor"><input type="checkbox" name="com_preco" ${j.com_preco ? 'checked' : ''}><span class="interruptor__trilho"></span><span>Imprimir preços e pagamento na comanda</span></label>
        <p class="campo__ajuda">No pedido do iFood, “pago online” ou “cobrar” sai sempre, mesmo sem os preços.</p>
        <p class="campo__rotulo" style="margin-top: var(--e-3);">Imprimir sozinho os pedidos de</p>
        <div style="display: grid;">
          ${ORIGENS.map(([valor, rotulo]) => `<label class="interruptor"><input type="checkbox" name="origens" value="${valor}" ${j.origens.includes(valor) ? 'checked' : ''}><span class="interruptor__trilho"></span><span>${rotulo}</span></label>`).join('')}
        </div>
        <p class="campo__ajuda">Desligado: o pedido entra no quadro sem comanda (dá para imprimir pelo botão “Imprimir” do pedido).</p>
        <div class="impressora__acoes" style="margin-top: var(--e-3);">
          <button type="submit" class="botao">Salvar ajustes</button>
          <span class="barra-salvar__nota" id="nota-impressora" role="status"></span>
        </div>
      </form>
    </details>`;
}

function maisHtml(s) {
  const a = s.agente;
  const navegador = s.ajustes.modo === 'navegador';
  return `
    <details class="bloco impressora__mais" ${navegador && a.tem_codigo ? 'open' : ''}>
      <summary>Mais opções</summary>
      ${a.tem_codigo && s.trabalhos.length ? trabalhosHtml(s) : ''}
      <h3 class="detalhe__titulo">Imprimir pelo navegador, sem programa</h3>
      <p class="campo__ajuda">Só funciona se a impressora já imprime certinho pelo Windows, com o painel aberto e “Imprimir comandas neste aparelho” ligado no quadro de Pedidos.
        Para não aparecer a janela de impressão a cada pedido, o Chrome precisa ser aberto com a opção <code>--kiosk-printing</code>. Por isso o programa da impressora é o caminho recomendado.</p>
      <div class="impressora__acoes">
        <button type="button" class="botao botao--secundario botao--p" data-impressora="teste-navegador">${icone('impressora', 16)}Imprimir teste pelo navegador</button>
        ${a.tem_codigo && navegador ? '<button type="button" class="botao botao--p" data-impressora="usar-programa">Voltar a usar o programa da impressora</button>' : ''}
        ${a.tem_codigo && !navegador ? '<button type="button" class="botao botao--fantasma botao--p" data-impressora="usar-navegador">Usar o navegador em vez do programa</button>' : ''}
      </div>
      <div class="comanda-previa" id="comanda-previa" hidden></div>
      ${a.tem_codigo ? `
      <h3 class="detalhe__titulo" style="margin-top: var(--e-4);">Desligar o programa da impressora</h3>
      <p class="campo__ajuda">O programa instalado para de imprimir (use se trocar de computador). Para ligar de novo, baixe o programa outra vez.</p>
      <div class="impressora__acoes"><button type="button" class="botao botao--perigo-suave botao--p" data-impressora="revogar">Desligar o programa</button></div>` : ''}
    </details>`;
}

function trabalhosHtml(s) {
  return `
    <h3 class="detalhe__titulo">Últimas comandas</h3>
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

function navegadorHtml() {
  return `
    <section class="bloco">
      <h2 class="bloco__titulo">Quem imprime agora: o navegador deste painel</h2>
      <p>As comandas saem no aparelho com “Imprimir comandas neste aparelho” ligado (no quadro de Pedidos), com o painel aberto. O programa da impressora está em pausa.</p>
      <div class="impressora__acoes"><button type="button" class="botao" data-impressora="usar-programa">Voltar a usar o programa da impressora</button></div>
    </section>`;
}

function desenhar(s) {
  situacao = s;
  editando = false;
  const usaNavegador = s.ajustes.modo === 'navegador' && s.agente.tem_codigo;
  const atual = passoAtual(s);
  if (atual !== 3) { prova = null; }
  const i = escolhida(s);
  const alerta = s.alerta && atual === 4 ? `<p class="detalhe__obs">${icone('alerta', 18)}<span>${esc(s.alerta.texto)}</span></p>` : '';
  const principal = usaNavegador ? navegadorHtml() : atual === 4 ? prontoHtml(s) : `
    <ol class="roteiro">
      ${passoHtml(1, atual, 'Instale o programa da impressora', s.agente.computador ? `Ligado no computador “${esc(s.agente.computador)}”` : '', atual === 1 ? passo1Html(s) : '')}
      ${passoHtml(2, atual, 'Escolha a impressora e o papel', i ? `${esc(i.nome)} · papel de ${s.ajustes.largura_mm} mm` : '', atual === 2 ? passo2Html(s) : '')}
      ${passoHtml(3, atual, 'Imprima um teste', '', atual === 3 ? passo3Html(s) : '')}
    </ol>`;
  conteudo.innerHTML = `
    <div class="cabecalho-tela"><h1 class="cabecalho-tela__titulo">Impressora</h1></div>
    <div class="impressora">
      ${alerta}
      ${principal}
      ${atual === 4 || usaNavegador ? ajustesHtml(s) : ''}
      ${maisHtml(s)}
    </div>`;
}

/** Atualiza com o que o programa contou. O "último sinal" muda a cada 3 s e não pede redesenho. */
const semRelogio = (s) => JSON.stringify({ ...s, agente: { ...s.agente, contato_em: s.agente.online ? null : s.agente.contato_em } });
async function atualizar(forcar = false) {
  try {
    const nova = await api('/api/admin/impressora');
    if (!forcar && situacao && semRelogio(nova) === semRelogio(situacao)) return;
    if (editando && !forcar) { situacao = { ...nova, ajustes: situacao.ajustes }; return; }
    if (nova.agente.online) baixou = false;
    const rolagem = window.scrollY;
    const aberto = $$('details[open]', conteudo).map((d) => d.querySelector('summary')?.textContent);
    desenhar(nova);
    for (const d of $$('details', conteudo)) {
      if (aberto.includes(d.querySelector('summary')?.textContent)) d.open = true;
    }
    window.scrollTo(0, rolagem);
  } catch { /* tenta no próximo ciclo */ }
}

// ---------- Ações ----------

/** Grava parte da parametrização (o resto fica como está) e redesenha. */
async function gravar(mudancas) {
  const nova = await api('/api/admin/impressora', { metodo: 'PUT', corpo: { ...situacao.ajustes, ...mudancas } });
  ajustesDoPainel.impressao_modo = nova.ajustes.modo;
  ajustesDoPainel.impressao_origens = nova.ajustes.origens;
  ajustesDoPainel.comanda_largura_mm = nova.ajustes.largura_mm;
  ajustesDoPainel.comanda_fonte = nova.ajustes.fonte;
  ajustesDoPainel.comanda_mostra_preco = nova.ajustes.com_preco;
  return nova;
}

/** Passo 1: baixa o instalador já configurado (cada download gera um código novo e o anterior para de valer). */
async function baixarPrograma(botao) {
  const a = situacao.agente;
  if (a.online && !(await confirmar({
    titulo: 'Baixar o programa da impressora?',
    texto: `O computador “${a.computador}” para de imprimir agora. A impressão volta quando você abrir o arquivo novo no computador que vai imprimir (pode ser este ou outro).`,
    confirmar: 'Baixar',
  }))) return;
  botao.setAttribute('aria-busy', 'true');
  try {
    const token = document.querySelector('meta[name="csrf"]')?.content ?? '';
    const r = await fetch('/api/admin/impressora/instalador', { method: 'POST', headers: { 'X-CSRF-Token': token }, credentials: 'same-origin' });
    if (!r.ok) {
      const erro = (await r.json().catch(() => null))?.erro ?? {};
      throw new ErroApi(erro.mensagem ?? 'Não deu para baixar agora. Tente de novo.', { status: r.status, codigo: erro.codigo ?? 'erro' });
    }
    const url = URL.createObjectURL(await r.blob());
    const link = Object.assign(document.createElement('a'), { href: url, download: 'instalar-impressora.cmd' });
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
    baixou = true;
    trocando = false;
    ajustesDoPainel.impressao_modo = 'agente';
    desenhar(await api('/api/admin/impressora'));
    baixou = true;
  } catch (erro) {
    mostrarErro(erro);
    botao.removeAttribute('aria-busy');
  }
}

/** Passo 2: impressora e papel. */
async function escolher(form) {
  const impressora = form.impressora?.value;
  const papel = form.papel?.value;
  if (!impressora || !papel) {
    avisar(!impressora ? 'Escolha a impressora' : 'Escolha a largura do papel', { tipo: 'erro' });
    return;
  }
  const j = situacao.ajustes;
  const mudancas = papel === 'etiqueta'
    ? { largura_mm: 100, altura_mm: 150, linguagem: 'tspl' }
    : { largura_mm: Number(papel), altura_mm: 0, linguagem: j.linguagem === 'tspl' && j.altura_mm > 0 ? 'escpos' : j.linguagem };
  try {
    const nova = await gravar({ modo: 'agente', impressora, ...mudancas });
    trocando = false;
    prova = null;
    tentadas = new Set();
    trocasDeLargura = 0;
    desenhar(nova);
  } catch (erro) {
    mostrarErro(erro);
  }
}

/** Manda uma comanda de teste ao programa e espera ele dizer como foi (até 30 s). */
async function testeNoPrograma() {
  let s = await api('/api/admin/impressora/teste', { metodo: 'POST' });
  const id = Math.max(0, ...s.trabalhos.filter((t) => t.tipo === 'teste').map((t) => t.id));
  const limite = Date.now() + 30_000;
  while (Date.now() < limite) {
    await esperar(1500);
    s = await api('/api/admin/impressora');
    const t = s.trabalhos.find((x) => x.id === id);
    if (t?.estado === 'feito' || t?.estado === 'falhou') return t;
  }
  return { estado: 'tempo' };
}

/** Passo 3: imprime o teste e pergunta como saiu. */
async function provar() {
  tentadas.add(situacao.ajustes.linguagem);
  prova = { estado: 'imprimindo', aviso: prova?.aviso };
  desenhar(situacao);
  try {
    const t = await testeNoPrograma();
    prova = t.estado === 'feito' ? { estado: 'pergunta' }
      : { estado: 'falhou', erro: t.estado === 'tempo' ? 'o programa não respondeu em 30 segundos' : t.erro };
  } catch (erro) {
    prova = { estado: 'falhou', erro: erro.message };
  }
  await atualizar(true);
}

async function responder(resposta) {
  const j = situacao.ajustes;
  try {
    if (resposta === 'certo') {
      prova = null;
      desenhar(await api('/api/admin/impressora/confirmar', { metodo: 'POST' }));
      avisar('Pronto: as comandas vão sair sozinhas');
      window.scrollTo(0, 0);
      return;
    }
    if (resposta === 'cortada') {
      trocasDeLargura += 1;
      const largura = j.largura_mm === 58 ? 80 : 58;
      const nova = await gravar({ largura_mm: largura, altura_mm: 0 });
      prova = { estado: null, aviso: trocasDeLargura > 2
        ? `Voltamos para o papel de ${largura} mm. Se ainda sair torta, ajuste a margem em “Ajustes da comanda” depois do teste.`
        : `Trocamos o papel para ${largura} mm. Imprima o teste de novo.` };
      desenhar(nova);
      return;
    }
    // Letras soltas e símbolos no cupom (ESC/POS) quase sempre são papel mais largo que o cabeçote: a impressora de 5,8 cm
    // não aceita a imagem de 8 cm e imprime os bytes como texto. Antes de trocar o jeito de falar, tenta o papel de 5,8 cm.
    if (j.linguagem === 'escpos' && j.largura_mm === 80) {
      tentadas.delete('escpos');
      const nova = await gravar({ largura_mm: 58, altura_mm: 0 });
      prova = { estado: null, aviso: 'Letras soltas e símbolos no papel quase sempre querem dizer que o papel é mais estreito que o escolhido. Trocamos para 5,8 cm. Desligue e ligue a impressora e imprima o teste de novo.' };
      desenhar(nova);
      return;
    }
    // Saiu estranha ou não saiu: tenta o próximo jeito de falar com a impressora.
    const proximo = ORDEM_LINGUAGENS.find((l) => !tentadas.has(l));
    if (!proximo) {
      prova = { estado: 'esgotou' };
      desenhar(situacao);
      return;
    }
    const nova = await gravar({ linguagem: proximo });
    prova = { estado: null, aviso: `Trocamos o jeito de falar com a impressora (tentativa ${tentadas.size + 1} de 3). Antes de imprimir de novo, desligue e ligue a impressora para limpar o que ficou nela.` };
    desenhar(nova);
  } catch (erro) {
    mostrarErro(erro);
  }
}

// ---------- Testar a conexão (tela pronta) ----------

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

/** Confere a ligação inteira e diz onde ela quebra: programa → impressora no Windows → comanda de teste. */
async function testarConexao() {
  const passos = [
    { estado: 'espera', texto: 'Programa no computador da impressora', dica: '' },
    { estado: 'pendente', texto: 'Impressora no Windows', dica: '' },
    { estado: 'pendente', texto: 'Comanda de teste', dica: '' },
  ];
  teste = { rodando: true, passos };
  pintarTeste();
  const fim = (indice, estado, texto, dica = '') => {
    passos[indice] = { estado, texto, dica };
    if (estado !== 'erro' && passos[indice + 1]) passos[indice + 1].estado = 'espera';
    pintarTeste();
  };
  try {
    const s = await api('/api/admin/impressora');
    const a = s.agente;
    if (!a.online) {
      fim(0, 'erro', a.contato_em ? `O programa parou de responder (último sinal às ${hora(a.contato_em)})` : 'O programa nunca se conectou',
        'Confira se o computador da impressora está ligado e com internet. Se estiver, baixe o programa de novo nesse computador e abra o arquivo.');
      return;
    }
    fim(0, 'ok', `Programa ligado${a.computador ? ` no computador “${a.computador}”` : ''}`);
    const i = s.agente.impressoras.find((x) => x.nome === s.ajustes.impressora);
    if (!i) {
      fim(1, 'erro', `A impressora “${s.ajustes.impressora}” não está mais nesse computador`, 'Ligue a impressora e confira o cabo USB. Se trocou de impressora, clique em “Trocar a impressora”.');
      return;
    }
    if (PROBLEMAS.includes(i.status)) {
      fim(1, 'erro', `A impressora está ${statusPt(i)}`, 'Resolva na impressora (papel, tampa, cabo) ou na fila de impressão do Windows e teste de novo.');
      return;
    }
    fim(1, 'ok', `Impressora “${i.nome}” ${statusPt(i)}`);
    const t = await testeNoPrograma();
    if (t.estado === 'feito') {
      fim(2, 'ok', 'A comanda de teste foi impressa', 'Se o papel não saiu, confira a bobina e a tampa. Se saiu estranha, clique em “Trocar a impressora” e refaça o teste.');
    } else if (t.estado === 'falhou') {
      fim(2, 'erro', `A impressora recusou a comanda${t.erro ? `: ${t.erro}` : ''}`, 'Desligue e ligue a impressora e teste de novo.');
    } else {
      fim(2, 'erro', 'O programa não respondeu em 30 segundos', 'A comanda ficou na fila. Confira a internet do computador da impressora.');
    }
  } catch (erro) {
    const i = passos.findIndex((p) => p.estado === 'espera');
    fim(i === -1 ? 0 : i, 'erro', erro.message || 'Não deu para fazer o teste', 'Tente de novo em alguns segundos.');
  } finally {
    teste.rodando = false;
    pintarTeste();
  }
}

// ---------- Ajustes da comanda ----------

function lerAjustes(form) {
  const papel = Number(form.papel.value);
  return {
    largura_mm: Number(form.largura_mm.value),
    altura_mm: papel === -1 ? Number(form.altura_mm.value || 0) : papel,
    margem_mm: Number(form.margem_mm.value),
    fonte: situacao.ajustes.fonte,   // a letra tem o seu próprio bloco (letraHtml)
    vias: Number(form.vias.value),
    com_preco: form.com_preco.checked,
    origens: $$('input[name="origens"]:checked', form).map((el) => el.value),
    ...(form.linguagem ? { linguagem: form.linguagem.value } : {}),
  };
}

async function salvarAjustes(form) {
  try {
    const nova = await gravar(lerAjustes(form));
    desenhar(nova);
    $('.impressora__mais', conteudo).open = true;
    avisar(nova.agente.confirmada_em || nova.ajustes.modo === 'navegador' ? 'Ajustes salvos' : 'Ajustes salvos. Imprima o teste de novo para conferir.');
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
  }
}

// ---------- Eventos ----------
delegar(conteudo, 'input', 'form', (e, form) => {
  editando = true;
  if (form.id === 'form-impressora') $('#nota-impressora').textContent = 'Alterações não salvas';
});
delegar(conteudo, 'change', 'select[name="papel"]', (e, select) => {
  $('[data-altura]', conteudo).hidden = select.value !== '-1';
});

delegar(conteudo, 'click', '[data-resposta]', (e, botao) => responder(botao.dataset.resposta));

delegar(conteudo, 'click', '[data-impressora]', async (e, botao) => {
  const acao = botao.dataset.impressora;
  if (acao === 'baixar') return baixarPrograma(botao);
  if (acao === 'provar') return provar();
  if (acao === 'recomecar') {
    tentadas = new Set();
    prova = null;
    try { desenhar(await gravar({ linguagem: ORDEM_LINGUAGENS[0] })); } catch (erro) { mostrarErro(erro); }
    return;
  }
  if (acao === 'trocar') {
    trocando = true;
    teste = null;
    desenhar(situacao);
    window.scrollTo(0, 0);
    return;
  }
  if (acao === 'cancelar-troca') {
    trocando = false;
    desenhar(situacao);
    return;
  }
  if (acao === 'conexao') {
    if (!teste?.rodando) testarConexao();
    return;
  }
  if (acao === 'usar-navegador' || acao === 'usar-programa') {
    try {
      desenhar(await gravar({ modo: acao === 'usar-programa' ? 'agente' : 'navegador' }));
      avisar(acao === 'usar-programa' ? 'O programa da impressora voltou a imprimir' : 'Agora quem imprime é o navegador');
    } catch (erro) {
      mostrarErro(erro);
    }
    return;
  }
  if (acao === 'teste-navegador') {
    const previa = $('#comanda-previa', conteudo);
    previa.innerHTML = imprimirTeste(situacao.ajustes.largura_mm >= 80 ? 80 : 58, situacao.ajustes.com_preco);
    previa.hidden = false;
    avisar('Comanda de teste enviada para a impressora padrão deste aparelho');
    return;
  }
  if (acao === 'revogar') {
    if (!(await confirmar({
      titulo: 'Desligar o programa da impressora?',
      texto: 'As comandas param de sair sozinhas nesse computador. Para ligar de novo, baixe o programa outra vez.',
      confirmar: 'Desligar',
      perigo: true,
    }))) return;
    try {
      const nova = await api('/api/admin/impressora/revogar', { metodo: 'POST' });
      ajustesDoPainel.impressao_modo = nova.ajustes.modo;
      baixou = false;
      desenhar(nova);
    } catch (erro) {
      mostrarErro(erro);
    }
  }
});

conteudo.addEventListener('submit', (e) => {
  e.preventDefault();
  if (e.target.id === 'form-escolha') escolher(e.target);
  if (e.target.id === 'form-impressora') salvarAjustes(e.target);
  if (e.target.id === 'form-letra') salvarLetra(e.target);
});

/** Salva o tamanho da letra e manda uma comanda de teste, para a pessoa ver o resultado no papel. */
async function salvarLetra(form) {
  const botao = form.querySelector('button[type="submit"]');
  botao.setAttribute('aria-busy', 'true');
  try {
    desenhar(await gravar({ fonte: form.fonte.value }));
    if (situacao.ajustes.modo === 'agente') {
      await api('/api/admin/impressora/teste', { metodo: 'POST' });
      avisar('Letra salva. A comanda de teste já está saindo.');
    } else {
      avisar('Letra salva');
    }
  } catch (erro) {
    botao.removeAttribute('aria-busy');
    mostrarErro(erro);
  }
}

export async function montar() {
  baixou = false;
  trocando = false;
  prova = null;
  teste = null;
  try {
    desenhar(await api('/api/admin/impressora'));
  } catch (erro) {
    mostrarErro(erro);
    return;
  }
  clearInterval(relogio);
  relogio = setInterval(() => { if (!prova || prova.estado !== 'imprimindo') atualizar(); }, ATUALIZAR_MS);
}

export function desmontar() {
  clearInterval(relogio);
}
