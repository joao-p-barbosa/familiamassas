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

test('vigia: espera o envio terminar, ignora o que o bot mandou e avisa cada mensagem da equipe com o texto', async () => {
  const registro = criarRegistroDeEnviadas();
  const avisos = [];
  const vigia = criarVigia({ registro, avisar: async (t, texto, id) => avisos.push([t, texto, id]),
    esperar: async () => registro.marcar('DO-BOT') });   // o envio do bot termina durante a espera
  assert.equal(await vigia('DO-BOT', '5511900000001', 'do bot'), false, 'mensagem do bot');
  assert.equal(await vigia('EQUIPE-1', '5511900000001', 'já sai!'), true, 'digitada no celular');
  assert.equal(await vigia('EQUIPE-2', '5511900000001', 'mais 10 min'), true, 'a mensagem seguinte também vai (D123)');
  assert.equal(await vigia('EQUIPE-2', '5511900000001', 'mais 10 min'), false, 'o mesmo id repetido, não');
  assert.deepEqual(avisos, [['5511900000001', 'já sai!', 'EQUIPE-1'], ['5511900000001', 'mais 10 min', 'EQUIPE-2']]);
});
