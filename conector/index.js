'use strict';
// Conector do WhatsApp por QR code (D66): mantém o número da loja conectado (Baileys) e conversa com o site por HTTP.
//   WhatsApp → site:  POST /api/conector/mensagens  (o que o cliente escreveu) e POST /api/conector/sinal (sinal de vida + QR)
//   site → WhatsApp:  POST http://127.0.0.1:CONECTOR_PORTA/enviar  (servidor.js; fila com 2 s entre mensagens)
// Variáveis: CONECTOR_SEGREDO (obrigatória), CONECTOR_SITE_URL (padrão http://127.0.0.1:$PORT), CONECTOR_PORTA (3001),
// DATABASE_URL (guarda o pareamento no Postgres; sem ela, em arquivos na pasta .auth, para desenvolvimento).
const path = require('node:path');
const fs = require('node:fs');
const pino = require('pino');
const QRCode = require('qrcode');
const { default: makeWASocket, DisconnectReason, fetchLatestBaileysVersion, useMultiFileAuthState, Browsers } = require('@whiskeysockets/baileys');
const { criarServidor } = require('./servidor');
const { telefoneDe, textoDe, localDe, segundosDe } = require('./util');

const SEGREDO = process.env.CONECTOR_SEGREDO ?? '';
const SITE = (process.env.CONECTOR_SITE_URL || `http://127.0.0.1:${process.env.PORT || 10000}`).replace(/\/$/, '');
const PORTA = Number(process.env.CONECTOR_PORTA || 3001);
const SINAL_MS = 30_000;
const log = pino({ level: process.env.CONECTOR_LOG || 'warn' });

if (SEGREDO.length < 16) {
  console.error('[conector] CONECTOR_SEGREDO ausente ou curto (mínimo 16 caracteres). Saindo.');
  process.exit(1);
}

let estado = 'desconectado';   // conectado | aguardando_qr | desconectado
let qr = null;
let sock = null;
let reconectando = false;
let tentativas = 0;
let auth = null;

// ---------- Site ----------
async function postar(caminho, corpo, limiteMs = 8000) {
  try {
    const r = await fetch(SITE + caminho, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Conector-Segredo': SEGREDO },
      body: JSON.stringify(corpo),
      signal: AbortSignal.timeout(limiteMs),
    });
    if (!r.ok) console.error(`[conector] site respondeu ${r.status} em ${caminho}`);
    return r.ok;
  } catch (erro) {
    console.error(`[conector] site fora do ar (${caminho}): ${erro.message}`);
    return false;
  }
}
const sinal = () => postar('/api/conector/sinal', { estado, qr: estado === 'aguardando_qr' ? qr : null });
function mudar(novo) {
  if (novo !== estado) console.log(`[conector] ${estado} → ${novo}`);
  estado = novo;
  sinal();
}

// ---------- Envio (site → WhatsApp) ----------
const saida = criarServidor({
  segredo: SEGREDO,
  conectado: () => estado === 'conectado' && sock !== null,
  enviar: (telefone, texto) => sock.sendMessage(`${telefone}@s.whatsapp.net`, { text: texto }),
});
saida.servidor.listen(PORTA, '127.0.0.1', () => console.log(`[conector] ouvindo em 127.0.0.1:${PORTA}`));

// ---------- Pareamento guardado ----------
async function abrirAuth() {
  if (process.env.DATABASE_URL) {
    const { Pool } = require('pg');
    const { authNoPostgres } = require('./auth-pg');
    // Conexão direta do Neon (sem "-pooler"), como o PHP faz.
    const pool = new Pool({ connectionString: process.env.DATABASE_URL.replace(/-pooler(?=\.)/, ''), max: 2 });
    return authNoPostgres(pool);
  }
  const pasta = process.env.CONECTOR_AUTH_DIR || path.join(__dirname, '.auth');
  const arquivos = await useMultiFileAuthState(pasta);
  return { ...arquivos, limpar: async () => fs.rmSync(pasta, { recursive: true, force: true }) };
}

// ---------- WhatsApp ----------
async function conectar() {
  auth ??= await abrirAuth();
  let version;
  try {
    ({ version } = await fetchLatestBaileysVersion());
  } catch { /* sem a versão mais nova, o Baileys usa a que vem nele */ }
  sock = makeWASocket({
    version,
    auth: auth.state,
    logger: log,
    browser: Browsers.macOS('Desktop'),
    markOnlineOnConnect: false,   // o celular da loja continua recebendo as notificações normalmente
    syncFullHistory: false,
  });
  sock.ev.on('creds.update', auth.saveCreds);

  sock.ev.on('connection.update', async (u) => {
    if (u.qr) {
      qr = await QRCode.toDataURL(u.qr, { margin: 1, width: 280 });
      mudar('aguardando_qr');   // manda o sinal com o QR novo (ele troca a cada ~20 s)
    }
    if (u.connection === 'open') {
      qr = null;
      tentativas = 0;
      mudar('conectado');
    }
    if (u.connection === 'close') {
      const codigo = u.lastDisconnect?.error?.output?.statusCode;
      const descartadas = saida.limparFila();
      if (descartadas > 0) console.log(`[conector] ${descartadas} mensagem(ns) na fila de envio descartada(s) com a queda`);
      qr = null;
      mudar('desconectado');
      if (codigo === DisconnectReason.loggedOut) {
        console.log('[conector] o celular desconectou este aparelho: apagando o pareamento para pedir um QR novo');
        await auth.limpar();
        auth = null;
      }
      agendar();
    }
  });

  // site ← WhatsApp
  sock.ev.on('messages.upsert', async ({ messages, type }) => {
    if (type !== 'notify') return;   // "append" é histórico antigo, não mensagem nova
    const lote = [];
    for (const m of messages) {
      if (m.key.fromMe || !m.message) continue;
      const telefone = telefoneDe(m.key);
      const texto = textoDe(m.message);
      const local = localDe(m.message);   // o pin da entrega, no pedido pelo chat
      if (telefone && (texto || local)) lote.push({ id: m.key.id, telefone, texto, local, nome: m.pushName ?? null, em: segundosDe(m.messageTimestamp) });
    }
    if (lote.length > 0) await postar('/api/conector/mensagens', { mensagens: lote }, 60_000);   // a resposta da IA pode levar alguns segundos
  });
}

/** Reconecta com espera crescente (3 s, 6 s, ... até 60 s). */
function agendar() {
  if (reconectando) return;
  reconectando = true;
  tentativas += 1;
  const espera = Math.min(60_000, 3000 * tentativas);
  setTimeout(async () => {
    reconectando = false;
    try {
      sock?.ev.removeAllListeners();
      await conectar();
    } catch (erro) {
      console.error(`[conector] erro ao reconectar: ${erro.message}`);
      agendar();
    }
  }, espera);
}

setInterval(sinal, SINAL_MS);
conectar().catch((erro) => {
  console.error(`[conector] erro ao iniciar: ${erro.message}`);
  agendar();
});
process.on('unhandledRejection', (erro) => console.error('[conector] erro não tratado:', erro?.message ?? erro));
