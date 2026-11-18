'use strict';
// Enquetes do WhatsApp (D85): o pedido pelo chat oferece as escolhas como enquete, e o voto volta como o número da opção.
//   * validar:      confere a enquete que o site pediu para enviar (2 a 12 opções, tamanhos do WhatsApp).
//   * criarCofre:   guarda por ~2 h o que foi enviado (a chave de cada enquete), porque o voto chega cifrado com ela.
//   * interpretar:  decifra o voto, mapeia a opção e devolve o texto que o cliente "digitou" (o número da opção).
// O Baileys 7.0.0-rc14 envia enquete mas não decifra voto (o código dele está comentado), então a decifragem é feita aqui
// com a função decryptPollVote que ele exporta.
const crypto = require('node:crypto');

const MAX_OPCOES = 12;
const MAX_OPCAO = 100;
const MAX_PERGUNTA = 255;
const VALIDADE_MS = 2 * 60 * 60 * 1000;
const MAX_GUARDADAS = 300;

/** Confere a enquete pedida pelo site. @returns {{enquete: object}|{erro: string}} */
function validar(e) {
  if (e === null || typeof e !== 'object' || Array.isArray(e)) return { erro: 'enquete inválida' };
  const tag = String(e.tag ?? '');
  const pergunta = String(e.pergunta ?? '').trim();
  const opcoes = Array.isArray(e.opcoes) ? e.opcoes.map((o) => (typeof o === 'string' ? o.trim() : '')) : null;
  if (!/^[A-Za-z0-9_-]{4,40}$/.test(tag)) return { erro: 'enquete: tag inválida' };
  if (pergunta === '' || pergunta.length > MAX_PERGUNTA) return { erro: `enquete: a pergunta precisa ter de 1 a ${MAX_PERGUNTA} caracteres` };
  if (opcoes === null || opcoes.length < 2 || opcoes.length > MAX_OPCOES) return { erro: `enquete: de 2 a ${MAX_OPCOES} opções` };
  if (opcoes.some((o) => o === '' || [...o].length > MAX_OPCAO)) return { erro: `enquete: cada opção precisa ter de 1 a ${MAX_OPCAO} caracteres` };
  if (new Set(opcoes).size !== opcoes.length) return { erro: 'enquete: opções repetidas' };
  return { enquete: { tag, pergunta, opcoes, multipla: e.multipla === true } };
}

const hashDe = (nome) => crypto.createHash('sha256').update(nome, 'utf8').digest('hex');

/** "5521999:12@s.whatsapp.net" → "5521999@s.whatsapp.net" (o WhatsApp cifra com o JID sem o número do aparelho). */
function semAparelho(jid) {
  const [usuario, servidor] = String(jid).split('@');
  return servidor ? `${usuario.split(':')[0]}@${servidor}` : String(jid);
}

/** Todas as formas plausíveis de um JID (como veio e sem o aparelho), sem repetir. */
function variantes(jids) {
  const saida = new Set();
  for (const jid of jids) {
    if (typeof jid !== 'string' || !jid.includes('@')) continue;
    if (jid.endsWith('@g.us') || jid.startsWith('status@')) continue;
    saida.add(semAparelho(jid));
    saida.add(jid);
  }
  return [...saida];
}

/**
 * Guarda as enquetes enviadas (chave, opções, tag), no máximo `max` e por `validadeMs`. Cada enquete vale uma vez só.
 * @param {{agora?: () => number, validadeMs?: number, max?: number}} [opcoes]
 */
function criarCofre({ agora = Date.now, validadeMs = VALIDADE_MS, max = MAX_GUARDADAS } = {}) {
  const mapa = new Map();   // id da mensagem → entrada (a ordem de inserção é a da idade)
  const limpar = () => {
    for (const [id, e] of mapa) {
      if (agora() - e.criadaEm > validadeMs) mapa.delete(id);
      else break;   // o resto é mais novo
    }
    while (mapa.size > max) mapa.delete(mapa.keys().next().value);
  };
  return {
    /** @param {{id: string, segredo: Buffer, tag: string, opcoes: string[], multipla: boolean, telefone: string, criadores: string[]}} e */
    guardar(e) {
      limpar();
      mapa.set(e.id, { ...e, hashes: e.opcoes.map(hashDe), criadaEm: agora(), usada: false });
      limpar();
    },
    buscar(id) {
      limpar();
      return mapa.get(id) ?? null;
    },
    tamanho: () => mapa.size,
  };
}

/** Tenta decifrar o voto com cada combinação de JID do criador (nós) e do votante. O AES-GCM autentica: a errada falha limpo. */
function decifrarVoto({ voto, id, segredo, criadores, votantes, decifrar }) {
  for (const criador of variantes(criadores)) {
    for (const votante of variantes(votantes)) {
      try {
        const mensagem = decifrar(voto, { pollCreatorJid: criador, pollMsgId: id, pollEncKey: segredo, voterJid: votante });
        return { selecionadas: (mensagem.selectedOptions ?? []).map((h) => Buffer.from(h).toString('hex')), criador, votante };
      } catch { /* combinação errada */ }
    }
  }
  return null;
}

/**
 * O voto vira o texto que o cliente teria digitado: escolha única → "3"; múltipla → só quando "✅ Pronto" (a última opção)
 * está marcado: "1, 3" ou "0" se marcou só o Pronto.
 * @returns {{ok: true, texto: string, entrada: object} | {ok: false, motivo: string}}
 */
function interpretar({ cofre, criacaoId, voto, votantes, decifrar }) {
  const entrada = cofre.buscar(criacaoId);
  if (!entrada) return { ok: false, motivo: 'enquete desconhecida (não enviada por este conector, vencida ou perdida num reinício)' };
  if (entrada.usada) return { ok: false, motivo: 'enquete já respondida' };
  // Numa conversa individual o votante é o cliente para quem a enquete foi enviada: o número dele também entra nas tentativas.
  const r = decifrarVoto({ voto, id: criacaoId, segredo: entrada.segredo, criadores: entrada.criadores, votantes: [...votantes, `${entrada.telefone}@s.whatsapp.net`], decifrar });
  if (!r) return { ok: false, motivo: `voto não decifrou (${variantes(entrada.criadores).length} JIDs de criador × ${variantes([...votantes, `${entrada.telefone}@s.whatsapp.net`]).length} de votante)` };
  const indices = [...new Set(r.selecionadas.map((h) => entrada.hashes.indexOf(h)))].filter((i) => i >= 0).sort((a, b) => a - b);
  if (indices.length === 0) return { ok: false, motivo: r.selecionadas.length === 0 ? 'voto vazio (o cliente desmarcou)' : 'opção do voto não existe na enquete' };
  if (!entrada.multipla) {
    return indices.length === 1 ? { ok: true, texto: String(indices[0] + 1), entrada } : { ok: false, motivo: 'mais de uma opção numa enquete de escolha única' };
  }
  const pronto = entrada.opcoes.length - 1;
  if (!indices.includes(pronto)) return { ok: false, motivo: 'esperando o "Pronto" (escolha múltipla)' };
  const escolhidas = indices.filter((i) => i !== pronto).map((i) => i + 1);
  return { ok: true, texto: escolhidas.length > 0 ? escolhidas.join(', ') : '0', entrada };
}

module.exports = { validar, criarCofre, interpretar, decifrarVoto, variantes, semAparelho, hashDe, MAX_OPCOES, MAX_OPCAO, MAX_PERGUNTA };
