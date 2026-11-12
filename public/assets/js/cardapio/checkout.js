// Fechar pedido: dados → revisão final (decisão D7) → confirmação.
// O pedido só é enviado depois que a pessoa vê a revisão com itens, montagem e total.
import { $, $$, esc, delegar, guardado } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { reais, telefone as formatarTelefone, centavosDeTexto, textoDeCentavos, plural } from '../shared/formato.js';
import { api } from '../shared/api.js';
import { avisar } from '../shared/avisos.js';
import { TIPOS, PAGAMENTOS, linhasMontagem } from '../shared/pedido.js';
import { estado, totaisDoCarrinho, esvaziarCarrinho, recarregarCardapio } from './estado.js';

const CHAVE_CLIENTE = 'fm_cliente_v1';
const visaoCardapio = $('#visao-cardapio');
const visaoCheckout = $('#visao-checkout');
const barra = $('#barra-carrinho');

// Dados do formulário. Ficam guardados no aparelho para o próximo pedido já vir preenchido.
let dados = {
  tipo: null, nome: '', telefone: '', rua: '', numero: '', bairro: '', complemento: '', referencia: '', mesa: '',
  pagamento: null, troco: '', observacoes: '',
  ...(guardado.ler(CHAVE_CLIENTE) ?? {}),
};
dados.observacoes = '';
dados.mesa = '';
let enviando = false;

const ROTULO_TIPO = { entrega: 'Entrega', retirada: 'Retirar na loja', local: 'Comer aqui' };

export function abrirCheckout() {
  if (!estado.loja.tipos_pedido.includes(dados.tipo)) dados.tipo = estado.loja.tipos_pedido[0] ?? null;
  if (estado.whatsapp) dados.telefone = formatarTelefone(estado.whatsapp.telefone);
  history.pushState({ visao: 'checkout' }, '');
  mostrar('checkout');
}

function mostrar(visao) {
  const noCardapio = visao === 'cardapio';
  visaoCardapio.hidden = !noCardapio;
  visaoCheckout.hidden = noCardapio;
  barra.classList.toggle('barra-carrinho--fora', !noCardapio);
  if (noCardapio) return;
  if (totaisDoCarrinho().quantidade === 0) {
    history.back();
    return;
  }
  visaoCheckout.innerHTML = visao === 'revisao' ? revisaoHtml() : formularioHtml();
  window.scrollTo(0, 0);
  $('h1', visaoCheckout)?.focus({ preventScroll: true });
}

window.addEventListener('popstate', () => mostrar(history.state?.visao ?? 'cardapio'));

// ---------- Passo 1: formulário ----------
const campo = (nome, rotulo, { tipo = 'text', opcional = false, ajuda = '', extra = '', largura = '' } = {}) => `
  <label class="campo ${largura}">
    <span class="campo__rotulo">${rotulo}${opcional ? ' <span class="campo__opcional">(opcional)</span>' : ''}</span>
    <input class="entrada" type="${tipo}" name="${nome}" value="${esc(dados[nome])}" aria-describedby="erro-${nome}" ${extra}>
    ${ajuda ? `<span class="campo__ajuda">${ajuda}</span>` : ''}
    <span class="campo__erro" id="erro-${nome}" hidden></span>
  </label>`;

function formularioHtml() {
  const loja = estado.loja;
  const t = totaisDoCarrinho(dados.tipo);
  const tipos = loja.tipos_pedido;
  return `
    <div class="checkout__dentro">
      <button type="button" class="botao botao--fantasma checkout__voltar" data-acao="voltar">${icone('seta-esq', 18)}Voltar ao cardápio</button>
      <h1 class="checkout__titulo" tabindex="-1">Fechar pedido</h1>

      <form class="checkout__form" id="form-checkout" novalidate>
        <section class="bloco">
          <h2 class="bloco__titulo">Como você quer receber?</h2>
          <fieldset class="segmentado" aria-label="Tipo de pedido">
            ${tipos.map((tipo) => `
              <label class="segmentado__item">
                <input type="radio" name="tipo" value="${tipo}" ${dados.tipo === tipo ? 'checked' : ''}>
                ${icone(TIPOS[tipo].icone, 18)}<span>${ROTULO_TIPO[tipo]}</span>
              </label>`).join('')}
          </fieldset>
          <span class="campo__erro" id="erro-tipo" hidden></span>
          <div id="campos-tipo">${camposDoTipo()}</div>
        </section>

        <section class="bloco">
          <h2 class="bloco__titulo">Seus dados</h2>
          <div class="bloco__grade">
            ${campo('nome', 'Nome', { extra: 'autocomplete="name" maxlength="80"' })}
            ${campo('telefone', 'WhatsApp', {
              tipo: 'tel',
              extra: `autocomplete="tel-national" inputmode="numeric" placeholder="(11) 91234-5678" ${estado.whatsapp ? 'readonly' : ''}`,
              ajuda: estado.whatsapp ? 'É o número da conversa de onde você veio.' : 'Avisamos por aqui quando o pedido andar.',
            })}
          </div>
        </section>

        <section class="bloco">
          <h2 class="bloco__titulo">Pagamento</h2>
          <p class="bloco__nota">Você paga direto para a loja, ${dados.tipo === 'entrega' ? 'na entrega' : 'ao receber o pedido'}.</p>
          <fieldset class="grupo-opcoes grupo-opcoes--tres">
            <legend class="so-leitor">Forma de pagamento</legend>
            ${Object.entries(PAGAMENTOS).map(([forma, rotulo]) => `
              <label class="opcao opcao--figura">
                <input class="opcao__entrada" type="radio" name="pagamento" value="${forma}" ${dados.pagamento === forma ? 'checked' : ''}>
                <span class="opcao__marca">${icone('check', 14)}</span>
                <span class="opcao__figura">${icone(forma, 22)}</span>
                <span class="opcao__corpo"><span class="opcao__titulo">${rotulo}</span></span>
              </label>`).join('')}
          </fieldset>
          <span class="campo__erro" id="erro-pagamento" hidden></span>
          <div id="campo-troco" ${dados.pagamento === 'dinheiro' ? '' : 'hidden'}>
            ${campo('troco', 'Troco para quanto?', { opcional: true, extra: 'inputmode="decimal" placeholder="Ex.: 100,00"', ajuda: 'Deixe vazio se não precisar de troco.' })}
          </div>
        </section>

        <section class="bloco">
          <h2 class="bloco__titulo">Algo mais que devemos saber?</h2>
          <label class="campo">
            <span class="so-leitor">Observações do pedido</span>
            <textarea class="entrada" name="observacoes" maxlength="300" rows="2" placeholder="Ex.: interfone quebrado, ligar ao chegar">${esc(dados.observacoes)}</textarea>
          </label>
        </section>

        <button type="submit" class="botao botao--g botao--bloco botao--com-valor"><span>Revisar pedido</span><span class="botao__valor" id="checkout-total">${reais(t.total)}</span></button>
      </form>
    </div>`;
}

function camposDoTipo() {
  const loja = estado.loja;
  if (dados.tipo === 'entrega') {
    return `
      <p class="bloco__nota">${icone('moto', 16)}<span>Entregamos em: ${esc(loja.area_entrega || 'consulte a loja')}. Taxa de ${reais(loja.taxa_entrega_centavos)}, cerca de ${loja.tempo_entrega_min} min.</span></p>
      <div class="bloco__grade">
        ${campo('rua', 'Rua', { extra: 'autocomplete="address-line1" maxlength="120"', largura: 'campo--larga' })}
        ${campo('numero', 'Número', { extra: 'maxlength="20" inputmode="numeric"' })}
        ${campo('bairro', 'Bairro', { extra: 'autocomplete="address-level3" maxlength="80"' })}
        ${campo('complemento', 'Complemento', { opcional: true, extra: 'maxlength="80" placeholder="Apto, bloco"' })}
        ${campo('referencia', 'Ponto de referência', { opcional: true, extra: 'maxlength="120"', largura: 'campo--larga' })}
      </div>`;
  }
  if (dados.tipo === 'local') {
    return `<div class="bloco__grade">${campo('mesa', 'Número da mesa', { extra: 'maxlength="10" inputmode="numeric"', ajuda: 'Está no suporte em cima da mesa.' })}</div>`;
  }
  if (dados.tipo === 'retirada') {
    return `<p class="bloco__nota">${icone('loja', 16)}<span>Retire em: ${esc(loja.endereco || 'endereço da loja')}. Fica pronto em cerca de ${loja.tempo_retirada_min} min.</span></p>`;
  }
  return '';
}

function lerFormulario() {
  const form = $('#form-checkout');
  if (!form) return;
  for (const el of form.elements) {
    if (!el.name) continue;
    if (el.type === 'radio') {
      if (el.checked) dados[el.name] = el.value;
    } else {
      dados[el.name] = el.value.trim();
    }
  }
  const { observacoes, mesa, ...paraGuardar } = dados;
  guardado.gravar(CHAVE_CLIENTE, paraGuardar);
}

function validar() {
  const erros = {};
  const digitos = dados.telefone.replace(/\D/g, '').replace(/^55(?=\d{10,11}$)/, '');
  if (!dados.tipo) erros.tipo = 'Escolha como quer receber o pedido.';
  if (dados.nome.length < 2) erros.nome = 'Diga seu nome para chamarmos você.';
  if (!/^[1-9]\d9?\d{8}$/.test(digitos)) erros.telefone = 'Informe o WhatsApp com DDD, por exemplo (11) 91234-5678.';
  if (dados.tipo === 'entrega') {
    if (!dados.rua) erros.rua = 'Informe a rua.';
    if (!dados.numero) erros.numero = 'Informe o número (ou "s/n").';
    if (!dados.bairro) erros.bairro = 'Informe o bairro.';
  }
  if (dados.tipo === 'local' && !dados.mesa) erros.mesa = 'Informe o número da mesa.';
  if (!dados.pagamento) erros.pagamento = 'Escolha a forma de pagamento.';
  if (dados.pagamento === 'dinheiro' && dados.troco) {
    const troco = centavosDeTexto(dados.troco);
    if (troco === null) erros.troco = 'Digite um valor, por exemplo 100,00.';
    else if (troco < totaisDoCarrinho(dados.tipo).total) erros.troco = 'O valor para troco precisa ser maior que o total do pedido.';
  }
  return erros;
}

function mostrarErros(erros) {
  $$('.campo__erro', visaoCheckout).forEach((el) => {
    const nome = el.id.replace('erro-', '');
    el.hidden = !erros[nome];
    el.textContent = erros[nome] ?? '';
    $$(`[name="${nome}"]`, visaoCheckout).forEach((entrada) => {
      if (entrada.type !== 'radio') entrada.setAttribute('aria-invalid', erros[nome] ? 'true' : 'false');
    });
  });
  const primeiro = Object.keys(erros)[0];
  if (primeiro) {
    const alvo = $(`[name="${primeiro}"]`, visaoCheckout);
    alvo?.focus();
    alvo?.scrollIntoView({ block: 'center', behavior: 'auto' });
  }
}

// ---------- Passo 2: revisão final ----------
function revisaoHtml() {
  const t = totaisDoCarrinho(dados.tipo);
  const loja = estado.loja;
  const troco = dados.pagamento === 'dinheiro' && dados.troco ? centavosDeTexto(dados.troco) : null;
  const onde = {
    entrega: `${esc(dados.rua)}, ${esc(dados.numero)}${dados.complemento ? ` · ${esc(dados.complemento)}` : ''}<br>${esc(dados.bairro)}${dados.referencia ? `<br>Ref.: ${esc(dados.referencia)}` : ''}`,
    retirada: esc(loja.endereco || 'Na loja'),
    local: `Mesa ${esc(dados.mesa)}`,
  }[dados.tipo];
  const minutos = dados.tipo === 'entrega' ? loja.tempo_entrega_min : loja.tempo_retirada_min;

  return `
    <div class="checkout__dentro">
      <button type="button" class="botao botao--fantasma checkout__voltar" data-acao="voltar">${icone('seta-esq', 18)}Alterar dados</button>
      <h1 class="checkout__titulo" tabindex="-1">Confira antes de enviar</h1>

      <section class="bloco">
        <h2 class="bloco__titulo">${plural(t.quantidade, 'item', 'itens')}</h2>
        <ul class="revisao-itens">
          ${t.linhas.map((l) => `
            <li class="revisao-itens__item">
              <div class="revisao-itens__topo">
                <p class="revisao-itens__nome"><span class="numero">${l.quantidade}×</span> ${esc(l.nome)}</p>
                <p class="numero">${reais(l.total)}</p>
              </div>
              ${l.montagem ? `<ul class="montagem">${linhasMontagem({
                tamanho: l.montagem.tamanho, massa: l.montagem.massa, molho: l.montagem.molho,
                inclusos: l.montagem.inclusos, extras: l.montagem.extras, queijo_ralado: l.queijo_ralado,
              })}</ul>` : ''}
              ${l.observacoes ? `<p class="revisao-itens__obs">Obs.: ${esc(l.observacoes)}</p>` : ''}
            </li>`).join('')}
        </ul>
      </section>

      <section class="bloco">
        <dl class="resumo-dados">
          <div><dt>${ROTULO_TIPO[dados.tipo]}</dt><dd>${onde}<br><span class="resumo-dados__leve">Cerca de ${minutos} min</span></dd></div>
          <div><dt>Quem pede</dt><dd>${esc(dados.nome)}<br>${esc(formatarTelefone(dados.telefone))}</dd></div>
          <div><dt>Pagamento</dt><dd>${PAGAMENTOS[dados.pagamento]}${troco ? `, troco para ${reais(troco)}` : ''}</dd></div>
          ${dados.observacoes ? `<div><dt>Observações</dt><dd>${esc(dados.observacoes)}</dd></div>` : ''}
        </dl>
      </section>

      <section class="bloco">
        <dl class="totais">
          <div><dt>Subtotal</dt><dd class="numero">${reais(t.subtotal)}</dd></div>
          ${dados.tipo === 'entrega' ? `<div><dt>Taxa de entrega</dt><dd class="numero">${t.taxa ? reais(t.taxa) : 'Grátis'}</dd></div>` : ''}
          <div class="totais__total"><dt>Total</dt><dd class="numero">${reais(t.total)}</dd></div>
        </dl>
      </section>

      <p class="campo__erro" id="erro-envio" role="alert" hidden></p>
      <button type="button" class="botao botao--g botao--bloco botao--com-valor" data-acao="confirmar"><span>Confirmar pedido</span><span class="botao__valor">${reais(t.total)}</span></button>
    </div>`;
}

async function confirmar(botao) {
  if (enviando) return;
  enviando = true;
  botao.setAttribute('aria-busy', 'true');
  const t = totaisDoCarrinho(dados.tipo);
  const troco = dados.pagamento === 'dinheiro' && dados.troco ? centavosDeTexto(dados.troco) : null;
  try {
    const criado = await api('/api/pedidos', {
      metodo: 'POST',
      corpo: {
        tipo: dados.tipo,
        cliente: { nome: dados.nome, telefone: dados.telefone },
        endereco: { rua: dados.rua, numero: dados.numero, bairro: dados.bairro, complemento: dados.complemento, referencia: dados.referencia },
        mesa: dados.mesa,
        pagamento: { forma: dados.pagamento, troco_para_centavos: troco },
        observacoes: dados.observacoes,
        itens: estado.carrinho.map(({ uid, ...linha }) => linha),
        total_esperado_centavos: t.total,
        t: estado.whatsapp?.t ?? '',
      },
    });
    guardado.gravar('fm_ultimo_pedido', { codigo: criado.codigo, numero: criado.numero });
    esvaziarCarrinho();
    location.assign(`/pedido/${criado.codigo}`);
  } catch (erro) {
    enviando = false;
    botao.removeAttribute('aria-busy');
    if (erro.codigo === 'validacao') {
      // O servidor achou um campo errado que passou aqui: volta ao formulário já mostrando onde.
      history.back();
      setTimeout(() => mostrarErros(erro.campos), 60);
      return;
    }
    if (['total_mudou', 'esgotado', 'item_removido', 'loja_fechada', 'montagem_invalida'].includes(erro.codigo)) {
      // O cardápio mudou enquanto a pessoa pedia: busca o atual e redesenha a revisão com os valores novos.
      await recarregarCardapio().catch(() => {});
      if (totaisDoCarrinho().temProblema || erro.codigo === 'loja_fechada') {
        avisar(erro.message, { tipo: 'erro', duracao: 7000 });
        history.go(history.state?.visao === 'revisao' ? -2 : -1);
        return;
      }
      mostrar('revisao');
    }
    const aviso = $('#erro-envio');
    if (aviso) {
      aviso.hidden = false;
      aviso.textContent = erro.message;
      aviso.scrollIntoView({ block: 'center' });
    } else {
      avisar(erro.message, { tipo: 'erro' });
    }
  }
}

// ---------- Eventos ----------
delegar(visaoCheckout, 'click', '[data-acao="voltar"]', () => {
  lerFormulario();
  history.back();
});
delegar(visaoCheckout, 'click', '[data-acao="confirmar"]', (e, botao) => confirmar(botao));

visaoCheckout.addEventListener('submit', (e) => {
  e.preventDefault();
  lerFormulario();
  const erros = validar();
  mostrarErros(erros);
  if (Object.keys(erros).length > 0) return;
  history.pushState({ visao: 'revisao' }, '');
  mostrar('revisao');
});

delegar(visaoCheckout, 'change', 'input[name="tipo"]', () => {
  lerFormulario();
  $('#campos-tipo').innerHTML = camposDoTipo();
  $('#checkout-total').textContent = reais(totaisDoCarrinho(dados.tipo).total);
});
delegar(visaoCheckout, 'change', 'input[name="pagamento"]', (e, entrada) => {
  $('#campo-troco').hidden = entrada.value !== 'dinheiro';
});
// Máscara do telefone enquanto digita.
delegar(visaoCheckout, 'input', 'input[name="telefone"]', (e, entrada) => {
  entrada.value = formatarTelefone(entrada.value);
});
delegar(visaoCheckout, 'focusout', 'input[name="troco"]', (e, entrada) => {
  const valor = centavosDeTexto(entrada.value);
  if (valor !== null) entrada.value = textoDeCentavos(valor);
});

// Formata o telefone guardado ao abrir.
dados.telefone = dados.telefone ? formatarTelefone(dados.telefone) : '';
