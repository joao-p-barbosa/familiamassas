// "Monte seu prato": tamanho → massa → molho → ingredientes → finalização → revisão.
// As escolhas ficam em `m.escolha` e nunca se perdem ao ir e voltar entre os passos.
import { $, $$, esc, delegar } from '../shared/dom.js';
import { icone, iconeMassa } from '../shared/icones.js';
import { reais, acrescimo, plural } from '../shared/formato.js';
import { calcularMontagem } from '../shared/preco.js';
import { abrirFolha, fecharFolha } from '../shared/folha.js';
import { avisar } from '../shared/avisos.js';
import { estado, adicionarAoCarrinho, atualizarLinha } from './estado.js';

const PASSOS = [
  { id: 'tamanho', rotulo: 'Tamanho', titulo: 'Qual o tamanho?' },
  { id: 'massa', rotulo: 'Massa', titulo: 'Escolha a massa' },
  { id: 'molho', rotulo: 'Molho', titulo: 'Escolha o molho' },
  { id: 'ingredientes', rotulo: 'Ingredientes', titulo: 'Escolha os ingredientes' },
  { id: 'final', rotulo: 'Finalização', titulo: 'Para finalizar' },
  { id: 'revisao', rotulo: 'Revisão', titulo: 'Confira seu prato' },
];
const REVISAO = PASSOS.length - 1;

const dialogo = $('#folha-montador');
const escolhaVazia = () => ({ tamanho_id: null, massa_id: null, molho_id: null, ingrediente_ids: [], queijo_ralado: true, observacoes: '', quantidade: 1 });

let m = null;          // montagem em andamento
let rascunho = null;   // última montagem livre fechada sem adicionar: volta ao reabrir

const disponivel = (o) => o && o.estado === 'disponivel';
const calculo = () => calcularMontagem(estado.cardapio, m.escolha);

/**
 * @param {{ itemCasa?: object, linha?: object }} opcoes
 *   itemCasa: prato da casa que serve de ponto de partida;  linha: linha do carrinho sendo editada.
 */
export function abrirMontador({ itemCasa = null, linha = null } = {}) {
  const c = estado.cardapio;
  if (linha) {
    m = { passo: REVISAO, alcancado: REVISAO, itemCasa: linha.item_id ? c.itens.find((i) => i.id === linha.item_id) ?? null : null, uid: linha.uid,
          escolha: { ...escolhaVazia(), ...structuredClone(linha) } };
  } else if (itemCasa) {
    const massa = c.massas.find((x) => x.id === itemCasa.massa_id);
    const molho = c.molhos.find((x) => x.id === itemCasa.molho_id);
    const ingredientes = itemCasa.ingrediente_ids.filter((id) => disponivel(c.ingredientes.find((x) => x.id === id)));
    m = { passo: 0, alcancado: 0, itemCasa, uid: null,
          escolha: { ...escolhaVazia(), massa_id: disponivel(massa) ? massa.id : null, molho_id: disponivel(molho) ? molho.id : null, ingrediente_ids: ingredientes } };
    if (!disponivel(massa) || !disponivel(molho) || ingredientes.length !== itemCasa.ingrediente_ids.length) {
      avisar('Parte deste prato esgotou. Escolha um substituto nos passos.');
    }
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
      if (m && !m.uid && !m.itemCasa && m.escolha.tamanho_id) rascunho = m;
      m = null;
    },
  });
}

/** Se algo escolhido esgotou enquanto a folha estava fechada, a escolha é desfeita. */
function limparIndisponiveis() {
  const c = estado.cardapio;
  const ok = (lista, id) => disponivel(lista.find((o) => o.id === id));
  if (!ok(c.tamanhos, m.escolha.tamanho_id)) m.escolha.tamanho_id = null;
  if (!ok(c.massas, m.escolha.massa_id)) m.escolha.massa_id = null;
  if (!ok(c.molhos, m.escolha.molho_id)) m.escolha.molho_id = null;
  m.escolha.ingrediente_ids = m.escolha.ingrediente_ids.filter((id) => ok(c.ingredientes, id));
  if (m.passo === REVISAO && !calculo().completa) {
    m.passo = PASSOS.findIndex((p) => !passoResolvido(p.id));
  }
}

function passoResolvido(id) {
  if (id === 'tamanho') return m.escolha.tamanho_id !== null;
  if (id === 'massa') return m.escolha.massa_id !== null;
  if (id === 'molho') return m.escolha.molho_id !== null;
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
      <ol class="passos__lista">
        ${PASSOS.map((p, i) => `<li><button type="button" class="passos__item" data-passo="${i}"><span class="passos__barra"></span><span class="passos__rotulo">${p.rotulo}</span></button></li>`).join('')}
      </ol>
      <p class="passos__atual" id="montador-atual"></p>
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
  $('#montador-titulo').textContent = m.passo === 0 && m.itemCasa ? `${m.itemCasa.nome}: qual o tamanho?` : passo.titulo;
  $('#montador-atual').textContent = `Passo ${m.passo + 1} de ${PASSOS.length}: ${passo.rotulo}`;

  $$('.passos__item', dialogo).forEach((botao, i) => {
    botao.toggleAttribute('data-feito', i < m.passo || (i <= m.alcancado && i !== m.passo));
    if (i === m.passo) botao.setAttribute('aria-current', 'step');
    else botao.removeAttribute('aria-current');
    botao.disabled = i > m.alcancado;
  });

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

function opcaoUnica(nome, o, { descricao, lado, figura = '' }) {
  const fora = !disponivel(o);
  return `
    <label class="opcao${figura ? ' opcao--figura' : ''}">
      <input class="opcao__entrada" type="radio" name="${nome}" value="${o.id}" ${m.escolha[nome] === o.id ? 'checked' : ''} ${fora ? 'disabled' : ''}>
      ${marca}${figura}
      <span class="opcao__corpo"><span class="opcao__titulo">${esc(o.nome)}</span>${descricao ? `<span class="opcao__descricao">${esc(descricao)}</span>` : ''}</span>
      <span class="opcao__lado">${fora ? esgotado : lado}</span>
    </label>`;
}

const CONTEUDO = {
  tamanho: () => `
    <fieldset class="grupo-opcoes">
      <legend class="so-leitor">Tamanho</legend>
      ${estado.cardapio.tamanhos.map((t) => opcaoUnica('tamanho_id', t, {
        descricao: [t.descricao, `${plural(t.inclusos, 'ingrediente incluso', 'ingredientes inclusos')}`].filter(Boolean).join(' · '),
        lado: reais(t.preco_centavos),
        figura: `<span class="opcao__figura sigla" aria-hidden="true">${esc(t.sigla)}</span>`,
      })).join('')}
    </fieldset>`,

  massa: () => `
    <fieldset class="grupo-opcoes grupo-opcoes--duas">
      <legend class="so-leitor">Massa</legend>
      ${estado.cardapio.massas.map((x) => opcaoUnica('massa_id', x, {
        descricao: x.descricao, lado: acrescimo(x.acrescimo_centavos),
        figura: `<span class="opcao__figura">${iconeMassa(x.nome)}</span>`,
      })).join('')}
    </fieldset>`,

  molho: () => `
    <fieldset class="grupo-opcoes grupo-opcoes--duas">
      <legend class="so-leitor">Molho</legend>
      ${estado.cardapio.molhos.map((x) => opcaoUnica('molho_id', x, {
        descricao: x.descricao, lado: acrescimo(x.acrescimo_centavos),
        figura: `<span class="opcao__figura bolinha" style="background: ${esc(x.cor)}" aria-hidden="true"></span>`,
      })).join('')}
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
          <legend class="grupo-opcoes__titulo">${esc(grupo)}</legend>
          ${lista.map((i) => `
            <label class="opcao opcao--caixa">
              <input class="opcao__entrada" type="checkbox" name="ingrediente" value="${i.id}" ${m.escolha.ingrediente_ids.includes(i.id) ? 'checked' : ''} ${disponivel(i) ? '' : 'disabled'}>
              ${marca}
              <span class="opcao__corpo"><span class="opcao__titulo">${esc(i.nome)}</span></span>
              <span class="opcao__lado" data-lado="${i.id}">${disponivel(i) ? '' : esgotado}</span>
            </label>`).join('')}
        </fieldset>`).join('')}`;
  },

  final: () => `
    <div class="montador__final">
      <label class="interruptor">
        <input type="checkbox" name="queijo_ralado" ${m.escolha.queijo_ralado ? 'checked' : ''}>
        <span class="interruptor__trilho"></span>
        <span>Queijo ralado por cima</span>
      </label>
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
    const linha = (rotulo, valor, passo, lado = '') => `
      <div class="revisao__linha">
        <dt>${rotulo}</dt>
        <dd>${valor}${lado ? `<span class="revisao__valor numero">${lado}</span>` : ''}</dd>
        <button type="button" class="revisao__alterar" data-ir="${passo}">Alterar<span class="so-leitor"> ${rotulo.toLowerCase()}</span></button>
      </div>`;
    const nomes = (lista) => lista.map((i) => esc(i.nome)).join(', ');
    return `
      <dl class="revisao">
        ${linha('Tamanho', `${esc(r.tamanho.nome)}${r.tamanho.descricao ? ` (${esc(r.tamanho.descricao)})` : ''}`, 0, reais(r.tamanho.preco_centavos))}
        ${linha('Massa', esc(r.massa.nome), 1, acrescimo(r.massa.acrescimo_centavos))}
        ${linha('Molho', esc(r.molho.nome), 2, acrescimo(r.molho.acrescimo_centavos))}
        ${linha('Ingredientes', r.inclusos.length ? nomes(r.inclusos) : 'Nenhum', 3, r.inclusos.length ? 'inclusos' : '')}
        ${r.extras.length ? linha('Extras', nomes(r.extras), 3, `+ ${reais(r.valorExtras)}`) : ''}
        ${linha('Queijo ralado', m.escolha.queijo_ralado ? 'Sim' : 'Não', 4)}
        ${linha('Observações', m.escolha.observacoes ? esc(m.escolha.observacoes) : 'Nenhuma', 4)}
      </dl>
      ${r.inclusos.length < r.limite ? `<p class="revisao__dica">${icone('info', 18)}<span>Seu tamanho inclui ${plural(r.limite, 'ingrediente', 'ingredientes')} e você escolheu ${r.inclusos.length}. <button type="button" class="revisao__alterar" data-ir="3">Escolher mais</button></span></p>` : ''}
      <div class="montador__quantidade">
        <span class="campo__rotulo" id="rotulo-quantidade">Quantidade</span>
        ${quantidadeHtml()}
      </div>`;
  },
};

function quantidadeHtml() {
  return `
    <div class="quantidade" role="group" aria-labelledby="rotulo-quantidade">
      <button type="button" class="quantidade__botao" data-quantidade="-1" aria-label="Diminuir">${icone('menos', 18)}</button>
      <output class="quantidade__valor" id="montador-quantidade">${m.escolha.quantidade}</output>
      <button type="button" class="quantidade__botao" data-quantidade="1" aria-label="Aumentar">${icone('mais', 18)}</button>
    </div>`;
}

// ---------- Atualizações sem redesenhar o passo (preço, contador, botões) ----------
function atualizar() {
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
  const usados = r.inclusos.length;
  const pontos = Array.from({ length: r.limite }, (_, i) => `<span class="contador__ponto" ${i < usados ? 'data-cheio' : ''}></span>`).join('');
  $('#montador-contador').innerHTML = `
    <div class="contador__texto">
      <strong class="numero">${usados} de ${r.limite} ${r.limite === 1 ? 'incluso' : 'inclusos'}</strong>
      ${r.extras.length ? `<span class="contador__extras numero">${plural(r.extras.length, 'extra', 'extras')} + ${reais(r.valorExtras)}</span>` : `<span>${usados < r.limite ? `Escolha mais ${r.limite - usados} sem pagar a mais` : 'A partir daqui, cada um é cobrado à parte'}</span>`}
    </div>
    <div class="contador__pontos" aria-hidden="true">${pontos}</div>`;

  // Ao lado de cada ingrediente: quanto custa adicionar agora, ou como ele entrou na conta.
  const idsExtras = new Set(r.extras.map((i) => i.id));
  const escolhidos = new Set(m.escolha.ingrediente_ids);
  for (const i of estado.cardapio.ingredientes) {
    const lado = $(`[data-lado="${i.id}"]`, dialogo);
    if (!lado || !disponivel(i)) continue;
    if (escolhidos.has(i.id)) {
      lado.textContent = idsExtras.has(i.id) ? `extra + ${reais(i.preco_extra_centavos)}` : 'incluso';
    } else if (usados >= r.limite) {
      const comEle = calcularMontagem(estado.cardapio, { ...m.escolha, ingrediente_ids: [...m.escolha.ingrediente_ids, i.id] });
      lado.textContent = `+ ${reais(comEle.preco - r.preco)}`;
    } else {
      lado.textContent = '';
    }
  }
}

function irPara(passo) {
  const anterior = m.passo;
  m.passo = Math.max(0, Math.min(REVISAO, passo));
  desenharPasso(m.passo > anterior ? 1 : -1);
}

function avancar() {
  if (!passoResolvido(PASSOS[m.passo].id)) return;
  if (m.passo < REVISAO) {
    // Prato da casa: escolhido o tamanho, o resto já vem pronto. Vai direto para a revisão.
    const pular = m.passo === 0 && m.itemCasa && calculo().completa && m.alcancado === 0;
    irPara(pular ? REVISAO : m.passo + 1);
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
  // Prato da casa alterado deixa de levar o nome da casa: vira uma montagem livre.
  let itemId = m.itemCasa?.id ?? null;
  if (m.itemCasa) {
    const original = [...m.itemCasa.ingrediente_ids].sort().join();
    const atual = [...m.escolha.ingrediente_ids].sort().join();
    if (m.itemCasa.massa_id !== m.escolha.massa_id || m.itemCasa.molho_id !== m.escolha.molho_id || original !== atual) itemId = null;
  }
  const linha = { tipo: 'montado', item_id: itemId, ...structuredClone(m.escolha) };
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
  if (campo.name === 'ingrediente') {
    const id = Number(campo.value);
    m.escolha.ingrediente_ids = campo.checked ? [...m.escolha.ingrediente_ids, id] : m.escolha.ingrediente_ids.filter((x) => x !== id);
  } else if (campo.name === 'queijo_ralado') {
    m.escolha.queijo_ralado = campo.checked;
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
