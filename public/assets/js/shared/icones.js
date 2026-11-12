// Ícones do sistema: um traço só (1.75), cantos redondos, grade de 24.
// Uso: icone('sacola')  →  string com o <svg>. Sempre decorativo (aria-hidden); o texto ao lado dá o nome.

const TRACOS = {
  busca: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.8-3.8"/>',
  sacola: '<path d="M5 8h14l-1.2 11.2a1 1 0 0 1-1 .8H7.2a1 1 0 0 1-1-.8L5 8Z"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8"/>',
  mais: '<path d="M12 5v14M5 12h14"/>',
  menos: '<path d="M5 12h14"/>',
  fechar: '<path d="m6 6 12 12M18 6 6 18"/>',
  'seta-dir': '<path d="M5 12h14m-6-6 6 6-6 6"/>',
  'seta-esq': '<path d="M19 12H5m6-6-6 6 6 6"/>',
  'seta-cima': '<path d="M12 19V5m-6 6 6-6 6 6"/>',
  'seta-baixo': '<path d="M12 5v14m-6-6 6 6 6-6"/>',
  'divisa-dir': '<path d="m9 6 6 6-6 6"/>',
  check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
  relogio: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
  pin: '<path d="M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0C18.5 15.4 12 21 12 21Z"/><circle cx="12" cy="10" r="2.3"/>',
  moto: '<circle cx="6" cy="16.5" r="3"/><circle cx="18" cy="16.5" r="3"/><path d="M9 16.5h6l-2.5-7H10m5.5 0H18l1 2.5M6 16.5l3-5h4"/>',
  loja: '<path d="M4 10v9a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-9"/><path d="M3 10 5 4h14l2 6a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0Z"/><path d="M10 20v-5h4v5"/>',
  talheres: '<path d="M7 3v7a2 2 0 0 0 2 2v9M5 3v5m4-5v5M17 21V3c-2 1.5-3.5 4-3.5 7.5 0 1.7 1.3 2.5 3.5 2.5"/>',
  impressora: '<path d="M7 9V4h10v5"/><rect x="3.5" y="9" width="17" height="8" rx="1.5"/><path d="M7 14h10v6H7z"/>',
  conversa: '<path d="M20 12a8 8 0 0 1-11.8 7L4 20l1.1-3.9A8 8 0 1 1 20 12Z"/>',
  editar: '<path d="m4 20 1-4L16.5 4.5a2 2 0 0 1 3 3L8 19l-4 1Z"/><path d="m14.5 6.5 3 3"/>',
  lixeira: '<path d="M4 7h16M9 7V4.5h6V7m3 0-.8 12a1 1 0 0 1-1 1H7.8a1 1 0 0 1-1-1L6 7m4 4v5m4-5v5"/>',
  olho: '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="2.8"/>',
  'olho-fechado': '<path d="M4 4l16 16M10 6c.6-.2 1.3-.5 2-.5 6 0 9.5 6.5 9.5 6.5s-1 1.9-3 3.7M6.6 7.7C4 9.5 2.5 12 2.5 12S6 18.5 12 18.5c1.4 0 2.7-.4 3.8-.9"/>',
  som: '<path d="M4 9.5v5h3.5L12 18.5v-13L7.5 9.5H4Z"/><path d="M15.5 9a4 4 0 0 1 0 6m2.5-8.5a7.5 7.5 0 0 1 0 11"/>',
  'som-mudo': '<path d="M4 9.5v5h3.5L12 18.5v-13L7.5 9.5H4Z"/><path d="m16 9.5 5 5m0-5-5 5"/>',
  alerta: '<path d="M12 4 2.8 19.5h18.4L12 4Z"/><path d="M12 10v4.5m0 2.5v.1"/>',
  info: '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5.5m0-9v.1"/>',
  sair: '<path d="M14 4h4.5a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H14M10 8l-4 4 4 4m-4-4h10"/>',
  pix: '<path d="m12 3.5 8.5 8.5-8.5 8.5L3.5 12 12 3.5Z"/><path d="m8 8 4 4 4-4M8 16l4-4 4 4"/>',
  cartao: '<rect x="3" y="5.5" width="18" height="13" rx="2"/><path d="M3 10h18M7 15h3"/>',
  dinheiro: '<rect x="3" y="6.5" width="18" height="11" rx="1.5"/><circle cx="12" cy="12" r="2.5"/><path d="M6.5 9.5v5m11-5v5"/>',
  arrastar: '<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>',
  imagem: '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><circle cx="9" cy="10" r="1.8"/><path d="m4 17 5-4.5 4 3.5 3-2.5 4.5 4"/>',
  ajustes: '<path d="M4 7h9m4 0h3M4 17h3m4 0h9"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/>',
  panela: '<path d="M4 10h16v6a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4v-6Zm-2 0h20M9 6.5c0-1.5 1.5-1.5 1.5-3M13.5 6.5c0-1.5 1.5-1.5 1.5-3"/>',
  quadro: '<rect x="3.5" y="4" width="5" height="16" rx="1"/><rect x="10" y="4" width="5" height="10" rx="1"/><rect x="16.5" y="4" width="4" height="13" rx="1"/>',
  livro: '<path d="M5 4.5h11a3 3 0 0 1 3 3V20H8a3 3 0 0 1-3-3V4.5Z"/><path d="M5 17a3 3 0 0 1 3-3h11M9 8.5h6"/>',
  usuario: '<circle cx="12" cy="8.5" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/>',
  recarregar: '<path d="M19.5 12a7.5 7.5 0 1 1-2.3-5.4M19.5 4.5v4.5H15"/>',
  desfazer: '<path d="M8 6 4 10l4 4"/><path d="M4 10h10a5 5 0 0 1 0 10h-3"/>',
  ligacao: '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3A4 4 0 0 0 11 18.7l1-1"/>',
};

// Formas de massa (pictogramas próprios, usados no passo "Massa" do montador).
const MASSAS = {
  penne: '<path d="M6.5 20 4 17.5 16.5 5c1-1 3.5 1.5 2.5 2.5L6.5 20Z"/><path d="m9 12.5 2.5 2.5M12 9.5l2.5 2.5"/>',
  espaguete: '<path d="M4 7c4-3 12-3 16 0M4 11c4-3 12-3 16 0M4 15c4-3 12-3 16 0M4 19c4-3 12-3 16 0"/>',
  fusilli: '<path d="M8 3.5c5 1 5 4 0 5 5 1 5 4 0 5 5 1 5 4 0 5M16 5.5c-5 1-5 4 0 5-5 1-5 4 0 5-5 1-5 4 0 5"/>',
  talharim: '<path d="M4 6.5c3-2.5 5 2.5 8 0s5 2.5 8 0M4 12c3-2.5 5 2.5 8 0s5 2.5 8 0M4 17.5c3-2.5 5 2.5 8 0s5 2.5 8 0"/>',
  nhoque: '<ellipse cx="12" cy="12" rx="8" ry="5.5" transform="rotate(-20 12 12)"/><path d="m8.5 10 1 4.5M12 8.8l1 5.5M15.5 8l1 4.5"/>',
  generica: '<path d="M12 21V8"/><path d="M12 8c0-2.5 1.5-4 3.5-4.5C15.5 6 14 8 12 8Zm0 0C12 5.5 10.5 4 8.5 3.5 8.5 6 10 8 12 8Zm0 5.5c0-2.5 1.5-4 3.5-4.5 0 2.5-1.5 4.5-3.5 4.5Zm0 0c0-2.5-1.5-4-3.5-4.5 0 2.5 1.5 4.5 3.5 4.5Zm0 5c0-2.5 1.5-4 3.5-4.5 0 2.5-1.5 4.5-3.5 4.5Zm0 0c0-2.5-1.5-4-3.5-4.5 0 2.5 1.5 4.5 3.5 4.5Z"/>',
};

const svg = (tracos, tamanho) =>
  `<svg width="${tamanho}" height="${tamanho}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${tracos}</svg>`;

export const icone = (nome, tamanho = 20) => svg(TRACOS[nome] ?? TRACOS.info, tamanho);

/** Pictograma da forma da massa, escolhido pelo nome ("Penne", "Nhoque"...). Nome desconhecido → espiga. */
export function iconeMassa(nome, tamanho = 32) {
  const chave = String(nome ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const forma = Object.keys(MASSAS).find((m) => chave.includes(m)) ?? 'generica';
  return svg(MASSAS[forma], tamanho);
}
