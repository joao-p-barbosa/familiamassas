// Parte comum a todas as telas do painel: dados da sessão, botão "Sair" e tratamento de sessão expirada.
import { $, dadosDaPagina, esc } from '../shared/dom.js';
import { api } from '../shared/api.js';
import { avisar } from '../shared/avisos.js';
import { abrirFolha, fecharFolha } from '../shared/folha.js';
import { icone } from '../shared/icones.js';

const dados = dadosDaPagina('dados-painel');

export const usuario = dados.usuario;   // { id, nome, login, perfil }
export const ajustes = dados.ajustes;   // { polling_segundos, comanda_largura_mm, comanda_mostra_preco, nome_loja }
export const telas = dados.telas;       // o que este perfil abre: { pedidos: {titulo, menu_ativo}, ... }
export const inicio = dados.inicio;     // tela inicial do perfil ("/painel/salao" ou "/painel/pedidos")
export const ehAdmin = usuario.perfil === 'admin';

// O painel é uma SPA: a página carrega uma vez e o roteador (app.js) troca as telas. Cada tela vive no seu próprio
// contêiner dentro de #conteudo, criado na primeira vez que ela é aberta e mantido (escondido) depois. É por isso que
// as telas ligam seus eventos uma vez só, no contêiner delas, sem vazar para as outras.
// O roteador aponta o contêiner da tela ANTES de importar o módulo dela; o módulo o pega na hora, com recipienteDaTela().
let recipienteEmImportacao = null;
export const definirRecipiente = (elemento) => (recipienteEmImportacao = elemento);
export const recipienteDaTela = () => recipienteEmImportacao;
/** O <main> que contém todas as telas (use `recipienteDaTela()` para desenhar a sua). */
export const conteudo = $('#conteudo');

/** Mostra o erro de uma chamada. Sessão expirada (401) leva de volta ao login. */
export function mostrarErro(erro) {
  if (erro?.status === 401) {
    location.assign('/painel/login');
    return;
  }
  avisar(erro?.message ?? 'Algo deu errado. Tente de novo.', { tipo: 'erro' });
}

$('#botao-sair')?.addEventListener('click', async () => {
  try {
    const r = await api('/api/admin/logout', { metodo: 'POST' });
    location.assign(r.ir_para);
  } catch {
    location.assign('/painel/login');
  }
});

/**
 * Diálogo de confirmação (no lugar do confirm() do navegador, que trava a tela e não segue o tema).
 * Devolve uma Promise: true se confirmou, false se desistiu.
 * @param {{ titulo: string, texto?: string, confirmar: string, perigo?: boolean }} opcoes
 */
export function confirmar({ titulo, texto = '', confirmar: rotulo, perigo = false }) {
  const dialogo = $('#folha-dialogo');
  dialogo.innerHTML = `
    <header class="folha__topo">
      <h2 class="folha__titulo" id="folha-dialogo-titulo">${esc(titulo)}</h2>
      <button type="button" class="botao botao--fantasma botao--icone" data-fechar aria-label="Fechar">${icone('fechar')}</button>
    </header>
    ${texto ? `<div class="folha__corpo"><p>${esc(texto)}</p></div>` : ''}
    <footer class="folha__rodape folha__rodape--acoes">
      <button type="button" class="botao botao--secundario" data-fechar>Voltar</button>
      <button type="button" class="botao ${perigo ? 'botao--perigo' : ''}" id="dialogo-confirmar">${esc(rotulo)}</button>
    </footer>`;
  return new Promise((resolver) => {
    let resposta = false;
    $('#dialogo-confirmar').addEventListener('click', () => {
      resposta = true;
      fecharFolha(dialogo);
    });
    abrirFolha(dialogo, { aoFechar: () => resolver(resposta) });
  });
}

// ---------- Menu lateral ----------
// Desktop: o botão recolhe o menu para só ícones e a escolha vai num cookie (o servidor já devolve a página assim,
// sem piscar). Celular: o menu é uma gaveta que abre pelo botão da barra de cima.
const lateral = $('#menu-lateral');
const fundo = $('#menu-fundo');
const area = $('#area');
const botaoAbrir = $('#menu-abrir');
const botaoFechar = $('#menu-fechar');
const botaoRecolher = $('#menu-recolher');
const desktop = window.matchMedia('(min-width: 64rem)');
let focoAnterior = null;

const gavetaAberta = () => lateral.dataset.aberta === 'true';

function abrirGaveta() {
  focoAnterior = document.activeElement;
  lateral.dataset.aberta = 'true';
  fundo.dataset.visivel = 'true';
  fundo.setAttribute('aria-hidden', 'false');
  botaoAbrir.setAttribute('aria-expanded', 'true');
  document.body.style.overflow = 'hidden'; // a página não rola por trás da gaveta
  area.inert = true;                       // o foco do teclado fica dentro da gaveta
  $('a, button', lateral)?.focus();
}

function fecharGaveta({ devolverFoco = true } = {}) {
  lateral.dataset.aberta = 'false';
  fundo.dataset.visivel = 'false';
  fundo.setAttribute('aria-hidden', 'true');
  botaoAbrir.setAttribute('aria-expanded', 'false');
  document.body.style.overflow = '';
  area.inert = false;
  if (devolverFoco && focoAnterior instanceof HTMLElement) focoAnterior.focus();
}

botaoAbrir.addEventListener('click', () => (gavetaAberta() ? fecharGaveta() : abrirGaveta()));
botaoFechar.addEventListener('click', () => fecharGaveta());
fundo.addEventListener('click', () => fecharGaveta());
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && gavetaAberta()) fecharGaveta();
});
// Girou o tablet ou aumentou a janela com a gaveta aberta: no desktop o menu já está à mostra.
desktop.addEventListener('change', (e) => {
  if (e.matches && gavetaAberta()) fecharGaveta({ devolverFoco: false });
});

let fimDaAnimacao = 0;
botaoRecolher.addEventListener('click', () => {
  const recolhido = document.body.classList.toggle('painel--recolhido');
  const rotulo = recolhido ? 'Expandir o menu' : 'Recolher o menu';
  botaoRecolher.setAttribute('aria-expanded', String(!recolhido));
  botaoRecolher.setAttribute('aria-label', rotulo);
  botaoRecolher.setAttribute('title', rotulo);
  document.cookie = `painel_menu=${recolhido ? 'recolhido' : 'aberto'}; path=/; max-age=31536000; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`;
  // Ao abrir, os rótulos surgem com um pequeno deslocamento; a classe sai logo depois.
  clearTimeout(fimDaAnimacao);
  document.body.classList.toggle('painel--animando', !recolhido);
  if (!recolhido) fimDaAnimacao = setTimeout(() => document.body.classList.remove('painel--animando'), 320);
  window.dispatchEvent(new Event('resize')); // a área de conteúdo mudou de largura
});

/** O roteador chama ao trocar de tela: no celular, a gaveta do menu fecha sozinha. */
export const fecharMenuDoCelular = () => {
  if (gavetaAberta()) fecharGaveta({ devolverFoco: false });
};
