# Publicar na internet (Render)

## Vercel? Não serve para este sistema

A Vercel não roda PHP de forma oficial (só por um runtime da comunidade, `vercel-php`), e o disco das funções dela é só leitura, com uma pasta temporária que some. O sistema grava o banco SQLite, as sessões e as fotos em arquivo, então perderia tudo. Por isso a hospedagem é na **Render**, que roda o sistema num contêiner Docker (PHP 8.3 + Apache), com HTTPS.

## Passo a passo

1. Abra o link e entre com a sua conta da Render (pode ser com o GitHub): <https://render.com/deploy?repo=https://github.com/joao-p-barbosa/familiamassas>
2. A Render lê o `render.yaml` do repositório e pede **duas senhas**: `SENHA_ADMIN_INICIAL` (proprietário) e `SENHA_ATENDENTE_INICIAL` (atendente). Escolha senhas com 8 ou mais caracteres. Elas **não** ficam no Git.
3. Clique em **Apply** e espere a construção (alguns minutos na primeira vez).
4. Abra o endereço que a Render mostra (algo como `https://familia-massas.onrender.com`): o cardápio é a Home e o painel é `/painel`. Entre com `admin` (ou `atendente`) e a senha que você digitou.
5. O serviço fica com Auto-Deploy "On Commit" (`autoDeployTrigger: commit`): cada `git push` na `main` publica uma versão nova sozinho. **Isso só funciona com o aplicativo "Render" instalado no GitHub** (GitHub → Settings → Applications → *Installed GitHub Apps*; instale em <https://github.com/apps/render/installations/new>, escolhendo "Only select repositories" e o repositório do sistema). Sem ele o GitHub não avisa a Render dos pushes: o deploy manual (**Manual Deploy → Deploy latest commit** no painel da Render) funciona, mas o automático não. Foi o que aconteceu na primeira publicação; com o aplicativo instalado, o gatilho passa a aparecer como "Auto-Deploy" na lista de deploys (testado: um push publicou em cerca de 30 s).

## O que o plano gratuito faz (e o que não faz)

| Limite | Efeito |
|---|---|
| Dorme depois de 15 min sem acesso | O primeiro acesso depois disso demora cerca de 1 minuto para acordar. |
| Disco temporário | **Sem o Neon**, o banco, as sessões e as fotos somem quando o serviço reinicia, dorme ou recebe uma versão nova (o banco volta com os dados de exemplo e as senhas das variáveis). **Com o Neon** (seção abaixo) nada disso se perde. |

Sem o Neon (abaixo), o plano gratuito serve para **demonstração e portfólio**. A alternativa ao Neon é um disco persistente, que a Render só oferece em plano pago:

1. No `render.yaml`, troque `plan: free` por `plan: starter`, descomente o bloco `disk` e a variável `DATA_DIR` (`/var/data`).
2. Faça o push. Com `DATA_DIR`, o contêiner guarda o banco, as sessões, as fotos e as cópias de segurança no disco (o `docker/entrypoint.sh` faz a ligação).

## Dados que não somem: banco no Neon (Postgres)

O disco do plano gratuito da Render é temporário, então o que fica em arquivo some quando o serviço reinicia. Com o **Neon** (Postgres gratuito) tudo o que importa passa a ficar no banco e sobrevive a reinícios, a deploys e ao "dormir" do serviço:

| O quê | Onde fica com o Neon |
|---|---|
| Cardápio, pedidos, atendimentos, usuários, configurações | tabelas do Postgres (`data/schema.pgsql.sql`) |
| Sessões do painel (login) | tabela `sessoes`: ninguém é deslogado quando o serviço reinicia |
| Fotos do cardápio enviadas pelo painel | tabela `imagens` (bytea), entregues por `GET /uploads/{nome}` |
| Versão do esquema | tabela `esquema_versao` |

**Como ligar:**
1. Crie um projeto no [Neon](https://console.neon.tech) (plano gratuito; região **AWS US East 1 (N. Virginia)**, a mesma da Render).
2. No projeto, clique em **Connect** e copie a *connection string* (`postgresql://usuario:senha@host/neondb?sslmode=require`). Ela tem a senha do banco: trate como segredo. O Neon mostra por padrão a do *pooler* (host com `-pooler`); pode colar essa mesma: o sistema usa sempre a conexão **direta** (o mesmo endereço sem `-pooler`) e faz a troca sozinho, porque o pooler (PgBouncer em modo transação) não aceita a transação com bloqueio exclusivo que cria e migra o banco.
3. Na Render, abra o serviço → **Environment** → adicione `DATABASE_URL` com essa string → salve. Com `DATABASE_URL` o sistema passa a usar o Postgres sozinho (sem ela, usa o SQLite).
4. Na primeira requisição o sistema cria as tabelas e os dados de exemplo no Neon (uma vez só, mesmo com várias requisições juntas). As senhas iniciais vêm de `SENHA_ADMIN_INICIAL` e `SENHA_ATENDENTE_INICIAL`, como antes.

Limites do plano gratuito do Neon: 0,5 GB, e o banco "dorme" depois de uns 5 minutos parado (o primeiro acesso depois disso leva um instante a mais). Para o tamanho de um restaurante isso sobra. O Neon guarda o histórico das últimas horas, que permite voltar o banco no tempo em caso de erro.

**Testar o Postgres no seu computador** (opcional): ponha `TEST_DATABASE_URL=<a mesma string>` no `.env` e rode `php -d extension=pdo_sqlite tests/regras.php pg` (o argumento `pg` liga o Postgres; sem ele os testes usam um SQLite temporário e levam segundos). A suíte inteira roda no Neon dentro de um *schema* temporário que ela cria e apaga no fim: os seus dados não são tocados. Para o sistema local usar o Neon (em vez do SQLite), ponha `DB_DRIVER=pgsql` e `DATABASE_URL=...` no `.env`.

## Variáveis de ambiente

| Variável | Para que serve |
|---|---|
| `APP_ENV=production` | Esconde detalhes de erro. |
| `APP_KEY` | Assina o link do WhatsApp. A Render sorteia. |
| `APP_TIMEZONE` | Fuso da loja (`America/Sao_Paulo`). |
| `TRUST_PROXY=1` | A Render fica na frente do site: vale o IP de quem acessa (`X-Forwarded-For`) no bloqueio de tentativas de login. Sem isso todo mundo teria o IP do proxy e o bloqueio pegaria todos de uma vez. |
| `SENHA_ADMIN_INICIAL`, `SENHA_ATENDENTE_INICIAL` | Senhas dos dois usuários quando o banco é criado. Sem elas (e fora do modo dev) o sistema sorteia uma e escreve no log do serviço; nunca nasce com a senha padrão. |
| `DATABASE_URL` | Connection string do Neon (veja acima). Liga o Postgres. |
| `DATA_DIR` | Só com SQLite em disco persistente (plano pago). Com o Neon não é preciso. |
| `WHATSAPP_*` | Credenciais da Meta, na Etapa 4. Coloque em **Environment** no painel da Render, nunca no repositório (ele é público). |

## Ligar o WhatsApp de verdade (Etapa 4)

Sem as credenciais, o bot fica no **modo simulado**: nada sai para a Meta e a tela **WhatsApp** do painel tem um simulador. Para ligar:

1. **Meta:** conta no Meta Business do restaurante → app no [Meta for Developers](https://developers.facebook.com/) com o produto WhatsApp → número **dedicado** (não pode estar ativo no WhatsApp pessoal; se estiver no app WhatsApp Business, avalie a Coexistência).
2. **Token permanente:** crie um *System User* no Business Manager, dê acesso ao app e à conta do WhatsApp, e gere um token com as permissões `whatsapp_business_messaging` e `whatsapp_business_management`. Não use o token temporário de 24 h do painel.
3. **Na Render → Environment**, adicione (nunca no repositório):
   - `WHATSAPP_TOKEN` (o token permanente) e `WHATSAPP_PHONE_NUMBER_ID` (o *Phone number ID*, não o número);
   - `WHATSAPP_APP_SECRET` (App settings → Basic → App secret);
   - `WHATSAPP_VERIFY_TOKEN` (um texto que você inventa, igual ao do passo 4);
   - opcionais: `APP_URL=https://familia-massas.onrender.com` (links do bot), `CRON_TOKEN` (para um cron externo chamar `/cron/whatsapp?chave=...`).
4. **Webhook:** no app, WhatsApp → Configuration → Callback URL `https://familia-massas.onrender.com/webhook/whatsapp.php` e o mesmo verify token → *Verify and save* → assine o campo **messages**.
5. **Template dos avisos fora da janela de 24 h:** em WhatsApp Manager → Message templates, crie um template da categoria **Utility**, idioma **Portuguese (BR)**, nome `atualizacao_pedido`, com o corpo, por exemplo: "Seu pedido #{{1}} está: {{2}}." Espere a aprovação. Outro nome: ponha em `WHATSAPP_MODELO_STATUS`.
6. **Teste:** mande "oi" para o número. A tela WhatsApp do painel passa a mostrar "Ligado na Meta" e a atividade recente.

Custo (base nov/2026, confira na tabela da Meta): conversas iniciadas pelo cliente e mensagens dentro da janela de 24 h são gratuitas; cada template fora da janela custa alguns centavos.

## Ligar o WhatsApp por QR code (Etapa 4b)

Alternativa à Cloud API: sem conta Meta, com um número dedicado à loja. **Não é oficial: o WhatsApp pode bloquear o número.** Use um chip só para isso, nunca o número pessoal. Decisões D66 a D69.

1. **Plano `starter` da Render** (US$ 7/mês, 512 MB): o gratuito dorme e derrubaria a conexão. O `render.yaml` já está nele.
2. Variáveis de ambiente do serviço: `WHATSAPP_CANAL` = `qr` e `CONECTOR_SEGREDO` (o blueprint sorteia; se criar à mão, 16+ caracteres). `DATABASE_URL` (Neon) já existe e é onde fica o pareamento.
3. Faça o deploy. Nos logs aparecem `[conector] ouvindo em 127.0.0.1:3001` e `desconectado → aguardando_qr`.
4. Abra **Painel → WhatsApp**: o QR code aparece. No celular do número dedicado: WhatsApp → Aparelhos conectados → Conectar um aparelho → aponte para o QR. Em segundos o estado vira **conectado**.
5. O pareamento fica no Neon: um deploy novo reconecta sozinho. Se o celular desconectar o aparelho, o conector apaga o pareamento e mostra um QR novo.
6. Se o WhatsApp cair por 2 min ou mais, a faixa vermelha aparece em todas as telas do painel (com som, se o botão "Som de pedido novo" estiver ligado). Na volta, escolha **Enviar pendentes** ou **Descartar**.

**Limites:** o Node usa até ~160 MB dentro da máquina de 512 MB; se o serviço reiniciar por falta de memória, suba o plano. O Baileys 6.7.24 é a versão estável; a imagem busca a dependência `libsignal` no GitHub no momento do build, com a versão fixada no `conector/package-lock.json` (mantenha o arquivo no repositório).

## Ligar o assistente com IA (Etapa 4c)

1. Crie a chave em https://openrouter.ai/keys e compre créditos (o cartão fica na conta do OpenRouter; o sistema nunca guarda cartão).
2. Na Render → **Environment**, crie `OPENROUTER_API_KEY` com a chave. Salve e deixe o serviço reiniciar.
3. Painel → **Assistente**: ligue, escolha o modelo (padrão `anthropic/claude-haiku-5.5`) e salve. Use a aba **Teste** para comparar modelos antes de decidir.
4. Defina o **teto mensal** na aba Modelo e custo. Ao atingi-lo, o bot volta ao menu de 3 opções.
5. **Crédito do cliente (D72):** a cada recarga que o cliente pagar a você, registre o valor na seção "Crédito do cliente" da mesma aba. O sistema desconta o gasto, avisa o proprietário quando o saldo ficar baixo e, se acabar, o bot volta ao menu de 3 opções. A recarga **não muda a chave**.
6. A compra do crédito de verdade é na **sua** conta do OpenRouter, em openrouter.ai/settings/credits (taxa de 5,5% com mínimo de US$ 0,80: compre de US$ 15 a 25 de cada vez). O crédito pode expirar um ano depois da compra.

A chave só existe na variável de ambiente: a tela mostra apenas se ela está configurada. Modelos gratuitos (`:free`) são só para teste: limite de 20 por minuto e 50 por dia, e quase sempre exigem desligar a opção de privacidade.

## Cuidados

- O repositório é **público**: nunca coloque senha, token ou `.env` nele.
- Troque a senha na tela **Usuários** (proprietário) se a combinada for provisória, e crie um login para cada pessoa da equipe. Com o banco no Neon (D56), usuários e senhas sobrevivem a reinícios.
- O HTTPS da Render libera a **localização do aparelho** no mapa da entrega (só funciona em https), então dá para testar com o GPS de um celular de verdade.
- A Etapa 4 (bot do WhatsApp) precisa de um endereço público com HTTPS para o webhook da Meta: este deploy já resolve isso.
- A imagem Docker foi escrita e conferida por partes (script de entrada, regras de reescrita, o comportamento de produção do PHP com as mesmas variáveis), mas **a construção completa da imagem só acontece na Render**: não há Docker neste computador. Se a primeira construção falhar, o log da Render mostra a linha do erro.
