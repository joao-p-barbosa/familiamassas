-- Esquema da VERSÃO 1 (rodada da manhã de 12/11), congelado: serve só para testar a migração 002 em um banco "antigo".
-- Não edite: o esquema atual está em data/schema.sqlite.sql.
-- Esquema do MVP (SQLite). Regras para a troca por Postgres/Neon ser simples:
--   * dinheiro sempre em centavos (INTEGER);
--   * datas em texto "AAAA-MM-DD HH:MM:SS" (UTC), geradas pelo PHP, nunca por função do banco;
--   * "sim/não" como INTEGER 0/1;
--   * nomes e SQL sem nada exclusivo do SQLite além do AUTOINCREMENT (no Postgres vira GENERATED ... AS IDENTITY).

CREATE TABLE configuracoes (
  chave TEXT PRIMARY KEY,
  valor TEXT NOT NULL
);

CREATE TABLE usuarios (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  nome       TEXT NOT NULL,
  login      TEXT NOT NULL UNIQUE,
  senha_hash TEXT NOT NULL,
  perfil     TEXT NOT NULL CHECK (perfil IN ('admin', 'atendente')),   -- admin = proprietário
  ativo      INTEGER NOT NULL DEFAULT 1,
  criado_em  TEXT NOT NULL
);

CREATE TABLE tentativas_login (
  chave         TEXT PRIMARY KEY,          -- "ip:<endereço>" ou "login:<login>"
  tentativas    INTEGER NOT NULL DEFAULT 0,
  bloqueado_ate TEXT,
  atualizado_em TEXT NOT NULL
);

-- ---------- Cardápio ----------

CREATE TABLE categorias (
  id     INTEGER PRIMARY KEY AUTOINCREMENT,
  nome   TEXT NOT NULL,
  ordem  INTEGER NOT NULL DEFAULT 0,
  estado TEXT NOT NULL DEFAULT 'disponivel' CHECK (estado IN ('disponivel', 'oculto'))
);

CREATE TABLE tamanhos (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  nome           TEXT NOT NULL,              -- Pequeno
  sigla          TEXT NOT NULL,              -- P
  descricao      TEXT NOT NULL DEFAULT '',   -- 300 g
  preco_centavos INTEGER NOT NULL CHECK (preco_centavos >= 0),
  inclusos       INTEGER NOT NULL CHECK (inclusos >= 0),   -- quantos ingredientes já estão no preço
  estado         TEXT NOT NULL DEFAULT 'disponivel' CHECK (estado IN ('disponivel', 'esgotado', 'oculto')),
  ordem          INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE massas (
  id                 INTEGER PRIMARY KEY AUTOINCREMENT,
  nome               TEXT NOT NULL,
  descricao          TEXT NOT NULL DEFAULT '',
  acrescimo_centavos INTEGER NOT NULL DEFAULT 0 CHECK (acrescimo_centavos >= 0),
  estado             TEXT NOT NULL DEFAULT 'disponivel' CHECK (estado IN ('disponivel', 'esgotado', 'oculto')),
  ordem              INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE molhos (
  id                 INTEGER PRIMARY KEY AUTOINCREMENT,
  nome               TEXT NOT NULL,
  descricao          TEXT NOT NULL DEFAULT '',
  acrescimo_centavos INTEGER NOT NULL DEFAULT 0 CHECK (acrescimo_centavos >= 0),
  cor                TEXT NOT NULL DEFAULT '#B8402A',   -- cor do molho, usada na bolinha do montador
  estado             TEXT NOT NULL DEFAULT 'disponivel' CHECK (estado IN ('disponivel', 'esgotado', 'oculto')),
  ordem              INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE ingredientes (
  id                   INTEGER PRIMARY KEY AUTOINCREMENT,
  nome                 TEXT NOT NULL,
  grupo                TEXT NOT NULL DEFAULT 'Outros',   -- Acompanhamentos, Queijos...
  preco_extra_centavos INTEGER NOT NULL DEFAULT 0 CHECK (preco_extra_centavos >= 0),  -- cobrado só quando passa dos inclusos
  estado               TEXT NOT NULL DEFAULT 'disponivel' CHECK (estado IN ('disponivel', 'esgotado', 'oculto')),
  ordem                INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE itens (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  categoria_id   INTEGER NOT NULL REFERENCES categorias (id) ON DELETE CASCADE,
  tipo           TEXT NOT NULL DEFAULT 'simples' CHECK (tipo IN ('simples', 'prato_casa')),
  nome           TEXT NOT NULL,
  descricao      TEXT NOT NULL DEFAULT '',
  preco_centavos INTEGER NOT NULL DEFAULT 0 CHECK (preco_centavos >= 0),   -- só para tipo "simples"
  imagem         TEXT,                                                      -- caminho em /uploads
  massa_id       INTEGER REFERENCES massas (id) ON DELETE SET NULL,        -- só para "prato_casa"
  molho_id       INTEGER REFERENCES molhos (id) ON DELETE SET NULL,        -- só para "prato_casa"
  estado         TEXT NOT NULL DEFAULT 'disponivel' CHECK (estado IN ('disponivel', 'esgotado', 'oculto')),
  ordem          INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE item_ingredientes (     -- ingredientes sugeridos de um prato da casa
  item_id        INTEGER NOT NULL REFERENCES itens (id) ON DELETE CASCADE,
  ingrediente_id INTEGER NOT NULL REFERENCES ingredientes (id) ON DELETE CASCADE,
  PRIMARY KEY (item_id, ingrediente_id)
);

-- ---------- Pedidos ----------

CREATE TABLE clientes (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  nome          TEXT NOT NULL,
  telefone      TEXT NOT NULL UNIQUE,      -- só dígitos, com DDI: 5511999998888
  criado_em     TEXT NOT NULL,
  atualizado_em TEXT NOT NULL
);

CREATE TABLE pedidos (
  id                   INTEGER PRIMARY KEY AUTOINCREMENT,   -- é o número do pedido (#123)
  codigo               TEXT NOT NULL UNIQUE,                -- código aleatório do link de acompanhamento
  cliente_id           INTEGER REFERENCES clientes (id) ON DELETE SET NULL,
  cliente_nome         TEXT NOT NULL,
  cliente_telefone     TEXT NOT NULL,
  tipo                 TEXT NOT NULL CHECK (tipo IN ('entrega', 'retirada', 'local', 'viagem')),   -- local = mesa e viagem = pra viagem (os dois só no salão)
  mesa                 TEXT,
  endereco_rua         TEXT,
  endereco_numero      TEXT,
  endereco_bairro      TEXT,
  endereco_complemento TEXT,
  endereco_referencia  TEXT,
  entrega_lat          REAL,                                -- local marcado no mapa (opcional)
  entrega_lng          REAL,
  pagamento            TEXT NOT NULL CHECK (pagamento IN ('pix', 'cartao', 'dinheiro', 'caixa')),   -- caixa = "na conta": pedido do salão, o pagamento real fica no atendimento
  troco_para_centavos  INTEGER,
  observacoes          TEXT NOT NULL DEFAULT '',
  subtotal_centavos    INTEGER NOT NULL,
  taxa_entrega_centavos INTEGER NOT NULL DEFAULT 0,
  total_centavos       INTEGER NOT NULL,
  status               TEXT NOT NULL DEFAULT 'novo'
                       CHECK (status IN ('novo', 'em_preparo', 'pronto', 'saiu_entrega', 'aguardando_retirada', 'concluido', 'cancelado')),
  origem               TEXT NOT NULL DEFAULT 'cardapio' CHECK (origem IN ('cardapio', 'whatsapp', 'balcao', 'ifood')),   -- balcao = registrado pela atendente no salão
  criado_por           INTEGER REFERENCES usuarios (id) ON DELETE SET NULL,   -- quem registrou (só pedidos do salão)
  impresso_em          TEXT,
  impresso_por         TEXT,                                -- id da estação de impressão
  status_atualizado_em TEXT NOT NULL,
  atualizado_em        TEXT NOT NULL,                       -- muda em qualquer alteração (base do polling do painel)
  criado_em            TEXT NOT NULL
);
CREATE INDEX idx_pedidos_atualizado ON pedidos (atualizado_em);
CREATE INDEX idx_pedidos_status ON pedidos (status);
CREATE INDEX idx_pedidos_telefone ON pedidos (cliente_telefone);

CREATE TABLE itens_pedido (
  id                  INTEGER PRIMARY KEY AUTOINCREMENT,
  pedido_id           INTEGER NOT NULL REFERENCES pedidos (id) ON DELETE CASCADE,
  item_id             INTEGER,                 -- item do cardápio de origem (pode ter sido apagado depois)
  tipo                TEXT NOT NULL CHECK (tipo IN ('montado', 'simples')),
  nome                TEXT NOT NULL,           -- cópia do nome na hora do pedido
  quantidade          INTEGER NOT NULL CHECK (quantidade > 0),
  preco_unit_centavos INTEGER NOT NULL,
  montagem            TEXT,                    -- JSON com a montagem completa (cópia na hora do pedido)
  observacoes         TEXT NOT NULL DEFAULT ''
);
CREATE INDEX idx_itens_pedido_pedido ON itens_pedido (pedido_id);

CREATE TABLE pedido_status_historico (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  pedido_id  INTEGER NOT NULL REFERENCES pedidos (id) ON DELETE CASCADE,
  status     TEXT NOT NULL,
  motivo     TEXT,
  usuario_id INTEGER,
  criado_em  TEXT NOT NULL
);
CREATE INDEX idx_historico_pedido ON pedido_status_historico (pedido_id);

CREATE TABLE estacoes_impressao (
  id                TEXT PRIMARY KEY,
  nome              TEXT NOT NULL,
  ativa             INTEGER NOT NULL DEFAULT 1,
  ultimo_contato_em TEXT NOT NULL
);

-- ---------- WhatsApp (tabelas já criadas; uso começa na Etapa 4) ----------

CREATE TABLE whatsapp_conversas (
  telefone              TEXT PRIMARY KEY,      -- E.164 sem "+"
  modo                  TEXT NOT NULL DEFAULT 'bot' CHECK (modo IN ('bot', 'humano')),
  humano_ate            TEXT,
  ultima_msg_cliente_em TEXT,
  ultimo_pedido_id      INTEGER,
  criado_em             TEXT NOT NULL
);

CREATE TABLE whatsapp_eventos (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  wamid            TEXT NOT NULL UNIQUE,
  telefone         TEXT NOT NULL,
  direcao          TEXT NOT NULL CHECK (direcao IN ('in', 'out')),
  tipo             TEXT NOT NULL,
  payload_resumido TEXT,
  criado_em        TEXT NOT NULL
);

CREATE TABLE whatsapp_envios_pendentes (
  id                   INTEGER PRIMARY KEY AUTOINCREMENT,
  telefone             TEXT NOT NULL,
  pedido_id            INTEGER,
  tipo                 TEXT NOT NULL,
  tentativas           INTEGER NOT NULL DEFAULT 0,
  proxima_tentativa_em TEXT NOT NULL,
  ultimo_erro          TEXT
);
