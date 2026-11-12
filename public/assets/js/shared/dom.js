// Ajudantes pequenos de DOM. O HTML das telas é montado com template strings;
// TODO texto que vem do banco ou do usuário passa por esc() antes de entrar no HTML.

const TROCAS = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

/** Escapa texto para uso dentro de HTML (conteúdo ou atributo). */
export const esc = (texto) => String(texto ?? '').replace(/[&<>"']/g, (c) => TROCAS[c]);

export const $ = (seletor, raiz = document) => raiz.querySelector(seletor);
export const $$ = (seletor, raiz = document) => [...raiz.querySelectorAll(seletor)];

/**
 * Escuta um evento em `raiz` e chama `acao` só quando o alvo (ou um pai dele) casa com o seletor.
 * Assim a lista pode ser redesenhada à vontade sem perder os cliques.
 */
export function delegar(raiz, evento, seletor, acao) {
  raiz.addEventListener(evento, (e) => {
    const alvo = e.target instanceof Element ? e.target.closest(seletor) : null;
    if (alvo && raiz.contains(alvo)) acao(e, alvo);
  });
}

/** O usuário pediu menos movimento no sistema? */
export const menosMovimento = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** Lê JSON embutido na página: <script type="application/json" id="..."> */
export function dadosDaPagina(id) {
  const el = document.getElementById(id);
  if (!el) return null;
  try {
    return JSON.parse(el.textContent);
  } catch {
    return null;
  }
}

/** localStorage que nunca derruba a página (modo privado, cota cheia, bloqueado). */
export const guardado = {
  ler(chave, padrao = null) {
    try {
      const cru = localStorage.getItem(chave);
      return cru === null ? padrao : JSON.parse(cru);
    } catch {
      return padrao;
    }
  },
  gravar(chave, valor) {
    try {
      localStorage.setItem(chave, JSON.stringify(valor));
      return true;
    } catch {
      return false;
    }
  },
  apagar(chave) {
    try {
      localStorage.removeItem(chave);
    } catch {
      /* sem armazenamento: segue sem lembrar */
    }
  },
};
