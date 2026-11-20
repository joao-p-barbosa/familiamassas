// Som de aviso do cliente quando o status do pedido muda (D94). Cada etapa tem a sua melodia, para a pessoa saber
// o que aconteceu sem olhar a tela: preparo começou, está pronto, saiu para entrega, pode buscar, concluído, cancelado.
// O navegador só toca som depois de um toque na página, por isso existe o botão "Avisar com som" (o toque nele libera o áudio).
// Depois de recarregar a página, o primeiro toque em qualquer lugar libera de novo.
import { guardado } from '../shared/dom.js';
import { icone } from '../shared/icones.js';

const CHAVE = 'fm_som_status_v1';
let contexto = null;

export const somDeStatusLigado = () => guardado.ler(CHAVE, false) === true;

function prepararAudio() {
  try {
    contexto ??= new (window.AudioContext || window.webkitAudioContext)();
    if (contexto.state === 'suspended') contexto.resume();
  } catch {
    contexto = null;
  }
}
document.addEventListener('pointerdown', () => somDeStatusLigado() && prepararAudio(), { once: true });

/**
 * Notas: [quando (s), frequência (Hz)]. Cada melodia tem um desenho que se distingue de olhos fechados:
 * subir de dois em dois (preparo), três notas subindo (pronto), batidas curtas repetidas (saiu para entrega, a moto),
 * sino agudo (pode buscar), descida que resolve (concluído) e duas notas graves caindo (cancelado).
 */
const MELODIAS = {
  em_preparo: { onda: 'triangle', duracao: 0.30, notas: [[0, 523], [0.18, 659]], vibra: [120] },
  pronto: { onda: 'sine', duracao: 0.34, notas: [[0, 523], [0.15, 659], [0.30, 784]], vibra: [100, 60, 100] },
  saiu_entrega: { onda: 'triangle', duracao: 0.14, notas: [[0, 392], [0.14, 392], [0.28, 523], [0.46, 523], [0.60, 659]], vibra: [60, 40, 60, 40, 160] },
  aguardando_retirada: { onda: 'sine', duracao: 0.9, notas: [[0, 1047], [0.30, 1319], [0.60, 1568]], vibra: [200, 80, 200] },
  concluido: { onda: 'sine', duracao: 0.38, notas: [[0, 784], [0.18, 659], [0.36, 523], [0.62, 659]], vibra: [90] },
  cancelado: { onda: 'sawtooth', duracao: 0.55, volume: 0.22, notas: [[0, 330], [0.30, 247]], vibra: [300] },
};

/** Toca a melodia da etapa. Sem som para a etapa que não tem melodia ("novo" é o pedido que acabou de chegar). */
export function tocarStatus(status) {
  const m = MELODIAS[status];
  if (!m || !somDeStatusLigado()) return false;
  prepararAudio();
  try {
    navigator.vibrate?.(m.vibra);
  } catch { /* alguns aparelhos recusam sem toque */ }
  if (!contexto || contexto.state !== 'running') return false;
  const agora = contexto.currentTime;
  const pico = m.volume ?? 0.4;
  for (const [inicio, frequencia] of m.notas) {
    const oscilador = contexto.createOscillator();
    const volume = contexto.createGain();
    oscilador.type = m.onda;
    oscilador.frequency.value = frequencia;
    volume.gain.setValueAtTime(0.0001, agora + inicio);
    volume.gain.exponentialRampToValueAtTime(pico, agora + inicio + 0.02);
    volume.gain.exponentialRampToValueAtTime(0.0001, agora + inicio + m.duracao);
    oscilador.connect(volume).connect(contexto.destination);
    oscilador.start(agora + inicio);
    oscilador.stop(agora + inicio + m.duracao + 0.05);
  }
  return true;
}

/** Liga ou desliga. Precisa ser chamado dentro de um toque (é o que libera o áudio). Ao ligar, toca o "pronto" de amostra. */
export function alternarSomDeStatus() {
  const ligado = !somDeStatusLigado();
  guardado.gravar(CHAVE, ligado);
  if (ligado) {
    prepararAudio();
    tocarStatus('pronto');
  }
  return ligado;
}

/** O botão que liga o aviso sonoro. O clique é tratado por quem desenha a página (atributo data-som-status). */
export function botaoSomHtml() {
  const ligado = somDeStatusLigado();
  return `
    <div class="aviso-som">
      <button type="button" class="botao botao--secundario botao--p" data-som-status aria-pressed="${ligado}">
        ${icone(ligado ? 'som' : 'som-mudo', 18)}<span>${ligado ? 'Aviso com som ligado' : 'Avisar com som quando o status mudar'}</span>
      </button>
      <p class="campo__ajuda">Cada etapa do pedido toca um som diferente. Deixe esta página aberta.</p>
    </div>`;
}
