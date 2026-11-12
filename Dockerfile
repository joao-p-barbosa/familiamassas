# Imagem de produção (Render e qualquer hospedagem com Docker): PHP 8.3 + Apache.
# A pasta /public é a raiz do site; o resto (app, data) fica fora dela, como no desenvolvimento.
FROM php:8.3-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

# Apache: raiz do site em /public, .htaccess valendo (reescrita para o index.php e cabeçalhos) e o php.ini de produção.
RUN a2enmod rewrite headers \
 && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && sed -ri -e 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
 && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /var/www/html
COPY . /var/www/html
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
 && mkdir -p data/sessoes \
 && chown -R www-data:www-data data public/uploads

# A Render manda a porta pela variável PORT (padrão 10000); o entrypoint ajusta o Apache a ela.
EXPOSE 10000
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
