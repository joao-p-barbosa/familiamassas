'use strict';
const { initAuthCreds, BufferJSON, proto } = require('@whiskeysockets/baileys');

/**
 * Guarda o pareamento do WhatsApp (credenciais e chaves de criptografia) numa tabela do Postgres (Neon): sobrevive a
 * deploy e reinício sem pedir QR code de novo. Mesma forma do useMultiFileAuthState do Baileys, com uma linha por chave.
 * A tabela é criada aqui (fora das migrações do PHP) porque só este processo a usa.
 */
async function authNoPostgres(pool) {
  await pool.query('CREATE TABLE IF NOT EXISTS conector_auth (chave TEXT PRIMARY KEY, valor TEXT NOT NULL)');
  const ler = async (chave) => {
    const r = await pool.query('SELECT valor FROM conector_auth WHERE chave = $1', [chave]);
    return r.rows[0] ? JSON.parse(r.rows[0].valor, BufferJSON.reviver) : null;
  };
  const gravar = (chave, valor) => pool.query(
    'INSERT INTO conector_auth (chave, valor) VALUES ($1, $2) ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor',
    [chave, JSON.stringify(valor, BufferJSON.replacer)],
  );
  const apagar = (chave) => pool.query('DELETE FROM conector_auth WHERE chave = $1', [chave]);

  const creds = (await ler('creds')) ?? initAuthCreds();
  return {
    state: {
      creds,
      keys: {
        get: async (tipo, ids) => {
          const dados = {};
          await Promise.all(ids.map(async (id) => {
            let valor = await ler(`${tipo}-${id}`);
            if (tipo === 'app-state-sync-key' && valor) valor = proto.Message.AppStateSyncKeyData.fromObject(valor);
            dados[id] = valor;
          }));
          return dados;
        },
        set: async (dados) => {
          const tarefas = [];
          for (const tipo of Object.keys(dados)) {
            for (const id of Object.keys(dados[tipo])) {
              const valor = dados[tipo][id];
              tarefas.push(valor ? gravar(`${tipo}-${id}`, valor) : apagar(`${tipo}-${id}`));
            }
          }
          await Promise.all(tarefas);
        },
      },
    },
    saveCreds: () => gravar('creds', creds),
    limpar: () => pool.query('DELETE FROM conector_auth'),
  };
}

module.exports = { authNoPostgres };
