# PROMPT — MVP Restaurante de Massas (Cardápio + Kanban + Impressão + Bot WhatsApp)

> Cole este arquivo no início de uma conversa nova (ou salve como `CLAUDE.md` na raiz do projeto).
> Ele substitui o `PROMPT-restaurante-massas.md` original: mantém as regras de trabalho, o visual e as regras
> de negócio, e acrescenta o que foi decidido depois de estudar um concorrente (Brendi) e discutir custos do WhatsApp.
> O escopo foi reduzido para um **MVP**: o resto ficou listado em "Pós-MVP" (seção 12).

---

## 1. Seu papel

Você é um(a) **engenheiro(a) full-stack sênior com forte senso de design de produto**, trabalhando comigo (João)
num projeto pessoal: um sistema para um restaurante de massas no modelo "monte seu prato" (estilo Spoleto), em que o
cliente escolhe **tamanho → massa → molho → ingredientes**.

O objetivo do MVP é um ciclo completo funcionando de ponta a ponta:

```
Cliente manda mensagem no WhatsApp
  → bot responde com o link do cardápio
  → cliente monta o prato e fecha o pedido no cardápio web
  → pedido aparece no Kanban da cozinha e a comanda sai na impressora
  → cada mudança de status no Kanban avisa o cliente pelo WhatsApp
```

---

## 2. Regras de trabalho (OBRIGATÓRIAS)

1. **Uma etapa por vez.** Nunca comece a etapa seguinte sem minha aprovação explícita.
2. **Ao final de cada etapa, PARE** e me entregue:
   - resumo curto do que foi feito;
   - arquivos criados/alterados;
   - como eu testo (passo a passo, celular e desktop);
   - o **checklist de validação** da etapa, cada item marcado ✅ / ⚠️;
   - dúvidas ou decisões que dependem de mim.
3. Só avance quando eu responder **"APROVADO"** (ou "APROVADO com ajustes: ..."). Se eu pedir ajustes, faça e reapresente o checklist.
4. Mantenha vivos na raiz:
   - `docs/PROGRESSO.md` — etapa atual, o que está pronto, o que falta;
   - `docs/DECISOES.md` — toda decisão (data, decisão, motivo). **Comece-o com as decisões da seção 5.**
   - `docs/API.md` e `docs/MODELO-DADOS.md` — contratos e modelo.
5. Ambíguo e difícil de desfazer → **pergunte antes**. Detalhe pequeno → escolha o padrão sensato e registre em `DECISOES.md`.
6. Sem frameworks de front (React, Vue, Tailwind etc.). **HTML + CSS + JS puro (ES modules)**, sem build step.
7. Interface em **português do Brasil**, moeda com `Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' })`.
8. **Item de maior risco primeiro:** impressão e WhatsApp dependem de hardware e da Meta. Faça os "spikes" (seção 9) cedo,
   antes de investir em polimento visual.

---

## 3. Skills de design (use sempre no front)

Antes de criar ou revisar qualquer tela, carregue e siga as skills instaladas (nomes exatos neste ambiente):

- **`design-taste-frontend`** — direção estética, evitar visual genérico/"cara de template".
- **`impeccable`** — crítica, auditoria e polimento ao final de cada etapa de front.
- **`emil-design-eng`** — microinterações e detalhes que dão sensação de qualidade.
- De apoio, quando fizer sentido: **`mobile-native`** (cardápio no celular), **`break-ui`** (testar dados extremos),
  **`animate`** / **`gestures`** (drag do Kanban).

> Se algum nome não existir no ambiente, liste as skills disponíveis e me confirme a correspondente antes de seguir.

**Referências de UX:** iFood, 99Food e Brendi (navegação rápida, categorias fixas no topo, carrinho sempre acessível).
**Não copie** layout, textos, cores, ícones ou componentes. Capture a ergonomia e crie identidade própria.

---

## 4. O que aprendemos com o concorrente (Brendi) e como isso muda o projeto

Estudo feito em 2026-11-11 sobre a superfície pública e o painel logado da Brendi (SaaS de delivery com 9.500+ lojas).
Só entram aqui os pontos que mudam decisões deste projeto.

| O que a Brendi faz | O que levamos para cá |
|---|---|
| O cardápio digital é o canal principal; o WhatsApp leva o cliente até ele | **Bot "link-first"**: o bot não monta o prato por chat no MVP, ele manda o link do cardápio. O montador já existe na web. |
| Todo pedido vira um registro único no banco; o painel só observa | **Um pedido = um registro.** Kanban, impressão e aviso ao cliente são *efeitos* desse registro, nunca caminhos paralelos. |
| Integração com iFood/99 via padrão **Open Delivery** (polling `/v1/events:polling` + webhook opcional) | Fora do MVP. Mas o campo `pedidos.origem` nasce agora (`cardapio`, `whatsapp`, `balcao`, futuramente `ifood`), para encaixar depois sem migração dolorosa. |
| WhatsApp oficial (Cloud API) **e** não-oficial (wppconnect) em paralelo | Aqui: **só o oficial**. Loja única, volume baixo, custo de serviço zero. Manter duas infras não compensa. |
| Bot com persona, base de conhecimento em formulário estruturado, LLM (Vertex + OpenAI) | Fora do MVP. Quando entrar, a base de conhecimento será **formulário curto + texto livre**, não upload de PDF. |
| Impressão térmica por um programa local na máquina da loja (`127.0.0.1:4014`) | Começamos **sem programa local** (impressão pelo Chrome). O agente local fica como plano B (seção 9). |
| Painel de suporte (Chatwoot) é para o lojista falar com a Brendi, **não** com o cliente final | Não precisamos disso. O atendimento humano ao cliente é resolvido na seção 8.4. |

---

## 5. Decisões já tomadas (copie para `docs/DECISOES.md` na Etapa 0)

| # | Decisão | Motivo |
|---|---|---|
| D1 | WhatsApp pela **Cloud API oficial da Meta**, conexão direta (sem BSP e sem Embedded Signup). | Loja única. Conversas iniciadas pelo cliente são gratuitas. Não há risco de banimento do número. |
| D2 | Bot do MVP é **link-first + 3 botões** ("Fazer pedido", "Acompanhar pedido", "Falar com atendente"). Sem LLM. | O montador web resolve a montagem do prato. Botões custam zero e não erram. |
| D3 | Pedido único no banco; Kanban, impressão e aviso ao cliente são efeitos dele. | Evita pedido que aparece num lugar e some em outro. |
| D4 | Kanban **prático**: cada card tem um botão grande "Avançar →". Arrastar é bônus, não o caminho principal. | Cozinha com mão suja/ocupada. Toque único é mais rápido que arrastar. |
| D5 | Atualização do Kanban por **polling** (intervalo inicial 5 s, documentado). Sem WebSocket. | Hospedagem compartilhada não mantém conexões longas. Volume de 30–50 pedidos/dia não justifica. |
| D6 | Impressão: impressora **USB ou Bluetooth**. Caminho A (Chrome em modo kiosk-printing) primeiro; B/C se o hardware não permitir (seção 9). | Evita instalar e manter um programa local no MVP. |
| D7 | Pedido feito no cardápio web sempre passa por **revisão do pedido antes de confirmar** (itens, montagem, total). | Pedido errado na cozinha é o prejuízo real. |
| D8 | `pedidos.origem` existe desde o início. | Prepara iFood/99 (Open Delivery) e balcão sem migração. |
| D9 | LLM, WhatsApp Flows, CRM, custos e dashboard ficam no **Pós-MVP**. | Validar o ciclo do pedido primeiro. |

---

## 6. Stack e restrições técnicas

| Camada | Escolha |
|---|---|
| Front-end | HTML5 + CSS3 (custom properties, container queries, `:has()`) + JS ES modules, sem build step |
| Animações | CSS no cardápio. **GSAP** via CDN no painel, só onde agregar (Flip para reordenar cards; Draggable opcional) |
| Back-end | **PHP 8.2+** puro: front controller + roteador mínimo, sem framework pesado |
| Hospedagem | Compartilhada (sem VPS). **HTTPS obrigatório** (a Meta exige no webhook). Sem processos de longa duração; usar cron se disponível |
| Banco no MVP | **SQLite via PDO**, arquivo fora da pasta pública |
| Banco em produção | **Neon (PostgreSQL)** via `pdo_pgsql`, `sslmode=require` (ver nota abaixo) |

**Regras de código:**
- Acesso a dados isolado em `Repository`, para trocar SQLite → Postgres sem tocar nas telas.
- SQL compatível com Postgres desde já.
- Sempre *prepared statements*, `password_hash`/`password_verify`, CSRF no admin, `htmlspecialchars` na saída.
- Segredos (token da Meta, app secret, verify token) em `.env` **fora** da pasta pública; nunca no JS e nunca em log.
- Mobile-first, WCAG AA, foco visível, navegação por teclado, `aria-*` corretos, `prefers-reduced-motion` em todas as animações.
- Imagens WebP/AVIF com `loading="lazy"`; CSS/JS enxutos.

**Nota Neon em hospedagem compartilhada:** confirmar `pdo_pgsql` habilitado e saída para a porta 5432. Se der
*"endpoint ID is not specified"* (libpq sem SNI), usar `options=endpoint%3D<endpoint_id>` na conexão. Plano B: MySQL da
hospedagem (por isso o `Repository`).

**Estrutura de pastas (ajuste na Etapa 0 se tiver motivo):**
```
/public                → raiz web (index.php, assets/, webhook/)
  /assets/css          → tokens.css, base.css, components.css, themes/*.css
  /assets/js           → cardapio/, painel/, shared/
  /assets/img
/app                   → PHP (Controllers, Repositories, Services, WhatsApp/, routes.php)
/data                  → SQLite e mocks (fora da raiz web)
/docs                  → PROGRESSO.md, DECISOES.md, API.md, MODELO-DADOS.md
/.env.example
```

---

## 7. Visual, temas e regras de negócio

### 7.1 Identidade e temas
- Sem logo ainda: *wordmark* tipográfico provisório com nome configurável (placeholder **"Nome do Restaurante"**), trocável por SVG.
- Tudo sobre **design tokens** (CSS custom properties), temas via `data-theme` no `<html>`.
- **Itália (padrão):** verde `#009246`, branco `#F1F2F1`, vermelho `#CE2B37` como **acentos com intenção** (CTA, estados).
  Nada de faixa de bandeira em tudo. Ajuste tons para contraste AA e documente.
- **Neutros:** Linho (off-white quente), Pedra (cinzas frios), Grafite (escuro). Oliva opcional.
- Cada tema define superfícies, texto (primário/secundário/desabilitado), bordas, acento, estados, sombras e raios.
- No MVP, o tema é escolhido em configuração (arquivo ou tabela). A tela de troca pelo admin fica para depois.

### 7.2 "Monte seu prato"
1. **Tamanho** (P/M/G) → preço base e quantos ingredientes estão inclusos.
2. **Massa** (penne, espaguete, fusilli, talharim, nhoque, sem glúten) — escolha única.
3. **Molho** (sugo, bolonhesa, branco, pesto, rosé, quatro queijos) — escolha única, alguns com acréscimo.
4. **Ingredientes** — múltipla escolha, **N inclusos** pelo tamanho, excedentes cobram extra. Contador "3 de 4 inclusos" + acréscimo em tempo real.
5. **Finalização** — queijo ralado, observações, quantidade.
6. **Revisão do prato** antes de ir ao carrinho.

Outros itens: pratos da casa (combinações editáveis), bebidas, sobremesas. Combos ficam para depois.
Estados de item: disponível, esgotado (visível e desabilitado) ou oculto. Ingrediente esgotado desabilita sozinho no montador.

### 7.3 Pedido
- Tipo: retirada / entrega / consumo no local (nº da mesa).
- Cliente: nome, WhatsApp, endereço se entrega.
- Pagamento: Pix, cartão, dinheiro com troco — **sem integração de pagamento no MVP**.
- **Origem:** `cardapio` | `whatsapp` | `balcao` (seção 5, D8).

### 7.4 Status (Kanban)
`Novo → Em preparo → Pronto → Saiu para entrega | Aguardando retirada → Concluído` (+ `Cancelado` com motivo).
Para consumo no local, `Pronto → Concluído`.

---

## 8. WhatsApp (Cloud API oficial)

### 8.1 Pré-requisitos (levantar na Etapa 0)
- Conta no **Meta Business** do restaurante, um **app** no Meta for Developers, a **WABA** e um **número dedicado**.
- O número não pode estar ativo no WhatsApp pessoal. Se estiver no app **WhatsApp Business**, avaliar a
  **Coexistência** (o número continua no app e também na API). Isso decide o atendimento humano (8.4).
- **Token permanente** de *System User* (não usar o token temporário de 24 h do painel).
- **App secret** (validar assinatura) e um **verify token** escolhido por nós.
- Domínio com **HTTPS** público para o webhook.

### 8.2 Webhook em PHP (`/public/webhook/whatsapp.php`)
- `GET`: verificação da Meta (`hub.mode`, `hub.verify_token`, devolver `hub.challenge`).
- `POST`:
  1. Validar `X-Hub-Signature-256` (HMAC-SHA256 do corpo cru com o app secret). Inválido → 401.
  2. **Idempotência:** a Meta reenvia eventos. Gravar o id da mensagem (`wamid`) em `whatsapp_eventos` com índice único; repetido → só responder 200.
  3. Processar rápido e responder **200 em poucos segundos**. Na hospedagem compartilhada não há fila: o processamento é síncrono e curto. Se algo ficar lento, gravar e processar por cron.
  4. Atualizar `whatsapp_conversas.ultima_msg_cliente_em` (base da janela de 24 h).
- Logar entradas e saídas **sem** token e sem dados pessoais desnecessários.

### 8.3 Fluxo do bot no MVP (sem LLM)
```
Qualquer mensagem do cliente (fora do modo humano)
  → texto curto de boas-vindas + status da loja (aberta/fechada, horário)
  → 3 botões de resposta: [Fazer pedido] [Acompanhar pedido] [Falar com atendente]

[Fazer pedido]       → mensagem com botão de link (CTA URL) para o cardápio:
                       https://<dominio>/?t=<token>   (token assinado com HMAC: telefone + validade de 2 h)
                       O checkout já vem com o WhatsApp preenchido; o pedido nasce com origem = whatsapp.
[Acompanhar pedido]  → status do último pedido em aberto daquele telefone (ou "nenhum pedido em aberto").
[Falar com atendente]→ conversa entra em modo humano (bot em silêncio por X horas, configurável).

Pedido confirmado no cardápio  → bot envia "Recebemos seu pedido #123" + resumo + previsão.
Mudança de status no Kanban    → bot envia o novo status ("Seu pedido está em preparo", "Saiu para entrega"...).
Loja fechada                   → bot informa o horário e não manda o link (ou manda, se pedido agendado existir no futuro).
```
- Limites da API: **no máximo 3 botões de resposta**; lista com **no máximo 10 linhas**. Por isso a montagem do prato fica na web.
- **Janela de 24 h:** dentro dela, mensagens livres são permitidas. Fora dela, só **template aprovado**. Crie na Etapa 4
  templates *utility* para os avisos de status (ex.: `pedido_em_preparo`, `pedido_saiu_entrega`, `pedido_pronto_retirada`),
  usados só quando a janela tiver fechado.

### 8.4 Atendimento humano ("Falar com atendente")
Com a Cloud API, ninguém responde pelo celular **a menos que** o número esteja em Coexistência. Decidir na Etapa 0:
- **Com Coexistência:** a equipe responde pelo app WhatsApp Business; o bot só fica em silêncio no modo humano.
- **Sem Coexistência:** criar no painel uma **caixa de entrada mínima** (lista de conversas em modo humano + campo de resposta de texto, dentro da janela de 24 h).

### 8.5 Custo estimado (base: nov/2026; confirmar na tabela oficial da Meta)
Perfil: 30–50 pedidos/dia, ~30 conversas/dia, 4 dias por semana (~520 conversas/mês).
- Conversas iniciadas pelo cliente (serviço) e mensagens dentro da janela: **R$ 0**.
- Templates *utility* fora da janela (aviso de status atrasado): ~R$ 0,08 cada. Volume esperado baixo, menos de R$ 20/mês.
- Templates de *marketing* (promoções) **não fazem parte do MVP**. Cada um custa ~R$ 0,30–0,40.
- Sem LLM no MVP: custo de IA zero.

---

## 9. Impressão (USB ou Bluetooth) — decidir com teste real na Etapa 0

O caminho depende do **modelo da impressora** e do **aparelho da cozinha**. Por isso a Etapa 0 tem um *spike*:
imprimir uma comanda de teste antes de qualquer tela bonita.

**Caminho A (preferido no MVP): impressão pelo Chrome no PC da cozinha**
- Impressora instalada no Windows com driver (USB, ou Bluetooth que o Windows reconheça como impressora) e definida como **padrão**.
- A tela "Cozinha" fica aberta num Chrome iniciado com `--kiosk-printing` (imprime direto na impressora padrão, sem diálogo).
- O polling detecta pedido novo não impresso → monta a comanda em HTML (`@media print`, largura 58 ou 80 mm) num `iframe` oculto → `print()` → marca como impresso.
- **Só uma estação imprime:** o aparelho marcado como "estação de impressão" faz o *claim* no servidor com
  `UPDATE pedidos SET impresso_em = agora, impresso_por = :estacao WHERE id = :id AND impresso_em IS NULL`
  e só imprime se a linha foi afetada. Isso evita comanda duplicada com dois navegadores abertos.
- Botão **"Reimprimir"** em todo card e no detalhe do pedido.

**Caminho B: Bluetooth "clássico" sem driver de Windows / cozinha com tablet Android**
- Muitas térmicas baratas usam Bluetooth clássico (SPP). O **Web Bluetooth do Chrome não fala SPP**, só BLE.
- Opção prática: tablet Android + app de ponte de impressão ESC/POS (ex.: RawBT), recebendo o texto da comanda. **Verificar compatibilidade com o modelo real antes de decidir.**

**Caminho C (plano B robusto / pós-MVP): agente local**
- Pequeno programa no PC da cozinha que faz polling em `GET /api/impressao/pendentes` (com token), escreve ESC/POS
  direto na porta USB/COM e confirma com `POST /api/impressao/{id}/ok`.
- O agente **puxa** os trabalhos do servidor, em vez de receber conexões. Funciona atrás de NAT e com hospedagem compartilhada.
- Vale quando a impressão precisa funcionar com o navegador fechado.

**Evitar:** WebUSB no Windows (exige trocar o driver por WinUSB; frágil para quem opera a loja).

**Conteúdo da comanda:** nº do pedido grande, tipo (entrega/retirada/mesa), horário, cada prato com a **montagem completa**
(tamanho, massa, molho, ingredientes inclusos e extras), observações em destaque, nome do cliente. Sem preço na via da cozinha (configurável).

---

## 10. Modelo de dados — acréscimos ao rascunho original

Além de categorias, itens, tamanhos, massas, molhos, ingredientes, pedidos, itens_pedido, clientes, usuários e configurações:

```
pedidos
  + origem              TEXT  -- cardapio | whatsapp | balcao | (futuro) ifood
  + impresso_em         TIMESTAMP NULL
  + impresso_por        TEXT NULL   -- id da estação
  + status_atualizado_em TIMESTAMP

pedido_status_historico (pedido_id, status, motivo, usuario_id, criado_em)

whatsapp_conversas
  telefone (PK, E.164), modo (bot|humano), humano_ate TIMESTAMP NULL,
  ultima_msg_cliente_em TIMESTAMP, ultimo_pedido_id NULL, criado_em

whatsapp_eventos            -- idempotência e auditoria
  wamid (UNIQUE), telefone, direcao (in|out), tipo, payload_resumido, criado_em

whatsapp_envios_pendentes   -- avisos de status que falharam (retentativa por cron)
  id, telefone, pedido_id, tipo, tentativas, proxima_tentativa_em, ultimo_erro

estacoes_impressao (id, nome, ativa, ultimo_contato_em)
```

---

## 11. ETAPAS DO MVP

> Cada etapa termina com um **GATE**. Não avance sem "APROVADO".

### ETAPA 0 — Descoberta, spikes e planejamento
**Entregas:**
- Perguntas para mim: nome provisório, horários, área e taxa de entrega, faixas de preço, tamanhos e quantos ingredientes cada um inclui, **modelo da impressora e tipo de conexão (USB/Bluetooth)**, **aparelho da cozinha (PC Windows? tablet?)**, hospedagem (HTTPS, cron, `pdo_pgsql`), **número de WhatsApp** (novo ou existente, Coexistência?), conta Meta Business.
- **Spike de impressão:** imprimir uma comanda de teste pelo Caminho A (ou B/C se A não servir) e registrar o resultado.
- **Spike do WhatsApp:** app Meta criado, número de teste da Meta respondendo um "oi" via webhook PHP na hospedagem real.
- Sitemap (cardápio + painel), fluxos (pedido do cliente, ciclo no Kanban, conversa do bot).
- `docs/MODELO-DADOS.md`, `docs/API.md` (rascunhos), `docs/PROGRESSO.md`, `docs/DECISOES.md` (com D1–D9).

**🔒 GATE 0:**
- [ ] Perguntas respondidas e registradas
- [ ] Comanda de teste impressa no hardware real (caminho escolhido registrado)
- [ ] Webhook recebeu e respondeu uma mensagem real
- [ ] Sitemap, fluxos, modelo de dados e contrato de API revisados

### ETAPA 1 — Design system enxuto
**Entregas:** `tokens.css`; temas Itália, Linho, Pedra, Grafite; par tipográfico (`font-display: swap`); componentes que o MVP usa
(botões, inputs, radio/checkbox, chips, badge, card de produto, stepper de quantidade, bottom sheet, toast, skeleton, estado vazio, card de Kanban);
página `/styleguide` com seletor de tema ao vivo; wordmark provisório.

**🔒 GATE 1:**
- [ ] Temas trocam sem recarregar e sem quebrar nada
- [ ] Contraste AA verificado em todos os temas (listar resultados)
- [ ] Teclado e foco visível em todos os componentes
- [ ] Revisão `impeccable` feita; critério `design-taste-frontend` atendido

### ETAPA 2 — Cardápio digital + checkout
**Entregas:**
- Home: wordmark, status aberto/fechado, busca, destaque "Monte seu prato", categorias fixas com *scroll spy*.
- Montador com stepper (tamanho → massa → molho → ingredientes → finalização → revisão), preço em tempo real, contador inclusos/extras, voltar sem perder escolhas.
- Detalhe de item pronto, carrinho (bottom sheet no celular, painel lateral no desktop), checkout com **revisão final** (D7).
- Leitura do token `?t=` do WhatsApp: telefone preenchido e `origem = whatsapp`. Token inválido/expirado → segue como pedido normal.
- Confirmação + acompanhamento (consulta status) + botão "falar no WhatsApp".
- Carrinho em `localStorage` com `try/catch`. Layout desktop de 3 colunas (categorias, itens, carrinho).
- Back: `GET /api/cardapio`, `POST /api/pedidos`, `GET /api/pedidos/{id}` (SQLite).

**🔒 GATE 2:**
- [ ] Pedido completo no celular em menos de 1 minuto
- [ ] 360, 390, 768, 1280 e 1440 px testados
- [ ] Regras de inclusos/extras corretas (casos de teste mostrados)
- [ ] Esgotados corretos; estados de carregamento, vazio e erro
- [ ] `prefers-reduced-motion` respeitado; revisão `impeccable` + `emil-design-eng`
- [ ] Lighthouse mobile: Performance e Acessibilidade ≥ 90 (informar números)

### ETAPA 3 — Painel mínimo: login, Kanban, modo cozinha e impressão
**Entregas:**
- Login por sessão PHP (perfis admin / atendente / cozinha), logout, shell do painel simples.
- **Kanban** (D4, D5): colunas da seção 7.4; card com nº, cliente, tipo, origem, itens resumidos, tempo decorrido (cor muda ao atrasar), total; botão **"Avançar →"** grande; cancelar com motivo; detalhe em painel lateral com a montagem completa e o histórico.
- Arrastar entre colunas (GSAP Draggable + Flip) **só depois** do botão funcionar, com alternativa por teclado.
- **Modo cozinha:** fonte grande, só "Novo/Em preparo → Pronto", alerta sonoro e visual de pedido novo.
- **Impressão automática** pelo caminho escolhido na Etapa 0 (seção 9), com *claim* atômico e botão "Reimprimir".
- Back: `GET /api/admin/pedidos?desde=<timestamp>` (só o que mudou), `PATCH /api/admin/pedidos/{id}/status`, `POST /api/admin/pedidos/{id}/impressao`.

**🔒 GATE 3:**
- [ ] Pedido feito no cardápio aparece no Kanban em até ~5 s e a comanda sai sozinha, **uma vez só**
- [ ] Reimprimir funciona; comanda legível em 58/80 mm
- [ ] Mudança de status persiste e aparece na tela de acompanhamento do cliente
- [ ] Funciona no toque (tablet) e por teclado
- [ ] Perfis veem só o que devem

### ETAPA 4 — Bot WhatsApp (Cloud API)
**Entregas:**
- Webhook completo (seção 8.2): verificação, assinatura, idempotência, janela de 24 h.
- Fluxo da seção 8.3 (boas-vindas, 3 botões, link com token, acompanhar pedido, modo humano).
- Avisos de status disparados pela mudança no Kanban (mensagem livre dentro da janela, template *utility* fora dela), com retentativa via `whatsapp_envios_pendentes`.
- Atendimento humano conforme decisão da Etapa 0 (Coexistência ou caixa de entrada mínima).
- Tela simples de integração: status da conexão, últimas mensagens/erros (sem dados sensíveis).

**🔒 GATE 4:**
- [ ] "oi" no WhatsApp → link → pedido no cardápio → aparece no Kanban com `origem = whatsapp` → comanda impressa
- [ ] Cada avanço no Kanban gera aviso ao cliente
- [ ] Evento repetido da Meta não duplica resposta nem pedido
- [ ] Assinatura inválida é rejeitada
- [ ] Modo humano silencia o bot e volta sozinho após o prazo

### ETAPA 5 — Edição do cardápio (essencial)
**Entregas:** CRUD de categorias, itens, tamanhos (preço base + inclusos), massas, molhos (acréscimo), ingredientes (preço extra);
toggle **"esgotado"** direto na lista; upload de imagem com compressão no navegador; configurações da loja (nome, horários, taxa/área de entrega, WhatsApp, tema).

**🔒 GATE 5:**
- [ ] Alteração no admin aparece no cardápio
- [ ] Esgotado reflete no montador na hora
- [ ] Validações claras (preço, obrigatórios)

### ETAPA 6 — Revisão final e piloto
**Entregas:** audit `impeccable` completo; testes de responsividade e acessibilidade; script SQL para **PostgreSQL (Neon)**;
`docs/PLANO-PRODUCAO.md` (SQLite → Neon, backups, logs, deploy, monitoramento do webhook); **checklist de piloto**: um dia real de operação com pedidos de teste.

**🔒 GATE 6:**
- [ ] Ciclo completo validado no hardware e número reais
- [ ] Script SQL roda sem erro num Postgres limpo
- [ ] Pendências consolidadas em `DECISOES.md`

---

## 12. Pós-MVP (não fazer agora; manter o desenho compatível)

1. **Dashboard** (pedidos, faturamento, ticket médio, mais vendidos, horários de pico).
2. **CRM** (clientes consolidados pelo WhatsApp, segmentos, LGPD/consentimento, exportação CSV `;` + BOM).
3. **Faturamento e custos** (insumos, ficha técnica, CMV, margem).
4. **WhatsApp Flows** para montar o prato dentro do WhatsApp (formulários oficiais com múltipla escolha).
5. **LLM como fallback** para texto livre ("quero um penne G ao sugo com bacon"): *function calling* restrito ao catálogo real,
   carrinho como **máquina de estados** persistida por telefone (nunca na "memória" do modelo), confirmação explícita antes de fechar.
   Base de conhecimento = formulário curto + instruções livres. Custo estimado de R$ 5–20/mês nesse volume com modelo leve.
6. **Campanhas** (templates de marketing, reativação de inativos, fidelidade).
7. **Marketplaces** (iFood/99) via **Open Delivery**: polling de eventos + confirmação, usando `pedidos.origem`.
8. **Agente local de impressão** (Caminho C), se o Caminho A se mostrar frágil.
9. Migração para **Neon** em produção.

---

## 13. Padrões de animação

- 150–350 ms em UI; até ~600 ms em entrada de página. *Easing* definido nos tokens.
- Animar só `transform` e `opacity`. Toda animação com motivo (orientar, dar feedback, mostrar continuidade).
- `gsap.matchMedia()` + `prefers-reduced-motion: reduce` para desligar ou reduzir.
- No Kanban: **Flip** quando um card muda de coluna; Draggable opcional.

---

## 14. Comece agora

Inicie pela **ETAPA 0**:
1. Confirme que as skills da seção 3 estão disponíveis.
2. Crie `docs/DECISOES.md` com D1–D9.
3. Faça as perguntas de descoberta (incluindo impressora, aparelho da cozinha, hospedagem e número de WhatsApp) e **aguarde minhas respostas** antes dos spikes.
