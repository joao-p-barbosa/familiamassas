'use strict';

/** Só dígitos do número de uma conversa individual. Grupos, status e canais devolvem null (o bot não responde neles). */
function telefoneDe(chave) {
  const jid = chave?.remoteJid ?? '';
  // Contatos "LID": o número de telefone vem em remoteJidAlt (Baileys 7) ou senderPn (Baileys 6.7).
  const alvo = jid.endsWith('@lid') ? (chave.remoteJidAlt ?? chave.senderPn ?? '') : jid;
  if (!alvo.endsWith('@s.whatsapp.net')) return null;
  const digitos = alvo.split('@')[0].split(':')[0].replace(/\D/g, '');
  return digitos.length >= 10 && digitos.length <= 15 ? digitos : null;
}

/** O texto que o cliente escreveu (mensagem comum, citação ou resposta de botão). Mídia sem legenda volta vazia. */
function textoDe(mensagem) {
  const m = mensagem ?? {};
  return (m.conversation || m.extendedTextMessage?.text || m.buttonsResponseMessage?.selectedDisplayText
    || m.templateButtonReplyMessage?.selectedDisplayText || '').trim();
}

/** A localização que o cliente mandou (pin ou localização em tempo real), ou null. */
function localDe(mensagem) {
  const l = mensagem?.locationMessage ?? mensagem?.liveLocationMessage;
  const lat = Number(l?.degreesLatitude);
  const lng = Number(l?.degreesLongitude);
  return l && Number.isFinite(lat) && Number.isFinite(lng) ? { lat, lng } : null;
}

// D120: mensagem de voz. Até 2 minutos e 140 KB (o envio ao site, em base64, cabe nos 200 KB que ele aceita).
const AUDIO_MAX_SEGUNDOS = 120;
const AUDIO_MAX_BYTES = 140_000;

/** A mensagem de voz (ou áudio comum): {segundos, longo}. longo = passou do limite e não vale baixar. null se não é áudio. */
function audioDe(mensagem) {
  const a = mensagem?.audioMessage;
  if (!a) return null;
  const segundos = Number(a.seconds) || 0;
  const bytes = Number(a.fileLength?.toNumber?.() ?? a.fileLength) || 0;
  return { segundos, longo: segundos > AUDIO_MAX_SEGUNDOS || bytes > AUDIO_MAX_BYTES };
}

/** messageTimestamp pode vir como número ou como Long. Devolve segundos desde 1970 (0 se não souber). */
function segundosDe(ts) {
  const n = typeof ts === 'object' && ts !== null ? (ts.toNumber?.() ?? 0) : Number(ts);
  return Number.isFinite(n) ? n : 0;
}

module.exports = { telefoneDe, textoDe, localDe, segundosDe, audioDe, AUDIO_MAX_SEGUNDOS, AUDIO_MAX_BYTES };
