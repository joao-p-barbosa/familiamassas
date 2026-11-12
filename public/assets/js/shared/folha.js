// Folha: o <dialog> que vira bottom sheet no celular e modal/painel lateral em tela larga.
// Cuida de três coisas que o <dialog> puro não faz:
//   1. botão "voltar" do celular fecha a folha (em vez de sair da página);
//   2. arrastar o topo para baixo fecha (com velocidade: um peteleco basta);
//   3. clicar fora fecha.
import { menosMovimento } from './dom.js';

const pilha = []; // folhas abertas, da mais antiga para a mais nova

/**
 * Abre um <dialog class="folha">.
 * @param {HTMLDialogElement} dialogo
 * @param {{ aoFechar?: () => void }} opcoes
 */
export function abrirFolha(dialogo, { aoFechar } = {}) {
  if (dialogo.open) return;
  dialogo._aoFechar = aoFechar;
  dialogo.style.transform = '';
  dialogo.showModal();
  pilha.push(dialogo);
  history.pushState({ ...history.state, folha: pilha.length }, '');
  prepararUmaVez(dialogo);
}

/**
 * Fecha a folha (e desfaz a entrada que ela criou no histórico do navegador).
 * Devolve uma Promise que termina quando a folha fechou de verdade: use `await` antes de abrir outra.
 */
export function fecharFolha(dialogo) {
  if (!dialogo.open) return Promise.resolve();
  const fechou = new Promise((pronto) => dialogo.addEventListener('close', () => pronto(), { once: true }));
  if (pilha.at(-1) === dialogo && history.state?.folha === pilha.length) {
    history.back(); // o popstate abaixo faz o fechamento de verdade
  } else {
    fecharDeVerdade(dialogo);
  }
  return fechou;
}

function fecharDeVerdade(dialogo) {
  const posicao = pilha.indexOf(dialogo);
  if (posicao >= 0) pilha.splice(posicao, 1);
  dialogo.close();
  dialogo._aoFechar?.();
}

// Botão "voltar" (ou history.back()): fecha a folha do topo.
window.addEventListener('popstate', () => {
  const esperadas = history.state?.folha ?? 0;
  while (pilha.length > esperadas) fecharDeVerdade(pilha.at(-1));
});

function prepararUmaVez(dialogo) {
  if (dialogo._preparada) return;
  dialogo._preparada = true;

  // Esc: o navegador fecharia sozinho, mas sem avisar a pilha. Assumimos o controle.
  dialogo.addEventListener('cancel', (e) => {
    e.preventDefault();
    fecharFolha(dialogo);
  });
  // Clique no fundo escuro (o clique cai no próprio <dialog>, fora do conteúdo).
  dialogo.addEventListener('click', (e) => {
    if (e.target === dialogo) fecharFolha(dialogo);
    else if (e.target instanceof Element && e.target.closest('[data-fechar]')) fecharFolha(dialogo);
  });

  // Arrastar o topo para baixo (só onde a folha é bottom sheet: telas estreitas).
  const topo = dialogo.querySelector('.folha__topo');
  if (!topo) return;
  let inicioY = 0;
  let inicioT = 0;
  let deslocamento = 0;
  let ponteiro = null;

  topo.addEventListener('pointerdown', (e) => {
    if (ponteiro !== null || window.innerWidth >= 768 || dialogo.classList.contains('folha--cheia')) return;
    if (e.target instanceof Element && e.target.closest('button, a, input')) return;
    ponteiro = e.pointerId;
    inicioY = e.clientY;
    inicioT = e.timeStamp;
    deslocamento = 0;
    topo.setPointerCapture(ponteiro);
    dialogo.dataset.arrastando = '';
  });
  topo.addEventListener('pointermove', (e) => {
    if (e.pointerId !== ponteiro) return;
    const delta = e.clientY - inicioY;
    // Para cima a folha resiste (atrito crescente) em vez de travar seca.
    deslocamento = delta >= 0 ? delta : -Math.sqrt(-delta) * 2;
    dialogo.style.transform = `translateY(${deslocamento}px)`;
  });
  const soltar = (e) => {
    if (e.pointerId !== ponteiro) return;
    ponteiro = null;
    delete dialogo.dataset.arrastando;
    const velocidade = deslocamento / Math.max(1, e.timeStamp - inicioT); // px por ms
    const fechar = deslocamento > dialogo.offsetHeight * 0.35 || (velocidade > 0.5 && deslocamento > 24);
    if (fechar && !menosMovimento()) {
      dialogo.style.transform = 'translateY(100%)';
      setTimeout(() => {
        fecharFolha(dialogo);
        dialogo.style.transform = '';
      }, 180);
    } else if (fechar) {
      fecharFolha(dialogo);
      dialogo.style.transform = '';
    } else {
      dialogo.style.transform = '';
    }
  };
  topo.addEventListener('pointerup', soltar);
  topo.addEventListener('pointercancel', soltar);
}
