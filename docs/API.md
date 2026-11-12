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
| 409 | conflito de estado: `esgotado`, `item_removido`, `total_mudou`, `loja_fechada`, `conflito`, `pedido_encerrado`, `categoria_com_itens` |
| 422 `validacao` | campos inválidos (`campos` traz um erro por campo) |
| 429 `muitas_tentativas` | login bloqueado por 5 min |

**PUT, PATCH e DELETE** podem chegar como `POST` com o cabeçalho `X-HTTP-Method-Override: PATCH` (D24). O front sempre faz assim.

## Pública (cardápio)

### `GET /api/cardapio`
Loja + cardápio sem nada oculto (esgotados vêm, com `estado: "esgotado"`).

```json
{
  "loja": { "nome": "...", "tema": "italia", "aberta": true, "mensagem": "Aberto até 23:00", "tipos_pedido": ["entrega","retirada","local"],
            "taxa_entrega_centavos": 600, "area_entrega": "...", "tempo_entrega_min": 50, "tempo_retirada_min": 25, "whatsapp": "55...", "endereco": "...", "horarios": {} },
  "categorias": [], "itens": [], "tamanhos": [], "massas": [], "molhos": [], "ingredientes": []
}
```

### `POST /api/pedidos`
```json
{
  "tipo": "entrega",
  "cliente": { "nome": "Maria", "telefone": "(11) 91234-5678" },
  "endereco": { "rua": "...", "numero": "10", "bairro": "...", "complemento": "", "referencia": "" },
  "mesa": "",
  "pagamento": { "forma": "dinheiro", "troco_para_centavos": 10000 },
  "observacoes": "",
  "itens": [
    { "tipo": "montado", "item_id": null, "tamanho_id": 2, "massa_id": 1, "molho_id": 2, "ingrediente_ids": [1,2,7], "queijo_ralado": true, "quantidade": 1, "observacoes": "" },
    { "tipo": "simples", "item_id": 8, "quantidade": 2, "observacoes": "" }
  ],
  "total_esperado_centavos": 5990,
  "t": ""
}
```
- O servidor **recalcula todos os preços**; nenhum preço do navegador é aceito (D13).
- `total_esperado_centavos` (opcional): o total que o cliente viu na revisão. Diferente do calculado → `409 total_mudou`.
- `t` (opcional): token do link do WhatsApp. Válido → telefone vem do token e `origem = whatsapp`.
- Para prato da casa sem alteração, `tipo: "montado"` com `item_id` do prato.

Resposta `201`: `{ "numero": 12, "codigo": "4f8c5109eaacf263" }`

### `GET /api/pedidos/{codigo}`
O que a página de acompanhamento mostra: `numero`, `status`, `rotulo`, `tipo`, `primeiro_nome`, `etapas` (`[{status, rotulo, feita, em}]`), `motivo_cancelamento`, `previsao_minutos`, `itens`, totais e `pagamento`. Sem telefone e sem endereço (D16).

## Painel (sessão + CSRF)

Toda escrita exige o cabeçalho `X-CSRF-Token` (o valor está na `<meta name="csrf">` das páginas do painel).

| Método e rota | Perfis | O que faz |
|---|---|---|
| `POST /api/admin/login` | todos | `{login, senha}` → `{ir_para}` |
| `POST /api/admin/logout` | todos | |
| `POST /api/admin/senha` | todos | `{atual, nova}` (nova com 8+ caracteres) |
| `GET /api/admin/pedidos?desde=<ISO>` | todos | Sem `desde`: abertos + encerrados de hoje. Com `desde`: só o que mudou. Devolve `{agora, pedidos}`; use `agora` no próximo `desde`. |
| `GET /api/admin/pedidos/{id}` | todos | Pedido completo com `historico`. |
| `PATCH /api/admin/pedidos/{id}/status` | todos* | `{status, motivo?}`. *Cozinha só até `pronto` e não cancela. Cancelar exige `motivo`. Outra pessoa moveu antes → `409 conflito`. |
| `POST /api/admin/pedidos/{id}/impressao` | todos | `{estacao_id, estacao_nome, modo: "auto" \| "manual"}` → `{imprimir, pedido}`. Em `auto`, só a primeira estação recebe `imprimir: true`. |
| `GET /api/admin/cardapio` | todos | Cardápio completo, inclusive ocultos; itens trazem `ingrediente_ids`. |
| `POST /api/admin/cardapio/{recurso}` | admin | Cria. `recurso`: `categorias`, `itens`, `tamanhos`, `massas`, `molhos`, `ingredientes`. |
| `PUT /api/admin/cardapio/{recurso}/{id}` | admin | Edita (só os campos enviados). Itens aceitam `ingrediente_ids`. |
| `PATCH /api/admin/cardapio/{recurso}/{id}/estado` | todos* | `{estado}`. *Atendente e cozinha só alternam disponível/esgotado. |
| `DELETE /api/admin/cardapio/{recurso}/{id}` | admin | Categoria com itens → `409 categoria_com_itens`. |
| `POST /api/admin/upload` | admin | multipart, campo `imagem` (JPG, PNG ou WebP até 1,5 MB; o tipo é conferido pelo conteúdo) → `{caminho}` |
| `GET /api/admin/configuracoes` | admin | Todas as configurações + `estado_agora`. |
| `PUT /api/admin/configuracoes` | admin | Salva tudo de uma vez; devolve igual ao GET. |
| `GET /api/admin/whatsapp` | admin | Quais credenciais estão preenchidas (nunca os valores), HTTPS, contadores de 24 h. |
| `POST /api/admin/whatsapp/link` | admin | `{telefone}` → `{url, validade_minutos}`: o mesmo link que o bot vai mandar. |

## Ainda não existe (Etapa 4)

- `GET/POST /webhook/whatsapp.php` (verificação da Meta, assinatura `X-Hub-Signature-256`, idempotência por `wamid`).
- Envio de avisos de status ao cliente e retentativa por cron.
