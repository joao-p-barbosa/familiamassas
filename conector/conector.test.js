'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const { telefoneDe, textoDe, localDe, segundosDe } = require('./util');
const { criarServidor } = require('./servidor');

test('telefoneDe: conversa individual, LID com senderPn, e o que o bot ignora', () => {
  assert.equal(telefoneDe({ remoteJid: '5511912345678@s.whatsapp.net' }), '5511912345678');
  assert.equal(telefoneDe({ remoteJid: '5511912345678:7@s.whatsapp.net' }), '5511912345678');
  assert.equal(telefoneDe({ remoteJid: '99999@lid', senderPn: '5511912345678@s.whatsapp.net' }), '5511912345678');
  assert.equal(telefoneDe({ remoteJid: '99999@lid' }), null);
  assert.equal(telefoneDe({ remoteJid: '1203630@g.us' }), null);
  assert.equal(telefoneDe({ remoteJid: 'status@broadcast' }), null);
  assert.equal(telefoneDe(undefined), null);
});

test('textoDe e segundosDe', () => {
  assert.equal(textoDe({ conversation: ' oi ' }), 'oi');
  assert.equal(textoDe({ extendedTextMessage: { text: 'link' } }), 'link');
  assert.equal(textoDe({ imageMessage: {} }), '');
  assert.equal(segundosDe(1700000000), 1700000000);
  assert.equal(segundosDe({ toNumber: () => 42 }), 42);
  assert.equal(segundosDe(undefined), 0);
  assert.deepEqual(localDe({ locationMessage: { degreesLatitude: -22.9, degreesLongitude: -43.2 } }), { lat: -22.9, lng: -43.2 });
  assert.equal(localDe({ conversation: 'oi' }), null);
});

/** Sobe o servidor numa porta livre e devolve uma função que faz POST /enviar. */
async function subir(opcoes) {
  const enviados = [];
  const dono = criarServidor({ segredo: 'segredo-de-teste-123', conectado: () => true, enviar: async (t, x) => enviados.push([t, x]), espacoMs: 0, registrar: () => {}, ...opcoes });
  await new Promise((r) => dono.servidor.listen(0, '127.0.0.1', r));
  const porta = dono.servidor.address().port;
  const postar = (corpo, segredo = 'segredo-de-teste-123', caminho = '/enviar') => new Promise((resolve) => {
    const req = http.request({ host: '127.0.0.1', port: porta, path: caminho, method: 'POST', headers: { 'X-Conector-Segredo': segredo, 'Content-Type': 'application/json' } }, (res) => {
      let texto = '';
      res.on('data', (p) => (texto += p));
      res.on('end', () => resolve({ status: res.statusCode, corpo: JSON.parse(texto) }));
    });
    req.end(typeof corpo === 'string' ? corpo : JSON.stringify(corpo));
  });
  return { dono, enviados, postar, fechar: () => dono.servidor.close() };
}

test('/enviar: segredo, validação e fila com 202', async () => {
  const t = await subir({});
  assert.equal((await t.postar({ telefone: '5511912345678', texto: 'oi' }, 'errado')).status, 403);
  assert.equal((await t.postar({ telefone: '5511912345678', texto: 'oi' }, 'x', '/outra')).status, 404);
  assert.equal((await t.postar('isto não é json')).status, 400);
  assert.equal((await t.postar({ telefone: '123', texto: 'oi' })).status, 400);
  assert.equal((await t.postar({ telefone: '5511912345678', texto: '' })).status, 400);
  const ok = await t.postar({ telefone: '5511912345678', texto: 'olá' });
  assert.equal(ok.status, 202);
  assert.ok(ok.corpo.id);
  await new Promise((r) => setTimeout(r, 20));
  assert.deepEqual(t.enviados, [['5511912345678', 'olá']]);
  t.fechar();
});

test('/enviar: desconectado devolve 503 e fila cheia devolve 429; a queda descarta o que esperava', async () => {
  const fora = await subir({ conectado: () => false });
  assert.equal((await fora.postar({ telefone: '5511912345678', texto: 'oi' })).status, 503);
  fora.fechar();

  let liberar;
  const trava = new Promise((r) => (liberar = r));
  const cheia = await subir({ filaMax: 2, enviar: () => trava });   // o primeiro envio fica preso; os outros esperam
  for (let i = 0; i < 3; i++) assert.equal((await cheia.postar({ telefone: '5511912345678', texto: `m${i}` })).status, 202);
  assert.equal((await cheia.postar({ telefone: '5511912345678', texto: 'm3' })).status, 429);
  assert.equal(cheia.dono.limparFila(), 2);
  liberar();
  cheia.fechar();
});
