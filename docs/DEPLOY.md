# Publicar na internet (Render)

## Vercel? Não serve para este sistema

A Vercel não roda PHP de forma oficial (só por um runtime da comunidade, `vercel-php`), e o disco das funções dela é só leitura, com uma pasta temporária que some. O sistema grava o banco SQLite, as sessões e as fotos em arquivo, então perderia tudo. Por isso a hospedagem é na **Render**, que roda o sistema num contêiner Docker (PHP 8.3 + Apache), com HTTPS.

## Passo a passo

1. Abra o link e entre com a sua conta da Render (pode ser com o GitHub): <https://render.com/deploy?repo=https://github.com/joao-p-barbosa/familiamassas>
2. A Render lê o `render.yaml` do repositório e pede **duas senhas**: `SENHA_ADMIN_INICIAL` (proprietário) e `SENHA_ATENDENTE_INICIAL` (atendente). Escolha senhas com 8 ou mais caracteres. Elas **não** ficam no Git.
3. Clique em **Apply** e espere a construção (alguns minutos na primeira vez).
4. Abra o endereço que a Render mostra (algo como `https://familia-massas.onrender.com`): o cardápio é a Home e o painel é `/painel`. Entre com `admin` (ou `atendente`) e a senha que você digitou.
5. Cada `git push` na `main` publica uma versão nova sozinho (`autoDeployTrigger: commit`).

## O que o plano gratuito faz (e o que não faz)

| Limite | Efeito |
|---|---|
| Dorme depois de 15 min sem acesso | O primeiro acesso depois disso demora cerca de 1 minuto para acordar. |
| Disco temporário | O banco, as sessões e as fotos **somem** quando o serviço reinicia, dorme ou recebe uma versão nova. O banco volta com os dados de exemplo e as senhas das variáveis. |

Serve para **demonstração e portfólio**. Para guardar pedidos de verdade é preciso um disco persistente, que a Render só oferece em plano pago:

1. No `render.yaml`, troque `plan: free` por `plan: starter`, descomente o bloco `disk` e a variável `DATA_DIR` (`/var/data`).
2. Faça o push. Com `DATA_DIR`, o contêiner guarda o banco, as sessões, as fotos e as cópias de segurança no disco (o `docker/entrypoint.sh` faz a ligação).

## Variáveis de ambiente

| Variável | Para que serve |
|---|---|
| `APP_ENV=production` | Esconde detalhes de erro. |
| `APP_KEY` | Assina o link do WhatsApp. A Render sorteia. |
| `APP_TIMEZONE` | Fuso da loja (`America/Sao_Paulo`). |
| `TRUST_PROXY=1` | A Render fica na frente do site: vale o IP de quem acessa (`X-Forwarded-For`) no bloqueio de tentativas de login. Sem isso todo mundo teria o IP do proxy e o bloqueio pegaria todos de uma vez. |
| `SENHA_ADMIN_INICIAL`, `SENHA_ATENDENTE_INICIAL` | Senhas dos dois usuários quando o banco é criado. Sem elas (e fora do modo dev) o sistema sorteia uma e escreve no log do serviço; nunca nasce com a senha padrão. |
| `DATA_DIR` | Só com disco persistente (veja acima). |
| `WHATSAPP_*` | Credenciais da Meta, na Etapa 4. Coloque em **Environment** no painel da Render, nunca no repositório (ele é público). |

## Cuidados

- O repositório é **público**: nunca coloque senha, token ou `.env` nele.
- Troque a senha em **Configurações → Minha senha** se a combinada for provisória. No plano gratuito, lembre que ela volta à da variável quando o serviço reinicia.
- O HTTPS da Render libera a **localização do aparelho** no mapa da entrega (só funciona em https), então dá para testar com o GPS de um celular de verdade.
- A Etapa 4 (bot do WhatsApp) precisa de um endereço público com HTTPS para o webhook da Meta: este deploy já resolve isso.
- A imagem Docker foi escrita e conferida por partes (script de entrada, regras de reescrita, o comportamento de produção do PHP com as mesmas variáveis), mas **a construção completa da imagem só acontece na Render**: não há Docker neste computador. Se a primeira construção falhar, o log da Render mostra a linha do erro.
