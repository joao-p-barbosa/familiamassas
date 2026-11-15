'use strict';
const http = require('node:http');
const crypto = require('node:crypto');

const LIMITE_CORPO = 100_000;

/**
 * Servidor local (127.0.0.1) por onde o PHP manda mensagens. Aceita e enfileira (202): o envio de verdade sai um a um,
 * com `espacoMs` entre eles, para não parecer rajada de robô. Se o WhatsApp cair, a fila é descartada (limparFila):
 * mensagem velha não deve sair de uma vez quando a conexão voltar (D68).
 */
function criarServidor({ segredo, conectado, enviar, espacoMs = 2000, filaMax = 50, dormir = (ms) => new Promise((r) => setTimeout(r, ms)), registrar = console.log }) {
  const fila = [];
  let drenando = false;

  async function drenar() {
    if (drenando) return;
    drenando = true;
    while (fila.length > 0) {
      const item = fila.shift();
      try {
        await enviar(item.telefone, item.texto);
      } catch (erro) {
        registrar(`[conector] envio falhou: ${erro?.message ?? erro}`);
      }
      if (fila.length > 0) await dormir(espacoMs);
    }
    drenando = false;
  }

  const igual = (a, b) => a.length === b.length && crypto.timingSafeEqual(Buffer.from(a), Buffer.from(b));
  const responder = (res, status, corpo) => {
    res.writeHead(status, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(corpo));
  };

  const servidor = http.createServer((req, res) => {
    if (req.method !== 'POST' || req.url !== '/enviar') return responder(res, 404, { erro: 'não existe' });
    if (!segredo || !igual(String(req.headers['x-conector-segredo'] ?? ''), segredo)) return responder(res, 403, { erro: 'segredo inválido' });
    let corpo = '';
    req.on('data', (parte) => {
      corpo += parte;
      if (corpo.length > LIMITE_CORPO) req.destroy();
    });
    req.on('end', () => {
      let dados;
      try {
        dados = JSON.parse(corpo);
      } catch {
        return responder(res, 400, { erro: 'JSON inválido' });
      }
      const telefone = String(dados.telefone ?? '').replace(/\D/g, '');
      const texto = String(dados.texto ?? '');
      if (telefone.length < 10 || telefone.length > 15 || texto === '') return responder(res, 400, { erro: 'telefone ou texto inválido' });
      if (!conectado()) return responder(res, 503, { erro: 'WhatsApp desconectado' });
      if (fila.length >= filaMax) return responder(res, 429, { erro: 'fila de envio cheia' });
      const id = crypto.randomUUID();
      fila.push({ id, telefone, texto });
      drenar();
      return responder(res, 202, { id });
    });
  });

  return {
    servidor,
    /** Descarta o que ainda não saiu. @returns {number} quantas mensagens foram descartadas */
    limparFila() {
      const n = fila.length;
      fila.length = 0;
      return n;
    },
    tamanhoDaFila: () => fila.length,
  };
}

module.exports = { criarServidor };
