// Alerta sonoro de pedido novo. O navegador só deixa tocar som depois de um toque/clique na página,
// por isso existe o botão "Som" (o toque nele libera o áudio).
import { guardado } from '../shared/dom.js';

const CHAVE = 'fm_som_v1';
let contexto = null;

export const somLigado = () => guardado.ler(CHAVE, false) === true;

/** Liga/desliga. Precisa ser chamado dentro de um clique (é o que libera o áudio). */
export function alternarSom() {
  const ligado = !somLigado();
  guardado.gravar(CHAVE, ligado);
  if (ligado) {
    prepararAudio();
    tocarAlerta();
  }
  return ligado;
}

function prepararAudio() {
  try {
    contexto ??= new (window.AudioContext || window.webkitAudioContext)();
    if (contexto.state === 'suspended') contexto.resume();
  } catch {
    contexto = null;
  }
}

// Depois de recarregar a página, o primeiro toque em qualquer lugar libera o áudio de novo.
document.addEventListener('pointerdown', () => somLigado() && prepararAudio(), { once: true });

/** Dois toques curtos ("din-don"). Não faz nada se o som estiver desligado ou bloqueado. */
export function tocarAlerta() {
  if (!somLigado()) return;
  prepararAudio();
  if (!contexto || contexto.state !== 'running') return;
  const agora = contexto.currentTime;
  for (const [inicio, frequencia] of [[0, 880], [0.18, 660]]) {
    const oscilador = contexto.createOscillator();
    const volume = contexto.createGain();
    oscilador.type = 'sine';
    oscilador.frequency.value = frequencia;
    volume.gain.setValueAtTime(0.0001, agora + inicio);
    volume.gain.exponentialRampToValueAtTime(0.4, agora + inicio + 0.02);
    volume.gain.exponentialRampToValueAtTime(0.0001, agora + inicio + 0.32);
    oscilador.connect(volume).connect(contexto.destination);
    oscilador.start(agora + inicio);
    oscilador.stop(agora + inicio + 0.35);
  }
}
