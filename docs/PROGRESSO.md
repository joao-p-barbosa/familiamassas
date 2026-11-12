# Progresso

**Situação em 2026-11-12:** front completo (cardápio do cliente + painel de gestão) com back-end funcionando em SQLite, **mais duas rodadas de melhorias** pedidas depois da primeira validação (manhã: mapa na entrega, acompanhamento na Home, Kanban único, menu lateral, perfil da atendente; tarde: ingredientes em Proteínas/Acompanhamentos/Queijos, **atendimentos do salão** com fechamento de conta, histórico do proprietário e **painel em SPA**). **Aguardando sua validação** antes de seguir para a Etapa 4 (bot do WhatsApp).

| Etapa | Situação |
|---|---|
| Rodada de melhorias (12/11, manhã) | ✅ Feita e testada; os 13 cenários pedidos estão no checklist abaixo. Decisões D33 a D46. |
| Rodada de ajustes (12/11, tarde) | ✅ Feita e testada: proteínas, atendimentos do salão, fechar conta, histórico, SPA e migrações. Os 13 critérios de aceite estão no checklist abaixo. Decisões D47 a D54. |
| 0 · Descoberta e spikes | ⚠️ Perguntas sem resposta (valores supostos em DECISOES D26). Spikes de impressão e WhatsApp pendentes. Docs, sitemap e fluxos feitos. |
| 1 · Design system | ✅ Feito. `/styleguide` com troca de tema ao vivo. |
| 2 · Cardápio + checkout | ✅ Feito. |
| 3 · Painel: login, Kanban, impressão | ✅ Feito (impressão testada só em simulação; falta a impressora real). O modo cozinha foi retirado na rodada de melhorias. |
| 4 · Bot WhatsApp | ⏳ Não começou. Já existem: tabelas, token assinado do link, link de teste e tela de integração. |
| 5 · Edição do cardápio | ✅ Feito (adiantado, faz parte da "página de gestão"). |
| 6 · Revisão final e piloto | ⏳ Não começou. |

## Como rodar

1. Dê dois cliques em **`iniciar.bat`** (na raiz). Ele liga o SQLite só para este servidor, sem mexer no `php.ini`.
2. Cardápio: <http://localhost:8090/> · Painel: <http://localhost:8090/painel> · Guia de estilo: <http://localhost:8090/styleguide>
3. Usuários (no desenvolvimento a senha inicial é **`trocar123`**; na hospedagem vem das variáveis `SENHA_*_INICIAL`, veja `docs/DEPLOY.md`; troque em Configurações → Minha senha): `admin` (proprietário) e `atendente`. O módulo Cozinha e o usuário `cozinha` foram retirados (D42).
4. No celular, na mesma rede Wi-Fi: o `iniciar.bat` mostra o IP do computador; abra `http://<IP>:8090/`. **A localização do aparelho no mapa só funciona em https** (ou em `localhost`): no celular por `http://<IP>` o mapa abre, avisa disso e o cliente arrasta o mapa ou digita o endereço.
5. Banco: `data/restaurante.sqlite`. Um banco que já existe é **atualizado sozinho** na primeira requisição (migrações, com cópia de segurança em `data/backups/`, D50): não precisa apagar nada. Para recomeçar do zero, pare o servidor e apague o arquivo (ele é recriado com os dados de exemplo).

**Testes automáticos** (na raiz):

```
php -d extension=pdo_sqlite tests/regras.php   # 164 testes: preço, grupos, pedido, atendimentos e fechar conta, histórico, tempo, fechamento do dia, perfis, cookie da Home, status, impressão, token, horários, produção, acesso em paralelo, migração
node tests/preco.test.mjs                      # o preço do navegador bate com o do servidor
node tests/tempo.test.mjs                      # as regras de tempo e cor do cartão no navegador
node tests/contraste.mjs                       # contraste AA dos 4 temas
```

## Sitemap

```
Cliente                                Painel (/painel), com menu lateral retrátil
/                cardápio               /painel/login          entrar             (atendente cai em /painel/salao)
  ├ seus pedidos (acompanhamento)       /painel/pedidos        Kanban             admin, atendente
  ├ Monte seu prato (folha, 6 passos)   /painel/salao          Kanban do Salão    admin, atendente
                                        /painel/atendimento    produtos da conta  admin, atendente (fora do menu)
                                        /painel/historico      histórico do salão admin
  ├ item pronto (folha)                 /painel/cardapio       cadastro           admin, atendente (só admin edita)
  ├ carrinho (lateral / folha)          /painel/configuracoes  loja e senha       admin
  └ fechar pedido → mapa → revisão      /painel/whatsapp       integração         admin
/pedido/{codigo} acompanhamento
/?t={token}      vindo do WhatsApp
/styleguide      guia de estilo
```

## Fluxos

**Pedido do cliente:** cardápio → "Monte seu prato" (tamanho Meio ou Inteiro → massa → molho → ingredientes → finalização → revisão) ou item pronto → carrinho → fechar pedido → **revisão final** → confirmar → página de acompanhamento. No fechamento o cliente escolhe **Entrega** (botão "Marcar no mapa", pino no centro, localização do aparelho se ele permitir, endereço preenchido e editável) ou **Retirada** ("Como chegar ao restaurante" abre o Google Maps; sem campo de observação). O pedido fica guardado neste navegador: ao voltar à Home, o acompanhamento aparece no topo (com a animação da etapa) e se atualiza sozinho a cada 10 s, mesmo depois de atualizar a página ou fechar o navegador.

**Atendimento no salão (atendente ou proprietário):** o **Salão** é o Kanban das contas abertas. **Novo pedido** → 1) nome do cliente (obrigatório) → 2) **Mesa** (com o número) ou **Pra viagem** → 3) produtos, com o mesmo montador, carrinho e preço do cliente → 4) "Enviar para o Salão" (sem pagamento). O atendimento nasce nesse envio e o pedido vai para a cozinha. Com a conta aberta, "Adicionar produtos" soma outro pedido à mesma conta (cada pedido tem o seu status). Quando os pedidos ficam Prontos, o cartão vai para "Prontos para servir" e a atendente é avisada; "Marcar servido" leva para "Aguardando a conta". **Fechar conta** → resumo de tudo (quantidades, valores e total) → escolhe Pix, Cartão ou Dinheiro → confirmar: o atendimento fica **encerrado** (com data/hora e pagamento) e não aceita mais produtos. O proprietário consulta tudo em **Histórico**.

**Ciclo no quadro (Kanban de 3 colunas):** pedido novo aparece em até ~5 s (som e destaque) → a estação de impressão imprime a comanda uma vez → "Iniciar preparo" → "Marcar pronto" → "Saiu para entrega" ou "Chamar para retirada" (continuam na coluna Prontos, com o status no cartão) → "Concluir" (o cartão sai do quadro e entra nos números de "Hoje"). Mesa e pra viagem: "Marcar pronto" → "Concluir". Cancelar a qualquer momento, com motivo. O cartão fica amarelo aos 20 min e vermelho aos 30 min de Novo + Em preparo.

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
- ✅ Perfis veem só o que devem (testado pela API: atendente não lê nem salva configurações, não vê o WhatsApp, não cria nem oculta item do cardápio, só marca esgotado; sem CSRF → 403). O perfil Cozinha foi retirado (D42).

### GATE 5
- ✅ Alteração no admin aparece no cardápio
- ✅ Esgotado reflete no montador: o cardápio aberto se atualiza a cada 1 min e ao voltar para a aba; quem já estava montando recebe o aviso na hora de confirmar e o carrinho marca o item
- ✅ Validações claras (preço, obrigatórios, categoria com itens não apaga)

### RODADA DE AJUSTES (12/11, tarde): proteínas, atendimentos do salão e SPA
Testados no navegador (celular 390 px e desktop 1280/1440 px) e com testes automáticos (164 no PHP). `⚠️` = o que só você consegue conferir.

Classificação dos ingredientes (seção 1 do pedido)
- ✅ **Proteínas**: Carne moída, Picadinho de frango, Carne seca, Camarão. **Acompanhamentos**: Bacon, Calabresa, Presunto e todos os vegetais. **Queijos**: sem mudança. As proteínas não foram para Acompanhamentos. Conferido no montador do cliente, no do salão e no cadastro do cardápio. Preço, regra de inclusos/extras, resumo e pedido seguem iguais; sem "queijo ralado". ⚠️ Confirme o preço de exemplo da Carne seca (R$ 7,00) e a troca do nome "Frango desfiado" para "Picadinho de frango" (D47).

Os 13 critérios de aceite (seção 6)
1. ✅ Login da atendente.
2. ✅ Cai direto no **Kanban Salão** (4 colunas: Na cozinha · Prontos para servir · Aguardando a conta · Encerrados hoje).
3. ✅ Botão destacado **Novo pedido**.
4. ✅ Etapa 1: nome do cliente, obrigatório (vazio é recusado).
5. ✅ Etapa 2: **Mesa** ou **Pra viagem** antes dos produtos (sem escolher, não avança).
6. ✅ Mesa exige o número da mesa; Pra viagem esconde o campo.
7. ✅ Etapa 3: vários produtos (prato montado + item pronto), com o mesmo montador, carrinho e preço do cliente.
8. ✅ Etapa 4: "Enviar para o Salão" (sem pagamento) e o cartão aparece no Kanban, com nome, mesa, modalidade, produtos, itens, status de cada pedido, hora de abertura e valor acumulado.
9. ✅ Acompanhar os status: o pedido anda no quadro de Pedidos e o cartão do Salão muda de coluna sozinho; a atendente é avisada ("pedido de X pronto para servir") em qualquer tela.
10. ✅ Conta aberta recebe mais produtos ("Adicionar produtos"): vira outro pedido da mesma conta, com status próprio; o valor acumulado soma.
11. ✅ **Fechar conta** abre o resumo de todos os produtos (quantidade, valor unitário e total) e avisa se ainda há pedido na cozinha.
12. ✅ Escolhe Pix, Cartão ou Dinheiro (sem escolher, não fecha).
13. ✅ Ao confirmar, o atendimento vira **encerrado** (data/hora e forma de pagamento gravadas) e recusa produtos novos (pela tela e pela API, `409 atendimento_encerrado`).

Regras e extras conferidos
- ✅ Atendimento e pedido separados: id próprio, vários pedidos, status independentes; conta aberta mesmo com tudo servido.
- ✅ O total soma cada pedido não cancelado uma vez; pedido cancelado não entra; duplo clique ou dois aparelhos não cobram duas vezes (`409 ja_encerrado`); total conferido diferente → `409 total_mudou`.
- ✅ Histórico para o proprietário (`/painel/historico`): período, situação, total por forma de pagamento, detalhe em leitura. Atendente: `403` na API e redirecionada na tela.
- ✅ Cada atendimento tem o seu carrinho (um não vaza para o outro); abandonar o assistente não cria conta vazia.
- ✅ **Painel em SPA**: trocar de tela pelo menu não recarrega a página; voltar/avançar, F5 em link direto e janelas abertas funcionam; som, impressão e avisos continuam em qualquer tela; sem erro no console.
- ✅ Sem rolagem lateral de 320 a 1600 px no Salão, Produtos do atendimento, Histórico e Pedidos (o quadro do Salão rola por dentro quando as 4 colunas não cabem).
- ✅ Banco antigo atualizado sozinho (migração 002, com cópia de segurança em `data/backups/`), testado em cópia do banco real e em teste automático.

### RODADA DE MELHORIAS (12/11, manhã): os 13 cenários pedidos
Testados no navegador (celular e desktop) e com testes automáticos. `⚠️` = o que só você consegue conferir.

1. ✅ Ingredientes nas categorias certas. *(Corrigido na rodada da tarde: são três grupos, Proteínas, Acompanhamentos e Queijos, D47.)* Sem "queijo ralado" em nenhuma etapa (montador, revisão, carrinho, comanda e pedido).
2. ✅ Criar pedido e atualizar a página: o acompanhamento continua na Home (testado atualizando a página e apagando o armazenamento do navegador). Pedido concluído ou cancelado mostra o resultado e pode ser dispensado.
3. ✅ Animação dos status: uma cena por etapa, muda sozinha quando o status muda, e para com "reduzir movimento".
4. ✅ Entrega autorizando a localização: testado com localização simulada e o OpenStreetMap de verdade (imagens e endereço). ⚠️ Falta testar com o GPS de um celular real, que exige o site em https.
5. ✅ Entrega sem autorizar: mensagem clara, mapa perto do restaurante, "Digitar o endereço" e o pedido segue normalmente.
6. ✅ Retirada: "Como chegar ao restaurante" abre o Google Maps com o endereço cadastrado. ⚠️ Depende do endereço real do restaurante em Configurações (hoje é um endereço de exemplo).
7. ✅ Pedido do salão pelo perfil da atendente (login leva direto ao Salão). *(Substituído pelo atendimento com conta, D48.)*
8. ✅ Pedido do salão pelo botão "Novo pedido" do quadro de Pedidos, que agora abre o assistente do Salão (proprietário e atendente).
9. ✅ Modalidade (Entrega, Retirada, Mesa 5, Pra viagem) e status em todos os cartões.
10. ✅ Cores nos limites 19, 20, 29 e 30 min: testado ao vivo (a cor muda sozinha no segundo certo) e nos testes automáticos, incluindo pedido que passa de Novo para Em preparo e pedido que volta de Pronto.
11. ✅ Sem os quadros de entrega/retirada, sem o de encerrados e sem a Cozinha: o fluxo de status segue funcionando (botão, arrastar e "Mover para…"). Os encerrados viram os números de "Hoje".
12. ✅ Permissões proprietário × atendente: conferidas pela API (403 onde não pode), pelas páginas (redireciona) e pelo menu.
13. ✅ Navegação em desktop e celular: sem rolagem lateral de 320 a 1440 px nas telas novas; menu recolhe no desktop e vira gaveta no celular. ⚠️ Falta testar num tablet de verdade.

## Perguntas da Etapa 0 (preciso das respostas)

1. Nome do restaurante (provisório "Família Massas").
2. Dias e horários de funcionamento.
3. Área de entrega e taxa (uma taxa só ou por bairro?).
4. Preço de cada tamanho e quantos ingredientes cada um inclui. Preço de cada extra e acréscimos de massa e molho.
5. Regra do extra: quando passa do incluso, cobra os mais baratos (como está, D12) ou os últimos escolhidos?
6. **Impressora:** modelo e conexão (USB ou Bluetooth).
7. **Aparelho que fica com o quadro de pedidos** (na cozinha ou no balcão): PC com Windows? Tablet Android?
8. **Hospedagem:** qual? Tem HTTPS, cron e `pdo_pgsql`?
9. **WhatsApp:** número novo ou já usado? Está no app WhatsApp Business (Coexistência)? Já existe conta no Meta Business?
10. Fotos dos pratos: existem? (o cadastro já aceita foto)

## O que falta depois da validação

- **Etapa 4** (você pediu para seguir): webhook, bot de 3 botões, avisos de status ao cliente, atendimento humano. Depende de fora: conta no Meta Business, hospedagem com HTTPS e as respostas da Etapa 0.
- Etapa 6: revisão final, script SQL do Postgres (Neon), plano de produção e piloto.
