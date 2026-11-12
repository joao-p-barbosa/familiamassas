# Publicar na internet (Render)

## Vercel? Não serve para este sistema

A Vercel não roda PHP de forma oficial (só por um runtime da comunidade, `vercel-php`), e o disco das funções dela é só leitura, com uma pasta temporária que some. O sistema grava o banco SQLite, as sessões e as fotos em arquivo, então perderia tudo. Por isso a hospedagem é na **Render**, que roda o sistema num contêiner Docker (PHP 8.3 + Apache), com HTTPS.

## Passo a passo

1. Abra o link e entre com a sua conta da Render (pode ser com o GitHub): <https://render.com/deploy?repo=https://github.com/joao-p-barbosa/familiamassas>
2. A Render lê o `render.yaml` do repositório e pede **duas senhas**: `SENHA_ADMIN_INICIAL` (proprietário) e `SENHA_ATENDENTE_INICIAL` (atendente). Escolha senhas com 8 ou mais caracteres. Elas **não** ficam no Git.
3. Clique em **Apply** e espere a construção (alguns minutos na primeira vez).
4. Abra o endereço que a Render mostra (algo como `https://familia-massas.onrender.com`): o cardápio é a Home e o painel é `/painel`. Entre com `admin` (ou `atendente`) e a senha que você digitou.
5. O serviço fica com Auto-Deploy "On Commit" (`autoDeployTrigger: commit`): em princípio cada `git push` na `main` publica uma versão nova. Quando o serviço foi criado pelo link de um repositório público, o aviso do GitHub pode não chegar à Render (aconteceu na primeira vez). Se um `push` não publicar, no painel da Render abra o serviço e use **Manual Deploy → Deploy latest commit**.

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

## Cuidados

- O repositório é **público**: nunca coloque senha, token ou `.env` nele.
- Troque a senha em **Configurações → Minha senha** se a combinada for provisória. No plano gratuito, lembre que ela volta à da variável quando o serviço reinicia.
- O HTTPS da Render libera a **localização do aparelho** no mapa da entrega (só funciona em https), então dá para testar com o GPS de um celular de verdade.
- A Etapa 4 (bot do WhatsApp) precisa de um endereço público com HTTPS para o webhook da Meta: este deploy já resolve isso.
- A imagem Docker foi escrita e conferida por partes (script de entrada, regras de reescrita, o comportamento de produção do PHP com as mesmas variáveis), mas **a construção completa da imagem só acontece na Render**: não há Docker neste computador. Se a primeira construção falhar, o log da Render mostra a linha do erro.
