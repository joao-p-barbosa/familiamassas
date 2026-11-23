'use strict';

/**
 * Quem escreveu pelo celular da loja (D106). O WhatsApp entrega ao conector, como "fromMe", tanto o que o bot enviou quanto o
 * que alguém da equipe digitou no aparelho. O registro guarda os ids do que o bot enviou; o resto é a equipe. O site recebe o
 * texto e decide: põe a conversa em atendimento humano (o bot fica quieto) ou, com "o bot continua" ligado (D123), só guarda a
 * mensagem para a IA saber o que a pessoa disse.
 *
 * O id só chega depois que o envio termina, e o WhatsApp pode avisar da mensagem antes disso: por isso a decisão espera
 * alguns segundos (esperaMs) antes de conferir o registro.
 */
const TIPOS_DE_CONTEUDO = ['conversation', 'extendedTextMessage', 'imageMessage', 'audioMessage', 'videoMessage', 'documentMessage', 'stickerMessage', 'locationMessage', 'contactMessage'];

function criarRegistroDeEnviadas({ validadeMs = 10 * 60_000, agora = () => Date.now() } = {}) {
  const ids = new Map();   // id → quando foi enviado
  const limpar = () => {
    const limite = agora() - validadeMs;
    for (const [id, quando] of ids) if (quando < limite) ids.delete(id);
  };
  return {
    marcar(id) {
      if (!id) return;
      limpar();
      ids.set(id, agora());
    },
    doBot: (id) => ids.has(id),
    tamanho: () => ids.size,
  };
}

/**
 * A mensagem "fromMe" pode ter sido digitada pela equipe? (conversa individual, recente e com conteúdo de verdade;
 * confirmação de leitura, reação, apagar mensagem e o histórico antigo que chega ao reconectar ficam de fora)
 */
function podeSerDaEquipe(m, conteudo, agoraSegundos, segundosDaMensagem) {
  if (!m?.key?.fromMe) return false;
  const tipoJid = (m.key.remoteJid ?? '').split('@')[1] ?? '';
  if (tipoJid !== 's.whatsapp.net' && tipoJid !== 'lid') return false;
  if (!segundosDaMensagem || agoraSegundos - segundosDaMensagem > 120) return false;
  return Boolean(conteudo) && TIPOS_DE_CONTEUDO.some((tipo) => conteudo[tipo]);
}

/**
 * Junta as decisões: espera o envio do bot terminar, confere o registro e avisa o site de cada mensagem da equipe
 * (uma vez por id), com o texto dela.
 * @param {{ registro: ReturnType<typeof criarRegistroDeEnviadas>, avisar: (telefone: string, texto: string, id: string) => Promise<unknown>, esperaMs?: number, esperar?: (ms: number) => Promise<void> }} opcoes
 */
function criarVigia({ registro, avisar, esperaMs = 4000, esperar = (ms) => new Promise((r) => setTimeout(r, ms)) }) {
  const avisados = criarRegistroDeEnviadas();   // ids já avisados (o WhatsApp pode entregar o mesmo evento duas vezes)
  return async function conferir(id, telefone, texto = '') {
    if (!id || !telefone) return false;
    await esperar(esperaMs);
    if (registro.doBot(id) || avisados.doBot(id)) return false;
    avisados.marcar(id);
    await avisar(telefone, String(texto ?? '').slice(0, 1000), id);
    return true;
  };
}

module.exports = { criarRegistroDeEnviadas, podeSerDaEquipe, criarVigia };
