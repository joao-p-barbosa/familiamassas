# Família Massas · cardápio e painel

Sistema de pedidos para um restaurante de massas do tipo "monte seu prato": cardápio web para o cliente, painel com quadro de pedidos para a equipe, Salão (atendimentos com conta, mesa ou pra viagem e fechamento com pagamento) para a atendente, histórico para o proprietário, impressão de comanda e (em breve) bot do WhatsApp.

PHP 8.2+ puro, SQLite (Postgres/Neon depois), HTML + CSS + JavaScript sem framework e sem build. O painel é uma SPA em JavaScript puro (módulos ES, History API).

## Rodar no Windows

1. Copie `.env.example` para `.env` e preencha `APP_KEY` (`php -r "echo bin2hex(random_bytes(32));"`).
2. Dê dois cliques em `iniciar.bat`.
3. Cardápio em <http://localhost:8090/> e painel em <http://localhost:8090/painel>.

O banco `data/restaurante.sqlite` é criado sozinho com dados de exemplo na primeira vez. Um banco que já existe é atualizado sozinho (migrações em `data/migracoes/`, com cópia de segurança em `data/backups/`). Os usuários (proprietário e atendente) e a senha inicial estão em `docs/PROGRESSO.md`. Troque a senha antes de usar de verdade.

## Banco de dados

SQLite no computador (nada a instalar além do PHP). Na hospedagem, **Postgres no Neon**: com a variável `DATABASE_URL` o sistema guarda dados, sessões e fotos no banco e nada some quando o serviço reinicia. Veja `docs/DEPLOY.md`.

## Publicar na Render

[![Deploy to Render](https://render.com/images/deploy-to-render-button.svg)](https://render.com/deploy?repo=https://github.com/joao-p-barbosa/familiamassas)

Docker (PHP + Apache) com HTTPS. O disco do plano gratuito é temporário, mas com o banco no Neon (acima) os dados, as sessões e as fotos persistem mesmo assim (testado reiniciando o serviço). Passo a passo, limites e variáveis em `docs/DEPLOY.md`. A Vercel não roda este sistema (PHP + SQLite em arquivo).

## Testes

```
php -d extension=pdo_sqlite tests/regras.php
node tests/preco.test.mjs
node tests/tempo.test.mjs
node tests/contraste.mjs
```

## Documentação

- `docs/PROGRESSO.md`: situação de cada etapa, como testar, checklists e perguntas abertas
- `docs/DECISOES.md`: decisões com data e motivo
- `docs/API.md`: contrato da API
- `docs/MODELO-DADOS.md`: tabelas e regras do banco
- `docs/DEPLOY.md`: publicar na Render (e por que não na Vercel)
- `PROMPT-MVP.md`: briefing original do projeto
