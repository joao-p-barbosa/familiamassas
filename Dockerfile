# Imagem de produção (Render e qualquer hospedagem com Docker): PHP 8.3 + Apache.
# A pasta /public é a raiz do site; o resto (app, data) fica fora dela, como no desenvolvimento.

# Estágio do conector do WhatsApp por QR code: instala as dependências do Node. Precisa da imagem completa porque o Baileys
# busca uma dependência (libsignal) no GitHub, e isso exige o git. O package-lock.json fixa a versão exata.
FROM node:20-bookworm AS conector
WORKDIR /build
COPY conector/package.json conector/package-lock.json ./
RUN npm ci --omit=dev

FROM php:8.3-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

# Postgres (Neon): a extensão pdo_pgsql não vem na imagem oficial.
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev \
 && docker-php-ext-install pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*

# Apache: raiz do site em /public, .htaccess valendo (reescrita para o index.php e cabeçalhos) e o php.ini de produção.
RUN a2enmod rewrite headers \
 && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && sed -ri -e 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
 && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Só o programa do Node (o conector é o único uso); as dependências vêm do estágio acima.
COPY --from=node:20-bookworm-slim /usr/local/bin/node /usr/local/bin/node

WORKDIR /var/www/html
COPY . /var/www/html
COPY --from=conector /build/node_modules /var/www/html/conector/node_modules
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
 && mkdir -p data/sessoes \
 && chown -R www-data:www-data data public/uploads

# A Render manda a porta pela variável PORT (padrão 10000); o entrypoint ajusta o Apache a ela.
EXPOSE 10000
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
