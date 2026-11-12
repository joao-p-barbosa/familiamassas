# Decisões

Toda decisão do projeto, com data e motivo. As marcadas **(confirmar)** foram tomadas por mim para não travar a construção e dependem da sua palavra.

## Decisões do briefing (seção 5 do PROMPT-MVP)

| # | Data | Decisão | Motivo |
|---|---|---|---|
| D1 | 2026-11-11 | WhatsApp pela **Cloud API oficial da Meta**, conexão direta (sem BSP e sem Embedded Signup). | Loja única. Conversas iniciadas pelo cliente são gratuitas. Não há risco de banimento do número. |
| D2 | 2026-11-11 | Bot do MVP é **link-first + 3 botões** ("Fazer pedido", "Acompanhar pedido", "Falar com atendente"). Sem LLM. | O montador web resolve a montagem do prato. Botões custam zero e não erram. |
| D3 | 2026-11-11 | Pedido único no banco; Kanban, impressão e aviso ao cliente são efeitos dele. | Evita pedido que aparece num lugar e some em outro. |
| D4 | 2026-11-11 | Kanban **prático**: cada card tem um botão grande de avanço. Arrastar é bônus, não o caminho principal. | Cozinha com mão suja/ocupada. Toque único é mais rápido que arrastar. |
| D5 | 2026-11-11 | Atualização do Kanban por **polling** (intervalo inicial 5 s). Sem WebSocket. | Hospedagem compartilhada não mantém conexões longas. Volume de 30–50 pedidos/dia não justifica. |
| D6 | 2026-11-11 | Impressão: impressora **USB ou Bluetooth**. Caminho A (Chrome em modo kiosk-printing) primeiro; B/C se o hardware não permitir. | Evita instalar e manter um programa local no MVP. |
| D7 | 2026-11-11 | Pedido feito no cardápio web sempre passa por **revisão do pedido antes de confirmar**. | Pedido errado na cozinha é o prejuízo real. |
| D8 | 2026-11-11 | `pedidos.origem` existe desde o início. | Prepara iFood/99 (Open Delivery) e balcão sem migração. |
| D9 | 2026-11-11 | LLM, WhatsApp Flows, CRM, custos e dashboard ficam no **Pós-MVP**. | Validar o ciclo do pedido primeiro. |

## Decisões tomadas durante a construção

| # | Data | Decisão | Motivo |
|---|---|---|---|
| D10 | 2026-11-11 | As perguntas da Etapa 0 ficaram **sem resposta** e o front (Etapas 1, 2, 3 e 5) foi construído com valores supostos, listados em D26. Os spikes de impressão e WhatsApp continuam pendentes. | Você pediu para seguir até o front completo e validar depois. Os spikes dependem da impressora real e da conta Meta. |
| D11 | 2026-11-11 | Dinheiro sempre em **centavos** (inteiro). Datas em texto `AAAA-MM-DD HH:MM:SS`, em UTC, geradas pelo PHP. "Sim/não" como 0/1. | O mesmo SQL roda em SQLite e em Postgres (Neon). Centavos evitam erro de arredondamento. |
| D12 | 2026-11-11 | **(confirmar)** Quando o cliente passa da quantidade inclusa, os ingredientes **mais caros** contam como inclusos e os mais baratos são cobrados como extra. | O preço não depende da ordem em que o cliente clicou, e o cliente sai ganhando. A alternativa (cobrar os últimos escolhidos) muda o preço conforme a ordem dos cliques. |
| D13 | 2026-11-11 | **O preço que vale é o do servidor.** O navegador calcula igual (`shared/preco.js`) só para mostrar. A revisão final manda o total que o cliente viu; se o cardápio mudou nesse meio-tempo, o servidor recusa e a tela mostra o total novo. | Ninguém consegue mudar preço pelo navegador, e o cliente nunca paga um valor que não viu. |
| D14 | 2026-11-11 | **(confirmar)** Prato da casa = montagem sugerida (massa, molho e ingredientes). O cliente escolhe o tamanho e paga pela regra do "Monte seu prato" ("a partir de"). Se mudar qualquer escolha, o prato vira "Monte seu prato". | Um preço só para tudo, sem tabela paralela de pratos da casa. A cozinha nunca recebe "Carbonara" que não é carbonara. |
| D15 | 2026-11-11 | O carrinho guarda só **ids e quantidades** no aparelho. Nome e preço vêm sempre do cardápio atual; item que esgotou aparece marcado e trava o "Fechar pedido". | Carrinho velho não engana o cliente nem a cozinha. |
| D16 | 2026-11-11 | A página de acompanhamento usa um **código aleatório** (`/pedido/4f8c5109eaacf263`), não o número do pedido, e mostra só o primeiro nome, sem telefone nem endereço. | Trocar o número na barra de endereço não mostra o pedido de outra pessoa. |
| D17 | 2026-11-11 | Link do WhatsApp: o token sai da barra de endereço assim que a página abre (fica só na aba) e o WhatsApp vem preenchido e travado no checkout. Token inválido ou vencido é ignorado sem aviso. | Se o cliente compartilhar o link do cardápio, o telefone dele não vai junto. |
| D18 | 2026-11-11 | Kanban em 5 colunas: Novos · Em preparo · Prontos · Entrega e retirada · Encerrados hoje. O botão do cartão diz a ação ("Iniciar preparo", "Marcar pronto", "Saiu para entrega", "Chamar para retirada", "Concluir"). | Uma coluna por etapa que a equipe olha; "Saiu para entrega" e "Aguardando retirada" são o mesmo momento (o pedido saiu da cozinha). |
| D19 | 2026-11-11 | Arrastar pela **alça** do cartão (GSAP Draggable + Flip). A mesma alça, clicada ou pelo teclado, abre "Mover para…". Se o CDN do GSAP falhar, tudo funciona pelos botões. | Rolar a lista no tablet não arrasta cartão sem querer. Atende o pedido de alternativa por teclado. |
| D20 | 2026-11-11 | Perfis: **admin** faz tudo; **atendente** vê Pedidos, Cozinha e Cardápio (só marca esgotado) e cancela pedido; **cozinha** vê Cozinha e Cardápio (só marca esgotado), move pedidos só até "Pronto" e não cancela. Configurações e WhatsApp só para admin. | Cada um vê o que usa. A cozinha não cancela pedido sem passar pelo atendimento. |
| D21 | 2026-11-11 | Cancelar exige motivo (4 motivos prontos + texto livre). O cliente vê o motivo na página do pedido. | O cliente merece saber; a loja ganha histórico. |
| D22 | 2026-11-11 | Impressão: cada aparelho liga "Imprimir comandas neste aparelho" (guardado no próprio navegador). O servidor faz o *claim* atômico; só quem ganhou imprime. "Reimprimir" sempre imprime. Comanda **sem preço** por padrão (configurável), papel de 80 mm por padrão. A comanda usa Arial: térmica não deve depender de baixar fonte. | Comanda uma vez só mesmo com dois navegadores abertos (D6 e seção 9 do briefing). |
| D23 | 2026-11-11 | Polling do painel pede só o que mudou (`?desde=`), com 2 s de folga. `pedidos.atualizado_em` muda em qualquer alteração (status ou impressão). | Nada que mudou "no mesmo segundo" se perde; pedido repetido não faz mal. |
| D24 | 2026-11-11 | PUT, PATCH e DELETE chegam como POST com o cabeçalho `X-HTTP-Method-Override`. | Algumas hospedagens compartilhadas bloqueiam esses métodos. |
| D25 | 2026-11-12 | Sessões do painel em `data/sessoes` (fora de `/public`), válidas por 12 h sem uso. | Na hospedagem compartilhada a pasta temporária é dividida com outros sites, que podem apagar sessões cedo. O padrão do PHP derruba o login depois de 24 min parado. |
| D26 | 2026-11-11 | **(confirmar)** Valores supostos da Etapa 0, todos editáveis no painel: nome **"Família Massas"** (nome da pasta do projeto); aberto de quarta a domingo, 11:00–23:00; taxa de entrega R$ 6,00; entrega em 50 min e retirada em 25 min; P R$ 24,90 (300 g, 3 inclusos), M R$ 31,90 (400 g, 4), G R$ 39,90 (550 g, 6); extras de R$ 3,00 a R$ 9,00; nhoque +R$ 4,00, sem glúten +R$ 5,00; bolonhesa +R$ 3,00, pesto +R$ 4,00, quatro queijos +R$ 5,00. | O cardápio precisava de dados para ser testado. |
| D27 | 2026-11-11 | Tipografia: **Young Serif** (títulos e nome da loja) + **Hanken Grotesk** (todo o resto), servidas pelo próprio site com `font-display: swap`. Ícones próprios em SVG, traço único de 1,75. | Identidade própria sem copiar iFood/Brendi; nenhuma dependência do Google em produção. |
| D28 | 2026-11-11 | Cores dos temas ajustadas para contraste AA: o verde `#009246` vira `#007a3a` onde carrega texto (5,4:1 com branco) e o vermelho `#CE2B37` vira `#c42632`. Os originais ficam em `--cor-marca-*`. O teste `tests/contraste.mjs` confere 26 pares em cada um dos 4 temas: todos passam. | Pedido explícito do briefing (seção 7.1). |
| D29 | 2026-11-11 | No tema Itália, o verde é a ação (botões, seleção, "deu certo") e o vermelho é a marca (ponto do nome) e o alerta (erro, cancelar, atraso). O único bloco em cor cheia do cardápio é o destaque "Monte seu prato". | "Acentos com intenção", sem faixa de bandeira em tudo. |
| D30 | 2026-11-11 | No montador, tocar numa escolha única (tamanho, massa, molho) já avança o passo. Pelas setas do teclado não avança. Prato da casa vai do tamanho direto para a revisão. | Meta do briefing: pedido completo em menos de 1 minuto no celular. |
| D31 | 2026-11-11 | No modo cozinha, cada toque em "Começar" ou "Pronto" mostra "Desfazer" por 6 s. | Toque errado acontece com a mão ocupada. |
| D32 | 2026-11-11 | O tema já é escolhido em **Configurações** (Etapa 5), com prévia ao vivo. | A Etapa 5 do briefing lista o tema nas configurações da loja. |

## Pendências que dependem de você

- Responder as perguntas da Etapa 0 (lista em `docs/PROGRESSO.md`).
- Confirmar D12, D14 e D26.
- Spike de impressão no hardware real e spike do WhatsApp na hospedagem real.
