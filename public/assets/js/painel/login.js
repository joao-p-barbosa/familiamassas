// Login do painel.
import { $ } from '../shared/dom.js';
import { api } from '../shared/api.js';

const form = $('#form-login');
const erro = $('#erro-login');
const botao = form.querySelector('button[type="submit"]');

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
