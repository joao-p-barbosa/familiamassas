// Roteador do painel (SPA, sem framework e sem build).
//
// O navegador carrega a casca UMA vez. Depois, trocar de tela é: fechar o que estiver aberto, esconder a tela de agora,
// importar o módulo da próxima (só na primeira vez) e chamar montar(). O endereço muda com a History API, então o botão
// voltar, o F5 e o link direto continuam funcionando (o servidor entrega a mesma casca para qualquer /painel/{tela}).
//
// Contrato de cada tela (módulo em /assets/js/painel/<tela>.js):
//   * no topo do módulo, `const raiz = recipienteDaTela()` pega o contêiner dela (criado aqui) e liga os eventos nele, uma vez só;
//   * `export async function montar({ consulta })`: busca os dados e desenha em `raiz`. Roda toda vez que a tela abre;
//   * `export function desmontar()` (opcional): para o que ela ligou (consultas periódicas, escutas do fluxo de pedidos).
import { $ } from '../shared/dom.js';
import { fecharFolha } from '../shared/folha.js';
import { icone } from '../shared/icones.js';
import { conteudo, telas, inicio, definirRecipiente, fecharMenuDoCelular } from './casca.js';

const MODULOS = {
  pedidos: () => import('./pedidos.js'),
  salao: () => import('./salao.js'),
  atendimento: () => import('./atendimento.js'),
  historico: () => import('./historico.js'),
  cardapio: () => import('./cardapio.js'),
  configuracoes: () => import('./configuracoes.js'),
  whatsapp: () => import('./whatsapp.js'),
};

const abertas = new Map();   // nome → { raiz, modulo }: cada tela fica no DOM (escondida) depois de aberta
let atual = null;            // { nome, caminho, consulta }
let fila = Promise.resolve(); // uma navegação por vez: o módulo novo precisa pegar o contêiner certo
let primeira = true;

const nomeDoMenu = (nome) => telas[nome]?.menu_ativo ?? nome;

function analisar(endereco) {
  const url = new URL(endereco, location.origin);
  const achou = url.pathname.match(/^\/painel\/([a-z]+)\/?$/);
  return { nome: achou?.[1] ?? null, caminho: url.pathname.replace(/\/$/, '') + url.search, consulta: url.searchParams };
}

/**
 * Vai para outra tela do painel sem recarregar a página.
 * @param {string} destino  ex.: "/painel/salao?novo=1"
 * @param {{ substituir?: boolean }} opcoes  substituir = não cria uma entrada nova no histórico (use ao redirecionar)
 */
export function navegar(destino, { substituir = false } = {}) {
  fila = fila.then(() => abrir(analisar(destino), substituir ? 'substituir' : 'empurrar')).catch(() => {});
  return fila;
}

async function fecharJanelasAbertas() {
  for (const janela of document.querySelectorAll('dialog[open]')) await fecharFolha(janela);
}

function marcarMenu(nome) {
  const ativo = `/painel/${nomeDoMenu(nome)}`;
  document.querySelectorAll('.lateral__item').forEach((link) => {
    if (new URL(link.href).pathname === ativo) link.setAttribute('aria-current', 'page');
    else link.removeAttribute('aria-current');
  });
}

function mostrarFalha(alvo) {
  const lugar = $('#tela-carregando');
  lugar.hidden = false;
  lugar.innerHTML = `
    <div class="vazio">
      <span class="vazio__icone">${icone('alerta', 40)}</span>
      <p class="vazio__titulo">Não conseguimos abrir esta tela</p>
      <p>Confira a internet e tente de novo.</p>
      <button type="button" class="botao" id="tela-tentar">Tentar de novo</button>
    </div>`;
  $('#tela-tentar').addEventListener('click', () => navegar(alvo.caminho, { substituir: true }));
}

async function abrir(alvo, historico) {
  // Endereço que não existe, ou que este perfil não abre: vai para a tela inicial dele.
  if (!alvo.nome || !telas[alvo.nome] || !MODULOS[alvo.nome]) {
    alvo = analisar(inicio);
    historico = 'substituir';
  }
  if (atual && atual.caminho === alvo.caminho && historico !== 'forcar') {
    return; // já está aqui
  }

  await fecharJanelasAbertas();
  fecharMenuDoCelular();
  if (atual) {
    const saindo = abertas.get(atual.nome);
    saindo?.modulo.desmontar?.();
    if (saindo) saindo.raiz.hidden = true;
  }

  if (historico === 'empurrar') history.pushState({ rota: alvo.caminho }, '', alvo.caminho);
  else if (historico === 'substituir') history.replaceState({ rota: alvo.caminho }, '', alvo.caminho);
  atual = alvo;
  marcarMenu(alvo.nome);
  const titulo = telas[alvo.nome].titulo;
  document.title = `${titulo} · ${document.documentElement.dataset.loja ?? ''}`.replace(/ · $/, '');

  let tela = abertas.get(alvo.nome);
  const carregando = $('#tela-carregando');
  if (!tela) {
    carregando.hidden = false;
    carregando.innerHTML = '<div class="esqueleto esqueleto--linha" style="width: 12rem; height: 2rem;"></div><div class="esqueleto esqueleto--cartao" style="height: 14rem; margin-top: var(--e-6);"></div>';
    const raiz = document.createElement('div');
    raiz.className = 'tela';
    raiz.dataset.tela = alvo.nome;
    raiz.hidden = true;
    conteudo.append(raiz);
    definirRecipiente(raiz);   // o módulo pega o contêiner dele quando é executado (agora, durante o import)
    try {
      tela = { raiz, modulo: await MODULOS[alvo.nome]() };
    } catch {
      raiz.remove();
      mostrarFalha(alvo);
      return;
    }
    abertas.set(alvo.nome, tela);
  }
  carregando.hidden = true;
  tela.raiz.hidden = false;
  await tela.modulo.montar?.({ consulta: alvo.consulta, caminho: alvo.caminho });

  $('#anuncio-rota').textContent = titulo;
  window.scrollTo(0, 0);
  if (!primeira && !document.querySelector('dialog[open]')) {
    // Quem usa teclado ou leitor de tela começa a nova tela pelo título, como numa página nova
    // (menos quando a tela já abriu uma janela, como o assistente de "Novo pedido": o foco fica nela).
    const cabecalho = tela.raiz.querySelector('h1');
    if (cabecalho) cabecalho.tabIndex = -1;
    (cabecalho ?? conteudo).focus({ preventScroll: true });
  }
  primeira = false;
}

// ---------- Ligação com o navegador ----------

// Clique em link do painel: troca a tela sem recarregar (com Ctrl, Cmd, botão do meio ou "nova aba", o navegador faz o normal).
document.addEventListener('click', (e) => {
  if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
  const link = e.target instanceof Element ? e.target.closest('a[href]') : null;
  if (!link || link.target === '_blank' || link.hasAttribute('download')) return;
  const url = new URL(link.href, location.href);
  if (url.origin !== location.origin || !/^\/painel\/[a-z]+\/?$/.test(url.pathname) || url.pathname === '/painel/login') return;
  e.preventDefault();
  navegar(url.pathname + url.search);
});

// Botão voltar/avançar. Fechar uma janela (folha) também mexe no histórico, mas o endereço continua o mesmo: não é troca de tela.
window.addEventListener('popstate', () => {
  const alvo = analisar(location.href);
  if (atual && alvo.caminho === atual.caminho) return;
  fila = fila.then(() => abrir(alvo, 'nenhum')).catch(() => {});
});

/** Abre a tela do endereço atual (chamado uma vez, quando a casca termina de carregar). */
export function iniciarRoteador() {
  history.scrollRestoration = 'manual';
  fila = fila.then(() => abrir(analisar(location.href), 'substituir')).catch(() => {});
  return fila;
}

/** Tira o "?parametro" do endereço de agora sem trocar de tela (ex.: depois de abrir o assistente de "?novo=1"). */
export function limparConsulta() {
  if (!atual || !atual.caminho.includes('?')) return;
  const limpo = atual.caminho.split('?')[0];
  history.replaceState({ rota: limpo }, '', limpo);
  atual = { ...atual, caminho: limpo, consulta: new URLSearchParams() };
}
