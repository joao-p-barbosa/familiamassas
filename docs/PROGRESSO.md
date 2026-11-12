# Progresso

**Situação em 2026-11-12:** front completo (cardápio do cliente + painel de gestão) com back-end funcionando em SQLite. **Aguardando sua validação** antes de seguir para a Etapa 4 (bot do WhatsApp).

| Etapa | Situação |
|---|---|
| 0 · Descoberta e spikes | ⚠️ Perguntas sem resposta (valores supostos em DECISOES D26). Spikes de impressão e WhatsApp pendentes. Docs, sitemap e fluxos feitos. |
| 1 · Design system | ✅ Feito. `/styleguide` com troca de tema ao vivo. |
| 2 · Cardápio + checkout | ✅ Feito. |
| 3 · Painel: login, Kanban, cozinha, impressão | ✅ Feito (impressão testada só em simulação; falta a impressora real). |
| 4 · Bot WhatsApp | ⏳ Não começou. Já existem: tabelas, token assinado do link, link de teste e tela de integração. |
| 5 · Edição do cardápio | ✅ Feito (adiantado, faz parte da "página de gestão"). |
| 6 · Revisão final e piloto | ⏳ Não começou. |

## Como rodar

1. Dê dois cliques em **`iniciar.bat`** (na raiz). Ele liga o SQLite só para este servidor, sem mexer no `php.ini`.
2. Cardápio: <http://localhost:8090/> · Painel: <http://localhost:8090/painel> · Guia de estilo: <http://localhost:8090/styleguide>
3. Usuários (senha inicial **`trocar123`**, troque em Configurações → Minha senha): `admin`, `atendente`, `cozinha`.
4. No celular, na mesma rede Wi-Fi: o `iniciar.bat` mostra o IP do computador; abra `http://<IP>:8090/`.
5. Banco: `data/restaurante.sqlite`. Para recomeçar do zero, pare o servidor e apague esse arquivo (ele é recriado com os dados de exemplo).

**Testes automáticos** (na raiz):

```
php -d extension=pdo_sqlite tests/regras.php   # 43 testes: preço, pedido, status, impressão, token, horários
node tests/preco.test.mjs                      # o preço do navegador bate com o do servidor
node tests/contraste.mjs                       # contraste AA dos 4 temas
```

## Sitemap

```
Cliente                                Painel (/painel)
/                cardápio               /painel/login          entrar
  ├ Monte seu prato (folha, 6 passos)   /painel/pedidos        Kanban            admin, atendente
  ├ item pronto (folha)                 /painel/cozinha        modo cozinha      todos
  ├ carrinho (lateral / folha)          /painel/cardapio       cadastro          todos (só admin edita)
  └ fechar pedido → revisão final       /painel/configuracoes  loja e senha      admin
/pedido/{codigo} acompanhamento         /painel/whatsapp       integração        admin
/?t={token}      vindo do WhatsApp
/styleguide      guia de estilo
```

## Fluxos

**Pedido do cliente:** cardápio → "Monte seu prato" (tamanho → massa → molho → ingredientes → finalização → revisão) ou item pronto → carrinho → fechar pedido (tipo, dados, pagamento) → **revisão final** → confirmar → página de acompanhamento, que se atualiza sozinha a cada 10 s.

**Ciclo no Kanban:** pedido novo aparece em até ~5 s (som e destaque) → a estação de impressão imprime a comanda uma vez → "Iniciar preparo" → "Marcar pronto" → "Saiu para entrega" ou "Chamar para retirada" → "Concluir". Mesa: "Marcar pronto" → "Concluir". Cancelar a qualquer momento, com motivo.

**Conversa do bot (Etapa 4, ainda não feito):** qualquer mensagem → boas-vindas + 3 botões → "Fazer pedido" manda o link `/?t=<token>` (já funciona: teste em Painel → WhatsApp → Link de teste).

## Checklists dos gates

### GATE 0
- ⚠️ Perguntas respondidas e registradas: **pendente** (lista abaixo)
- ⚠️ Comanda de teste impressa no hardware real: **pendente**. A comanda de teste já sai pelo botão em Configurações.
- ⚠️ Webhook recebeu e respondeu uma mensagem real: **pendente** (precisa da conta Meta e da hospedagem com HTTPS)
- ✅ Sitemap, fluxos, modelo de dados e contrato de API (este arquivo, `MODELO-DADOS.md`, `API.md`)

### GATE 1
- ✅ Temas trocam sem recarregar e sem quebrar nada (`/styleguide` e Configurações; sem rolagem lateral em nenhum tema)
- ✅ Contraste AA verificado em todos os temas: 26 pares × 4 temas, todos passam (`tests/contraste.mjs`)
- ✅ Teclado e foco visível: primeiro Tab é "Pular para o cardápio"; foco volta ao botão ao fechar uma folha; anel de foco em todo controle
- ⚠️ Revisão de design: o detector automático rodou (1 aviso, esperado: Arial na comanda impressa). A revisão completa por um revisor independente não foi feita.

### GATE 2
- ⚠️ Pedido completo no celular em menos de 1 minuto: do "Montar meu prato" ao carrinho são cerca de 10 toques (com 3 ingredientes), porque tamanho, massa e molho avançam sozinhos; falta cronometrar com uma pessoa de verdade
- ✅ 360, 390, 768, 1280 e 1440 px testados (e 320): nenhuma tela com rolagem lateral, inclusive com nomes e endereços enormes
- ✅ Regras de inclusos/extras: 8 casos conferidos no PHP e no JS com o mesmo resultado (ex.: Médio, bolonhesa, 6 ingredientes = R$ 40,90, extras Brócolis e Milho)
- ✅ Esgotados (Palmito e Chá gelado vêm esgotados no exemplo); estados de carregamento, vazio, erro, loja fechada e carrinho com item esgotado
- ✅ `prefers-reduced-motion`: folhas e passos aparecem só por opacidade, sem deslizar
- ✅ Lighthouse mobile (servidor local): **Performance 97 · Acessibilidade 100 · Boas práticas 100**

### GATE 3
- ✅ Pedido do cardápio aparece no Kanban em até ~5 s (medido: 4,9 s) e a comanda sai **uma vez só** (simulado: 1 impressão por pedido, nenhuma repetição no ciclo seguinte; claim atômico testado com duas estações)
- ⚠️ Reimprimir funciona ✅; comanda legível em 58/80 mm: prévia na tela ok, **falta papel real**
- ✅ Mudança de status persiste e aparece no acompanhamento do cliente
- ⚠️ Funciona por teclado ✅ e com clique/arrasto ✅; **falta testar num tablet de verdade**
- ✅ Perfis veem só o que devem (testado pela API: cozinha não conclui, não cancela, não oculta, não edita; atendente não edita cardápio nem vê WhatsApp; sem CSRF → 403)

### GATE 5
- ✅ Alteração no admin aparece no cardápio
- ✅ Esgotado reflete no montador: o cardápio aberto se atualiza a cada 1 min e ao voltar para a aba; quem já estava montando recebe o aviso na hora de confirmar e o carrinho marca o item
- ✅ Validações claras (preço, obrigatórios, categoria com itens não apaga)

## Perguntas da Etapa 0 (preciso das respostas)

1. Nome do restaurante (provisório "Família Massas").
2. Dias e horários de funcionamento.
3. Área de entrega e taxa (uma taxa só ou por bairro?).
4. Preço de cada tamanho e quantos ingredientes cada um inclui. Preço de cada extra e acréscimos de massa e molho.
5. Regra do extra: quando passa do incluso, cobra os mais baratos (como está, D12) ou os últimos escolhidos?
6. **Impressora:** modelo e conexão (USB ou Bluetooth).
7. **Aparelho da cozinha:** PC com Windows? Tablet Android?
8. **Hospedagem:** qual? Tem HTTPS, cron e `pdo_pgsql`?
9. **WhatsApp:** número novo ou já usado? Está no app WhatsApp Business (Coexistência)? Já existe conta no Meta Business?
10. Fotos dos pratos: existem? (o cadastro já aceita foto)

## O que falta depois da validação

- Etapa 4: webhook, bot de 3 botões, avisos de status ao cliente, atendimento humano.
- Etapa 6: revisão final, script SQL do Postgres (Neon), plano de produção e piloto.
