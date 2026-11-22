'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { criarRegistroDeEnviadas, podeSerDaEquipe, criarVigia } = require('./equipe');

const meu = (jid = '5511912345678@s.whatsapp.net') => ({ key: { fromMe: true, remoteJid: jid, id: 'X1' } });

test('podeSerDaEquipe: texto recente numa conversa individual, sim; o resto, não', () => {
  const agora = 1_700_000_000;
  assert.equal(podeSerDaEquipe(meu(), { conversation: 'já sai!' }, agora, agora - 5), true);
  assert.equal(podeSerDaEquipe(meu('999@lid'), { audioMessage: {} }, agora, agora), true);
  assert.equal(podeSerDaEquipe({ key: { fromMe: false, remoteJid: '5511912345678@s.whatsapp.net' } }, { conversation: 'oi' }, agora, agora), false, 'mensagem do cliente');
  assert.equal(podeSerDaEquipe(meu('1203630@g.us'), { conversation: 'oi' }, agora, agora), false, 'grupo');
  assert.equal(podeSerDaEquipe(meu(), { conversation: 'antiga' }, agora, agora - 600), false, 'histórico ao reconectar');
  assert.equal(podeSerDaEquipe(meu(), { reactionMessage: {} }, agora, agora), false, 'reação');
  assert.equal(podeSerDaEquipe(meu(), { protocolMessage: {} }, agora, agora), false, 'apagar mensagem');
  assert.equal(podeSerDaEquipe(meu(), null, agora, agora), false);
});

test('registro de enviadas: guarda o id e esquece depois da validade', () => {
  let relogio = 0;
  const r = criarRegistroDeEnviadas({ validadeMs: 1000, agora: () => relogio });
  r.marcar('A');
  assert.equal(r.doBot('A'), true);
  assert.equal(r.doBot('B'), false);
  relogio = 5000;
  r.marcar('C');   // limpa os vencidos
  assert.equal(r.doBot('A'), false);
  assert.equal(r.tamanho(), 1);
});

test('vigia: espera o envio terminar, ignora o que o bot mandou e avisa uma vez por conversa', async () => {
  let relogio = 0;
  const registro = criarRegistroDeEnviadas({ agora: () => relogio });
  const avisos = [];
  const vigia = criarVigia({ registro, avisar: async (t) => avisos.push(t), agora: () => relogio,
    esperar: async () => registro.marcar('DO-BOT') });   // o envio do bot termina durante a espera
  assert.equal(await vigia('DO-BOT', '5511900000001'), false, 'mensagem do bot');
  assert.equal(await vigia('EQUIPE-1', '5511900000001'), true, 'digitada no celular');
  assert.equal(await vigia('EQUIPE-2', '5511900000001'), false, 'mesma conversa logo depois: não repete o aviso');
  relogio = 61_000;
  assert.equal(await vigia('EQUIPE-3', '5511900000001'), true, 'depois do intervalo, avisa de novo');
  assert.deepEqual(avisos, ['5511900000001', '5511900000001']);
});
