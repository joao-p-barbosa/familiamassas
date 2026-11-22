# API

Tudo em JSON, dinheiro em centavos, datas em ISO 8601 UTC. Rotas em `app/routes.php`.

**Erros** sempre no mesmo formato, com mensagem pronta para mostrar:

```json
{ "erro": { "codigo": "validacao", "mensagem": "Confira os campos destacados.", "campos": { "nome": "Diga seu nome para chamarmos você." } } }
```

| Status | Quando |
|---|---|
| 401 `nao_autenticado` | painel sem login |
| 403 `sem_permissao` / `csrf` | perfil sem acesso / token CSRF ausente ou errado |
| 404 `nao_encontrado` | |
| 409 | conflito de estado: `esgotado`, `item_removido`, `total_mudou`, `loja_fechada`, `conflito`, `pedido_encerrado`, `categoria_com_itens`, `atendimento_encerrado`, `ja_encerrado`, `conta_encerrada` |
| 422 `validacao` | campos inválidos (`campos` traz um erro por campo) |
| 429 `muitas_tentativas` | login bloqueado por 5 min |

**PUT, PATCH e DELETE** podem chegar como `POST` com o cabeçalho `X-HTTP-Method-Override: PATCH` (D24). O front sempre faz assim.

## Pública (cardápio)

### `GET /api/cardapio`
Loja + cardápio sem nada oculto (esgotados vêm, com `estado: "esgotado"`).

```json
{
  "loja": { "nome": "...", "tema": "italia", "aberta": true, "mensagem": "Aberto até 23:00", "tipos_pedido": ["entrega","retirada"],
            "entrega_por_raio": null, "area_entrega": "...", "tempo_entrega_min": 50, "tempo_retirada_min": 25, "whatsapp": "55...", "endereco": "...", "horarios": {} },
  "categorias": [], "itens": [], "tamanhos": [], "massas": [], "molhos": [], "ingredientes": []
}
```

### `POST /api/pedidos`
```json
{
  "tipo": "entrega",
  "cliente": { "nome": "Maria", "telefone": "(11) 91234-5678" },
  "endereco": { "rua": "...", "numero": "10", "bairro": "...", "complemento": "", "referencia": "", "lat": -23.5614, "lng": -46.6558 },
  "pagamento": { "forma": "dinheiro", "troco_para_centavos": 10000 },
  "observacoes": "",
  "itens": [
    { "tipo": "montado", "item_id": null, "tamanho_id": 2, "massa_id": 1, "molho_ids": [2, 3], "ingrediente_ids": [1,2,7], "quantidade": 1, "observacoes": "" },
    { "tipo": "simples", "item_id": 8, "quantidade": 2, "observacoes": "" }
  ],
  "total_esperado_centavos": 5990,
  "t": ""
}
```

`molho_ids`: um ou mais molhos (D110); o formato antigo `"molho_id": 2` continua aceito.
- `tipo`: só `entrega` ou `retirada` (D35). Mesa e pra viagem são do salão (`POST /api/admin/atendimentos`). `pagamento.forma`: `pix`, `cartao` ou `dinheiro` (`caixa` é só do salão).
- `endereco.lat` e `endereco.lng` (opcionais): o local marcado no mapa. Valor ausente ou fora do planeta é ignorado, e o endereço digitado continua valendo (D39).
- `observacoes` só vale na entrega; na retirada o servidor descarta (D36).
- O servidor **recalcula todos os preços**; nenhum preço do navegador é aceito (D13).
- `total_esperado_centavos` (opcional): o total que o cliente viu na revisão. Diferente do calculado → `409 total_mudou`.
- `t` (opcional): token do link do WhatsApp. Válido → telefone vem do token e `origem = whatsapp`.
- Para prato da casa sem alteração, `tipo: "montado"` com `item_id` do prato.

Resposta `201`: `{ "numero": 12, "codigo": "4f8c5109eaacf263" }`. A resposta também grava o pedido no cookie `fm_pedidos` deste navegador (D37).

### `GET /api/pedidos/{codigo}`
O que a página de acompanhamento mostra: `numero`, `status`, `rotulo`, `tipo`, `primeiro_nome`, `etapas` (`[{status, rotulo, feita, em}]`), `motivo_cancelamento`, `previsao_minutos`, `itens`, totais e `pagamento`. Sem telefone e sem endereço (D16).

### `POST /api/pedidos/{codigo}/dispensar`
Tira o pedido da Home **deste navegador** (botão "Dispensar" de um pedido concluído ou cancelado). Só mexe no cookie `fm_pedidos`; não altera o pedido. Resposta `{ "ok": true }`.

### Cookie `fm_pedidos` (acompanhamento na Home)
`HttpOnly`, `SameSite=Lax`, 7 dias, no máximo 3 códigos de pedido separados por vírgula. É escrito ao criar o pedido e ao abrir `/pedido/{codigo}` de um pedido ativo. A Home (`GET /`) lê o cookie e já embute os pedidos (`dados-acompanhamento`), então o acompanhamento aparece sem piscar e sobrevive a atualizar a página ou fechar o navegador. Pedido encerrado há mais de 3 h e códigos que não existem mais saem do cookie sozinhos (D37).

## Painel (sessão + CSRF)

Toda escrita exige o cabeçalho `X-CSRF-Token` (o valor está na `<meta name="csrf">` da casca do painel).

**Páginas.** O painel é uma SPA (D53): o servidor entrega a **mesma casca** para qualquer `/painel/{tela}` que o perfil possa abrir (`pedidos`, `atendimento`, `cardapio`, `usuarios`, `configuracoes`, `whatsapp`) e o navegador troca de tela sem recarregar. Link direto e F5 funcionam. Tela que o perfil não abre redireciona para `/painel/pedidos` (a tela inicial dos dois perfis). As telas retiradas `/painel/salao` e `/painel/historico` (D63) também redirecionam para lá; `/painel/salao?novo=1` vira `/painel/pedidos?novo=1`, que abre o assistente "Novo pedido salão".

| Método e rota | Perfis | O que faz |
|---|---|---|
| `POST /api/admin/login` | todos | `{login, senha}` → `{ir_para}` |
| `POST /api/admin/logout` | todos | |
| `GET /api/admin/pedidos?desde=<ISO>` | admin, atendente | Sem `desde`: só os pedidos abertos. Com `desde`: o que mudou (inclusive o que acabou de encerrar, para o cartão sair do quadro). Devolve `{agora, pedidos, fechamento}`; use `agora` no próximo `desde` e para corrigir o relógio do aparelho. Cada pedido traz `producao_acumulado_s` e `producao_desde` (ver "Tempo de preparo" em `MODELO-DADOS.md`). `fechamento` = `{pedidos, concluidos, cancelados, em_andamento}` do dia da loja; o `faturamento_centavos` só vai para o admin (D40). Cada pedido traz `conta_aberta`: `null` (pedido online, sem conta), `true` (pedido do salão com a conta aberta) ou `false` (conta já fechada). Sem `desde`, além dos abertos vêm os **concluídos do salão com a conta aberta**, para a coluna Salão mostrar "Fechar conta" (D63). |
| `GET /api/admin/pedidos/{id}` | admin, atendente | Pedido completo com `historico`. Pedido do salão traz `atendimento_id`. |
| `PATCH /api/admin/pedidos/{id}/status` | admin, atendente | `{status, motivo?}`. Cancelar exige `motivo`. Outra pessoa moveu antes → `409 conflito`. Cancelar pedido de uma conta já fechada → `409 conta_encerrada` (o valor já foi cobrado). |
| `GET /api/admin/atendimentos` | admin, atendente | Sem tela desde a D63 (o salão virou uma coluna do quadro de Pedidos). **O quadro do Salão** (D48): atendimentos abertos + encerrados hoje, cada um com `fase`, pedidos e itens. Devolve `{agora, atendimentos, resumo}`; `resumo` = `{abertos, cozinha, pronto, conta, encerrados_hoje, valor_em_aberto_centavos, vendido_hoje_centavos}` (o `vendido_hoje_centavos` só vai para o admin). |
| `POST /api/admin/atendimentos` | admin, atendente | **Abre o atendimento e registra o primeiro pedido.** `{cliente: {nome}, modalidade: "mesa" \| "viagem", mesa?, itens, total_esperado_centavos?}`. Nome obrigatório (2+ letras); `mesa` exige o número; em `viagem` a mesa é descartada. Vale com a loja "fechada" para o cardápio. Mesmas regras e mesmo preço do cliente (itens como em `POST /api/pedidos`). Resposta `201 {id, pedido_id, numero, codigo}` (`id` é o do atendimento). Nada é gravado se algo falhar. |
| `GET /api/admin/atendimentos/{id}` | admin, atendente | Atendimento completo: dados da conta, `pedidos` (cada um com seu `status`, `itens` e `montagem`), `total_centavos`, `quantidade_itens`, `pedidos_ativos`, `fase`, `aberto_por_nome` e `encerrado_por_nome`. |
| `POST /api/admin/atendimentos/{id}/pedidos` | admin, atendente | **Soma mais produtos à conta aberta** (novo pedido, com status próprio). `{itens, total_esperado_centavos?}` → `201 {id, numero, codigo}`. Conta fechada → `409 atendimento_encerrado`. |
| `POST /api/admin/atendimentos/{id}/servir` | admin, atendente | "Marcar servido": passa para Concluído os pedidos **prontos** da conta. A conta continua aberta. Devolve o atendimento. |
| `POST /api/admin/atendimentos/{id}/fechar` | admin, atendente | **Fechar a conta** (D49). `{pagamento: "pix" \| "cartao" \| "dinheiro", total_esperado_centavos}`. Registra forma de pagamento, valor cobrado, data/hora e quem fechou; status → `encerrado`. `pagamento` é obrigatório quando há valor (`422 validacao`); conta sem nada a cobrar (tudo cancelado) fecha sem pagamento. Total conferido diferente do da conta → `409 total_mudou` (entrou ou saiu pedido); conta já fechada → `409 ja_encerrado` (duplo clique não cobra duas vezes). Devolve o atendimento. |
| `GET /api/admin/historico?periodo=hoje\|7d\|30d&status=aberto\|encerrado` | **admin** | Sem tela desde a D63 (fica para um relatório futuro). Histórico de atendimentos e pagamentos (D52). `{periodo, atendimentos, resumo}`; `resumo` = `{quantidade, encerrados, abertos, total_cobrado_centavos, por_pagamento: {pix, cartao, dinheiro}}`. Atendente recebe `403`. |
| `POST /api/admin/pedidos/{id}/impressao` | admin, atendente | `{estacao_id, estacao_nome, modo: "auto" \| "manual"}` → `{imprimir, pedido}`. Em `auto`, só a primeira estação recebe `imprimir: true`. |
| `GET /api/admin/usuarios` | **admin** | Usuários do painel (D64): `{usuarios: [{id, nome, login, perfil, ativo, criado_em}], eu}`. A senha nunca sai do servidor. |
| `POST /api/admin/usuarios` | **admin** | `{nome, login, perfil: "admin" \| "atendente", senha}` → `201` com o usuário. `login`: 3 a 30 caracteres `[a-z0-9._-]` (vira minúsculas), único; `nome` 2+ letras; `senha` 8+ caracteres. Erros por campo em `422 validacao`. |
| `PUT /api/admin/usuarios/{id}` | **admin** | `{nome?, login?, perfil?, ativo?}` → o usuário. Login novo segue as regras da criação (D91). Desativar ou rebaixar o próprio usuário → `409 proprio_usuario`; deixar a loja sem proprietário ativo → `409 ultimo_proprietario`. Quem é desativado sai do painel no próximo clique; perfil e nome novos valem sem entrar de novo (a sessão confere o usuário no banco a cada requisição). |
| `DELETE /api/admin/usuarios/{id}` | admin | Exclui (D91). Não exclui a si mesmo (`409 proprio_usuario`) nem o único proprietário ativo (`409 ultimo_proprietario`). |
| `POST /api/admin/usuarios/{id}/senha` | **admin** | `{senha}` (8+ caracteres): define a senha nova. A antiga deixa de valer na hora. Substitui o antigo `POST /api/admin/senha` ("Minha senha"). |
| `GET /api/admin/cardapio` | todos | Cardápio completo, inclusive ocultos; itens trazem `ingrediente_ids` e `precos` (`[{tamanho_id, preco_centavos}]`, só prato da casa). |
| `PUT /api/admin/cardapio-precos` | admin | Tabela de preços (D87): `{monte: {tamanho_id: centavos}, pratos: {item_id: {tamanho_id: centavos\|null}}}`. `null` = o prato não é vendido nesse tamanho. Devolve o cardápio completo. |
| `POST /api/admin/cardapio/{recurso}` | admin | Cria. `recurso`: `categorias`, `itens`, `tamanhos`, `massas`, `molhos`, `ingredientes`. |
| `PUT /api/admin/cardapio/{recurso}/{id}` | admin | Edita (só os campos enviados). Itens aceitam `ingrediente_ids`. |
| `PATCH /api/admin/cardapio/{recurso}/{id}/estado` | admin, atendente* | `{estado}`. *A atendente só alterna disponível/esgotado (não oculta). |
| `DELETE /api/admin/cardapio/{recurso}/{id}` | admin | Categoria com itens → `409 categoria_com_itens`. |
| `POST /api/admin/upload` | admin | multipart, campo `imagem` (JPG, PNG ou WebP até 1,5 MB; o tipo é conferido pelo conteúdo) → `{caminho}` |
| `GET /api/admin/configuracoes` | admin | Todas as configurações + `estado_agora`. |
| `PUT /api/admin/configuracoes` | admin | Salva tudo de uma vez; devolve igual ao GET. |
| `GET /api/admin/whatsapp` | admin | Quais credenciais estão preenchidas (nunca os valores), HTTPS, contadores de 24 h. |
| `POST /api/admin/whatsapp/link` | admin | `{telefone}` → `{url, validade_minutos}`: o mesmo link que o bot vai mandar. |

### Assistente com IA (Etapa 4c, só proprietário)

| Rota | O que faz |
|---|---|
| `GET /api/admin/assistente` | Tudo para a tela: `config` (chaves `ia_*`, com os interruptores como booleanos), `faqs`, `fluxos`, `chave_configurada`, `indisponivel` (`null`, `desligada`, `sem_chave`, `sem_credito` ou `teto`), `credito` (`{ativa, saldo_usd, recargas_usd, gasto_usd, desde, alerta_usd, recargas: [{id, em, valor_usd, nota}]}`), `modelos` sugeridos, `tons`, `tamanhos` e `uso` (gasto do mês e de hoje, respostas, falhas, por modelo e as 15 últimas). A chave da API nunca é devolvida. |
| `PUT /api/admin/assistente` | `{config, faqs: [{pergunta, resposta, ativo}], fluxos: [{titulo, quando, passos, ativo}]}`: salva tudo de uma vez (as duas listas são trocadas inteiras, numa transação). Erros em `422` com `campos` (`ia_modelo`, `ia_teto_usd`, `faqs.N`, `fluxos.N`...). Devolve o mesmo que o `GET`. |
| `GET /api/admin/assistente/saldo` | Saldo e uso da chave no OpenRouter (`GET /api/v1/key`): `{ok, limite, restante, uso_total, uso_mes, uso_hoje, gratuito, gratis_hoje}`. Faz uma chamada de rede, por isso é separada. |
| `GET /api/admin/assistente/prompt` | O texto exato que o modelo recebe como instrução, com `caracteres` e `tokens_aprox`. |
| `POST /api/admin/assistente/testar` | `{pergunta, modelo, historico?}` → uma pergunta a UM modelo, sem tocar em conversa nenhuma: `{ok, texto, modelo, tokens_in, tokens_out, custo_usd, ms, ferramentas, erro}`. Sem chave → `409 sem_chave`; teto atingido → `409 teto`. A tela chama uma vez por modelo, em paralelo. |
| `POST /api/admin/assistente/recargas` | `{valor_usd, nota?}` → registra uma recarga paga pelo cliente (D72). Valor de US$ 0,01 a US$ 10.000 (aceita vírgula); fora disso `422` com `campos.valor_usd`. Liga o controle de crédito e limpa a marca de "sem crédito". Devolve o mesmo que `GET /api/admin/assistente`. |
| `DELETE /api/admin/assistente/recargas/{id}` | Tira uma recarga lançada por engano. Devolve o mesmo que o `GET`. |

## Faturamento (D101, só proprietário)

| Rota | O que faz |
|---|---|
| `GET /api/admin/vendas?de&ate&canal&pagamento&situacao&pagina` | Período em dias locais (padrão hoje, até 1 ano). Devolve `periodo`, `hoje`, `resumo` (`faturamento_centavos`, `vendas`, `ticket_centavos`, `itens`, `mais_vendido`, `canceladas`, `cancelado_centavos`), `calor` (`horas`, `dias[{nome, rotulo, celulas[{vendas, total, nivel}], vendas, total}]`, `pico`, `melhor_dia`), `por_canal` e `por_pagamento` (`[{chave, rotulo, vendas, total_centavos, ticket_centavos}]`) e os `lancamentos` filtrados (30 por página; cada um com `chave` `pedido:N` ou `conta:N`, valores, `situacao` `ativa` \| `parcial` \| `cancelada` e `itens[{id, nome, quantidade, cancelada, preco_unit_centavos}]`). Os filtros valem só para os lançamentos. |
| `POST /api/admin/vendas/cancelar` | `{venda, tipo: total \| parcial, itens: [{id, quantidade}], motivo}`. Motivo obrigatório. `409 ja_cancelada`, `409 dia_fechado` (o caixa do dia da venda já foi fechado). |
| `POST /api/admin/vendas/zerar` | `{confirmacao: "ZERAR"}` (D116). Apaga pedidos, contas do Salão, cancelamentos e fechamentos e volta a numeração para #1. Devolve `{ok, apagados: {pedidos, contas, fechamentos}}`. Sem ZERAR: `422 validacao`. |
| `GET /api/admin/fechamento?mes=AAAA-MM` | Calendário: `mes`, `anterior`, `proximo` (null no mês atual), `hoje`, `dias[{data, dia, semana, estado: fechado \| pendente \| hoje \| futuro \| neutro, valor_centavos}]`, `pendentes` (datas). |
| `GET /api/admin/fechamento/{data}` | O dia: `estado`, `resumo` (`faturamento_centavos`, `vendas`, `por_pagamento`, `por_canal`, `dinheiro_centavos`), `fechamento` ativo ou null, `anterior` (`{data, contado_centavos}` do último fechamento) e `historico` (fechamentos reabertos). |
| `POST /api/admin/fechamento/{data}` | `{caixa_inicial_centavos, retiradas_centavos, contado_centavos, observacao}` → fecha o caixa (esperado = inicial + dinheiro − retiradas). `409 ja_fechado`; data futura → `422`. Devolve o dia. |
| `POST /api/admin/fechamento/{id}/reabrir` | `{motivo?}` → reabre o dia; o fechamento vai para o histórico. Devolve o dia. |

## Serviços de terceiros usados pelo navegador (só no mapa da entrega)

O mapa do fechamento do pedido de entrega fala **direto do navegador** com o OpenStreetMap, sem chave de acesso (D38):

- `tile.openstreetmap.org`: as imagens do mapa;
- `nominatim.openstreetmap.org/reverse` e `/search`: o endereço de um ponto e o ponto de um endereço.

O OpenStreetMap exige saber de que site vem o pedido, então só essas chamadas mandam o endereço do site (`referrerPolicy: origin`); o resto do site continua com `Referrer-Policy: same-origin`. As coordenadas do cliente vão para o Nominatim, que é um serviço de voluntários com limite de uso (1 consulta por segundo, sem uso pesado). Com a loja crescendo, o caminho é um provedor de mapas contratado (a troca é a URL das imagens em `public/assets/js/cardapio/mapa.js`). A biblioteca Leaflet 1.9.4 fica no próprio site em `public/assets/vendor/leaflet/` (licença BSD-2 junto).

## WhatsApp (Etapa 4)

### Webhook da Meta (sem sessão)
| Método e rota | O que faz |
|---|---|
| `GET /webhook/whatsapp.php?hub.mode=subscribe&hub.verify_token=…&hub.challenge=…` | Cadastro do webhook na Meta. Verify token igual a `WHATSAPP_VERIFY_TOKEN` → `200` com o `hub.challenge` em texto puro; senão `403`. |
| `POST /webhook/whatsapp.php` | Mensagens dos clientes e status das nossas mensagens. Exige `X-Hub-Signature-256: sha256=<HMAC-SHA256 do corpo cru com WHATSAPP_APP_SECRET>`; inválida ou ausente → `401 assinatura_invalida`. Responde `200 {ok, mensagens, repetidas, falhas_de_entrega}`. Mesmo `wamid` de novo (a Meta reenvia) → conta em `repetidas` e nada é respondido de novo (D57). |
| `GET /cron/whatsapp?chave=<CRON_TOKEN>` | Processa a fila de avisos (até 20). Para um serviço de cron externo, opcional: a fila também anda quando o painel consulta os pedidos e quando chega evento no webhook. Sem `CRON_TOKEN` ou chave errada → `404`. |

### Painel (proprietário)
| Método e rota | O que faz |
|---|---|
| `GET /api/admin/whatsapp` | Situação: `simulado`, credenciais preenchidas (nunca o valor), `https`, `webhook` (endereço para cadastrar na Meta), `modelo_status`, `humano_horas`, números das últimas 24 h (`recebidas_24h`, `enviadas_24h`, `falhas_24h`, `envios_pendentes`, `pedidos_whatsapp_hoje`), `recentes` (sem texto e com telefone mascarado), `fila` (avisos esperando ou que a fila desistiu) e `atendimento` (conversas em atendimento humano). |
| `POST /api/admin/whatsapp/simular` | `{telefone, texto?, botao?: "pedir"\|"acompanhar"\|"atendente", nome?}` → faz de conta que o cliente mandou a mensagem; passa pelo mesmo bot do webhook. Só no modo simulado (com credenciais reais → `409 whatsapp_real`). Devolve a conversa. |
| `GET /api/admin/whatsapp/conversa?telefone=` | `{telefone, modo: "bot"\|"humano", humano_ate, janela_aberta, mensagens: [{direcao, tipo, em, texto, botoes, link, erro, nome}]}`. |
| `POST /api/admin/whatsapp/conversas/{telefone}/responder` | `{texto}` → resposta da equipe. Fora da janela de 24 h → `409 janela_fechada`. Falha na Meta → `502 whatsapp_falhou`. |
| `POST /api/admin/whatsapp/conversas/{telefone}/bot` | Encerra o atendimento humano (o bot volta a responder). |
| `POST /api/admin/whatsapp/link` | `{telefone}` → `{url, validade_minutos}`: o mesmo link do botão "Fazer pedido". |

### Canal QR code (Etapa 4b, `WHATSAPP_CANAL=qr`)

Rotas do conector (só o Node usa). Cabeçalho `X-Conector-Segredo` igual a `CONECTOR_SEGREDO`; sem ele, ou com o canal desligado, `404`.

| Rota | O que faz |
|---|---|
| `POST /api/conector/sinal` | `{estado: "conectado"\|"aguardando_qr"\|"desconectado", qr?: "data:image/png;base64,..."}`: sinal de vida a cada 30 s. O QR só é guardado em `aguardando_qr`. |
| `POST /api/conector/mensagens` | `{mensagens: [{id, telefone, texto, nome?, em?}]}` (até 50; `em` em segundos desde 1970) → `{ok, respondidas, repetidas, antigas, ignoradas}`. Mensagem com mais de 10 min de atraso conta em `antigas` e não é respondida. |
| `POST /api/conector/equipe` | `{telefone}` → `{ok, pausou}`. Alguém da loja escreveu ao cliente pelo celular (D106): com o pedido pela IA ligado, a conversa vai para atendimento humano pelas horas configuradas. |

Painel (proprietário):

| Rota | O que faz |
|---|---|
| `GET /api/admin/whatsapp` | Com o canal QR traz também `canal: "qr"` e `conector: {estado: "conectado"\|"aguardando_qr"\|"desconectado"\|"sem_sinal", desde, qr, fila_retida, pendentes, alarme}`. |
| `POST /api/admin/whatsapp/fila/liberar` | "Enviar pendentes": a fila retida volta a andar (avisos com mais de 30 min são descartados ao enviar). Devolve o `conector`. |
| `POST /api/admin/whatsapp/fila/descartar` | "Descartar": apaga os avisos esperando e libera a fila. Devolve o `conector` mais `descartados`. |

`GET /api/admin/pedidos` ganha o campo `whatsapp` (`null` fora do canal QR, ou `{estado, desde, fila_retida, pendentes, alarme}`): é o que alimenta a faixa de queda em qualquer tela. O `alarme` só liga depois de 2 min fora do ar. O proprietário recebe também `ia` (`null` ou `{estado: "saldo_baixo"|"sem_credito", saldo_usd}`), que alimenta a faixa de crédito do assistente (D72).

O PHP fala com o Node em `POST http://127.0.0.1:3001/enviar` (`{telefone, texto}`, mesmo cabeçalho de segredo): `202 {id}` quando aceita na fila, `503` com o WhatsApp desconectado, `429` com a fila cheia (50), `403` sem o segredo.

### O que o bot envia (Cloud API: `POST https://graph.facebook.com/<versão>/<WHATSAPP_PHONE_NUMBER_ID>/messages`)
- **Qualquer mensagem** (fora do atendimento humano) → texto de boas-vindas com o status da loja + 3 botões de resposta: `pedir`, `acompanhar`, `atendente`.
- **`pedir`** → loja aberta: mensagem com botão de link (`cta_url`) para `<site>/?t=<token>`; loja fechada: texto com o horário, sem link.
- **`acompanhar`** → status do último pedido em andamento do telefone e o link `/pedido/{codigo}`; sem pedido: botões `pedir` e `atendente`.
- **`atendente`** → modo humano por `whatsapp_humano_horas` (padrão 2 h): o bot fica em silêncio e a conversa aparece na caixa de entrada do painel.
- **Avisos de status** (só pedidos com `origem = whatsapp`, D58): "Recebemos seu pedido #N" (total, previsão e link), "em preparo", "pronto", "saiu para entrega", "esperando você no balcão", "concluído", "cancelado" (com o motivo). Dentro da janela de 24 h vai texto; fora dela, o template `WHATSAPP_MODELO_STATUS` (padrão `atualizacao_pedido`, parâmetros `{{1}}` número e `{{2}}` status).
