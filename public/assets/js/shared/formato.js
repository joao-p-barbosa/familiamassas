// Formatação para pt-BR: dinheiro, telefone, horas e tempo decorrido.

const moeda = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

/** 3190 → "R$ 31,90" (o sistema guarda dinheiro em centavos) */
export const reais = (centavos) => moeda.format((centavos || 0) / 100);

/** 400 → "+ R$ 4,00"; 0 → "" */
export const acrescimo = (centavos) => (centavos > 0 ? `+ ${reais(centavos)}` : '');

/** "31,90" ou "31.9" digitado → 3190. Devolve null se não for um valor. */
export function centavosDeTexto(texto) {
  const limpo = String(texto ?? '').replace(/[^\d,.]/g, '').replace(/\.(?=\d{3}(\D|$))/g, '').replace(',', '.');
  if (limpo === '' || Number.isNaN(Number(limpo))) return null;
  return Math.round(Number(limpo) * 100);
}

/** 3190 → "31,90" (para preencher campo de preço) */
export const textoDeCentavos = (centavos) => ((centavos || 0) / 100).toFixed(2).replace('.', ',');

/** "5511912345678" → "(11) 91234-5678" */
export function telefone(digitos) {
  let d = String(digitos ?? '').replace(/\D/g, '');
  if (d.length >= 12 && d.startsWith('55')) d = d.slice(2);
  if (d.length <= 2) return d;
  if (d.length <= 6) return `(${d.slice(0, 2)}) ${d.slice(2)}`;
  if (d.length <= 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;
  return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7, 11)}`;
}

const horaCurta = new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' });
const diaHora = new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });

/** ISO → "19:42" */
export const hora = (iso) => (iso ? horaCurta.format(new Date(iso)) : '');

/** ISO → "11/11, 19:42" */
export const dataHora = (iso) => (iso ? diaHora.format(new Date(iso)) : '');

/** Minutos inteiros entre um ISO e agora. */
export const minutosDesde = (iso, agora = Date.now()) => Math.max(0, Math.floor((agora - new Date(iso).getTime()) / 60000));

/** 7 → "7 min"; 75 → "1 h 15" */
export function duracao(minutos) {
  if (minutos < 60) return `${minutos} min`;
  const h = Math.floor(minutos / 60);
  const m = minutos % 60;
  return m === 0 ? `${h} h` : `${h} h ${String(m).padStart(2, '0')}`;
}

/** plural(2, 'item', 'itens') → "2 itens" */
export const plural = (n, um, varios) => `${n} ${n === 1 ? um : varios}`;

/** Remove acentos e caixa, para busca: "Nhóque" → "nhoque" */
export const semAcento = (texto) => String(texto ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
