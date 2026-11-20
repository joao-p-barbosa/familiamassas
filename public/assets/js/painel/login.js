// Login do painel.
import { $ } from '../shared/dom.js';
import { api } from '../shared/api.js';
import { icone } from '../shared/icones.js';

const form = $('#form-login');
const erro = $('#erro-login');
const botao = form.querySelector('button[type="submit"]');

// Olhinho: mostra ou esconde a senha digitada (o foco volta para o campo, para a pessoa seguir digitando).
form.querySelector('[data-mostrar-senha]').addEventListener('click', (e) => {
  const botao = e.currentTarget;
  const mostrar = form.senha.type === 'password';
  form.senha.type = mostrar ? 'text' : 'password';
  botao.setAttribute('aria-pressed', String(mostrar));
  botao.setAttribute('aria-label', mostrar ? 'Esconder a senha' : 'Mostrar a senha');
  botao.innerHTML = icone(mostrar ? 'olho-fechado' : 'olho', 18);
  form.senha.focus();
});

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  erro.hidden = true;
  botao.setAttribute('aria-busy', 'true');
  try {
    const r = await api('/api/admin/login', { metodo: 'POST', corpo: { login: form.login.value, senha: form.senha.value } });
    location.assign(r.ir_para);
  } catch (falha) {
    botao.removeAttribute('aria-busy');
    erro.textContent = falha.message;
    erro.hidden = false;
    form.senha.value = '';
    (falha.codigo === 'login_invalido' ? form.senha : form.login).focus();
  }
});
