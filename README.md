# Família Massas · cardápio e painel

Sistema de pedidos para um restaurante de massas do tipo "monte seu prato": cardápio web para o cliente, painel com Kanban para a equipe, modo cozinha, impressão de comanda e (em breve) bot do WhatsApp.

PHP 8.2+ puro, SQLite (Postgres/Neon depois), HTML + CSS + JavaScript sem framework e sem build.

## Rodar no Windows

1. Copie `.env.example` para `.env` e preencha `APP_KEY` (`php -r "echo bin2hex(random_bytes(32));"`).
2. Dê dois cliques em `iniciar.bat`.
3. Cardápio em <http://localhost:8090/> e painel em <http://localhost:8090/painel>.

O banco `data/restaurante.sqlite` é criado sozinho com dados de exemplo na primeira vez. Os usuários e a senha inicial estão em `docs/PROGRESSO.md`. Troque a senha antes de usar de verdade.

## Testes

```
php -d extension=pdo_sqlite tests/regras.php
node tests/preco.test.mjs
node tests/contraste.mjs
```

## Documentação

- `docs/PROGRESSO.md`: situação de cada etapa, como testar, checklists e perguntas abertas
- `docs/DECISOES.md`: decisões com data e motivo
- `docs/API.md`: contrato da API
- `docs/MODELO-DADOS.md`: tabelas e regras do banco
- `PROMPT-MVP.md`: briefing original do projeto
