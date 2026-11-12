// Usuários do painel (só o proprietário): quem entra, com qual perfil, e a senha de cada um.
// A senha nunca volta do servidor: aqui ela só é definida (ao criar ou em "Trocar senha").
import { $, esc, delegar } from '../shared/dom.js';
import { icone } from '../shared/icones.js';
import { api } from '../shared/api.js';
import { abrirFolha, fecharFolha } from '../shared/folha.js';
import { avisar } from '../shared/avisos.js';
import { recipienteDaTela, mostrarErro } from './casca.js';

const conteudo = recipienteDaTela();
const lateral = $('#folha-lateral');

const PERFIS = {
  admin: { nome: 'Proprietário', ajuda: 'Vê e muda tudo: cardápio, configurações, WhatsApp e usuários.' },
  atendente: { nome: 'Atendente', ajuda: 'Pedidos e cardápio (marca o que acabou). Não mexe em configurações nem em usuários.' },
};

let usuarios = [];
let eu = null;          // id de quem está logado
let editando = null;    // { modo: 'novo' | 'editar' | 'senha', usuario|null }

const iniciais = (nome) => nome.trim().split(/\s+/).slice(0, 2).map((p) => p[0]).join('').toUpperCase();
const dataCurta = (iso) => (iso ? new Date(iso).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' }) : '');

// ---------- Lista ----------
function linhaHtml(u) {
  const souEu = u.id === eu;
  return `
    <li class="linha-cadastro linha-usuario" data-id="${u.id}" data-estado="${u.ativo ? 'disponivel' : 'oculto'}">
      <div class="linha-cadastro__texto">
        <span class="miniatura avatar" aria-hidden="true">${esc(iniciais(u.nome))}</span>
        <div>
          <p class="linha-cadastro__nome">${esc(u.nome)} ${souEu ? '<span class="selo selo--info">Você</span>' : ''} ${u.ativo ? '' : '<span class="selo">Desativado</span>'}</p>
          <p class="linha-cadastro__meta">Login <strong>${esc(u.login)}</strong>${u.criado_em ? ` · desde ${esc(dataCurta(u.criado_em))}` : ''}</p>
        </div>
      </div>
      <span class="linha-cadastro__estado"><span class="selo ${u.perfil === 'admin' ? 'selo--acento' : ''}">${PERFIS[u.perfil]?.nome ?? esc(u.perfil)}</span></span>
      <div class="linha-cadastro__acoes">
        <button type="button" class="botao botao--fantasma botao--p" data-editar>${icone('editar', 16)}Editar<span class="so-leitor"> ${esc(u.nome)}</span></button>
        <button type="button" class="botao botao--fantasma botao--p" data-senha>${icone('olho-fechado', 16)}Trocar senha<span class="so-leitor"> de ${esc(u.nome)}</span></button>
      </div>
    </li>`;
}

function desenhar() {
  const ativos = usuarios.filter((u) => u.ativo).length;
  conteudo.innerHTML = `
    <div class="cabecalho-tela">
      <h1 class="cabecalho-tela__titulo">Usuários</h1>
      <button type="button" class="botao" data-novo>${icone('mais', 18)}Novo usuário</button>
    </div>
    <div class="cadastro">
      <div class="cadastro__topo">
        <p class="cadastro__nota">${ativos === 1 ? '1 pessoa entra no painel.' : `${ativos} pessoas entram no painel.`} Quem sai da equipe é desativado: o histórico dos pedidos continua com o nome dela.</p>
      </div>
      <ul class="lista-cadastro">${usuarios.map(linhaHtml).join('')}</ul>
    </div>`;
}

async function carregar() {
  try {
    const r = await api('/api/admin/usuarios');
    usuarios = r.usuarios;
    eu = r.eu;
    desenhar();
  } catch (erro) {
    mostrarErro(erro);
    conteudo.innerHTML = `
      <div class="vazio">
        <span class="vazio__icone">${icone('alerta', 40)}</span>
        <p class="vazio__titulo">Não deu para carregar os usuários</p>
        <button type="button" class="botao" id="tentar-usuarios">Tentar de novo</button>
      </div>`;
    $('#tentar-usuarios').addEventListener('click', carregar);
  }
}

// ---------- Formulário (painel lateral) ----------
const campo = (nome, rotulo, controle, ajuda = '') => `
  <label class="campo">
    <span class="campo__rotulo">${rotulo}</span>
    ${controle}
    ${ajuda ? `<span class="campo__ajuda" id="ajuda-${nome}">${ajuda}</span>` : ''}
    <span class="campo__erro" id="erro-${nome}" hidden></span>
  </label>`;

const campoSenha = (rotulo) => `
  <div class="campo">
    <label class="campo__rotulo" for="usuario-senha">${rotulo}</label>
    <div class="entrada-senha">
      <input class="entrada" type="password" id="usuario-senha" name="senha" minlength="8" autocomplete="new-password" aria-describedby="ajuda-senha erro-senha" required>
      <button type="button" class="botao botao--fantasma botao--icone" data-mostrar-senha aria-pressed="false" aria-label="Mostrar a senha">${icone('olho', 18)}</button>
    </div>
    <span class="campo__ajuda" id="ajuda-senha">Pelo menos 8 caracteres. Passe para a pessoa pessoalmente, não por mensagem.</span>
    <span class="campo__erro" id="erro-senha" hidden></span>
  </div>`;

function camposPerfil(u, travado) {
  const perfil = u?.perfil ?? 'atendente';
  return `
    <fieldset class="campo escolha-perfil" ${travado ? 'disabled' : ''}>
      <legend class="campo__rotulo">Perfil</legend>
      ${Object.entries(PERFIS).map(([id, p]) => `
        <label class="escolha-perfil__opcao">
          <input type="radio" name="perfil" value="${id}" ${perfil === id ? 'checked' : ''}>
          <span><strong>${p.nome}</strong><span class="campo__ajuda">${p.ajuda}</span></span>
        </label>`).join('')}
      ${travado ? '<span class="campo__ajuda">Você não pode tirar o seu próprio perfil de proprietário.</span>' : ''}
      <span class="campo__erro" id="erro-perfil" hidden></span>
    </fieldset>`;
}

function corpoDoFormulario() {
  const { modo, usuario: u } = editando;
  if (modo === 'senha') {
    return `<p class="cadastro__nota">Nova senha de <strong>${esc(u.nome)}</strong> (login ${esc(u.login)}). A senha antiga deixa de valer na hora.</p>${campoSenha('Nova senha')}`;
  }
  const souEu = u?.id === eu;
  const nome = campo('nome', 'Nome', `<input class="entrada" type="text" name="nome" value="${esc(u?.nome ?? '')}" maxlength="80" autocomplete="off" aria-describedby="erro-nome" required>`);
  if (modo === 'novo') {
    return `${nome}
      ${campo('login', 'Login', '<input class="entrada" type="text" name="login" maxlength="30" autocomplete="off" autocapitalize="none" spellcheck="false" aria-describedby="ajuda-login erro-login" required>',
        'É o que a pessoa digita para entrar. Letras minúsculas, números, ponto ou hífen (ex.: maria.silva).')}
      ${camposPerfil(null, false)}
      ${campoSenha('Senha inicial')}`;
  }
  return `${nome}
    <p class="campo__ajuda">Login <strong>${esc(u.login)}</strong> (não muda).</p>
    ${camposPerfil(u, souEu)}
    <label class="interruptor">
      <input type="checkbox" name="ativo" ${u.ativo ? 'checked' : ''} ${souEu ? 'disabled' : ''}>
      <span class="interruptor__trilho"></span>
      <span>Pode entrar no painel</span>
    </label>
    <span class="campo__ajuda">${souEu ? 'Você não pode desativar o seu próprio usuário.' : 'Desligado, a pessoa sai do painel no próximo clique e não entra mais.'}</span>
    <span class="campo__erro" id="erro-ativo" hidden></span>`;
}

function abrirFormulario(modo, usuario = null) {
  editando = { modo, usuario };
  const titulo = { novo: 'Novo usuário', editar: 'Editar usuário', senha: 'Trocar senha' }[modo];
  const acao = { novo: 'Criar usuário', editar: 'Salvar alterações', senha: 'Trocar senha' }[modo];
  lateral.innerHTML = `
    <header class="folha__topo">
      <h2 class="folha__titulo" id="folha-lateral-titulo">${titulo}</h2>
      <button type="button" class="botao botao--fantasma botao--icone" data-fechar aria-label="Fechar sem salvar">${icone('fechar')}</button>
    </header>
    <form class="folha__corpo formulario" id="form-usuario" novalidate>${corpoDoFormulario()}</form>
    <footer class="folha__rodape">
      <button type="submit" form="form-usuario" class="botao botao--g botao--bloco">${acao}</button>
    </footer>`;
  abrirFolha(lateral, { aoFechar: () => (editando = null) });
}

function mostrarErros(erros) {
  for (const el of lateral.querySelectorAll('.campo__erro')) {
    const nome = el.id.replace('erro-', '');
    el.hidden = !erros[nome];
    el.textContent = erros[nome] ?? '';
    lateral.querySelector(`[name="${nome}"]`)?.setAttribute('aria-invalid', erros[nome] ? 'true' : 'false');
  }
  const primeiro = Object.keys(erros)[0];
  if (primeiro) lateral.querySelector(`[name="${primeiro}"]`)?.focus();
}

function lerFormulario(form) {
  const { modo } = editando;
  const valores = {};
  const erros = {};
  if (modo !== 'senha') {
    valores.nome = form.nome.value.trim();
    if (valores.nome.length < 2) erros.nome = 'Informe o nome.';
    const perfil = form.querySelector('[name="perfil"]:checked');
    if (perfil && !perfil.disabled) valores.perfil = perfil.value;
  }
  if (modo === 'novo') {
    valores.login = form.login.value.trim().toLowerCase();
    if (!/^[a-z0-9._-]{3,30}$/.test(valores.login)) erros.login = 'Use de 3 a 30 caracteres: letras minúsculas, números, ponto, hífen ou sublinhado.';
    if (!valores.perfil) erros.perfil = 'Escolha um perfil.';
  }
  if (modo === 'editar' && !form.ativo.disabled) valores.ativo = form.ativo.checked;
  if (modo !== 'editar') {
    valores.senha = form.senha.value;
    if (valores.senha.length < 8) erros.senha = 'A senha precisa de pelo menos 8 caracteres.';
  }
  return { valores, erros };
}

// ---------- Eventos ----------
delegar(conteudo, 'click', '[data-novo]', () => abrirFormulario('novo'));
delegar(conteudo, 'click', '[data-editar]', (e, botao) => {
  abrirFormulario('editar', usuarios.find((u) => u.id === Number(botao.closest('[data-id]').dataset.id)));
});
delegar(conteudo, 'click', '[data-senha]', (e, botao) => {
  abrirFormulario('senha', usuarios.find((u) => u.id === Number(botao.closest('[data-id]').dataset.id)));
});

delegar(lateral, 'click', '[data-mostrar-senha]', (e, botao) => {
  const entrada = $('#usuario-senha');
  const mostrar = entrada.type === 'password';
  entrada.type = mostrar ? 'text' : 'password';
  botao.setAttribute('aria-pressed', String(mostrar));
  botao.setAttribute('aria-label', mostrar ? 'Esconder a senha' : 'Mostrar a senha');
  botao.innerHTML = icone(mostrar ? 'olho-fechado' : 'olho', 18);
});

lateral.addEventListener('submit', async (e) => {
  if (e.target.id !== 'form-usuario' || !editando) return;
  e.preventDefault();
  const { valores, erros } = lerFormulario(e.target);
  mostrarErros(erros);
  if (Object.keys(erros).length) return;

  const { modo, usuario } = editando;
  const botao = lateral.querySelector('button[type="submit"]');
  botao.setAttribute('aria-busy', 'true');
  try {
    if (modo === 'novo') {
      const criado = await api('/api/admin/usuarios', { metodo: 'POST', corpo: valores });
      avisar(`${criado.nome} pode entrar com o login ${criado.login}`);
    } else if (modo === 'editar') {
      await api(`/api/admin/usuarios/${usuario.id}`, { metodo: 'PUT', corpo: valores });
      avisar(`${valores.nome} atualizado`);
    } else {
      await api(`/api/admin/usuarios/${usuario.id}/senha`, { metodo: 'POST', corpo: { senha: valores.senha } });
      avisar(`Senha de ${usuario.nome} trocada`);
    }
    await fecharFolha(lateral);
    await carregar();
  } catch (erro) {
    botao.removeAttribute('aria-busy');
    if (erro.codigo === 'validacao') mostrarErros(erro.campos ?? {});
    else mostrarErro(erro);
  }
});

export async function montar() {
  await carregar();
}
