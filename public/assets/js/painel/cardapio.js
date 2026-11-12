// Cadastro do cardápio (Etapa 5): itens, categorias, tamanhos, massas, molhos e ingredientes.
// O interruptor de cada linha marca "esgotado" na hora. Só o administrador cria, edita, ordena e apaga.
import { $, esc, delegar } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { reais, centavosDeTexto, textoDeCentavos, plural } from '../shared/formato.js';
import { api } from '../shared/api.js';
import { abrirFolha, fecharFolha } from '../shared/folha.js';
import { avisar } from '../shared/avisos.js';
import { conteudo, ehAdmin, mostrarErro, confirmar } from './casca.js';

const ABAS = [
  { id: 'itens', titulo: 'Itens', um: 'item', novo: 'Novo item' },
  { id: 'categorias', titulo: 'Categorias', um: 'categoria', novo: 'Nova categoria', soAdmin: true },
  { id: 'tamanhos', titulo: 'Tamanhos', um: 'tamanho', novo: 'Novo tamanho' },
  { id: 'massas', titulo: 'Massas', um: 'massa', novo: 'Nova massa' },
  { id: 'molhos', titulo: 'Molhos', um: 'molho', novo: 'Novo molho' },
  { id: 'ingredientes', titulo: 'Ingredientes', um: 'ingrediente', novo: 'Novo ingrediente' },
].filter((aba) => ehAdmin || !aba.soAdmin);

const lateral = $('#folha-lateral');
let dados = null;            // cardápio completo vindo da API
let aba = ABAS[0].id;
let editando = null;         // { recurso, registro|null, imagem }

const acrescimo = (centavos) => (centavos ? `+ ${reais(centavos)}` : 'sem acréscimo');
const nomeDe = (lista, id) => dados[lista].find((x) => x.id === id)?.nome ?? '(removido)';

// ---------- Lista ----------
/** Devolve as linhas da aba, já na ordem de exibição, com o grupo de cada uma. */
function linhasDaAba() {
  if (aba === 'itens') {
    return dados.categorias.flatMap((c) => dados.itens.filter((i) => i.categoria_id === c.id).map((i) => ({ registro: i, grupo: c.nome })));
  }
  if (aba === 'ingredientes') {
    const grupos = [...new Set(dados.ingredientes.map((i) => i.grupo))];
    return grupos.flatMap((g) => dados.ingredientes.filter((i) => i.grupo === g).map((i) => ({ registro: i, grupo: g })));
  }
  return dados[aba].map((registro) => ({ registro, grupo: null }));
}

function metaDe(r) {
  switch (aba) {
    case 'itens':
      return r.tipo === 'prato_casa'
        ? `Prato da casa · ${nomeDe('massas', r.massa_id)} + ${nomeDe('molhos', r.molho_id)}${r.ingrediente_ids.length ? ` + ${plural(r.ingrediente_ids.length, 'ingrediente', 'ingredientes')}` : ''}`
        : reais(r.preco_centavos);
    case 'categorias':
      return plural(dados.itens.filter((i) => i.categoria_id === r.id).length, 'item', 'itens');
    case 'tamanhos':
      return [r.sigla, r.descricao, reais(r.preco_centavos), plural(r.inclusos, 'ingrediente incluso', 'ingredientes inclusos')].filter(Boolean).join(' · ');
    case 'massas':
    case 'molhos':
      return [r.descricao, acrescimo(r.acrescimo_centavos)].filter(Boolean).join(' · ');
    case 'ingredientes':
      return `extra ${reais(r.preco_extra_centavos)}`;
  }
  return '';
}

function figuraDe(r) {
  if (aba === 'itens') return r.imagem ? `<img class="miniatura" src="${esc(r.imagem)}" alt="" width="48" height="48" loading="lazy">` : `<span class="miniatura">${icone('imagem', 20)}</span>`;
  if (aba === 'molhos') return `<span class="miniatura miniatura--cor" style="background: ${esc(r.cor)}"></span>`;
  return '';
}

function linhaHtml({ registro: r }, posicao, total) {
  const ligado = r.estado === 'disponivel';
  const rotulo = aba === 'categorias' ? 'Visível' : 'Disponível';
  return `
    <li class="linha-cadastro" data-id="${r.id}" data-estado="${r.estado}">
      <div class="linha-cadastro__texto">
        ${figuraDe(r)}
        <div>
          <p class="linha-cadastro__nome">${esc(r.nome)} ${r.estado === 'oculto' ? '<span class="selo">Oculto</span>' : r.estado === 'esgotado' ? '<span class="selo selo--alerta">Esgotado</span>' : ''}</p>
          <p class="linha-cadastro__meta">${esc(metaDe(r))}</p>
        </div>
      </div>
      <label class="interruptor linha-cadastro__estado">
        <input type="checkbox" data-estado-de="${r.id}" ${ligado ? 'checked' : ''} ${!ehAdmin && r.estado === 'oculto' ? 'disabled' : ''}>
        <span class="interruptor__trilho"></span>
        <span>${rotulo}<span class="so-leitor">: ${esc(r.nome)}</span></span>
      </label>
      ${ehAdmin ? `
      <div class="linha-cadastro__acoes">
        <button type="button" class="botao botao--fantasma botao--icone" data-ordem="-1" ${posicao === 0 ? 'disabled' : ''} aria-label="Subir ${esc(r.nome)}">${icone('seta-cima', 18)}</button>
        <button type="button" class="botao botao--fantasma botao--icone" data-ordem="1" ${posicao === total - 1 ? 'disabled' : ''} aria-label="Descer ${esc(r.nome)}">${icone('seta-baixo', 18)}</button>
        <button type="button" class="botao botao--fantasma botao--icone" data-editar aria-label="Editar ${esc(r.nome)}">${icone('editar', 18)}</button>
        <button type="button" class="botao botao--perigo-suave botao--icone" data-apagar aria-label="Apagar ${esc(r.nome)}">${icone('lixeira', 18)}</button>
      </div>` : ''}
    </li>`;
}

function desenhar() {
  const info = ABAS.find((a) => a.id === aba);
  const linhas = linhasDaAba();
  const grupos = [...new Set(linhas.map((l) => l.grupo))];
  const lista = linhas.length === 0
    ? `<div class="vazio"><span class="vazio__icone">${icone('livro', 40)}</span><p class="vazio__titulo">Nenhum cadastro aqui ainda</p>${
        ehAdmin ? `<p>${aba === 'itens' && dados.categorias.length === 0 ? 'Crie primeiro uma categoria, depois os itens dela.' : `Comece pelo botão “${info.novo}”.`}</p>` : ''}</div>`
    : grupos.map((grupo) => {
        const doGrupo = linhas.filter((l) => l.grupo === grupo);
        return `${grupo ? `<h2 class="cadastro__grupo">${esc(grupo)}</h2>` : ''}<ul class="lista-cadastro">${doGrupo.map((l, i) => linhaHtml(l, i, doGrupo.length)).join('')}</ul>`;
      }).join('');

  conteudo.innerHTML = `
    <div class="cabecalho-tela">
      <h1 class="cabecalho-tela__titulo">Cardápio</h1>
    </div>
    <div class="abas" role="tablist" aria-label="Cadastros do cardápio">
      ${ABAS.map((a) => `<button type="button" class="chip" role="tab" data-aba="${a.id}" aria-selected="${a.id === aba}">${a.titulo}</button>`).join('')}
    </div>
    <div class="cadastro" role="tabpanel">
      <div class="cadastro__topo">
        <p class="cadastro__nota">${aba === 'categorias' ? 'Categoria desligada some do cardápio com todos os itens dela.' : 'Desligue o que acabou: no cardápio do cliente aparece como esgotado na hora.'}</p>
        ${ehAdmin ? `<button type="button" class="botao" data-novo>${icone('mais', 18)}${info.novo}</button>` : ''}
      </div>
      ${lista}
    </div>`;
}

async function carregar() {
  try {
    dados = await api('/api/admin/cardapio');
    desenhar();
  } catch (erro) {
    mostrarErro(erro);
    conteudo.innerHTML = `
      <div class="vazio">
        <span class="vazio__icone">${icone('alerta', 40)}</span>
        <p class="vazio__titulo">Não deu para carregar o cardápio</p>
        <button type="button" class="botao" id="tentar">Tentar de novo</button>
      </div>`;
    $('#tentar').addEventListener('click', carregar);
  }
}

// ---------- Formulário (painel lateral) ----------
const campoTexto = (nome, rotulo, valor, { ajuda = '', extra = '', opcional = false } = {}) => `
  <label class="campo">
    <span class="campo__rotulo">${rotulo}${opcional ? ' <span class="campo__opcional">(opcional)</span>' : ''}</span>
    <input class="entrada" type="text" name="${nome}" value="${esc(valor ?? '')}" aria-describedby="erro-${nome}" ${extra}>
    ${ajuda ? `<span class="campo__ajuda">${ajuda}</span>` : ''}
    <span class="campo__erro" id="erro-${nome}" hidden></span>
  </label>`;
const campoPreco = (nome, rotulo, centavos, ajuda = '') =>
  campoTexto(nome, rotulo, centavos === null || centavos === undefined ? '' : textoDeCentavos(centavos), { ajuda, extra: 'inputmode="decimal" placeholder="0,00"' });
const campoSelecao = (nome, rotulo, opcoes, valor) => `
  <label class="campo">
    <span class="campo__rotulo">${rotulo}</span>
    <select class="entrada" name="${nome}" aria-describedby="erro-${nome}">
      ${opcoes.map(([v, texto]) => `<option value="${esc(v)}" ${String(v) === String(valor ?? '') ? 'selected' : ''}>${esc(texto)}</option>`).join('')}
    </select>
    <span class="campo__erro" id="erro-${nome}" hidden></span>
  </label>`;

function camposDoRecurso(recurso, r) {
  const estados = recurso === 'categorias'
    ? [['disponivel', 'Visível no cardápio'], ['oculto', 'Oculta (some do cardápio)']]
    : [['disponivel', 'Disponível'], ['esgotado', 'Esgotado (aparece desabilitado)'], ['oculto', 'Oculto (some do cardápio)']];
  const nome = campoTexto('nome', 'Nome', r?.nome, { extra: 'maxlength="60" required' });
  const estado = campoSelecao('estado', 'Situação', estados, r?.estado ?? 'disponivel');

  switch (recurso) {
    case 'categorias':
      return nome + estado;
    case 'tamanhos':
      return `${nome}
        <div class="formulario__par">
          ${campoTexto('sigla', 'Sigla', r?.sigla, { extra: 'maxlength="3"', ajuda: 'Aparece no cartão da cozinha: P, M, G.' })}
          ${campoTexto('descricao', 'Peso ou porção', r?.descricao, { extra: 'maxlength="60" placeholder="400 g"', opcional: true })}
        </div>
        <div class="formulario__par">
          ${campoPreco('preco_centavos', 'Preço base (R$)', r?.preco_centavos)}
          ${campoTexto('inclusos', 'Ingredientes inclusos', r?.inclusos ?? '', { extra: 'inputmode="numeric" maxlength="2"', ajuda: 'Acima disso, cada um é cobrado como extra.' })}
        </div>${estado}`;
    case 'massas':
      return `${nome}${campoTexto('descricao', 'Descrição', r?.descricao, { extra: 'maxlength="100"', opcional: true })}
        ${campoPreco('acrescimo_centavos', 'Acréscimo (R$)', r?.acrescimo_centavos ?? 0, 'Deixe 0,00 se não muda o preço do prato.')}${estado}`;
    case 'molhos':
      return `${nome}${campoTexto('descricao', 'Descrição', r?.descricao, { extra: 'maxlength="100"', opcional: true })}
        <div class="formulario__par">
          ${campoPreco('acrescimo_centavos', 'Acréscimo (R$)', r?.acrescimo_centavos ?? 0)}
          <label class="campo">
            <span class="campo__rotulo">Cor do molho</span>
            <input class="entrada entrada--cor" type="color" name="cor" value="${esc(r?.cor ?? '#C2382B')}">
            <span class="campo__ajuda">A bolinha que o cliente vê ao escolher.</span>
          </label>
        </div>${estado}`;
    case 'ingredientes': {
      const grupos = [...new Set(dados.ingredientes.map((i) => i.grupo))];
      return `${nome}
        <div class="formulario__par">
          ${campoTexto('grupo', 'Grupo', r?.grupo ?? grupos[0] ?? '', { extra: 'maxlength="40" list="lista-grupos"', ajuda: 'Ex.: Proteínas, Vegetais, Queijos.' })}
          ${campoPreco('preco_extra_centavos', 'Preço como extra (R$)', r?.preco_extra_centavos, 'Cobrado só quando passa dos inclusos.')}
        </div>
        <datalist id="lista-grupos">${grupos.map((g) => `<option value="${esc(g)}">`).join('')}</datalist>${estado}`;
    }
    case 'itens': {
      const tipo = r?.tipo ?? 'simples';
      const marcados = new Set(r?.ingrediente_ids ?? []);
      return `
        ${campoSelecao('categoria_id', 'Categoria', dados.categorias.map((c) => [c.id, c.nome]), r?.categoria_id)}
        ${nome}
        ${campoTexto('descricao', 'Descrição', r?.descricao, { extra: 'maxlength="160"', opcional: true })}
        ${campoSelecao('tipo', 'Tipo', [['simples', 'Item pronto, com preço fixo (bebida, sobremesa)'], ['prato_casa', 'Prato da casa (montagem sugerida)']], tipo)}
        <div data-so="simples" ${tipo === 'simples' ? '' : 'hidden'}>${campoPreco('preco_centavos', 'Preço (R$)', r?.preco_centavos)}</div>
        <div class="formulario" data-so="prato_casa" ${tipo === 'prato_casa' ? '' : 'hidden'}>
          <p class="cadastro__nota">O cliente escolhe o tamanho e pode trocar o que quiser. O preço segue a regra do “Monte seu prato”.</p>
          <div class="formulario__par">
            ${campoSelecao('massa_id', 'Massa', dados.massas.map((x) => [x.id, x.nome]), r?.massa_id)}
            ${campoSelecao('molho_id', 'Molho', dados.molhos.map((x) => [x.id, x.nome]), r?.molho_id)}
          </div>
          <fieldset class="campo" style="border: 0; padding: 0; margin: 0;">
            <legend class="campo__rotulo">Ingredientes do prato</legend>
            <div class="formulario__marcar">
              ${dados.ingredientes.map((i) => `<label><input type="checkbox" name="ingrediente" value="${i.id}" ${marcados.has(i.id) ? 'checked' : ''}><span>${esc(i.nome)}</span></label>`).join('')}
            </div>
          </fieldset>
        </div>
        <div class="campo">
          <span class="campo__rotulo">Foto <span class="campo__opcional">(opcional)</span></span>
          <div class="envio-foto" id="envio-foto"></div>
          <span class="campo__erro" id="erro-imagem" hidden></span>
        </div>
        ${estado}`;
    }
  }
  return nome + estado;
}

function desenharFoto() {
  const caixa = $('#envio-foto');
  if (!caixa) return;
  caixa.innerHTML = `
    ${editando.imagem ? `<img class="envio-foto__previa" src="${esc(editando.imagem)}" alt="Foto atual">` : `<span class="envio-foto__previa">${icone('imagem', 28)}</span>`}
    <div class="envio-foto__acoes">
      <label class="botao botao--secundario botao--p">${icone('imagem', 16)}${editando.imagem ? 'Trocar foto' : 'Escolher foto'}<input type="file" accept="image/*" id="arquivo-foto" class="so-leitor"></label>
      ${editando.imagem ? '<button type="button" class="botao botao--fantasma botao--p" data-tirar-foto>Tirar a foto</button>' : ''}
      <span class="campo__ajuda">A foto é reduzida aqui no navegador antes de enviar.</span>
    </div>`;
}

function abrirFormulario(recurso, registro = null) {
  const info = ABAS.find((a) => a.id === recurso);
  editando = { recurso, registro, imagem: registro?.imagem ?? null };
  lateral.innerHTML = `
    <header class="folha__topo">
      <h2 class="folha__titulo" id="folha-lateral-titulo">${registro ? `Editar ${info.um}` : info.novo}</h2>
      <button type="button" class="botao botao--fantasma botao--icone" data-fechar aria-label="Fechar sem salvar">${icone('fechar')}</button>
    </header>
    <form class="folha__corpo formulario" id="form-cadastro" novalidate>${camposDoRecurso(recurso, registro)}</form>
    <footer class="folha__rodape">
      <button type="submit" form="form-cadastro" class="botao botao--g botao--bloco">${registro ? 'Salvar alterações' : `Criar ${info.um}`}</button>
    </footer>`;
  desenharFoto();
  abrirFolha(lateral, { aoFechar: () => (editando = null) });
}

/** Reduz a foto para no máximo 1200 px e converte para WebP (ou JPEG, se o navegador não gerar WebP). */
async function reduzirFoto(arquivo) {
  const imagem = await createImageBitmap(arquivo);
  const escala = Math.min(1, 1200 / Math.max(imagem.width, imagem.height));
  const tela = document.createElement('canvas');
  tela.width = Math.round(imagem.width * escala);
  tela.height = Math.round(imagem.height * escala);
  tela.getContext('2d').drawImage(imagem, 0, 0, tela.width, tela.height);
  const gerar = (tipo) => new Promise((pronto) => tela.toBlob(pronto, tipo, 0.82));
  let blob = await gerar('image/webp');
  if (!blob || blob.type !== 'image/webp') blob = await gerar('image/jpeg');
  return blob;
}

function lerFormulario(form) {
  const v = {};
  const erros = {};
  const dinheiro = (nome) => {
    const centavos = centavosDeTexto(form[nome].value);
    if (centavos === null) erros[nome] = 'Digite um valor, por exemplo 4,00.';
    return centavos;
  };
  v.nome = form.nome.value.trim();
  v.estado = form.estado.value;
  switch (editando.recurso) {
    case 'tamanhos':
      v.sigla = form.sigla.value.trim();
      v.descricao = form.descricao.value.trim();
      v.preco_centavos = dinheiro('preco_centavos');
      v.inclusos = form.inclusos.value.trim();
      break;
    case 'massas':
      v.descricao = form.descricao.value.trim();
      v.acrescimo_centavos = dinheiro('acrescimo_centavos');
      break;
    case 'molhos':
      v.descricao = form.descricao.value.trim();
      v.acrescimo_centavos = dinheiro('acrescimo_centavos');
      v.cor = form.cor.value;
      break;
    case 'ingredientes':
      v.grupo = form.grupo.value.trim();
      v.preco_extra_centavos = dinheiro('preco_extra_centavos');
      break;
    case 'itens':
      v.categoria_id = Number(form.categoria_id.value);
      v.descricao = form.descricao.value.trim();
      v.tipo = form.tipo.value;
      v.imagem = editando.imagem;
      if (v.tipo === 'simples') {
        v.preco_centavos = dinheiro('preco_centavos');
      } else {
        v.massa_id = Number(form.massa_id.value);
        v.molho_id = Number(form.molho_id.value);
      }
      v.ingrediente_ids = [...form.querySelectorAll('[name="ingrediente"]:checked')].map((c) => Number(c.value));
      break;
  }
  return { valores: v, erros };
}

function mostrarErrosDoFormulario(erros) {
  for (const el of lateral.querySelectorAll('.campo__erro')) {
    const nome = el.id.replace('erro-', '');
    el.hidden = !erros[nome];
    el.textContent = erros[nome] ?? '';
    lateral.querySelector(`[name="${nome}"]`)?.setAttribute('aria-invalid', erros[nome] ? 'true' : 'false');
  }
  const primeiro = Object.keys(erros)[0];
  const alvo = primeiro ? lateral.querySelector(`[name="${primeiro}"]`) ?? lateral.querySelector(`#erro-${primeiro}`) : null;
  alvo?.focus?.();
  alvo?.scrollIntoView({ block: 'center' });
}

// ---------- Eventos ----------
delegar(conteudo, 'click', '[data-aba]', (e, botao) => {
  aba = botao.dataset.aba;
  desenhar();
  $(`[data-aba="${aba}"]`).focus();
});
delegar(conteudo, 'click', '[data-novo]', () => {
  if (aba === 'itens' && dados.categorias.length === 0) {
    avisar('Crie uma categoria antes de cadastrar itens', { tipo: 'erro' });
    return;
  }
  abrirFormulario(aba);
});
delegar(conteudo, 'click', '[data-editar]', (e, botao) => {
  const id = Number(botao.closest('[data-id]').dataset.id);
  abrirFormulario(aba, dados[aba].find((r) => r.id === id));
});

// Interruptor "Disponível": muda na hora, e volta se o servidor recusar.
delegar(conteudo, 'change', '[data-estado-de]', async (e, caixa) => {
  const id = Number(caixa.dataset.estadoDe);
  const estado = caixa.checked ? 'disponivel' : aba === 'categorias' ? 'oculto' : 'esgotado';
  caixa.disabled = true;
  try {
    const salvo = await api(`/api/admin/cardapio/${aba}/${id}/estado`, { metodo: 'PATCH', corpo: { estado } });
    Object.assign(dados[aba].find((r) => r.id === id), salvo);
    desenhar();
    $(`[data-estado-de="${id}"]`)?.focus();
    avisar(`${salvo.nome}: ${{ disponivel: aba === 'categorias' ? 'visível no cardápio' : 'disponível', esgotado: 'esgotado', oculto: 'oculto do cardápio' }[salvo.estado]}`);
  } catch (erro) {
    caixa.checked = !caixa.checked;
    caixa.disabled = false;
    mostrarErro(erro);
  }
});

delegar(conteudo, 'click', '[data-apagar]', async (e, botao) => {
  const id = Number(botao.closest('[data-id]').dataset.id);
  const registro = dados[aba].find((r) => r.id === id);
  const sim = await confirmar({
    titulo: `Apagar “${registro.nome}”?`,
    texto: 'Pedidos já feitos continuam com o nome e o preço da época. Se for só por hoje, prefira marcar como esgotado.',
    confirmar: 'Apagar', perigo: true,
  });
  if (!sim) return;
  try {
    await api(`/api/admin/cardapio/${aba}/${id}`, { metodo: 'DELETE' });
    avisar(`${registro.nome} apagado`);
    await carregar();
  } catch (erro) {
    mostrarErro(erro);
  }
});

// Subir/descer: reordena a lista inteira da aba e grava só quem mudou de posição.
delegar(conteudo, 'click', '[data-ordem]', async (e, botao) => {
  const id = Number(botao.closest('[data-id]').dataset.id);
  const linhas = linhasDaAba();
  const de = linhas.findIndex((l) => l.registro.id === id);
  const para = de + Number(botao.dataset.ordem);
  if (para < 0 || para >= linhas.length || linhas[para].grupo !== linhas[de].grupo) return;
  [linhas[de], linhas[para]] = [linhas[para], linhas[de]];
  const mudancas = linhas.map((l, ordem) => ({ registro: l.registro, ordem })).filter((m) => m.registro.ordem !== m.ordem);
  try {
    await Promise.all(mudancas.map((m) => api(`/api/admin/cardapio/${aba}/${m.registro.id}`, { metodo: 'PUT', corpo: { ordem: m.ordem } })));
    mudancas.forEach((m) => (m.registro.ordem = m.ordem));
    dados[aba].sort((a, b) => a.ordem - b.ordem || a.id - b.id);
    desenhar();
    $(`[data-id="${id}"] [data-ordem="${botao.dataset.ordem}"]:not(:disabled)`)?.focus() ?? $(`[data-id="${id}"] [data-ordem]:not(:disabled)`)?.focus();
  } catch (erro) {
    mostrarErro(erro);
    carregar();
  }
});

delegar(lateral, 'change', 'select[name="tipo"]', (e, selecao) => {
  lateral.querySelectorAll('[data-so]').forEach((bloco) => (bloco.hidden = bloco.dataset.so !== selecao.value));
});
delegar(lateral, 'focusout', 'input[inputmode="decimal"]', (e, entrada) => {
  const valor = centavosDeTexto(entrada.value);
  if (valor !== null) entrada.value = textoDeCentavos(valor);
});
delegar(lateral, 'change', '#arquivo-foto', async (e, entrada) => {
  const arquivo = entrada.files?.[0];
  if (!arquivo || !editando) return;
  const erroFoto = $('#erro-imagem');
  erroFoto.hidden = true;
  try {
    const formulario = new FormData();
    formulario.append('imagem', await reduzirFoto(arquivo), 'foto');
    const r = await api('/api/admin/upload', { metodo: 'POST', formulario });
    editando.imagem = r.caminho;
    desenharFoto();
  } catch (erro) {
    erroFoto.textContent = erro.campos?.imagem ?? erro.message ?? 'Não conseguimos ler esta foto. Tente outra.';
    erroFoto.hidden = false;
  }
});
delegar(lateral, 'click', '[data-tirar-foto]', () => {
  editando.imagem = null;
  desenharFoto();
});

lateral.addEventListener('submit', async (e) => {
  if (e.target.id !== 'form-cadastro' || !editando) return;
  e.preventDefault();
  const { valores, erros } = lerFormulario(e.target);
  if (!valores.nome) erros.nome = 'Dê um nome.';
  mostrarErrosDoFormulario(erros);
  if (Object.keys(erros).length) return;

  const botao = lateral.querySelector('button[type="submit"]');
  botao.setAttribute('aria-busy', 'true');
  const { recurso, registro } = editando;
  try {
    await api(`/api/admin/cardapio/${recurso}${registro ? `/${registro.id}` : ''}`, { metodo: registro ? 'PUT' : 'POST', corpo: valores });
    await fecharFolha(lateral);
    avisar(registro ? `${valores.nome} atualizado` : `${valores.nome} criado`);
    await carregar();
  } catch (erro) {
    botao.removeAttribute('aria-busy');
    if (erro.codigo === 'validacao') mostrarErrosDoFormulario(erro.campos);
    else mostrarErro(erro);
  }
});

carregar();
