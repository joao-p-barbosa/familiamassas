// Avisos rápidos (toasts). Uso: avisar('Adicionado ao carrinho')  ou  avisar('Sem conexão', { tipo: 'erro' })
import { esc } from './dom.js';
import { icone } from './icones.js';

let regiao = null;

function garantirRegiao() {
  if (!regiao) {
    regiao = document.createElement('div');
    regiao.className = 'avisos';
    // role=status: o leitor de tela anuncia sem roubar o foco.
    regiao.setAttribute('role', 'status');
    regiao.setAttribute('aria-live', 'polite');
    document.body.append(regiao);
  }
  // Diálogos abertos ficam numa camada acima de tudo: o aviso precisa entrar dentro do que está aberto.
  const aberto = [...document.querySelectorAll('dialog[open]')].at(-1);
  const destino = aberto ?? document.body;
  if (regiao.parentElement !== destino) destino.append(regiao);
  return regiao;
}

/**
 * @param {string} texto
 * @param {{ tipo?: 'ok'|'erro', duracao?: number, acao?: { rotulo: string, aoClicar: () => void } }} opcoes
 */
export function avisar(texto, { tipo = 'ok', duracao = 3200, acao } = {}) {
  const lista = garantirRegiao();
  const el = document.createElement('div');
  el.className = `aviso${tipo === 'erro' ? ' aviso--erro' : ''}`;
  el.innerHTML = `${icone(tipo === 'erro' ? 'alerta' : 'check', 18)}<span>${esc(texto)}</span>${
    acao ? `<button type="button" class="aviso__acao">${esc(acao.rotulo)}</button>` : ''
  }`;

  let relogio;
  const sair = () => {
    clearTimeout(relogio);
    el.dataset.saindo = '';
    setTimeout(() => el.remove(), 200);
  };
  el.querySelector('.aviso__acao')?.addEventListener('click', () => {
    acao.aoClicar();
    sair();
  });
  // No máximo 3 avisos na tela: o mais antigo sai.
  while (lista.children.length >= 3) lista.firstElementChild.remove();
  lista.append(el);
  relogio = setTimeout(sair, tipo === 'erro' ? Math.max(duracao, 5000) : duracao);
  return sair;
}
