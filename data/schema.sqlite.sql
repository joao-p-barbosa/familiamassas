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
  preco_centavos INTEGER NOT NULL CHECK (preco_centavos >= 0),   -- preço do "Monte sua massa" neste tamanho
  inclusos       INTEGER NOT NULL CHECK (inclusos >= 0),   -- sem uso desde a D87 (era quantos ingredientes vinham no preço)
  estado         TEXT NOT NULL DEFAULT 'disponivel' CHECK (estado IN ('disponivel', 'esgotado', 'oculto')),
  ordem          INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE massas (
  id                 INTEGER PRIMARY KEY AUTOINCREMENT,
  nome               TEXT NOT NULL,
  descricao          TEXT NOT NULL DEFAULT '',
  acrescimo_centavos INTEGER NOT NULL DEFAULT 0 CHECK (acrescimo_centavos >= 0),
  estado             TEXT NOT NULL DEFAULT 'disponivel' CHECK (estado IN ('disponivel', 'esgotado', 'oculto')),
  ordem              INTEGER NOT NULL DEFAULT 0,
  preparo_min        INTEGER NOT NULL DEFAULT 15            -- minutos de preparo de um prato com esta massa (D100)
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
  preco_extra_centavos INTEGER NOT NULL DEFAULT 0 CHECK (preco_extra_centavos >= 0),  -- somado ao preço do "Monte sua massa" (0 = sem custo)
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
  ordem          INTEGER NOT NULL DEFAULT 0,
  preparo_min    INTEGER                                                    -- tempo próprio do prato da casa; NULL = o da massa (D100)
);

CREATE TABLE item_ingredientes (     -- ingredientes sugeridos de um prato da casa
  item_id        INTEGER NOT NULL REFERENCES itens (id) ON DELETE CASCADE,
  ingrediente_id INTEGER NOT NULL REFERENCES ingredientes (id) ON DELETE CASCADE,
  PRIMARY KEY (item_id, ingrediente_id)
);

CREATE TABLE item_precos (           -- preço fixo de um prato da casa em cada tamanho (Meio, Inteiro...)
  item_id        INTEGER NOT NULL REFERENCES itens (id) ON DELETE CASCADE,
  tamanho_id     INTEGER NOT NULL REFERENCES tamanhos (id) ON DELETE CASCADE,
  preco_centavos INTEGER NOT NULL CHECK (preco_centavos >= 0),   -- preço do "Monte sua massa" neste tamanho
  PRIMARY KEY (item_id, tamanho_id)
);

-- ---------- Pedidos ----------

CREATE TABLE clientes (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  nome          TEXT NOT NULL,
  telefone      TEXT NOT NULL UNIQUE,      -- só dígitos, com DDI: 5511999998888
  criado_em     TEXT NOT NULL,
  atualizado_em TEXT NOT NULL
);

-- Atendimento do salão = a conta de um cliente (Mesa ou Pra viagem). Reúne vários pedidos e só fecha com o pagamento.
-- É independente de cada pedido: um atendimento pode ter pedidos concluídos e continuar aberto até a conta ser paga.
CREATE TABLE atendimentos (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  cliente_nome   TEXT NOT NULL,
  modalidade     TEXT NOT NULL CHECK (modalidade IN ('mesa', 'viagem')),
  mesa           TEXT,                                   -- só quando a modalidade é "mesa"
  status         TEXT NOT NULL DEFAULT 'aberto' CHECK (status IN ('aberto', 'encerrado')),
  pagamento      TEXT CHECK (pagamento IN ('pix', 'cartao', 'dinheiro')),   -- preenchido ao fechar a conta (vazio se não havia o que cobrar)
  total_centavos INTEGER,                                -- valor cobrado: preenchido ao fechar a conta
  aberto_por     INTEGER REFERENCES usuarios (id) ON DELETE SET NULL,
  encerrado_por  INTEGER REFERENCES usuarios (id) ON DELETE SET NULL,
  aberto_em      TEXT NOT NULL,
  encerrado_em   TEXT,
  atualizado_em  TEXT NOT NULL
);
CREATE INDEX idx_atendimentos_status ON atendimentos (status);
CREATE INDEX idx_atendimentos_encerrado ON atendimentos (encerrado_em);

CREATE TABLE pedidos (
  id                   INTEGER PRIMARY KEY AUTOINCREMENT,   -- é o número do pedido (#123)
  atendimento_id       INTEGER REFERENCES atendimentos (id),   -- só pedidos do salão: a conta a que pertencem
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
  criado_em            TEXT NOT NULL,
  previsao_minutos     INTEGER                              -- previsão gravada na hora do pedido (tempo fixo + fila da cozinha, D99)
);
CREATE INDEX idx_pedidos_atualizado ON pedidos (atualizado_em);
CREATE INDEX idx_pedidos_status ON pedidos (status);
CREATE INDEX idx_pedidos_telefone ON pedidos (cliente_telefone);
CREATE INDEX idx_pedidos_atendimento ON pedidos (atendimento_id);

CREATE TABLE itens_pedido (
  id                  INTEGER PRIMARY KEY AUTOINCREMENT,
  pedido_id           INTEGER NOT NULL REFERENCES pedidos (id) ON DELETE CASCADE,
  item_id             INTEGER,                 -- item do cardápio de origem (pode ter sido apagado depois)
  tipo                TEXT NOT NULL CHECK (tipo IN ('montado', 'simples')),
  nome                TEXT NOT NULL,           -- cópia do nome na hora do pedido
  quantidade          INTEGER NOT NULL CHECK (quantidade > 0),
  preco_unit_centavos INTEGER NOT NULL,
  montagem            TEXT,                    -- JSON com a montagem completa (cópia na hora do pedido)
  observacoes         TEXT NOT NULL DEFAULT '',
  quantidade_cancelada INTEGER NOT NULL DEFAULT 0          -- unidades já canceladas na tela Vendas (D101)
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
  ultimo_erro          TEXT,
  criado_em            TEXT                    -- vazio nas linhas antigas; o canal QR descarta aviso velho (D68)
);

-- Estado do conector do WhatsApp por QR code (D66): uma linha só. O processo Node manda o sinal de vida.
CREATE TABLE whatsapp_conector (
  id           INTEGER PRIMARY KEY CHECK (id = 1),
  estado       TEXT NOT NULL,                  -- conectado | aguardando_qr | desconectado
  estado_desde TEXT NOT NULL,
  visto_em     TEXT,                           -- último sinal de vida
  qr           TEXT,                           -- imagem do QR (data URL) enquanto aguarda o pareamento
  fila_retida  INTEGER NOT NULL DEFAULT 0      -- 1 = a queda passou de 2 min: a fila espera o proprietário decidir
);

-- Assistente com IA (D70): perguntas e respostas, fluxos em texto e o uso (custo real por resposta, em micro-dólares).
CREATE TABLE assistente_faq (
  id       INTEGER PRIMARY KEY AUTOINCREMENT,
  pergunta TEXT NOT NULL,
  resposta TEXT NOT NULL,
  ativo    INTEGER NOT NULL DEFAULT 1,
  ordem    INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE assistente_fluxos (
  id     INTEGER PRIMARY KEY AUTOINCREMENT,
  titulo TEXT NOT NULL,
  quando TEXT NOT NULL,
  passos TEXT NOT NULL,
  ativo  INTEGER NOT NULL DEFAULT 1,
  ordem  INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE ia_uso (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  criado_em     TEXT NOT NULL,
  origem        TEXT NOT NULL,                 -- whatsapp | teste
  telefone      TEXT,
  modelo        TEXT NOT NULL,
  tokens_in     INTEGER NOT NULL DEFAULT 0,
  tokens_out    INTEGER NOT NULL DEFAULT 0,
  custo_micro   INTEGER NOT NULL DEFAULT 0,    -- custo devolvido pelo OpenRouter, em milionésimos de dólar
  duracao_ms    INTEGER NOT NULL DEFAULT 0,
  ferramentas   TEXT,
  erro          TEXT
);
CREATE INDEX idx_ia_uso_criado ON ia_uso (criado_em);
CREATE INDEX idx_ia_uso_telefone ON ia_uso (telefone, criado_em);

-- Crédito do cliente no assistente (D72): cada recarga que o cliente pagou, em micro-dólares.
CREATE TABLE ia_recargas (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  criado_em   TEXT NOT NULL,
  valor_micro INTEGER NOT NULL CHECK (valor_micro > 0),
  nota        TEXT NOT NULL DEFAULT ''
);

-- Pedido pelo WhatsApp passo a passo (D74): o pedido em andamento de cada cliente no chat.
CREATE TABLE whatsapp_sessoes (
  telefone      TEXT PRIMARY KEY,
  etapa         TEXT NOT NULL,
  dados         TEXT NOT NULL,                 -- JSON com o que o cliente já escolheu
  atualizado_em TEXT NOT NULL
);

-- ---------- iFood (D76 a D78) ----------

-- A ligação da loja com a Merchant API do iFood (uma linha só). Os tokens ficam aqui para sobreviver a reinícios, mas
-- nunca saem para o painel. O clientId e o clientSecret do aplicativo ficam só em variável de ambiente.
CREATE TABLE ifood_conexao (
  id                 INTEGER PRIMARY KEY CHECK (id = 1),
  estado             TEXT NOT NULL DEFAULT 'desconectado',   -- desconectado | aguardando_codigo | conectado | erro
  merchant_id        TEXT,
  merchant_nome      TEXT,
  access_token       TEXT,
  token_expira_em    TEXT,
  refresh_token      TEXT,                                    -- só no aplicativo distribuído (fluxo do código)
  user_code          TEXT,                                    -- código que o dono digita no Portal do Parceiro
  verificador        TEXT,                                    -- authorizationCodeVerifier do mesmo pedido de código
  url_autorizacao    TEXT,
  codigo_expira_em   TEXT,
  proximo_polling_em TEXT,                                    -- trava do ciclo: só um processo faz o polling por vez
  ultimo_polling_em  TEXT,
  ultimo_erro        TEXT,
  ultimo_erro_em     TEXT,
  atualizado_em      TEXT NOT NULL
);

-- O que o pedido do iFood tem e o nosso não tem. O pedido em si fica em "pedidos" (origem ifood), como todos os outros.
CREATE TABLE ifood_pedidos (
  pedido_id       INTEGER PRIMARY KEY REFERENCES pedidos (id) ON DELETE CASCADE,
  ifood_id        TEXT NOT NULL UNIQUE,      -- id (UUID) do pedido no iFood: a mesma venda nunca entra duas vezes
  display_id      TEXT NOT NULL,             -- o número curto que o entregador e o cliente veem no app
  merchant_id     TEXT,
  tipo            TEXT NOT NULL,             -- orderType como veio: DELIVERY | TAKEOUT | DINE_IN | INDOOR
  agendado_para   TEXT,                      -- pedido agendado: início da janela de entrega (UTC)
  entregue_por    TEXT,                      -- IFOOD | MERCHANT
  pago_online     INTEGER NOT NULL DEFAULT 0,
  pagamento_texto TEXT NOT NULL DEFAULT '',  -- "Pago online no iFood (Crédito VISA)", "Cobrar na entrega: Dinheiro"...
  telefone        TEXT NOT NULL DEFAULT '',  -- o 0800 do iFood (o mesmo para todos os clientes) ...
  localizador     TEXT NOT NULL DEFAULT '',  -- ... e o código que leva a ligação até este cliente
  codigo_coleta   TEXT NOT NULL DEFAULT '',  -- código que o entregador do iFood informa na retirada
  status_ifood    TEXT NOT NULL DEFAULT 'PLACED',
  confirmado_em   TEXT,
  simulado        INTEGER NOT NULL DEFAULT 0,  -- 1 = pedido de teste criado pelo simulador do painel
  dados           TEXT NOT NULL,             -- JSON original do iFood (para conferência)
  criado_em       TEXT NOT NULL,
  atualizado_em   TEXT NOT NULL
);

-- Cada evento recebido no polling. O iFood pode mandar o mesmo evento de novo; aqui ele é processado uma vez só.
CREATE TABLE ifood_eventos (
  id           TEXT PRIMARY KEY,
  codigo       TEXT NOT NULL,                -- fullCode: PLACED, CONFIRMED, CANCELLED, CONCLUDED...
  ifood_pedido TEXT NOT NULL,
  recebido_em  TEXT NOT NULL,
  resultado    TEXT NOT NULL DEFAULT ''
);
CREATE INDEX idx_ifood_eventos_recebido ON ifood_eventos (recebido_em);

-- O que o painel precisa contar ao iFood. Se a chamada falhar, tenta de novo (até 5 vezes) sem travar o Kanban.
CREATE TABLE ifood_acoes (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  pedido_id   INTEGER NOT NULL REFERENCES pedidos (id) ON DELETE CASCADE,
  acao        TEXT NOT NULL,                 -- confirm | startPreparation | readyToPickup | dispatch | requestCancellation
  corpo       TEXT,                          -- JSON (só o cancelamento tem)
  estado      TEXT NOT NULL DEFAULT 'pendente' CHECK (estado IN ('pendente', 'feita', 'falhou')),
  tentativas  INTEGER NOT NULL DEFAULT 0,
  proxima_em  TEXT NOT NULL,
  erro        TEXT,
  criado_em   TEXT NOT NULL,
  feita_em    TEXT
);
CREATE INDEX idx_ifood_acoes_estado ON ifood_acoes (estado, proxima_em);

-- ---------- Agente de impressão (D79 a D81) ----------

-- Fila de comandas do programa que roda no computador da loja e imprime direto na impressora USB.
CREATE TABLE impressao_trabalhos (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  pedido_id   INTEGER REFERENCES pedidos (id) ON DELETE CASCADE,   -- vazio na comanda de teste
  tipo        TEXT NOT NULL CHECK (tipo IN ('auto', 'reimpressao', 'teste')),
  estado      TEXT NOT NULL DEFAULT 'pendente' CHECK (estado IN ('pendente', 'enviado', 'feito', 'falhou')),
  tentativas  INTEGER NOT NULL DEFAULT 0,
  erro        TEXT,
  criado_em   TEXT NOT NULL,
  enviado_em  TEXT,
  feito_em    TEXT
);
CREATE INDEX idx_impressao_estado ON impressao_trabalhos (estado);

-- Faturamento (D101): conferência da gaveta de cada dia (reabrir marca cancelado_em) e cancelamentos de venda.
CREATE TABLE fechamentos_caixa (
  id                       INTEGER PRIMARY KEY AUTOINCREMENT,
  data                     TEXT NOT NULL,                     -- dia no fuso da loja (AAAA-MM-DD)
  caixa_inicial_centavos   INTEGER NOT NULL,
  vendas_dinheiro_centavos INTEGER NOT NULL,
  retiradas_centavos       INTEGER NOT NULL,
  esperado_centavos        INTEGER NOT NULL,
  contado_centavos         INTEGER NOT NULL,
  diferenca_centavos       INTEGER NOT NULL,
  faturamento_centavos     INTEGER NOT NULL,
  observacao               TEXT NOT NULL DEFAULT '',
  fechado_por              INTEGER REFERENCES usuarios (id) ON DELETE SET NULL,
  fechado_em               TEXT NOT NULL,
  cancelado_em             TEXT,
  cancelado_por            INTEGER REFERENCES usuarios (id) ON DELETE SET NULL,
  motivo_cancelamento      TEXT
);
CREATE INDEX idx_fechamentos_data ON fechamentos_caixa (data);

CREATE TABLE cancelamentos (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  pedido_id      INTEGER REFERENCES pedidos (id) ON DELETE CASCADE,
  atendimento_id INTEGER REFERENCES atendimentos (id) ON DELETE CASCADE,
  tipo           TEXT NOT NULL CHECK (tipo IN ('total', 'parcial')),
  valor_centavos INTEGER NOT NULL CHECK (valor_centavos >= 0),
  itens          TEXT NOT NULL,                     -- JSON: [{id, nome, quantidade}]
  motivo         TEXT NOT NULL,
  usuario_id     INTEGER REFERENCES usuarios (id) ON DELETE SET NULL,
  criado_em      TEXT NOT NULL
);
