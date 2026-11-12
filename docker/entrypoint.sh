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

exec apache2-foreground
