'use strict';

/** Só dígitos do número de uma conversa individual. Grupos, status e canais devolvem null (o bot não responde neles). */
function telefoneDe(chave) {
  const jid = chave?.remoteJid ?? '';
  // Contatos "LID": o número de telefone vem em senderPn (Baileys 6.7).
  const alvo = jid.endsWith('@lid') ? (chave.senderPn ?? '') : jid;
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

/** messageTimestamp pode vir como número ou como Long. Devolve segundos desde 1970 (0 se não souber). */
function segundosDe(ts) {
  const n = typeof ts === 'object' && ts !== null ? (ts.toNumber?.() ?? 0) : Number(ts);
  return Number.isFinite(n) ? n : 0;
}

module.exports = { telefoneDe, textoDe, segundosDe };
