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
            "taxa_entrega_centavos": 600, "area_entrega": "...", "tempo_entrega_min": 50, "tempo_retirada_min": 25, "whatsapp": "55...", "endereco": "...", "horarios": {} },
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
    { "tipo": "montado", "item_id": null, "tamanho_id": 2, "massa_id": 1, "molho_id": 2, "ingrediente_ids": [1,2,7], "quantidade": 1, "observacoes": "" },
    { "tipo": "simples", "item_id": 8, "quantidade": 2, "observacoes": "" }
  ],
  "total_esperado_centavos": 5990,
  "t": ""
}
```
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

**Páginas.** O painel é uma SPA (D53): o servidor entrega a **mesma casca** para qualquer `/painel/{tela}` que o perfil possa abrir (`pedidos`, `salao`, `atendimento`, `historico`, `cardapio`, `configuracoes`, `whatsapp`) e o navegador troca de tela sem recarregar. Link direto e F5 funcionam. Tela que o perfil não abre redireciona para a tela inicial dele (atendente: `/painel/salao`; proprietário: `/painel/pedidos`).

| Método e rota | Perfis | O que faz |
|---|---|---|
| `POST /api/admin/login` | todos | `{login, senha}` → `{ir_para}` |
| `POST /api/admin/logout` | todos | |
| `POST /api/admin/senha` | todos | `{atual, nova}` (nova com 8+ caracteres) |
| `GET /api/admin/pedidos?desde=<ISO>` | admin, atendente | Sem `desde`: só os pedidos abertos. Com `desde`: o que mudou (inclusive o que acabou de encerrar, para o cartão sair do quadro). Devolve `{agora, pedidos, fechamento}`; use `agora` no próximo `desde` e para corrigir o relógio do aparelho. Cada pedido traz `producao_acumulado_s` e `producao_desde` (ver "Tempo de preparo" em `MODELO-DADOS.md`). `fechamento` = `{pedidos, concluidos, cancelados, em_andamento}` do dia da loja; o `faturamento_centavos` só vai para o admin (D40). |
| `GET /api/admin/pedidos/{id}` | admin, atendente | Pedido completo com `historico`. Pedido do salão traz `atendimento_id`. |
| `PATCH /api/admin/pedidos/{id}/status` | admin, atendente | `{status, motivo?}`. Cancelar exige `motivo`. Outra pessoa moveu antes → `409 conflito`. Cancelar pedido de uma conta já fechada → `409 conta_encerrada` (o valor já foi cobrado). |
| `GET /api/admin/atendimentos` | admin, atendente | **O quadro do Salão** (D48): atendimentos abertos + encerrados hoje, cada um com `fase`, pedidos e itens. Devolve `{agora, atendimentos, resumo}`; `resumo` = `{abertos, cozinha, pronto, conta, encerrados_hoje, valor_em_aberto_centavos, vendido_hoje_centavos}` (o `vendido_hoje_centavos` só vai para o admin). |
| `POST /api/admin/atendimentos` | admin, atendente | **Abre o atendimento e registra o primeiro pedido.** `{cliente: {nome}, modalidade: "mesa" \| "viagem", mesa?, itens, total_esperado_centavos?}`. Nome obrigatório (2+ letras); `mesa` exige o número; em `viagem` a mesa é descartada. Vale com a loja "fechada" para o cardápio. Mesmas regras e mesmo preço do cliente (itens como em `POST /api/pedidos`). Resposta `201 {id, pedido_id, numero, codigo}` (`id` é o do atendimento). Nada é gravado se algo falhar. |
| `GET /api/admin/atendimentos/{id}` | admin, atendente | Atendimento completo: dados da conta, `pedidos` (cada um com seu `status`, `itens` e `montagem`), `total_centavos`, `quantidade_itens`, `pedidos_ativos`, `fase`, `aberto_por_nome` e `encerrado_por_nome`. |
| `POST /api/admin/atendimentos/{id}/pedidos` | admin, atendente | **Soma mais produtos à conta aberta** (novo pedido, com status próprio). `{itens, total_esperado_centavos?}` → `201 {id, numero, codigo}`. Conta fechada → `409 atendimento_encerrado`. |
| `POST /api/admin/atendimentos/{id}/servir` | admin, atendente | "Marcar servido": passa para Concluído os pedidos **prontos** da conta. A conta continua aberta. Devolve o atendimento. |
| `POST /api/admin/atendimentos/{id}/fechar` | admin, atendente | **Fechar a conta** (D49). `{pagamento: "pix" \| "cartao" \| "dinheiro", total_esperado_centavos}`. Registra forma de pagamento, valor cobrado, data/hora e quem fechou; status → `encerrado`. `pagamento` é obrigatório quando há valor (`422 validacao`); conta sem nada a cobrar (tudo cancelado) fecha sem pagamento. Total conferido diferente do da conta → `409 total_mudou` (entrou ou saiu pedido); conta já fechada → `409 ja_encerrado` (duplo clique não cobra duas vezes). Devolve o atendimento. |
| `GET /api/admin/historico?periodo=hoje\|7d\|30d&status=aberto\|encerrado` | **admin** | Histórico de atendimentos e pagamentos (D52). `{periodo, atendimentos, resumo}`; `resumo` = `{quantidade, encerrados, abertos, total_cobrado_centavos, por_pagamento: {pix, cartao, dinheiro}}`. Atendente recebe `403`. |
| `POST /api/admin/pedidos/{id}/impressao` | admin, atendente | `{estacao_id, estacao_nome, modo: "auto" \| "manual"}` → `{imprimir, pedido}`. Em `auto`, só a primeira estação recebe `imprimir: true`. |
| `GET /api/admin/cardapio` | todos | Cardápio completo, inclusive ocultos; itens trazem `ingrediente_ids`. |
| `POST /api/admin/cardapio/{recurso}` | admin | Cria. `recurso`: `categorias`, `itens`, `tamanhos`, `massas`, `molhos`, `ingredientes`. |
| `PUT /api/admin/cardapio/{recurso}/{id}` | admin | Edita (só os campos enviados). Itens aceitam `ingrediente_ids`. |
| `PATCH /api/admin/cardapio/{recurso}/{id}/estado` | admin, atendente* | `{estado}`. *A atendente só alterna disponível/esgotado (não oculta). |
| `DELETE /api/admin/cardapio/{recurso}/{id}` | admin | Categoria com itens → `409 categoria_com_itens`. |
| `POST /api/admin/upload` | admin | multipart, campo `imagem` (JPG, PNG ou WebP até 1,5 MB; o tipo é conferido pelo conteúdo) → `{caminho}` |
| `GET /api/admin/configuracoes` | admin | Todas as configurações + `estado_agora`. |
| `PUT /api/admin/configuracoes` | admin | Salva tudo de uma vez; devolve igual ao GET. |
| `GET /api/admin/whatsapp` | admin | Quais credenciais estão preenchidas (nunca os valores), HTTPS, contadores de 24 h. |
| `POST /api/admin/whatsapp/link` | admin | `{telefone}` → `{url, validade_minutos}`: o mesmo link que o bot vai mandar. |

## Serviços de terceiros usados pelo navegador (só no mapa da entrega)

O mapa do fechamento do pedido de entrega fala **direto do navegador** com o OpenStreetMap, sem chave de acesso (D38):

- `tile.openstreetmap.org`: as imagens do mapa;
- `nominatim.openstreetmap.org/reverse` e `/search`: o endereço de um ponto e o ponto de um endereço.

O OpenStreetMap exige saber de que site vem o pedido, então só essas chamadas mandam o endereço do site (`referrerPolicy: origin`); o resto do site continua com `Referrer-Policy: same-origin`. As coordenadas do cliente vão para o Nominatim, que é um serviço de voluntários com limite de uso (1 consulta por segundo, sem uso pesado). Com a loja crescendo, o caminho é um provedor de mapas contratado (a troca é a URL das imagens em `public/assets/js/cardapio/mapa.js`). A biblioteca Leaflet 1.9.4 fica no próprio site em `public/assets/vendor/leaflet/` (licença BSD-2 junto).

## Ainda não existe (Etapa 4)

- `GET/POST /webhook/whatsapp.php` (verificação da Meta, assinatura `X-Hub-Signature-256`, idempotência por `wamid`).
- Envio de avisos de status ao cliente e retentativa por cron.
