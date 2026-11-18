'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const http = require('node:http');
const { proto, decryptPollVote } = require('@whiskeysockets/baileys');
const { validar, criarCofre, interpretar, hashDe, semAparelho, variantes } = require('./enquete');
const { criarServidor } = require('./servidor');

/** O lado do celular do cliente: cifra o voto como o WhatsApp (espelha a decryptPollVote do Baileys). */
function cifrarVoto({ segredo, id, criador, votante, opcoes }) {
  const assinatura = Buffer.concat([Buffer.from(id), Buffer.from(criador), Buffer.from(votante), Buffer.from('Poll Vote'), Buffer.from([1])]);
  const chave0 = crypto.createHmac('sha256', Buffer.alloc(32)).update(segredo).digest();
  const chave = crypto.createHmac('sha256', chave0).update(assinatura).digest();
  const iv = crypto.randomBytes(12);
  const cifra = crypto.createCipheriv('aes-256-gcm', chave, iv);
  cifra.setAAD(Buffer.from(`${id}\u0000${votante}`));
  const plano = proto.Message.PollVoteMessage.encode({ selectedOptions: opcoes.map((o) => crypto.createHash('sha256').update(o, 'utf8').digest()) }).finish();
  return { encPayload: Buffer.concat([cifra.update(plano), cifra.final(), cifra.getAuthTag()]), encIv: iv };
}

const CLIENTE = '5511912345678';
const NOSSO_PN = '5521999990000:12@s.whatsapp.net';
const NOSSO_LID = '98765432100:12@lid';

function cofreCom({ opcoes, multipla = false, id = 'ENQ1', agora } = {}) {
  const cofre = criarCofre(agora ? { agora } : {});
  const segredo = crypto.randomBytes(32);
  cofre.guardar({ id, segredo, tag: 'abc123def456', opcoes, multipla, telefone: CLIENTE, criadores: [NOSSO_PN, NOSSO_LID] });
  return { cofre, segredo };
}
const VOTO = (extra) => ({ cofre: undefined, criacaoId: 'ENQ1', decifrar: decryptPollVote, ...extra });
const TRES = ['1. Pequeno — R$ 24,90', '2. Médio — R$ 32,90', '3. Grande — R$ 39,90'];

test('validar: 2 a 12 opções, tamanhos do WhatsApp, sem repetidas', () => {
  const ok = validar({ tag: 'abc123def456', pergunta: 'Qual tamanho?', opcoes: TRES, multipla: false });
  assert.deepEqual(ok.enquete, { tag: 'abc123def456', pergunta: 'Qual tamanho?', opcoes: TRES, multipla: false });
  assert.equal(validar({ tag: 'abc123def456', pergunta: 'x', opcoes: ['a', 'b'], multipla: true }).enquete.multipla, true);
  const invalidas = [
    null, 'texto', [], {},
    { tag: 'abc123def456', pergunta: 'x', opcoes: ['só uma'] },
    { tag: 'abc123def456', pergunta: 'x', opcoes: Array.from({ length: 13 }, (_, i) => `op ${i}`) },
    { tag: 'abc123def456', pergunta: 'x', opcoes: ['a', 'a'] },
    { tag: 'abc123def456', pergunta: 'x', opcoes: ['a', ''] },
    { tag: 'abc123def456', pergunta: 'x', opcoes: ['a', 'b'.repeat(101)] },
    { tag: 'abc123def456', pergunta: '', opcoes: ['a', 'b'] },
    { tag: 'abc123def456', pergunta: 'p'.repeat(256), opcoes: ['a', 'b'] },
    { tag: 'abc', pergunta: 'x', opcoes: ['a', 'b'] },
    { tag: 'tag com espaço', pergunta: 'x', opcoes: ['a', 'b'] },
  ];
  for (const i of invalidas) assert.ok(validar(i).erro, `deveria recusar ${JSON.stringify(i)?.slice(0, 60)}`);
  assert.equal(validar({ tag: 'abc123def456', pergunta: 'x', opcoes: Array.from({ length: 12 }, (_, i) => `op ${i}`) }).erro, undefined);
  assert.equal(validar({ tag: 'abc123def456', pergunta: 'x', opcoes: ['a', 'b'.repeat(100)] }).erro, undefined);
});

test('JIDs: tira o número do aparelho e junta as variantes sem repetir', () => {
  assert.equal(semAparelho('5521999990000:12@s.whatsapp.net'), '5521999990000@s.whatsapp.net');
  assert.equal(semAparelho('98765432100@lid'), '98765432100@lid');
  assert.deepEqual(variantes(['5521:1@s.whatsapp.net', '5521@s.whatsapp.net', undefined, 'grupo@g.us', 'status@broadcast', 'sem-arroba']), ['5521@s.whatsapp.net', '5521:1@s.whatsapp.net']);
});

test('voto em enquete de escolha única vira o número da opção (decifrado pela decryptPollVote do Baileys)', () => {
  const { cofre, segredo } = cofreCom({ opcoes: TRES });
  const voto = cifrarVoto({ segredo, id: 'ENQ1', criador: '5521999990000@s.whatsapp.net', votante: `${CLIENTE}@s.whatsapp.net`, opcoes: [TRES[2]] });
  const r = interpretar({ cofre, criacaoId: 'ENQ1', voto, votantes: [`${CLIENTE}@s.whatsapp.net`], decifrar: decryptPollVote });
  assert.equal(r.ok, true);
  assert.equal(r.texto, '3');
  assert.equal(r.entrada.tag, 'abc123def456');
  assert.equal(r.entrada.telefone, CLIENTE);
});

test('o voto decifra com o JID do criador e do votante em qualquer das formas (número, LID, com e sem aparelho)', () => {
  const casos = [
    { criador: '5521999990000:12@s.whatsapp.net', votante: `${CLIENTE}@s.whatsapp.net`, chaveVotante: `${CLIENTE}@s.whatsapp.net` },
    { criador: '98765432100@lid', votante: '55511223344@lid', chaveVotante: '55511223344@lid' },
    { criador: '98765432100:12@lid', votante: '55511223344:3@lid', chaveVotante: '55511223344:3@lid' },
    { criador: '5521999990000@s.whatsapp.net', votante: '55511223344@lid', chaveVotante: '55511223344@lid' },
  ];
  for (const c of casos) {
    const { cofre, segredo } = cofreCom({ opcoes: TRES });
    const voto = cifrarVoto({ segredo, id: 'ENQ1', criador: c.criador, votante: c.votante, opcoes: [TRES[1]] });
    const r = interpretar({ cofre, criacaoId: 'ENQ1', voto, votantes: [c.chaveVotante], decifrar: decryptPollVote });
    assert.equal(r.ok, true, `não decifrou com criador ${c.criador} e votante ${c.votante}`);
    assert.equal(r.texto, '2');
  }
});

test('escolha múltipla: só vale quando o "✅ Pronto" está marcado; vira "1, 3" ou "0"', () => {
  const opcoes = ['1. Frango', '2. Camarão', '3. Bacon', '✅ Pronto (ou nenhum)'];
  const { cofre, segredo } = cofreCom({ opcoes, multipla: true });
  const votar = (marcadas) => interpretar({
    cofre, criacaoId: 'ENQ1', decifrar: decryptPollVote, votantes: [`${CLIENTE}@s.whatsapp.net`],
    voto: cifrarVoto({ segredo, id: 'ENQ1', criador: '5521999990000@s.whatsapp.net', votante: `${CLIENTE}@s.whatsapp.net`, opcoes: marcadas }),
  });
  assert.equal(votar([opcoes[0], opcoes[2]]).ok, false);   // ainda escolhendo: nada acontece
  assert.match(votar([opcoes[0]]).motivo, /Pronto/);
  assert.equal(votar([]).ok, false);                       // desmarcou tudo
  const pronto = votar([opcoes[2], opcoes[3], opcoes[0]]); // fora de ordem
  assert.equal(pronto.ok, true);
  assert.equal(pronto.texto, '1, 3');
  const nenhum = votar([opcoes[3]]);
  assert.equal(nenhum.ok, true);
  assert.equal(nenhum.texto, '0');
});

test('cada enquete vale uma vez só; voto sem enquete guardada, vencida ou que não decifra é ignorado', () => {
  const { cofre, segredo } = cofreCom({ opcoes: TRES });
  const voto = cifrarVoto({ segredo, id: 'ENQ1', criador: '5521999990000@s.whatsapp.net', votante: `${CLIENTE}@s.whatsapp.net`, opcoes: [TRES[0]] });
  const pedido = { cofre, criacaoId: 'ENQ1', voto, votantes: [], decifrar: decryptPollVote };
  const primeiro = interpretar(pedido);
  assert.equal(primeiro.ok, true);
  primeiro.entrada.usada = true;
  assert.match(interpretar(pedido).motivo, /já respondida/);
  primeiro.entrada.usada = false;   // o site não recebeu: a enquete volta a valer
  assert.equal(interpretar(pedido).ok, true);

  assert.match(interpretar({ ...pedido, criacaoId: 'OUTRA' }).motivo, /desconhecida/);
  const outraChave = cifrarVoto({ segredo: crypto.randomBytes(32), id: 'ENQ1', criador: '5521999990000@s.whatsapp.net', votante: `${CLIENTE}@s.whatsapp.net`, opcoes: [TRES[0]] });
  assert.match(interpretar({ ...pedido, voto: outraChave }).motivo, /não decifrou/);
  const deOutroVotante = cifrarVoto({ segredo, id: 'ENQ1', criador: '5521999990000@s.whatsapp.net', votante: '5599888877776@s.whatsapp.net', opcoes: [TRES[0]] });
  assert.match(interpretar({ ...pedido, voto: deOutroVotante }).motivo, /não decifrou/);
  const opcaoDeFora = cifrarVoto({ segredo, id: 'ENQ1', criador: '5521999990000@s.whatsapp.net', votante: `${CLIENTE}@s.whatsapp.net`, opcoes: ['9. Não existe'] });
  assert.match(interpretar({ ...pedido, voto: opcaoDeFora }).motivo, /não existe/);
});

test('cofre: a enquete vence em 2 h e só ficam as 300 mais novas', () => {
  let agora = 1_000_000;
  const cofre = criarCofre({ agora: () => agora, validadeMs: 2 * 3600_000, max: 300 });
  const nova = (id) => cofre.guardar({ id, segredo: Buffer.alloc(32), tag: 'abc123def456', opcoes: ['a', 'b'], multipla: false, telefone: CLIENTE, criadores: [] });
  nova('velha');
  agora += 2 * 3600_000 - 1;
  assert.ok(cofre.buscar('velha'));
  agora += 2;
  assert.equal(cofre.buscar('velha'), null);
  for (let i = 0; i < 305; i++) nova(`e${i}`);
  assert.equal(cofre.tamanho(), 300);
  assert.equal(cofre.buscar('e0'), null);
  assert.ok(cofre.buscar('e304'));
  assert.equal(hashDe('a').length, 64);
});

/** Sobe o servidor de envio numa porta livre. */
async function subir() {
  const enviados = [];
  const dono = criarServidor({ segredo: 'segredo-de-teste-123', conectado: () => true, enviar: async (t, x, e) => enviados.push([t, x, e]), espacoMs: 0, registrar: () => {} });
  await new Promise((r) => dono.servidor.listen(0, '127.0.0.1', r));
  const porta = dono.servidor.address().port;
  const postar = (corpo) => new Promise((resolve) => {
    const req = http.request({ host: '127.0.0.1', port: porta, path: '/enviar', method: 'POST', headers: { 'X-Conector-Segredo': 'segredo-de-teste-123', 'Content-Type': 'application/json' } }, (res) => {
      let texto = '';
      res.on('data', (p) => (texto += p));
      res.on('end', () => resolve({ status: res.statusCode, corpo: JSON.parse(texto) }));
    });
    req.end(JSON.stringify(corpo));
  });
  return { enviados, postar, fechar: () => dono.servidor.close() };
}

test('/enviar com enquete: válida vai para a fila com 202; inválida volta 400; texto vazio sem enquete continua 400', async () => {
  const t = await subir();
  const enquete = { tag: 'abc123def456', pergunta: 'Qual tamanho?', opcoes: TRES, multipla: false };
  const ok = await t.postar({ telefone: CLIENTE, texto: '', enquete });
  assert.equal(ok.status, 202);
  await new Promise((r) => setTimeout(r, 20));
  assert.deepEqual(t.enviados, [[CLIENTE, '', enquete]]);

  const curta = await t.postar({ telefone: CLIENTE, texto: '', enquete: { ...enquete, opcoes: ['só uma'] } });
  assert.equal(curta.status, 400);
  assert.match(curta.corpo.erro, /enquete/);
  assert.equal((await t.postar({ telefone: CLIENTE, texto: '', enquete: 'x' })).status, 400);
  assert.equal((await t.postar({ telefone: CLIENTE, texto: '' })).status, 400);
  assert.equal((await t.postar({ telefone: CLIENTE, texto: 'oi' })).status, 202);
  await new Promise((r) => setTimeout(r, 20));
  assert.deepEqual(t.enviados[1], [CLIENTE, 'oi', null]);
  t.fechar();
});
