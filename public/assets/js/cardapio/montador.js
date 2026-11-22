// "Monte seu prato": massa → molho → ingredientes → tamanho → finalização → revisão.
// Prato da casa (receita fixa, D87): tamanho → finalização → revisão.
// As escolhas ficam em `m.escolha` e nunca se perdem ao ir e voltar entre os passos.
import { $, $$, esc, delegar } from '../shared/dom.js';
import { icone, iconeMassa, iconePorcao } from '../shared/icones.js';
import { reais, acrescimo, plural } from '../shared/formato.js';
import { calcularMontagem, precoDoPrato, noTamanho, faixa, idsDosMolhos, limiteDoGrupo, dicaDoGrupo } from '../shared/preco.js';
import { abrirFolha, fecharFolha } from '../shared/folha.js';
import { avisar } from '../shared/avisos.js';
import { estado, adicionarAoCarrinho, atualizarLinha } from './estado.js';

const PASSO = {
  tamanho: { id: 'tamanho', rotulo: 'Tamanho', titulo: 'Qual o tamanho?' },
  massa: { id: 'massa', rotulo: 'Massa', titulo: 'Escolha a massa' },
  molho: { id: 'molho', rotulo: 'Molho', titulo: 'Escolha o molho' },   // D110: pode mais de um
  ingredientes: { id: 'ingredientes', rotulo: 'Ingredientes', titulo: 'Escolha os ingredientes' },
  final: { id: 'final', rotulo: 'Finalização', titulo: 'Para finalizar' },
  revisao: { id: 'revisao', rotulo: 'Revisão', titulo: 'Confira seu prato' },
};
const PASSOS_MONTE = ['massa', 'molho', 'ingredientes', 'tamanho', 'final', 'revisao'].map((id) => PASSO[id]);
const PASSOS_CASA = ['tamanho', 'final', 'revisao'].map((id) => PASSO[id]);
let PASSOS = PASSOS_MONTE;   // trocado a cada abertura: prato da casa tem menos passos
let REVISAO = PASSOS.length - 1;
const indice = (id) => PASSOS.findIndex((p) => p.id === id);

const dialogo = $('#folha-montador');
const escolhaVazia = () => ({ tamanho_id: null, massa_id: null, molho_ids: [], ingrediente_ids: [], observacoes: '', quantidade: 1 });

let m = null;          // montagem em andamento
let rascunho = null;   // última montagem livre fechada sem adicionar: volta ao reabrir

/** Esquece a montagem que ficou pela metade (ao trocar de atendimento, ela não é da conta nova). */
export function limparRascunho() {
  rascunho = null;
}

const disponivel = (o) => o && o.estado === 'disponivel';
const calculo = (escolha = m.escolha) => calcularMontagem(estado.cardapio, { ...escolha, item_id: m.itemCasa?.id ?? null });
/** Tamanho que pode ser pedido agora: disponível e, no prato da casa, com preço cadastrado. */
const tamanhoVende = (t) => disponivel(t) && (!m.itemCasa || precoDoPrato(m.itemCasa, t.id) !== null);

/**
 * @param {{ itemCasa?: object, linha?: object }} opcoes
 *   itemCasa: prato da casa que serve de ponto de partida;  linha: linha do carrinho sendo editada.
 */
export function abrirMontador({ itemCasa = null, linha = null } = {}) {
  const c = estado.cardapio;
  const casa = linha ? (linha.item_id ? c.itens.find((i) => i.id === linha.item_id) ?? null : null) : itemCasa;
  PASSOS = casa ? PASSOS_CASA : PASSOS_MONTE;
  REVISAO = PASSOS.length - 1;
  if (linha) {
    m = { passo: REVISAO, alcancado: REVISAO, itemCasa: casa, uid: linha.uid,
          escolha: { ...escolhaVazia(), ...structuredClone(linha) } };
    m.escolha.molho_ids = idsDosMolhos(m.escolha);
    delete m.escolha.molho_id;
  } else if (itemCasa) {
    m = { passo: 0, alcancado: 0, itemCasa, uid: null, escolha: escolhaVazia() };
  } else {
    m = rascunho ?? { passo: 0, alcancado: 0, itemCasa: null, uid: null, escolha: escolhaVazia() };
    rascunho = null;
  }
  limparIndisponiveis();
  desenharCasca();
  desenharPasso(0);
  abrirFolha(dialogo, {
    aoFechar: () => {
      // Fechou sem adicionar uma montagem livre: guarda para a pessoa não recomeçar do zero.
      if (m && !m.uid && !m.itemCasa && m.escolha.massa_id) rascunho = m;
      m = null;
    },
  });
}

/** Se algo escolhido esgotou enquanto a folha estava fechada, a escolha é desfeita. */
function limparIndisponiveis() {
  const c = estado.cardapio;
  const ok = (lista, id) => disponivel(lista.find((o) => o.id === id));
  if (!tamanhoVende(c.tamanhos.find((o) => o.id === m.escolha.tamanho_id))) m.escolha.tamanho_id = null;
  if (!ok(c.massas, m.escolha.massa_id)) m.escolha.massa_id = null;
  m.escolha.molho_ids = m.escolha.molho_ids.filter((id) => ok(c.molhos, id));
  m.escolha.ingrediente_ids = m.escolha.ingrediente_ids.filter((id) => ok(c.ingredientes, id));
  if (m.passo === REVISAO && !calculo().completa) {
    m.passo = PASSOS.findIndex((p) => !passoResolvido(p.id));
  }
}

function passoResolvido(id) {
  if (id === 'tamanho') return m.escolha.tamanho_id !== null;
  if (id === 'massa') return m.escolha.massa_id !== null;
  if (id === 'molho') return m.escolha.molho_ids.length > 0;
  return true;
}

// ---------- Casca (desenhada uma vez por abertura) ----------
function desenharCasca() {
  dialogo.innerHTML = `
    <header class="folha__topo">
      <h2 class="folha__titulo" id="montador-titulo"></h2>
      <button type="button" class="botao botao--fantasma botao--icone" data-fechar aria-label="Fechar sem adicionar">${icone('fechar')}</button>
    </header>
    <nav class="passos" aria-label="Passos da montagem">
      <div class="passos__resumo">
        <p class="passos__contagem"><strong id="montador-contagem"></strong><span class="passos__nome" id="montador-nome"></span><span id="montador-faltam"></span></p>
        <div class="passos__progresso" role="progressbar" aria-label="Progresso do prato" aria-valuemin="1" aria-valuemax="${PASSOS.length}"><span id="montador-barra"></span></div>
      </div>
      <ol class="passos__lista">
        ${PASSOS.map((p, i) => `
          <li class="passos__ponto">
            <button type="button" class="passos__item" data-passo="${i}">
              <span class="passos__marca" aria-hidden="true"></span>
              <span class="passos__texto"><span class="passos__rotulo">${p.rotulo}</span><span class="passos__valor"></span></span>
              <span class="so-leitor passos__estado"></span>
            </button>
          </li>`).join('')}
      </ol>
    </nav>
    <form class="folha__corpo montador__corpo" id="montador-corpo" novalidate></form>
    <footer class="folha__rodape montador__rodape">
      <button type="button" class="botao botao--secundario botao--icone botao--g" id="montador-voltar" aria-label="Voltar um passo">${icone('seta-esq')}</button>
      <button type="button" class="botao botao--g botao--com-valor" id="montador-avancar"><span id="montador-avancar-texto"></span><span class="botao__valor" id="montador-valor"></span></button>
    </footer>`;
}

// ---------- Passo ----------
function desenharPasso(direcao) {
  const passo = PASSOS[m.passo];
  m.alcancado = Math.max(m.alcancado, m.passo);
  $('#montador-titulo').textContent = passo.id === 'tamanho' && m.itemCasa ? `${m.itemCasa.nome}: qual o tamanho?` : passo.titulo;

  const corpo = $('#montador-corpo');
  corpo.innerHTML = `<div class="montador__conteudo" style="--dir: ${direcao}">${CONTEUDO[passo.id]()}</div>`;
  corpo.scrollTop = 0;
  $('#montador-voltar').hidden = m.passo === 0;
  atualizar();

  // Foco para quem usa teclado: primeira opção marcada (ou a primeira do passo).
  if (direcao !== 0) {
    const alvo = passo.id === 'revisao' || passo.id === 'final' ? $('#montador-titulo') : $('input:checked:not(:disabled)', corpo) ?? $('input:not(:disabled)', corpo);
    if (alvo === $('#montador-titulo')) alvo.setAttribute('tabindex', '-1');
    alvo?.focus({ preventScroll: true });
  }
}

const marca = `<span class="opcao__marca">${icone('check', 14)}</span>`;
const esgotado = '<span class="selo">Esgotado</span>';

function opcaoUnica(nome, o, { descricao, detalhe = '', lado, figura = '', fora = !disponivel(o) }) {
  return `
    <label class="opcao${figura ? ' opcao--figura' : ''}">
      <input class="opcao__entrada" type="radio" name="${nome}" value="${o.id}" ${m.escolha[nome] === o.id ? 'checked' : ''} ${fora ? 'disabled' : ''}>
      ${marca}${figura}
      <span class="opcao__corpo">
        <span class="opcao__titulo">${esc(o.nome)}</span>
        ${descricao ? `<span class="opcao__descricao">${esc(descricao)}</span>` : ''}
        ${detalhe ? `<span class="opcao__detalhe">${esc(detalhe)}</span>` : ''}
      </span>
      <span class="opcao__lado">${fora ? esgotado : lado}</span>
    </label>`;
}


/**
 * O acréscimo que aparece ao lado de uma massa, molho ou ingrediente (D108). Com o tamanho já escolhido (voltou para alterar),
 * o valor daquele tamanho; antes dele, a faixa entre os tamanhos ("+ R$ 3,00 a R$ 5,00", "+ até R$ 2,00").
 */
function lado(opcao, campo) {
  if (m.escolha.tamanho_id) return acrescimo(noTamanho(opcao, campo, m.escolha.tamanho_id));
  const [menor, maior] = faixa(opcao, campo, estado.cardapio.tamanhos);
  if (menor === maior) return acrescimo(menor);
  return menor === 0 ? `+ até ${reais(maior)}` : `+ ${reais(menor)} a ${reais(maior)}`;
}

const CONTEUDO = {
  // Meio e Inteiro: o desenho do prato (metade ou cheio), a descrição de quanto serve e o preço do prato nesse tamanho.
  tamanho: () => `
    <fieldset class="grupo-opcoes">
      <legend class="so-leitor">Tamanho</legend>
      ${estado.cardapio.tamanhos.map((t) => opcaoUnica('tamanho_id', t, {
        descricao: t.descricao,
        fora: !tamanhoVende(t),
        lado: `<span class="preco-opcao">${reais(calculo({ ...m.escolha, tamanho_id: t.id }).preco)}</span>`,
        figura: `<span class="opcao__figura porcao" aria-hidden="true">${iconePorcao(t.nome, 32)}</span>`,
      })).join('')}
    </fieldset>`,

  massa: () => `
    <fieldset class="grupo-opcoes grupo-opcoes--duas">
      <legend class="so-leitor">Massa</legend>
      ${estado.cardapio.massas.map((x) => opcaoUnica('massa_id', x, {
        descricao: x.descricao, lado: lado(x, 'acrescimo_centavos'),
        figura: `<span class="opcao__figura">${iconeMassa(x.nome)}</span>`,
      })).join('')}
    </fieldset>`,

  // D110: o molho é de marcar (pode mais de um); cada molho soma o seu acréscimo.
  molho: () => `
    <div class="contador"><div class="contador__texto"><strong>Pode escolher mais de um</strong><span>Cada molho com preço ao lado soma no prato.</span></div></div>
    <fieldset class="grupo-opcoes grupo-opcoes--duas">
      <legend class="so-leitor">Molho (pode escolher mais de um)</legend>
      ${estado.cardapio.molhos.map((x) => `
        <label class="opcao opcao--caixa opcao--figura">
          <input class="opcao__entrada" type="checkbox" name="molho" value="${x.id}" ${m.escolha.molho_ids.includes(x.id) ? 'checked' : ''} ${disponivel(x) ? '' : 'disabled'}>
          ${marca}<span class="opcao__figura bolinha" style="background: ${esc(x.cor)}" aria-hidden="true"></span>
          <span class="opcao__corpo">
            <span class="opcao__titulo">${esc(x.nome)}</span>
            ${x.descricao ? `<span class="opcao__descricao">${esc(x.descricao)}</span>` : ''}
          </span>
          <span class="opcao__lado">${disponivel(x) ? lado(x, 'acrescimo_centavos') : esgotado}</span>
        </label>`).join('')}
    </fieldset>`,

  ingredientes: () => {
    const grupos = new Map();
    for (const i of estado.cardapio.ingredientes) {
      if (!grupos.has(i.grupo)) grupos.set(i.grupo, []);
      grupos.get(i.grupo).push(i);
    }
    return `
      <div class="contador" id="montador-contador" role="status" aria-live="polite"></div>
      ${[...grupos].map(([grupo, lista]) => `
        <fieldset class="grupo-opcoes grupo-opcoes--duas">
          <legend class="grupo-opcoes__titulo">${esc(grupo)}${dicaDoGrupo(grupo) ? ` <span class="grupo-opcoes__dica">${dicaDoGrupo(grupo)}</span>` : ''}</legend>
          ${lista.map((i) => `
            <label class="opcao opcao--caixa">
              <input class="opcao__entrada" type="checkbox" name="ingrediente" value="${i.id}" ${m.escolha.ingrediente_ids.includes(i.id) ? 'checked' : ''} ${disponivel(i) ? '' : 'disabled'}>
              ${marca}
              <span class="opcao__corpo"><span class="opcao__titulo">${esc(i.nome)}</span></span>
              <span class="opcao__lado">${!disponivel(i) ? esgotado : lado(i, 'preco_extra_centavos')}</span>
            </label>`).join('')}
        </fieldset>`).join('')}`;
  },

  final: () => `
    <div class="montador__final">
      <label class="campo">
        <span class="campo__rotulo">Observações <span class="campo__opcional">(opcional)</span></span>
        <textarea class="entrada" name="observacoes" maxlength="200" rows="3" placeholder="Ex.: molho à parte, sem pimenta">${esc(m.escolha.observacoes)}</textarea>
        <span class="campo__ajuda">A cozinha lê isto na comanda.</span>
      </label>
      <div class="montador__quantidade">
        <span class="campo__rotulo" id="rotulo-quantidade">Quantos pratos iguais a este?</span>
        ${quantidadeHtml()}
      </div>
    </div>`,

  revisao: () => {
    const r = calculo();
    if (m.itemCasa) return revisaoCasa(r);
    const linha = (rotulo, valor, passo, lado = '') => `
      <div class="revisao__linha">
        <dt>${rotulo}</dt>
        <dd>${valor}${lado ? `<span class="revisao__valor numero">${lado}</span>` : ''}</dd>
        <button type="button" class="revisao__alterar" data-ir="${passo}">Alterar<span class="so-leitor"> ${rotulo.toLowerCase()}</span></button>
      </div>`;
    const nomes = (lista) => lista.map((i) => esc(i.nome)).join(', ');
    return `
      <dl class="revisao">
        ${linha('Massa', esc(r.massa.nome), indice('massa'), acrescimo(r.massa.acrescimo_centavos))}
        ${linha(r.molhos.length > 1 ? 'Molhos' : 'Molho', esc(r.molho.nome), indice('molho'), acrescimo(r.molho.acrescimo_centavos))}
        ${r.inclusos.length || !r.extras.length ? linha('Ingredientes', r.inclusos.length ? nomes(r.inclusos) : 'Nenhum', indice('ingredientes')) : ''}
        ${r.extras.length ? linha('Extras', nomes(r.extras), indice('ingredientes'), `+ ${reais(r.valorExtras)}`) : ''}
        ${linha('Tamanho', `${esc(r.tamanho.nome)}${r.tamanho.descricao ? ` (${esc(r.tamanho.descricao)})` : ''}`, indice('tamanho'), reais(r.tamanho.preco_centavos))}
        ${linha('Observações', m.escolha.observacoes ? esc(m.escolha.observacoes) : 'Nenhuma', indice('final'))}
      </dl>
      <div class="montador__quantidade">
        <span class="campo__rotulo" id="rotulo-quantidade">Quantidade</span>
        ${quantidadeHtml()}
      </div>`;
  },
};

/** Revisão do prato da casa: a receita é fixa, então só o tamanho e a observação mudam. */
function revisaoCasa(r) {
  const nomes = [r.massa?.nome, r.molho && `molho ${r.molho.nome}`, ...r.inclusos.map((i) => i.nome)].filter(Boolean).map(esc).join(', ');
  return `
    <dl class="revisao">
      <div class="revisao__linha"><dt>Prato</dt><dd>${esc(m.itemCasa.nome)}${nomes ? `<br><span class="opcao__descricao">${nomes}</span>` : ''}</dd></div>
      <div class="revisao__linha">
        <dt>Tamanho</dt>
        <dd>${esc(r.tamanho.nome)}${r.tamanho.descricao ? ` (${esc(r.tamanho.descricao)})` : ''}<span class="revisao__valor numero">${reais(r.preco)}</span></dd>
        <button type="button" class="revisao__alterar" data-ir="${indice('tamanho')}">Alterar<span class="so-leitor"> tamanho</span></button>
      </div>
      <div class="revisao__linha">
        <dt>Observações</dt><dd>${m.escolha.observacoes ? esc(m.escolha.observacoes) : 'Nenhuma'}</dd>
        <button type="button" class="revisao__alterar" data-ir="${indice('final')}">Alterar<span class="so-leitor"> observações</span></button>
      </div>
    </dl>
    <div class="montador__quantidade">
      <span class="campo__rotulo" id="rotulo-quantidade">Quantidade</span>
      ${quantidadeHtml()}
    </div>`;
}

function quantidadeHtml() {
  return `
    <div class="quantidade" role="group" aria-labelledby="rotulo-quantidade">
      <button type="button" class="quantidade__botao" data-quantidade="-1" aria-label="Diminuir">${icone('menos', 18)}</button>
      <output class="quantidade__valor" id="montador-quantidade">${m.escolha.quantidade}</output>
      <button type="button" class="quantidade__botao" data-quantidade="1" aria-label="Aumentar">${icone('mais', 18)}</button>
    </div>`;
}

// ---------- Indicador de progresso (as etapas ao lado/em cima do prato) ----------
const ESTADO_NO_LEITOR = { feito: 'concluída', atual: 'etapa atual', futuro: 'ainda não iniciada' };

/** O que já foi escolhido em cada etapa, para aparecer embaixo do nome dela. */
function valorDoPasso(id) {
  const c = estado.cardapio;
  const nome = (lista, chave) => lista.find((o) => o.id === m.escolha[chave])?.nome ?? '';
  switch (id) {
    case 'tamanho': return nome(c.tamanhos, 'tamanho_id');
    case 'massa': return nome(c.massas, 'massa_id');
    case 'molho': return c.molhos.filter((o) => m.escolha.molho_ids.includes(o.id)).map((o) => o.nome).join(', ');
    case 'ingredientes': return m.escolha.ingrediente_ids.length ? plural(m.escolha.ingrediente_ids.length, 'ingrediente', 'ingredientes') : 'Nenhum';
    case 'final': return plural(m.escolha.quantidade, 'prato', 'pratos');
    default: return '';
  }
}

/** Cada etapa fica em um de três estados: concluída (já passou), atual ou ainda não iniciada. */
function atualizarPassos() {
  const total = PASSOS.length;
  const faltam = total - m.passo - 1;
  $('#montador-contagem').textContent = `Etapa ${m.passo + 1} de ${total}`;
  $('#montador-nome').textContent = ` · ${PASSOS[m.passo].rotulo}`;
  $('#montador-faltam').textContent = faltam === 0 ? ' · última etapa' : faltam === 1 ? ' · falta 1' : ` · faltam ${faltam}`;
  const progresso = $('.passos__progresso', dialogo);
  progresso.setAttribute('aria-valuenow', String(m.passo + 1));
  progresso.setAttribute('aria-valuetext', `Etapa ${m.passo + 1} de ${total}`);
  $('#montador-barra').style.setProperty('--p', String((m.passo + 1) / total));

  $$('.passos__item', dialogo).forEach((botao, i) => {
    const situacao = i === m.passo ? 'atual' : i <= m.alcancado ? 'feito' : 'futuro';
    botao.dataset.estado = situacao;
    if (i === m.passo) botao.setAttribute('aria-current', 'step');
    else botao.removeAttribute('aria-current');
    botao.disabled = i > m.alcancado;
    $('.passos__marca', botao).innerHTML = situacao === 'feito' ? icone('check', 14) : String(i + 1);
    $('.passos__valor', botao).textContent = situacao === 'futuro' ? '' : valorDoPasso(PASSOS[i].id);
    $('.passos__estado', botao).textContent = ` (${ESTADO_NO_LEITOR[situacao]})`;
  });
}

// ---------- Atualizações sem redesenhar o passo (preço, contador, botões) ----------
function atualizar() {
  atualizarPassos();
  const r = calculo();
  const passo = PASSOS[m.passo].id;
  const avancar = $('#montador-avancar');
  avancar.disabled = !passoResolvido(passo);
  $('#montador-avancar-texto').textContent = m.passo < REVISAO ? 'Continuar' : m.uid ? 'Salvar' : 'Adicionar';
  $('#montador-valor').textContent = r.tamanho ? reais(m.passo >= REVISAO - 1 ? r.preco * m.escolha.quantidade : r.preco) : '';

  const quantidade = $('#montador-quantidade');
  if (quantidade) {
    quantidade.textContent = m.escolha.quantidade;
    $('[data-quantidade="-1"]', dialogo).disabled = m.escolha.quantidade <= 1;
    $('[data-quantidade="1"]', dialogo).disabled = m.escolha.quantidade >= 20;
  }

  if (passo !== 'ingredientes') return;
  $('#montador-contador').innerHTML = `
    <div class="contador__texto">
      <strong>Escolha quantos quiser</strong>
      ${r.extras.length ? `<span class="contador__extras numero">${plural(r.extras.length, 'extra', 'extras')} + ${reais(r.valorExtras)}</span>` : '<span>Os que têm preço ao lado são cobrados à parte.</span>'}
    </div>`;
}

function irPara(passo) {
  const anterior = m.passo;
  m.passo = Math.max(0, Math.min(REVISAO, passo));
  desenharPasso(m.passo > anterior ? 1 : -1);
}

function avancar() {
  if (!passoResolvido(PASSOS[m.passo].id)) return;
  if (m.passo < REVISAO) {
    irPara(m.passo + 1);
    return;
  }
  concluir();
}

function concluir() {
  const r = calculo();
  if (!r.completa) {
    irPara(PASSOS.findIndex((p) => !passoResolvido(p.id)));
    return;
  }
  const linha = { tipo: 'montado', ...structuredClone(m.escolha), item_id: m.itemCasa?.id ?? null };
  const editando = m.uid;
  if (editando) atualizarLinha(editando, linha);
  else adicionarAoCarrinho(linha);
  m = null;
  fecharFolha(dialogo);
  avisar(editando ? 'Prato atualizado' : 'Prato adicionado ao carrinho');
}

// ---------- Eventos ----------
delegar(dialogo, 'change', 'input, textarea', (e, campo) => {
  if (!m) return;
  if (campo.name === 'ingrediente' || campo.name === 'molho') {
    const id = Number(campo.value);
    const chave = campo.name === 'molho' ? 'molho_ids' : 'ingrediente_ids';
    if (campo.name === 'ingrediente' && campo.checked) {   // D115: acompanhamentos até 8
      const grupo = estado.cardapio.ingredientes.find((i) => i.id === id)?.grupo;
      const limite = limiteDoGrupo(grupo);
      const noGrupo = m.escolha.ingrediente_ids.filter((x) => estado.cardapio.ingredientes.find((i) => i.id === x)?.grupo === grupo).length;
      if (limite !== null && noGrupo >= limite) {
        campo.checked = false;
        avisar(`Escolha até ${limite} em ${String(grupo).toLowerCase()}.`);
        return;
      }
    }
    m.escolha[chave] = campo.checked ? [...m.escolha[chave], id] : m.escolha[chave].filter((x) => x !== id);
  } else if (campo.name === 'observacoes') {
    m.escolha.observacoes = campo.value.trim();
  } else if (campo.type === 'radio') {
    m.escolha[campo.name] = Number(campo.value);
  }
  atualizar();
});
delegar(dialogo, 'input', 'textarea[name="observacoes"]', (e, campo) => {
  if (m) m.escolha.observacoes = campo.value.trim();
});

// Toque/clique numa escolha única avança sozinho (pedido em menos de 1 minuto).
// e.detail > 0 = clique de verdade; pelas setas do teclado não avança, para não surpreender.
delegar(dialogo, 'click', 'input[type="radio"]', (e) => {
  if (!m || e.detail === 0) return;
  const passoNoClique = m.passo;
  setTimeout(() => {
    if (m && m.passo === passoNoClique && passoResolvido(PASSOS[m.passo].id)) avancar();
  }, 220);
});

delegar(dialogo, 'click', '#montador-avancar', () => m && avancar());
delegar(dialogo, 'click', '#montador-voltar', () => m && irPara(m.passo - 1));
delegar(dialogo, 'click', '.passos__item', (e, botao) => m && irPara(Number(botao.dataset.passo)));
delegar(dialogo, 'click', '[data-ir]', (e, botao) => m && irPara(Number(botao.dataset.ir)));
delegar(dialogo, 'click', '[data-quantidade]', (e, botao) => {
  if (!m) return;
  m.escolha.quantidade = Math.max(1, Math.min(20, m.escolha.quantidade + Number(botao.dataset.quantidade)));
  atualizar();
});
// Enter dentro do formulário não deve recarregar a página.
dialogo.addEventListener('submit', (e) => e.preventDefault());
