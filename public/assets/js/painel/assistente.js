// Assistente com IA (Etapa 4c, D70): humor, conhecimento, fluxos, modelo e custo, e teste lado a lado.
// Só o proprietário abre esta tela. A chave do OpenRouter nunca chega aqui: a API só diz se ela está configurada.
// Todas as abas ficam num formulário só (o que foi digitado não se perde ao trocar de aba); "Teste" fica de fora, não salva nada.
import { $, $$, esc, delegar } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { api } from '../shared/api.js';
import { avisar } from '../shared/avisos.js';
import { dataHora, plural } from '../shared/formato.js';
import { recipienteDaTela, mostrarErro, confirmar } from './casca.js';

const conteudo = recipienteDaTela();   // o contêiner desta tela (criado pelo roteador)

const ABAS = [
  { id: 'humor', titulo: 'Humor' },
  { id: 'conhecimento', titulo: 'Conhecimento' },
  { id: 'fluxos', titulo: 'Fluxos' },
  { id: 'modelo', titulo: 'Modelo e custo' },
  { id: 'teste', titulo: 'Teste' },
];
// Em que aba mora cada campo (para levar a pessoa ao erro depois de um "Salvar" recusado).
const ABA_DO_CAMPO = (nome) => {
  if (nome.startsWith('faqs') || nome === 'ia_texto_livre') return 'conhecimento';
  if (nome.startsWith('fluxos')) return 'fluxos';
  if (['ia_modelo', 'ia_teto_usd', 'ia_alerta_saldo_usd', 'ia_limite_hora', 'ia_memoria'].includes(nome)) return 'modelo';
  return 'humor';
};
const PERGUNTAS_DE_EXEMPLO = [
  'Quanto custa o prato pequeno?',
  'Vocês abrem no domingo?',
  'Qual a taxa de entrega?',
  'Quero falar com uma pessoa',
  'Tem alguma opção sem glúten?',
];

let dados = null;
let aba = 'humor';

// ---------- Formatação ----------
const usd = (v) => `US$ ${Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: v > 0 && v < 0.01 ? 5 : 2 })}`;
const milhar = (n) => Number(n || 0).toLocaleString('pt-BR');
const nomeDoModelo = (id) => dados?.modelos.find((m) => m.id === id)?.nome ?? id;

// ---------- Peças de formulário ----------
const campo = (nome, rotulo, valor, { ajuda = '', max = 200, tipo = 'text', extra = '' } = {}) => `
  <label class="campo">
    <span class="campo__rotulo">${rotulo}</span>
    <input class="entrada" type="${tipo}" name="${nome}" value="${esc(valor ?? '')}" maxlength="${max}" aria-describedby="erro-${nome}" ${extra}>
    ${ajuda ? `<span class="campo__ajuda">${ajuda}</span>` : ''}
    <span class="campo__erro" id="erro-${nome}" hidden></span>
  </label>`;
const area = (nome, rotulo, valor, { ajuda = '', max = 1500, linhas = 4 } = {}) => `
  <label class="campo">
    <span class="campo__rotulo">${rotulo}</span>
    <textarea class="entrada" name="${nome}" rows="${linhas}" maxlength="${max}" aria-describedby="erro-${nome}">${esc(valor ?? '')}</textarea>
    ${ajuda ? `<span class="campo__ajuda">${ajuda}</span>` : ''}
    <span class="campo__erro" id="erro-${nome}" hidden></span>
  </label>`;
const liga = (nome, rotulo, ligado, ajuda = '') => `
  <div class="campo">
    <label class="interruptor"><input type="checkbox" name="${nome}" ${ligado ? 'checked' : ''}><span class="interruptor__trilho"></span><span>${rotulo}</span></label>
    ${ajuda ? `<span class="campo__ajuda">${ajuda}</span>` : ''}
  </div>`;
const opcoes = (mapa, atual) => Object.entries(mapa).map(([id, nome]) => `<option value="${esc(id)}" ${id === atual ? 'selected' : ''}>${esc(nome)}</option>`).join('');

// ---------- Aba: Humor ----------
function humorHtml(c) {
  return `
    <section class="bloco">
      <h2 class="bloco__titulo">Quem é o assistente</h2>
      <div class="bloco__grade">
        ${campo('ia_nome', 'Nome', c.ia_nome, { max: 40, ajuda: 'Como ele se apresenta ao cliente.' })}
        ${area('ia_papel', 'Papel e escopo', c.ia_papel, { max: 1500, ajuda: 'O que ele faz pela loja e o que fica fora do assunto.' })}
      </div>
    </section>
    <section class="bloco">
      <h2 class="bloco__titulo">Jeito de falar</h2>
      <div class="bloco__grade">
        <label class="campo">
          <span class="campo__rotulo">Tom de voz (humor)</span>
          <select class="entrada" name="ia_tom" aria-describedby="erro-ia_tom">${opcoes(dados.tons, c.ia_tom)}</select>
          <span class="campo__erro" id="erro-ia_tom" hidden></span>
        </label>
        <div id="campo-tom-texto" ${c.ia_tom === 'personalizado' ? '' : 'hidden'}>
          ${campo('ia_tom_texto', 'Descreva o tom', c.ia_tom_texto, { max: 300, ajuda: 'Ex.: "carioca, com gírias leves, chama o cliente de amigo".' })}
        </div>
        <label class="campo">
          <span class="campo__rotulo">Tamanho das respostas</span>
          <select class="entrada" name="ia_tamanho" aria-describedby="erro-ia_tamanho">${opcoes(dados.tamanhos, c.ia_tamanho)}</select>
          <span class="campo__erro" id="erro-ia_tamanho" hidden></span>
        </label>
        ${campo('ia_saudacao', 'Saudação', c.ia_saudacao, { max: 200, ajuda: 'Como ele abre a primeira mensagem da conversa.' })}
        ${liga('ia_avisar_ia', 'Dizer que é um assistente virtual', c.ia_avisar_ia, 'Na primeira mensagem. É o recomendado: o cliente sabe com quem fala.')}
      </div>
    </section>
    <section class="bloco">
      <h2 class="bloco__titulo">Regras e quando chamar uma pessoa</h2>
      <div class="bloco__grade">
        ${area('ia_regras', 'Regras do dono', c.ia_regras, { max: 1500, ajuda: 'O que ele nunca deve fazer ou dizer. As regras de não inventar preço e de não montar pedido já vêm de fábrica.' })}
        ${area('ia_escalonar', 'Quando passar para uma pessoa', c.ia_escalonar, { max: 1000, linhas: 3, ajuda: 'A conversa vai para a caixa de entrada do WhatsApp, com o bot em silêncio.' })}
      </div>
    </section>`;
}

// ---------- Aba: Conhecimento ----------
const faqHtml = (f = { pergunta: '', resposta: '', ativo: true }) => `
  <li class="item-edicao" data-faq>
    <div class="item-edicao__topo">
      <label class="interruptor"><input type="checkbox" name="faq-ativo" ${f.ativo ? 'checked' : ''}><span class="interruptor__trilho"></span><span>Ativa</span></label>
      <button type="button" class="botao botao--fantasma botao--icone" data-tirar aria-label="Tirar esta pergunta">${icone('lixeira', 18)}</button>
    </div>
    <label class="campo"><span class="campo__rotulo">Pergunta do cliente</span><input class="entrada" name="faq-pergunta" maxlength="200" value="${esc(f.pergunta)}"></label>
    <label class="campo"><span class="campo__rotulo">Resposta</span><textarea class="entrada" name="faq-resposta" rows="2" maxlength="800">${esc(f.resposta)}</textarea></label>
    <span class="campo__erro" data-erro-item hidden></span>
  </li>`;

function conhecimentoHtml(c) {
  return `
    <section class="bloco">
      <h2 class="bloco__titulo">O que vem do sistema (atualiza sozinho)</h2>
      <p class="bloco__nota">${icone('info', 16)}<span>O assistente lê isto na hora de cada resposta. Mudou o preço ou o horário? Ele já sabe.</span></p>
      <div class="bloco__grade">
        ${liga('ia_incluir_cardapio', 'Cardápio', c.ia_incluir_cardapio, 'Tamanhos, massas, molhos, ingredientes, pratos da casa e o que está esgotado.')}
        ${liga('ia_incluir_horarios', 'Horários de funcionamento', c.ia_incluir_horarios, 'A semana inteira e se a loja está aberta agora.')}
        ${liga('ia_incluir_entrega', 'Entrega e retirada', c.ia_incluir_entrega, 'Taxa, área atendida e tempos.')}
      </div>
    </section>
    <section class="bloco">
      <h2 class="bloco__titulo">Perguntas e respostas</h2>
      <p class="bloco__nota">${icone('info', 16)}<span>Respostas que você quer exatamente assim (formas de pagamento, promoções, alergias). Até 50.</span></p>
      <ul class="item-edicao-lista" id="lista-faqs">${dados.faqs.map(faqHtml).join('')}</ul>
      <div><button type="button" class="botao botao--secundario botao--p" data-nova-faq>${icone('mais', 16)}Nova pergunta</button></div>
    </section>
    <section class="bloco">
      <h2 class="bloco__titulo">Texto livre</h2>
      <div class="bloco__grade">
        ${area('ia_texto_livre', 'Informações e instruções extras', c.ia_texto_livre, { max: 4000, linhas: 6, ajuda: 'A história da loja, avisos de feriado, o que sugerir primeiro... Escreva com as suas palavras.' })}
      </div>
    </section>
    <section class="bloco">
      <h2 class="bloco__titulo">O que o assistente recebe</h2>
      <p class="bloco__nota">${icone('info', 16)}<span>O texto exato que vai ao modelo como instrução (sem as mensagens do cliente). Confira se ele sabe o que você acha que sabe.</span></p>
      <div><button type="button" class="botao botao--secundario botao--p" data-ver-prompt>Ver o que o assistente sabe</button></div>
      <div id="prompt-visto" hidden></div>
    </section>`;
}

// ---------- Aba: Fluxos ----------
const fluxoHtml = (f = { titulo: '', quando: '', passos: '', ativo: true }) => `
  <li class="item-edicao" data-fluxo>
    <div class="item-edicao__topo">
      <label class="interruptor"><input type="checkbox" name="fluxo-ativo" ${f.ativo ? 'checked' : ''}><span class="interruptor__trilho"></span><span>Ativo</span></label>
      <button type="button" class="botao botao--fantasma botao--icone" data-tirar aria-label="Tirar este fluxo">${icone('lixeira', 18)}</button>
    </div>
    <label class="campo"><span class="campo__rotulo">Nome do fluxo</span><input class="entrada" name="fluxo-titulo" maxlength="80" value="${esc(f.titulo)}" placeholder="Ex.: Cliente reclama do pedido"></label>
    <label class="campo"><span class="campo__rotulo">Quando acontece</span><input class="entrada" name="fluxo-quando" maxlength="300" value="${esc(f.quando)}" placeholder="Ex.: o cliente disser que o pedido atrasou ou veio errado"></label>
    <label class="campo"><span class="campo__rotulo">O que o assistente faz</span><textarea class="entrada" name="fluxo-passos" rows="3" maxlength="1000" placeholder="Ex.: peça desculpas, pergunte o número do pedido e chame uma pessoa">${esc(f.passos)}</textarea></label>
    <span class="campo__erro" data-erro-item hidden></span>
  </li>`;

function fluxosHtml() {
  return `
    <section class="bloco">
      <h2 class="bloco__titulo">Fluxos de atendimento</h2>
      <p class="bloco__nota">${icone('info', 16)}<span>Cada fluxo é uma situação e o que fazer nela, escrito com as suas palavras. O assistente segue quando a situação aparece. Até 20.</span></p>
      <ul class="item-edicao-lista" id="lista-fluxos">${dados.fluxos.map(fluxoHtml).join('')}</ul>
      <div><button type="button" class="botao botao--secundario botao--p" data-novo-fluxo>${icone('mais', 16)}Novo fluxo</button></div>
    </section>
    <section class="bloco">
      <h2 class="bloco__titulo">O que o assistente sabe fazer</h2>
      <p class="bloco__nota">${icone('info', 16)}<span>São as ações de verdade que ele pode chamar. Ele não monta pedido nem cobra: quem monta é o cliente, no cardápio.</span></p>
      <ul class="checagem">
        <li data-ok><span class="checagem__marca">${icone('check', 14)}</span><span><strong>Enviar o link do cardápio</strong> com o número do cliente já preenchido (só com a loja aberta).</span></li>
        <li data-ok><span class="checagem__marca">${icone('check', 14)}</span><span><strong>Consultar o pedido</strong> em andamento daquele cliente e mandar o link de acompanhamento.</span></li>
        <li data-ok><span class="checagem__marca">${icone('check', 14)}</span><span><strong>Passar para uma pessoa</strong>: a conversa vai para a caixa de entrada e o bot fica em silêncio.</span></li>
      </ul>
    </section>`;
}

// ---------- Aba: Modelo e custo ----------
const dolar = (v) => `US$ ${Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function creditoHtml() {
  const k = dados.credito;
  const situacao = !k.ativa ? ['selo--info', 'controle desligado']
    : k.saldo_usd <= 0 ? ['selo--perigo', 'acabou']
      : k.alerta_usd > 0 && k.saldo_usd < k.alerta_usd ? ['selo--alerta', 'baixo']
        : ['selo--sucesso', 'ok'];
  return `
    <section class="bloco" id="bloco-credito">
      <h2 class="bloco__titulo">Crédito do cliente <span class="selo ${situacao[0]}">${situacao[1]}</span></h2>
      <p class="bloco__nota">${icone('info', 16)}<span>Registre aqui cada recarga que o cliente pagar. O saldo é o total das recargas menos o que o assistente gastou no WhatsApp desde a primeira (os testes do painel ficam por sua conta). Quando o saldo chega a zero, o bot volta ao menu de 3 opções e o painel avisa. Sem nenhuma recarga registrada, o controle fica desligado.</span></p>
      ${k.ativa ? `
        <dl class="numeros">
          <div><dt>Saldo do cliente</dt><dd class="numero">${dolar(k.saldo_usd)}</dd></div>
          <div><dt>Recargas no total</dt><dd class="numero">${dolar(k.recargas_usd)}</dd></div>
          <div><dt>Gasto desde a 1ª recarga</dt><dd class="numero">${usd(k.gasto_usd)}</dd></div>
        </dl>` : '<p class="campo__ajuda">Controle desligado. Registre a primeira recarga para ligar.</p>'}
      <div class="credito-recarga" id="form-recarga">
        <label class="campo">
          <span class="campo__rotulo">Valor da recarga (US$)</span>
          <input class="entrada" name="recarga-valor" inputmode="decimal" maxlength="8" placeholder="10" aria-describedby="erro-recarga-valor" autocomplete="off">
          <span class="campo__erro" id="erro-recarga-valor" hidden></span>
        </label>
        <label class="campo">
          <span class="campo__rotulo">Anotação (opcional)</span>
          <input class="entrada" name="recarga-nota" maxlength="120" placeholder="Ex.: Pix de R$ 60 em 15/11" autocomplete="off">
        </label>
        <button type="button" class="botao" data-recarregar>Registrar recarga</button>
      </div>
      ${k.recargas.length ? `
        <ul class="item-edicao-lista">${k.recargas.map((r) => `
          <li class="item-edicao"><div class="item-edicao__topo">
            <span><strong>${dolar(r.valor_usd)}</strong> · ${dataHora(r.em)}${r.nota ? ` · ${esc(r.nota)}` : ''}</span>
            <button type="button" class="botao botao--fantasma botao--icone" data-apagar-recarga="${r.id}" aria-label="Tirar a recarga de ${dolar(r.valor_usd)}">${icone('lixeira', 18)}</button>
          </div></li>`).join('')}</ul>` : ''}
      <p class="campo__ajuda">Este registro é o <strong>crédito que o cliente pagou a você</strong>. Para o assistente funcionar, a <strong>sua conta no OpenRouter</strong> também precisa ter saldo: é lá que se compra, em <a href="https://openrouter.ai/settings/credits" target="_blank" rel="noopener">openrouter.ai/settings/credits</a> (precisa do seu login).</p>
    </section>`;
}

function modeloHtml(c) {
  const u = dados.uso;
  const teto = Number(c.ia_teto_usd) || 0;
  const pct = teto > 0 ? Math.min(100, Math.round((u.mes_usd / teto) * 100)) : 0;
  return `
    ${creditoHtml()}
    <section class="bloco">
      <h2 class="bloco__titulo">Modelo de IA</h2>
      <p class="bloco__nota">${icone('info', 16)}<span>Tudo passa pelo OpenRouter, então trocar de modelo é só trocar o identificador. Compare na aba <strong>Teste</strong> antes de decidir.</span></p>
      <div class="bloco__grade">
        ${campo('ia_modelo', 'Modelo (identificador no OpenRouter)', c.ia_modelo, { max: 100, extra: 'list="modelos-lista" autocomplete="off" spellcheck="false"', ajuda: `Padrão: <code>anthropic/claude-haiku-5.5</code>. Aceita qualquer modelo do OpenRouter que use ferramentas.` })}
        <datalist id="modelos-lista">${dados.modelos.map((m) => `<option value="${esc(m.id)}">${esc(m.nome)}</option>`).join('')}</datalist>
        <div class="modelos-sugeridos" role="group" aria-label="Modelos sugeridos">
          ${dados.modelos.map((m) => `
            <button type="button" class="modelo-opcao" data-modelo="${esc(m.id)}" aria-pressed="${m.id === c.ia_modelo}">
              <strong>${esc(m.nome)}</strong>
              <span>${esc(m.preco)}</span>
            </button>`).join('')}
        </div>
        ${liga('ia_privacidade', 'Só provedores que não guardam os dados (recomendado)', c.ia_privacidade === 'deny', 'Evita que as conversas dos clientes sejam guardadas por quem roda o modelo. Os modelos gratuitos quase sempre exigem desligar isto: use-os só no teste, com conversa de mentira.')}
      </div>
    </section>
    <section class="bloco">
      <h2 class="bloco__titulo">Limites de gasto</h2>
      <div class="bloco__grade">
        ${campo('ia_teto_usd', 'Teto mensal (US$)', c.ia_teto_usd, { max: 8, tipo: 'text', extra: 'inputmode="decimal"', ajuda: 'Ao chegar nele, o bot volta ao menu de 3 opções (sem IA) até o mês virar. Os testes do painel entram na conta.' })}
        ${campo('ia_alerta_saldo_usd', 'Avisar quando o saldo do cliente ficar abaixo de (US$)', c.ia_alerta_saldo_usd, { max: 8, tipo: 'text', extra: 'inputmode="decimal"', ajuda: 'A faixa de aviso aparece para o proprietário em qualquer tela. 0 desliga o aviso.' })}
        ${campo('ia_limite_hora', 'Respostas por cliente por hora', c.ia_limite_hora, { max: 3, tipo: 'text', extra: 'inputmode="numeric"', ajuda: 'Protege o crédito de quem ficar mandando mensagem sem parar.' })}
        ${campo('ia_memoria', 'Mensagens anteriores que ele lembra', c.ia_memoria, { max: 2, tipo: 'text', extra: 'inputmode="numeric"', ajuda: 'De 0 a 20. Mais memória custa um pouco mais por resposta.' })}
      </div>
    </section>
    <section class="bloco">
      <h2 class="bloco__titulo">Gasto <span class="selo ${dados.chave_configurada ? 'selo--sucesso' : 'selo--perigo'}">${dados.chave_configurada ? 'chave configurada' : 'sem chave'}</span></h2>
      ${dados.chave_configurada ? '' : `<p class="bloco__nota">${icone('alerta', 16)}<span>Falta a chave do OpenRouter. No servidor (Render → Environment), crie a variável <code>OPENROUTER_API_KEY</code>. Ela nunca é digitada nem guardada aqui. O cartão e os créditos ficam na sua conta do OpenRouter.</span></p>`}
      <dl class="numeros">
        <div><dt>Gasto no mês</dt><dd class="numero">${usd(u.mes_usd)}</dd></div>
        <div><dt>Gasto hoje</dt><dd class="numero">${usd(u.hoje_usd)}</dd></div>
        <div><dt>Respostas no mês</dt><dd class="numero">${milhar(u.mes_respostas)}</dd></div>
        <div><dt>Falhas no mês</dt><dd class="numero">${milhar(u.mes_erros)}</dd></div>
      </dl>
      ${teto > 0 ? `<p class="campo__ajuda">${pct}% do teto de ${usd(teto)}.</p><progress max="100" value="${pct}" aria-label="Quanto do teto mensal já foi gasto" style="width: 100%;"></progress>` : ''}
      <p class="campo__ajuda">O gasto é o custo real que o OpenRouter informa a cada resposta. O mês vai de dia 1 a fim do mês, em horário UTC (o mesmo do saldo do OpenRouter).</p>
      <div>
        <button type="button" class="botao botao--secundario botao--p" data-saldo ${dados.chave_configurada ? '' : 'disabled'}>Ver saldo no OpenRouter</button>
        <div id="saldo-openrouter" class="campo__ajuda" style="margin-top: var(--e-2);" role="status"></div>
      </div>
      ${u.por_modelo.length ? `
        <h3 class="detalhe__titulo" style="margin-top: var(--e-4);">Por modelo, neste mês</h3>
        <div class="tabela-rolagem"><table class="tabela">
          <thead><tr><th>Modelo</th><th>Respostas</th><th>Tokens (entrada / saída)</th><th>Custo</th></tr></thead>
          <tbody>${u.por_modelo.map((m) => `<tr><td>${esc(nomeDoModelo(m.modelo))}</td><td class="numero">${milhar(m.respostas)}</td><td class="numero">${milhar(m.tokens_in)} / ${milhar(m.tokens_out)}</td><td class="numero">${usd(m.custo_usd)}</td></tr>`).join('')}</tbody>
        </table></div>` : ''}
      ${u.recentes.length ? `
        <h3 class="detalhe__titulo" style="margin-top: var(--e-4);">Últimas respostas</h3>
        <div class="tabela-rolagem"><table class="tabela">
          <thead><tr><th>Quando</th><th>Origem</th><th>Modelo</th><th>Custo</th><th>Resultado</th></tr></thead>
          <tbody>${u.recentes.map((r) => `<tr ${r.erro ? 'data-erro' : ''}><td class="numero">${dataHora(r.em)}</td><td>${r.origem === 'teste' ? 'Teste' : 'WhatsApp'}</td><td>${esc(nomeDoModelo(r.modelo))}</td><td class="numero">${usd(r.custo_usd)}</td><td>${r.erro ? esc(r.erro) : `${(r.ms / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} s${r.ferramentas ? ` · ${esc(r.ferramentas.replaceAll(',', ', '))}` : ''}`}</td></tr>`).join('')}</tbody>
        </table></div>` : ''}
    </section>`;
}

// ---------- Aba: Teste ----------
function testeHtml() {
  const c = dados.config;
  return `
    <section class="bloco">
      <h2 class="bloco__titulo">Comparar modelos</h2>
      <p class="bloco__nota">${icone('info', 16)}<span>A mesma pergunta vai a até 3 modelos ao mesmo tempo, com o humor e o conhecimento que estão salvos. Nada é enviado a cliente e nenhuma conversa é alterada. Cada teste gasta um pouco de crédito.</span></p>
      ${dados.chave_configurada ? '' : `<p class="bloco__nota">${icone('alerta', 16)}<span>Falta a chave do OpenRouter (variável <code>OPENROUTER_API_KEY</code> no servidor). Sem ela não dá para testar.</span></p>`}
      <div id="form-teste" class="bloco__grade">
        <label class="campo">
          <span class="campo__rotulo">Pergunta do cliente</span>
          <textarea class="entrada" name="pergunta" rows="2" maxlength="500" placeholder="Ex.: Quanto custa o prato pequeno com carne seca?"></textarea>
          <span class="campo__erro" id="erro-pergunta" hidden></span>
        </label>
        <div class="chips-exemplo" role="group" aria-label="Perguntas de exemplo">
          ${PERGUNTAS_DE_EXEMPLO.map((p) => `<button type="button" class="chip" data-exemplo="${esc(p)}">${esc(p)}</button>`).join('')}
        </div>
        <fieldset class="campo teste-modelos">
          <legend class="campo__rotulo">Modelos (até 3)</legend>
          ${dados.modelos.map((m, i) => `
            <label class="interruptor"><input type="checkbox" name="modelo-teste" value="${esc(m.id)}" ${i < 2 || m.id === c.ia_modelo ? 'checked' : ''}><span class="interruptor__trilho"></span><span><strong>${esc(m.nome)}</strong> <span class="campo__ajuda">${esc(m.preco)}</span></span></label>`).join('')}
          <label class="campo"><span class="campo__rotulo">Outro modelo (opcional)</span><input class="entrada" name="modelo-outro" maxlength="100" placeholder="provedor/modelo" autocomplete="off" spellcheck="false"></label>
          <span class="campo__erro" id="erro-modelos" hidden></span>
        </fieldset>
        <div><button type="button" class="botao" data-comparar ${dados.chave_configurada ? '' : 'disabled'}>Comparar</button></div>
      </div>
    </section>
    <div class="teste-grade" id="teste-resultados" aria-live="polite"></div>`;
}

// ---------- Tela ----------
function estadoHtml() {
  const mensagens = {
    desligada: ['selo--alerta', 'Desligado', 'O bot responde só com o menu de 3 opções.'],
    sem_chave: ['selo--perigo', 'Sem chave', 'Falta a chave do OpenRouter (variável OPENROUTER_API_KEY no servidor). Enquanto isso, o bot usa o menu de 3 opções.'],
    sem_credito: ['selo--perigo', 'Sem crédito', 'O crédito acabou (o saldo do cliente chegou a zero ou o OpenRouter recusou por falta de saldo). O bot usa o menu de 3 opções até entrar uma recarga.'],
    teto: ['selo--perigo', 'Teto atingido', 'O gasto do mês chegou ao teto. O bot usa o menu de 3 opções até o mês virar ou até você aumentar o teto.'],
  };
  const [classe, rotulo, texto] = mensagens[dados.indisponivel] ?? ['selo--sucesso', 'Respondendo', `O texto livre dos clientes é respondido por ${esc(nomeDoModelo(dados.config.ia_modelo))}. Os botões e os números 1, 2 e 3 continuam respostas fixas, sem custo.`];
  return `<span class="selo ${classe}">${rotulo}</span> <span class="campo__ajuda">${texto}</span>`;
}

function desenhar() {
  const c = dados.config;
  conteudo.innerHTML = `
    <div class="cabecalho-tela"><h1 class="cabecalho-tela__titulo">Assistente</h1></div>
    <div class="config assistente">
      <form id="form-assistente" class="config" novalidate>
      <section class="bloco">
        <div class="assistente__estado">
          ${liga('ia_ativo', 'Assistente com IA ligado', c.ia_ativo)}
        </div>
        <p id="estado-ia" role="status">${estadoHtml()}</p>
      </section>
      <div class="abas" role="tablist" aria-label="Seções do assistente">
        ${ABAS.map((a) => `<button type="button" class="chip" role="tab" id="aba-${a.id}" data-aba="${a.id}" aria-selected="${a.id === aba}" aria-controls="painel-${a.id}">${a.titulo}</button>`).join('')}
      </div>
        <div id="painel-humor" role="tabpanel" aria-labelledby="aba-humor" class="config" ${aba === 'humor' ? '' : 'hidden'}>${humorHtml(c)}</div>
        <div id="painel-conhecimento" role="tabpanel" aria-labelledby="aba-conhecimento" class="config" ${aba === 'conhecimento' ? '' : 'hidden'}>${conhecimentoHtml(c)}</div>
        <div id="painel-fluxos" role="tabpanel" aria-labelledby="aba-fluxos" class="config" ${aba === 'fluxos' ? '' : 'hidden'}>${fluxosHtml()}</div>
        <div id="painel-modelo" role="tabpanel" aria-labelledby="aba-modelo" class="config" ${aba === 'modelo' ? '' : 'hidden'}>${modeloHtml(c)}</div>
      <div id="painel-teste" role="tabpanel" aria-labelledby="aba-teste" class="config" ${aba === 'teste' ? '' : 'hidden'}>${testeHtml()}</div>
        <div class="barra-salvar" id="barra-salvar" ${aba === 'teste' ? 'hidden' : ''}>
          <span class="barra-salvar__nota" id="nota-salvar" role="status"></span>
          <button type="submit" class="botao botao--g">Salvar assistente</button>
        </div>
      </form>
    </div>`;
}

function mostrarAba(nova) {
  aba = nova;
  $$('[data-aba]', conteudo).forEach((b) => b.setAttribute('aria-selected', String(b.dataset.aba === nova)));
  for (const a of ABAS) {
    const painel = $(`#painel-${a.id}`, conteudo);
    if (painel) painel.hidden = a.id !== nova;
  }
  $('#barra-salvar', conteudo).hidden = nova === 'teste';
}

// ---------- Salvar ----------
function coletar() {
  const f = $('#form-assistente', conteudo);
  const v = (nome) => f.elements[nome]?.value ?? '';
  const l = (nome) => f.elements[nome]?.checked ?? false;
  const config = {
    ia_ativo: l('ia_ativo'), ia_nome: v('ia_nome'), ia_papel: v('ia_papel'), ia_tom: v('ia_tom'), ia_tom_texto: v('ia_tom_texto'), ia_tamanho: v('ia_tamanho'),
    ia_saudacao: v('ia_saudacao'), ia_avisar_ia: l('ia_avisar_ia'), ia_regras: v('ia_regras'), ia_escalonar: v('ia_escalonar'), ia_texto_livre: v('ia_texto_livre'),
    ia_incluir_cardapio: l('ia_incluir_cardapio'), ia_incluir_horarios: l('ia_incluir_horarios'), ia_incluir_entrega: l('ia_incluir_entrega'),
    ia_modelo: v('ia_modelo').trim(), ia_privacidade: l('ia_privacidade') ? 'deny' : 'allow',
    ia_teto_usd: v('ia_teto_usd'), ia_alerta_saldo_usd: v('ia_alerta_saldo_usd'), ia_limite_hora: v('ia_limite_hora'), ia_memoria: v('ia_memoria'),
  };
  const valorDe = (item, nome) => $(`[name="${nome}"]`, item).value;
  const faqs = $$('[data-faq]', f).map((li) => ({ pergunta: valorDe(li, 'faq-pergunta'), resposta: valorDe(li, 'faq-resposta'), ativo: $('[name="faq-ativo"]', li).checked }));
  const fluxos = $$('[data-fluxo]', f).map((li) => ({ titulo: valorDe(li, 'fluxo-titulo'), quando: valorDe(li, 'fluxo-quando'), passos: valorDe(li, 'fluxo-passos'), ativo: $('[name="fluxo-ativo"]', li).checked }));
  return { config, faqs, fluxos };
}

function limparErros() {
  $$('.campo__erro', conteudo).forEach((e) => { e.hidden = true; e.textContent = ''; });
  $$('[aria-invalid]', conteudo).forEach((e) => e.removeAttribute('aria-invalid'));
}

function mostrarErrosDeCampo(campos) {
  let primeira = null;
  for (const [nome, mensagem] of Object.entries(campos)) {
    const itemListado = /^(faqs|fluxos)\.(\d+)$/.exec(nome);
    let alvo = null;
    if (itemListado) {
      // O índice conta só as linhas que não estavam em branco; a tela mostra o erro na linha de mesma posição.
      const linhas = $$(itemListado[1] === 'faqs' ? '[data-faq]' : '[data-fluxo]', conteudo).filter((li) => $$('input, textarea', li).some((x) => x.type !== 'checkbox' && x.value.trim() !== ''));
      alvo = $('[data-erro-item]', linhas[Number(itemListado[2])] ?? linhas.at(-1) ?? conteudo);
    } else {
      alvo = $(`#erro-${nome}`, conteudo);
      $(`[name="${nome}"]`, conteudo)?.setAttribute('aria-invalid', 'true');
    }
    if (alvo) {
      alvo.textContent = mensagem;
      alvo.hidden = false;
    }
    primeira ??= nome;
  }
  if (primeira) {
    mostrarAba(ABA_DO_CAMPO(primeira));
    ($(`[name="${primeira}"]`, conteudo) ?? $('[data-erro-item]:not([hidden])', conteudo))?.scrollIntoView({ block: 'center' });
  }
}

async function salvar() {
  const botao = $('button[type="submit"]', $('#form-assistente', conteudo));
  botao.setAttribute('aria-busy', 'true');
  limparErros();
  try {
    dados = await api('/api/admin/assistente', { metodo: 'PUT', corpo: coletar() });
    desenhar();
    avisar('Assistente salvo');
  } catch (erro) {
    if (erro.codigo === 'validacao') {
      mostrarErrosDeCampo(erro.campos);
      avisar('Confira os campos destacados.', { tipo: 'erro' });
    } else {
      mostrarErro(erro);
    }
  }
  botao.removeAttribute('aria-busy');
}

// ---------- Teste lado a lado ----------
function cartaoDeTeste(modelo, estado) {
  let corpo;
  if (estado === 'carregando') {
    corpo = '<p class="campo__ajuda" aria-busy="true">Pensando…</p>';
  } else if (estado.erro) {
    corpo = `<p class="campo__erro">${icone('alerta', 14)} ${esc(estado.erro)}</p>`;
  } else {
    corpo = `
      <p class="teste-cartao__texto">${esc(estado.texto)}</p>
      <p class="campo__ajuda">${milhar(estado.tokens_in + estado.tokens_out)} tokens · ${usd(estado.custo_usd)} · ${(estado.ms / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} s${estado.ferramentas.length ? ` · usou: ${esc(estado.ferramentas.join(', '))}` : ''}</p>`;
  }
  return `
    <article class="teste-cartao">
      <header><strong>${esc(nomeDoModelo(modelo))}</strong> <span class="campo__ajuda">${esc(modelo)}</span></header>
      ${corpo}
    </article>`;
}

async function comparar() {
  const form = $('#form-teste', conteudo);
  const pergunta = $('textarea[name="pergunta"]', form).value.trim();
  const erroPergunta = $('#erro-pergunta', conteudo);
  const erroModelos = $('#erro-modelos', conteudo);
  erroPergunta.hidden = erroModelos.hidden = true;
  const modelos = [...new Set([...$$('input[name="modelo-teste"]:checked', form).map((i) => i.value), $('input[name="modelo-outro"]', form).value.trim()].filter(Boolean))];
  if (!pergunta) {
    erroPergunta.textContent = 'Escreva a pergunta do cliente.';
    erroPergunta.hidden = false;
    return;
  }
  if (modelos.length === 0 || modelos.length > 3) {
    erroModelos.textContent = modelos.length === 0 ? 'Escolha pelo menos um modelo.' : 'No máximo 3 modelos de cada vez.';
    erroModelos.hidden = false;
    return;
  }
  const alvo = $('#teste-resultados', conteudo);
  const estados = new Map(modelos.map((m) => [m, 'carregando']));
  const redesenhar = () => { alvo.innerHTML = modelos.map((m) => cartaoDeTeste(m, estados.get(m))).join(''); };
  redesenhar();
  const botao = $('[data-comparar]', form);
  botao.setAttribute('aria-busy', 'true');
  // Uma chamada por modelo, todas ao mesmo tempo: o tempo total é o do mais lento.
  await Promise.all(modelos.map(async (modelo) => {
    try {
      estados.set(modelo, await api('/api/admin/assistente/testar', { metodo: 'POST', corpo: { pergunta, modelo } }));
    } catch (erro) {
      if (erro.status === 401) {
        mostrarErro(erro);
        return;
      }
      estados.set(modelo, { erro: erro.campos?.modelo ?? erro.message });
    }
    redesenhar();
  }));
  botao.removeAttribute('aria-busy');
  try {
    const atual = await api('/api/admin/assistente');   // o gasto do teste aparece na aba de custo; o teste também pode ligar ou desligar o "sem crédito"
    dados.uso = atual.uso;
    dados.credito = atual.credito;
    dados.indisponivel = atual.indisponivel;
    $('#estado-ia', conteudo).innerHTML = estadoHtml();
  } catch { /* o resumo se atualiza na próxima vez */ }
}

// ---------- Eventos ----------
delegar(conteudo, 'click', '[data-aba]', (e, botao) => mostrarAba(botao.dataset.aba));
delegar(conteudo, 'change', 'select[name="ia_tom"]', (e, selecao) => {
  $('#campo-tom-texto', conteudo).hidden = selecao.value !== 'personalizado';
});
delegar(conteudo, 'input', '#form-assistente', (e) => {
  if (e.target.closest('#painel-teste, #bloco-credito')) return;   // digitar no teste ou na recarga não é alterar o assistente
  $('#nota-salvar', conteudo).textContent = 'Há alterações não salvas.';
});
delegar(conteudo, 'change', 'input[name="ia_ativo"]', () => {
  $('#nota-salvar', conteudo).textContent = 'Há alterações não salvas.';
});
delegar(conteudo, 'click', '[data-comparar]', () => comparar());
// Enter dentro do teste compara; ele não pode disparar o "Salvar" do formulário.
delegar(conteudo, 'keydown', '#painel-teste input', (e) => {
  if (e.key === 'Enter') {
    e.preventDefault();
    comparar();
  }
});
delegar(conteudo, 'click', '[data-nova-faq]', () => {
  $('#lista-faqs', conteudo).insertAdjacentHTML('beforeend', faqHtml());
  $$('[data-faq]', conteudo).at(-1).querySelector('input[name="faq-pergunta"]').focus();
});
delegar(conteudo, 'click', '[data-novo-fluxo]', () => {
  $('#lista-fluxos', conteudo).insertAdjacentHTML('beforeend', fluxoHtml());
  $$('[data-fluxo]', conteudo).at(-1).querySelector('input[name="fluxo-titulo"]').focus();
});
delegar(conteudo, 'click', '[data-tirar]', (e, botao) => {
  botao.closest('li').remove();
  $('#nota-salvar', conteudo).textContent = 'Há alterações não salvas.';
});
delegar(conteudo, 'click', '[data-modelo]', (e, botao) => {
  $('input[name="ia_modelo"]', conteudo).value = botao.dataset.modelo;
  $$('[data-modelo]', conteudo).forEach((b) => b.setAttribute('aria-pressed', String(b === botao)));
  $('#nota-salvar', conteudo).textContent = 'Há alterações não salvas.';
});
delegar(conteudo, 'input', 'input[name="ia_modelo"]', (e, entrada) => {
  $$('[data-modelo]', conteudo).forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.modelo === entrada.value.trim())));
});
delegar(conteudo, 'click', '[data-exemplo]', (e, botao) => {
  const campoPergunta = $('textarea[name="pergunta"]', conteudo);
  campoPergunta.value = botao.dataset.exemplo;
  campoPergunta.focus();
});
// No máximo 3 modelos marcados no teste.
delegar(conteudo, 'change', 'input[name="modelo-teste"]', (e, caixa) => {
  const marcadas = $$('input[name="modelo-teste"]:checked', conteudo);
  if (marcadas.length > 3) {
    caixa.checked = false;
    avisar('No máximo 3 modelos de cada vez.', { tipo: 'erro' });
  }
});
delegar(conteudo, 'click', '[data-ver-prompt]', async (e, botao) => {
  const alvo = $('#prompt-visto', conteudo);
  botao.setAttribute('aria-busy', 'true');
  try {
    const r = await api('/api/admin/assistente/prompt');
    alvo.innerHTML = `
      <p class="campo__ajuda">${milhar(r.caracteres)} caracteres, cerca de ${milhar(r.tokens_aprox)} tokens por resposta (é isto que se paga a cada mensagem do cliente, além da conversa). Mostra o que está <strong>salvo</strong>, não o que você acabou de digitar.</p>
      <pre class="prompt-visto">${esc(r.prompt)}</pre>`;
    alvo.hidden = false;
  } catch (erro) {
    mostrarErro(erro);
  }
  botao.removeAttribute('aria-busy');
});
delegar(conteudo, 'click', '[data-saldo]', async (e, botao) => {
  const alvo = $('#saldo-openrouter', conteudo);
  botao.setAttribute('aria-busy', 'true');
  try {
    const s = await api('/api/admin/assistente/saldo');
    if (!s.ok) {
      alvo.textContent = s.erro;
    } else {
      const partes = [];
      if (s.restante !== null) partes.push(`Restam ${usd(s.restante)}${s.limite !== null ? ` de ${usd(s.limite)}` : ''} nesta chave.`);
      partes.push(`Uso no OpenRouter: ${usd(s.uso_mes)} neste mês, ${usd(s.uso_hoje)} hoje.`);
      if (s.gratis_hoje) partes.push(`Modelos gratuitos hoje: ${s.gratis_hoje.usadas} de ${s.gratis_hoje.limite}.`);
      alvo.textContent = partes.join(' ');
    }
  } catch (erro) {
    mostrarErro(erro);
  }
  botao.removeAttribute('aria-busy');
});

conteudo.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (e.target.id === 'form-assistente') await salvar();
});

// ---------- Crédito do cliente ----------
/** Troca só a seção de crédito e o selo de estado: o que foi digitado no resto do formulário não se perde. */
function atualizarCredito(novos) {
  dados = novos;
  $('#bloco-credito', conteudo).outerHTML = creditoHtml();
  $('#estado-ia', conteudo).innerHTML = estadoHtml();
}

async function recarregar() {
  const erro = $('#erro-recarga-valor', conteudo);
  erro.hidden = true;
  const botao = $('[data-recarregar]', conteudo);
  botao.setAttribute('aria-busy', 'true');
  try {
    atualizarCredito(await api('/api/admin/assistente/recargas', {
      metodo: 'POST',
      corpo: { valor_usd: $('[name="recarga-valor"]', conteudo).value, nota: $('[name="recarga-nota"]', conteudo).value },
    }));
    avisar('Recarga registrada');
  } catch (falha) {
    if (falha.codigo === 'validacao') {
      erro.textContent = falha.campos.valor_usd ?? falha.message;
      erro.hidden = false;
    } else {
      mostrarErro(falha);
    }
    botao.removeAttribute('aria-busy');
  }
}

delegar(conteudo, 'click', '[data-recarregar]', () => recarregar());
delegar(conteudo, 'keydown', '#bloco-credito input', (e) => {
  if (e.key === 'Enter') {   // Enter aqui registra a recarga; não pode salvar o formulário inteiro
    e.preventDefault();
    recarregar();
  }
});
delegar(conteudo, 'click', '[data-apagar-recarga]', async (e, botao) => {
  if (!(await confirmar({ titulo: 'Tirar esta recarga?', texto: 'O saldo do cliente diminui desse valor. Use só para corrigir um lançamento errado.', confirmar: 'Tirar recarga', perigo: true }))) return;
  try {
    atualizarCredito(await api(`/api/admin/assistente/recargas/${botao.dataset.apagarRecarga}`, { metodo: 'DELETE' }));
    avisar('Recarga retirada');
  } catch (falha) {
    mostrarErro(falha);
  }
});

/** A tela abriu (a primeira vez ou de novo): busca tudo e desenha. */
export async function montar() {
  try {
    dados = await api('/api/admin/assistente');
    desenhar();
  } catch (erro) {
    mostrarErro(erro);
  }
}

export function desmontar() {}
