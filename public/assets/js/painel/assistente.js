// Assistente com IA (D70 a D72), na versão simples para o dono da loja: só o que ele sabe e quer mexer.
// Desde o D119 não é mais uma tela: aparece dentro da tela WhatsApp (whatsapp.js chama montarEm).
//   Humor: nome, jeito de falar (cartões com exemplo), tamanho das respostas, saudação.
//   Conhecimento: perguntas e respostas prontas e outras informações da loja.
//   Testar: uma pergunta ao assistente, do jeito que está salvo.
// O resto (regras de segurança, quando chamar uma pessoa, teto de gasto, privacidade, modelo Haiku 5.5, crédito) fica com os
// valores padrão do sistema: não aparece aqui e o "Salvar" não mexe neles. A chave do OpenRouter nunca chega a esta tela.
import { $, $$, esc, delegar } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { api } from '../shared/api.js';
import { avisar } from '../shared/avisos.js';
import { mostrarErro } from './casca.js';

let conteudo = null;   // o contêiner dentro da tela WhatsApp

const ABAS = [
  { id: 'humor', titulo: 'Humor' },
  { id: 'conhecimento', titulo: 'Conhecimento' },
  { id: 'teste', titulo: 'Testar' },
];
// Cada tom com uma frase de exemplo: o dono escolhe pelo jeito de falar, não pelo nome técnico.
const TONS = [
  { id: 'amigavel', nome: 'Amigável', exemplo: 'Oi! Que bom te ver por aqui 😊' },
  { id: 'bem_humorado', nome: 'Bem-humorado', exemplo: 'Opa! Bateu a fome de massa? 🍝' },
  { id: 'profissional', nome: 'Profissional', exemplo: 'Boa noite. Em que posso ajudar?' },
  { id: 'neutro', nome: 'Direto', exemplo: 'Olá. Como posso ajudar?' },
  { id: 'personalizado', nome: 'Do meu jeito', exemplo: 'Você descreve como ele deve falar' },
];
const TAMANHOS = [
  { id: 'curta', nome: 'Curtas', exemplo: '1 ou 2 frases, direto ao ponto' },
  { id: 'padrao', nome: 'Médias', exemplo: 'Um parágrafo curto' },
  { id: 'detalhada', nome: 'Completas', exemplo: 'Explica com mais detalhe' },
];
const PERGUNTAS_DE_EXEMPLO = ['Vocês abrem hoje?', 'Qual a taxa de entrega?', 'Tem opção sem glúten?', 'Aceitam Pix?', 'Quero falar com uma pessoa'];

let dados = null;
let aba = 'humor';

// ---------- Peças ----------
const campo = (nome, rotulo, valor, { ajuda = '', max = 200 } = {}) => `
  <label class="campo">
    <span class="campo__rotulo">${rotulo}</span>
    <input class="entrada" name="${nome}" value="${esc(valor ?? '')}" maxlength="${max}" aria-describedby="erro-${nome}">
    ${ajuda ? `<span class="campo__ajuda">${ajuda}</span>` : ''}
    <span class="campo__erro" id="erro-${nome}" hidden></span>
  </label>`;
const cartoes = (nome, lista, atual, rotulo) => `
  <fieldset class="cartoes-escolha">
    <legend class="campo__rotulo">${rotulo}</legend>
    ${lista.map((o) => `
      <label class="cartao-escolha">
        <input type="radio" name="${nome}" value="${o.id}" ${o.id === atual ? 'checked' : ''}>
        <strong>${esc(o.nome)}</strong>
        <span>${esc(o.exemplo)}</span>
      </label>`).join('')}
  </fieldset>`;

// ---------- Abas ----------
function humorHtml(c) {
  return `
    <section class="bloco">
      <h2 class="bloco__titulo">Como o assistente fala</h2>
      <div class="assistente-colunas">
        ${campo('ia_nome', 'Nome do assistente', c.ia_nome, { max: 40, ajuda: 'Ex.: Assistente, Nonna, Beto.' })}
        ${campo('ia_saudacao', 'Primeira frase da conversa', c.ia_saudacao, { max: 200, ajuda: 'Ex.: Olá! Tudo bem?' })}
      </div>
      ${cartoes('ia_tom', TONS, c.ia_tom, 'Humor')}
      <div id="campo-tom-texto" ${c.ia_tom === 'personalizado' ? '' : 'hidden'}>
        ${campo('ia_tom_texto', 'Descreva o jeito de falar', c.ia_tom_texto, { max: 300, ajuda: 'Ex.: carioca, com gírias leves, chama o cliente de amigo.' })}
      </div>
      ${cartoes('ia_tamanho', TAMANHOS, c.ia_tamanho, 'Tamanho das respostas')}
    </section>`;
}

const faqHtml = (f = { pergunta: '', resposta: '', ativo: true }) => `
  <li class="item-edicao" data-faq>
    <div class="item-edicao__topo">
      <span class="campo__rotulo">Quando o cliente perguntar…</span>
      <button type="button" class="botao botao--fantasma botao--icone" data-tirar aria-label="Tirar esta resposta">${icone('lixeira', 18)}</button>
    </div>
    <input class="entrada" name="faq-pergunta" maxlength="200" value="${esc(f.pergunta)}" placeholder="Ex.: Aceitam Pix?" aria-label="Pergunta do cliente">
    <span class="campo__rotulo">…o assistente responde:</span>
    <textarea class="entrada" name="faq-resposta" rows="2" maxlength="800" placeholder="Ex.: Sim! Pix, cartão e dinheiro na entrega." aria-label="Resposta">${esc(f.resposta)}</textarea>
    <span class="campo__erro" data-erro-item hidden></span>
  </li>`;

function conhecimentoHtml(c) {
  return `
    <section class="bloco">
      <h2 class="bloco__titulo">Respostas prontas</h2>
      <p class="bloco__nota">${icone('info', 16)}<span>O cardápio, os preços, os horários e a entrega o assistente já sabe sozinho. Aqui você ensina o resto: pagamento, promoções, alergias…</span></p>
      <ul class="item-edicao-lista" id="lista-faqs">${dados.faqs.map(faqHtml).join('')}</ul>
      <div><button type="button" class="botao botao--secundario" data-nova-faq>${icone('mais', 16)}Nova resposta</button></div>
    </section>
    <section class="bloco">
      <h2 class="bloco__titulo">Outras informações da loja</h2>
      <label class="campo">
        <textarea class="entrada" name="ia_texto_livre" rows="5" maxlength="4000" aria-label="Outras informações da loja" aria-describedby="erro-ia_texto_livre"
          placeholder="Ex.: Somos uma empresa familiar desde 2010. Fechamos no Natal e no Ano-Novo. Às sextas, sobremesa grátis acima de R$ 80.">${esc(c.ia_texto_livre)}</textarea>
        <span class="campo__ajuda">Escreva como você explicaria para um funcionário novo.</span>
        <span class="campo__erro" id="erro-ia_texto_livre" hidden></span>
      </label>
    </section>`;
}

function testeHtml() {
  return `
    <section class="bloco">
      <h2 class="bloco__titulo">Testar o assistente</h2>
      <p class="bloco__nota">${icone('info', 16)}<span>Faça uma pergunta como se fosse um cliente. Ele responde com o que está <strong>salvo</strong>. Nada é enviado a ninguém.</span></p>
      ${dados.chave_configurada ? '' : `<p class="bloco__nota">${icone('alerta', 16)}<span>O assistente ainda não foi ativado no servidor. Fale com o suporte.</span></p>`}
      <div id="form-teste" class="teste-form">
        <div class="chips-exemplo" role="group" aria-label="Perguntas de exemplo">
          ${PERGUNTAS_DE_EXEMPLO.map((p) => `<button type="button" class="chip" data-exemplo="${esc(p)}">${esc(p)}</button>`).join('')}
        </div>
        <div class="teste-linha">
          <input class="entrada" name="pergunta" maxlength="500" placeholder="Escreva a pergunta do cliente" autocomplete="off" aria-label="Pergunta do cliente">
          <button type="button" class="botao" data-testar ${dados.chave_configurada ? '' : 'disabled'}>Perguntar</button>
        </div>
        <div id="teste-resposta" aria-live="polite"></div>
      </div>
    </section>`;
}

function estadoHtml() {
  const textos = {
    desligada: ['selo--alerta', 'Desligado', 'Ligue para o assistente responder as dúvidas dos clientes no WhatsApp.'],
    sem_chave: ['selo--perigo', 'Não ativado', 'O assistente ainda não foi ativado no servidor. Fale com o suporte.'],
    sem_credito: ['selo--perigo', 'Sem crédito', 'O crédito acabou. Fale com o suporte para recarregar.'],
    teto: ['selo--perigo', 'Pausado', 'O limite de uso do mês foi atingido. Fale com o suporte.'],
  };
  const [classe, rotulo, texto] = textos[dados.indisponivel] ?? ['selo--sucesso', 'Ligado', dados.config.ia_pedido ? 'Atendendo e anotando os pedidos dos clientes no WhatsApp.' : 'Respondendo as dúvidas dos clientes no WhatsApp.'];
  return `<span class="selo ${classe}">${rotulo}</span> <span class="campo__ajuda">${texto}</span>`;
}

function desenhar() {
  const c = dados.config;
  conteudo.innerHTML = `
    <form id="form-assistente" class="config assistente" novalidate>
      <section class="bloco assistente__topo">
        <h2 class="bloco__titulo">Assistente com IA</h2>
        <label class="interruptor"><input type="checkbox" name="ia_ativo" ${c.ia_ativo ? 'checked' : ''}><span class="interruptor__trilho"></span><span><strong>Assistente ligado</strong></span></label>
        <p id="estado-ia" role="status">${estadoHtml()}</p>
      </section>
      <section class="bloco">
        <h2 class="bloco__titulo">Pedido pelo WhatsApp</h2>
        <label class="interruptor"><input type="checkbox" name="ia_pedido" ${c.ia_pedido ? 'checked' : ''}><span class="interruptor__trilho"></span><span><strong>A IA atende e anota o pedido</strong> (o cliente escreve do jeito dele)</span></label>
        <p class="campo__ajuda">Com o assistente ligado, ele responde toda mensagem, monta o pedido, manda o resumo com o total e só cria o pedido depois do “sim” do cliente. <strong>Desligue a saudação automática do WhatsApp Business</strong>, para o cliente não receber duas respostas. Se alguém da loja responder pelo celular, o assistente fica quieto nessa conversa pelas horas do atendimento humano. Se a IA falhar, o pedido segue passo a passo, com as opções numeradas.</p>
      </section>
      <div class="abas" role="tablist" aria-label="Seções do assistente">
        ${ABAS.map((a) => `<button type="button" class="chip" role="tab" id="aba-${a.id}" data-aba="${a.id}" aria-selected="${a.id === aba}" aria-controls="painel-${a.id}">${a.titulo}</button>`).join('')}
      </div>
      <div id="painel-humor" role="tabpanel" aria-labelledby="aba-humor" class="config" ${aba === 'humor' ? '' : 'hidden'}>${humorHtml(c)}</div>
      <div id="painel-conhecimento" role="tabpanel" aria-labelledby="aba-conhecimento" class="config" ${aba === 'conhecimento' ? '' : 'hidden'}>${conhecimentoHtml(c)}</div>
      <div id="painel-teste" role="tabpanel" aria-labelledby="aba-teste" class="config" ${aba === 'teste' ? '' : 'hidden'}>${testeHtml()}</div>
      <div class="barra-salvar" id="barra-salvar" ${aba === 'teste' ? 'hidden' : ''}>
        <span class="barra-salvar__nota" id="nota-salvar" role="status"></span>
        <button type="submit" class="botao botao--g">Salvar</button>
      </div>
    </form>`;
}

function mostrarAba(nova) {
  aba = nova;
  $$('[data-aba]', conteudo).forEach((b) => b.setAttribute('aria-selected', String(b.dataset.aba === nova)));
  for (const a of ABAS) $(`#painel-${a.id}`, conteudo).hidden = a.id !== nova;
  $('#barra-salvar', conteudo).hidden = nova === 'teste';
}

// ---------- Salvar ----------
// Parte da configuração salva como está (o que não aparece nesta tela) e troca só o que a tela mostra. Os fluxos em texto
// (que não aparecem mais aqui) vão de volta iguais, para o "Salvar" não apagar nada.
function coletar() {
  const f = $('#form-assistente', conteudo);
  const v = (nome) => f.elements[nome]?.value ?? '';
  const config = {
    ...dados.config,
    ia_ativo: f.elements.ia_ativo.checked,
    ia_pedido: f.elements.ia_pedido.checked,
    ia_nome: v('ia_nome'), ia_saudacao: v('ia_saudacao'), ia_tom_texto: v('ia_tom_texto'), ia_texto_livre: v('ia_texto_livre'),
    ia_tom: f.querySelector('input[name="ia_tom"]:checked')?.value ?? dados.config.ia_tom,
    ia_tamanho: f.querySelector('input[name="ia_tamanho"]:checked')?.value ?? dados.config.ia_tamanho,
  };
  const faqs = $$('[data-faq]', f).map((li) => ({ pergunta: $('[name="faq-pergunta"]', li).value, resposta: $('[name="faq-resposta"]', li).value, ativo: true }));
  return { config, faqs, fluxos: dados.fluxos };
}

function limparErros() {
  $$('.campo__erro', conteudo).forEach((e) => { e.hidden = true; e.textContent = ''; });
}

function mostrarErros(campos) {
  for (const [nome, mensagem] of Object.entries(campos)) {
    const item = /^faqs\.(\d+)$/.exec(nome);
    const linhas = $$('[data-faq]', conteudo).filter((li) => $$('input, textarea', li).some((x) => x.value.trim() !== ''));
    const alvo = item ? $('[data-erro-item]', linhas[Number(item[1])] ?? conteudo) : $(`#erro-${nome}`, conteudo);
    if (alvo) {
      alvo.textContent = mensagem;
      alvo.hidden = false;
    }
  }
  const primeira = Object.keys(campos)[0] ?? '';
  mostrarAba(primeira.startsWith('faqs') || primeira === 'ia_texto_livre' ? 'conhecimento' : 'humor');
}

async function salvar() {
  const botao = $('#form-assistente button[type="submit"]', conteudo);
  botao.setAttribute('aria-busy', 'true');
  limparErros();
  try {
    dados = await api('/api/admin/assistente', { metodo: 'PUT', corpo: coletar() });
    desenhar();
    avisar('Assistente salvo');
  } catch (erro) {
    if (erro.codigo === 'validacao') {
      mostrarErros(erro.campos);
      avisar('Confira os campos destacados.', { tipo: 'erro' });
    } else {
      mostrarErro(erro);
    }
    botao.removeAttribute('aria-busy');
  }
}

// ---------- Testar ----------
async function testar() {
  const entrada = $('input[name="pergunta"]', conteudo);
  const pergunta = entrada.value.trim();
  const alvo = $('#teste-resposta', conteudo);
  if (!pergunta) {
    entrada.focus();
    return;
  }
  const botao = $('[data-testar]', conteudo);
  botao.setAttribute('aria-busy', 'true');
  alvo.innerHTML = '<p class="campo__ajuda" aria-busy="true">Pensando…</p>';
  try {
    const r = await api('/api/admin/assistente/testar', { metodo: 'POST', corpo: { pergunta, modelo: dados.config.ia_modelo } });
    alvo.innerHTML = r.ok
      ? `<div class="balao-teste"><span class="campo__ajuda">Cliente</span><p>${esc(pergunta)}</p></div>
         <div class="balao-teste balao-teste--loja"><span class="campo__ajuda">${esc(dados.config.ia_nome)}</span><p>${esc(r.texto)}</p></div>`
      : `<p class="campo__erro">${icone('alerta', 14)} O assistente não conseguiu responder agora. Tente de novo em instantes.</p>`;
  } catch (erro) {
    alvo.innerHTML = `<p class="campo__erro">${icone('alerta', 14)} ${esc(erro.message)}</p>`;
  }
  botao.removeAttribute('aria-busy');
}

// ---------- Eventos ----------
function ligarEventos() {
  const sujo = () => { $('#nota-salvar', conteudo).textContent = 'Há alterações não salvas.'; };
  delegar(conteudo, 'click', '[data-aba]', (e, botao) => mostrarAba(botao.dataset.aba));
  delegar(conteudo, 'change', 'input[name="ia_tom"]', (e, radio) => {
    $('#campo-tom-texto', conteudo).hidden = radio.value !== 'personalizado';
  });
  delegar(conteudo, 'input', '#form-assistente', (e) => { if (!e.target.closest('#painel-teste')) sujo(); });
  delegar(conteudo, 'change', '#form-assistente', (e) => { if (!e.target.closest('#painel-teste')) sujo(); });
  delegar(conteudo, 'click', '[data-nova-faq]', () => {
    $('#lista-faqs', conteudo).insertAdjacentHTML('beforeend', faqHtml());
    $$('[data-faq]', conteudo).at(-1).querySelector('input').focus();
    sujo();
  });
  delegar(conteudo, 'click', '[data-tirar]', (e, botao) => {
    botao.closest('li').remove();
    sujo();
  });
  delegar(conteudo, 'click', '[data-exemplo]', (e, botao) => {
    $('input[name="pergunta"]', conteudo).value = botao.dataset.exemplo;
    testar();
  });
  delegar(conteudo, 'click', '[data-testar]', () => testar());
  delegar(conteudo, 'keydown', '#painel-teste input', (e) => {
    if (e.key === 'Enter') {   // Enter no teste pergunta; não pode salvar o formulário
      e.preventDefault();
      testar();
    }
  });
  conteudo.addEventListener('submit', (e) => {
    e.preventDefault();
    salvar();
  });
}

/** Desenha o assistente dentro de `alvo` (a tela WhatsApp). Os eventos são ligados uma vez por contêiner. */
export async function montarEm(alvo) {
  if (conteudo !== alvo) {
    conteudo = alvo;
    ligarEventos();
  }
  try {
    dados = await api('/api/admin/assistente');
    desenhar();
  } catch (erro) {
    mostrarErro(erro);
  }
}
