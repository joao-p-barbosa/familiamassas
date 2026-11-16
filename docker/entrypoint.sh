#!/bin/sh
# Prepara o contêiner e liga o Apache. Roda toda vez que o serviço sobe.
set -e

APP=/var/www/html
PORTA="${PORT:-10000}"

# 1) O Apache escuta na porta que a plataforma mandou.
sed -ri "s/^Listen [0-9]+[[:space:]]*$/Listen ${PORTA}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORTA}>/" /etc/apache2/sites-available/000-default.conf

# 2) Onde ficam os dados que o sistema grava (banco, sessões e fotos).
#    Sem DATA_DIR: ficam dentro do contêiner e SOMEM quando o serviço reinicia (plano gratuito da Render).
#    Com DATA_DIR (um disco persistente montado, ex.: /var/data): tudo vai para o disco e sobrevive.
if [ -n "${DATA_DIR:-}" ]; then
  mkdir -p "$DATA_DIR/sessoes" "$DATA_DIR/uploads"
  # A pasta de fotos precisa do .htaccess que proíbe scripts nela.
  if [ -f "$APP/public/uploads/.htaccess" ]; then
    cp "$APP/public/uploads/.htaccess" "$DATA_DIR/uploads/.htaccess"
  fi
  rm -rf "$APP/data/sessoes" "$APP/public/uploads"
  ln -s "$DATA_DIR/sessoes" "$APP/data/sessoes"
  ln -s "$DATA_DIR/uploads" "$APP/public/uploads"
  export DB_SQLITE_PATH="${DB_SQLITE_PATH:-$DATA_DIR/restaurante.sqlite}"
  chown -R www-data:www-data "$DATA_DIR"
else
  mkdir -p "$APP/data/sessoes"
  chown -R www-data:www-data "$APP/data" "$APP/public/uploads"
fi

# 3) Conector do WhatsApp por QR code (D66). Só sobe com WHATSAPP_CANAL=qr. Roda como www-data, ao lado do Apache, e é
#    reiniciado se cair. O pareamento fica no banco (DATABASE_URL), então um deploy não pede o QR de novo.
#    O limite de memória do Node deixa folga para o Apache e o PHP na máquina de 512 MB.
if [ "${WHATSAPP_CANAL:-}" = "qr" ]; then
  if [ -z "${CONECTOR_SEGREDO:-}" ]; then
    echo "[entrypoint] WHATSAPP_CANAL=qr sem CONECTOR_SEGREDO: o conector NAO foi iniciado." >&2
  else
    export CONECTOR_SITE_URL="${CONECTOR_SITE_URL:-http://127.0.0.1:${PORTA}}"
    export NODE_OPTIONS="${NODE_OPTIONS:---max-old-space-size=160}"
    (
      while true; do
        su -p -s /bin/sh www-data -c "cd $APP/conector && exec node index.js" || true
        echo "[entrypoint] o conector encerrou; reiniciando em 5 s" >&2
        sleep 5
      done
    ) &
  fi
fi

# 4) iFood (D77): o polling de pedidos roda num processo PHP ao lado do Apache, a cada 30 s, mesmo sem ninguém no painel
#    (é o polling que mantém a loja aberta no app do iFood). Sobe sempre: o Client ID e o Client Secret podem vir das variáveis
#    de ambiente ou da tela iFood (D82), e enquanto não houver credenciais ele só confere de novo a cada 30 s. Reinicia se cair.
(
  while true; do
    su -p -s /bin/sh www-data -c "cd $APP && exec php bin/ifood.php" || true
    echo "[entrypoint] o processo do iFood encerrou; reiniciando em 5 s" >&2
    sleep 5
  done
) &

exec apache2-foreground
