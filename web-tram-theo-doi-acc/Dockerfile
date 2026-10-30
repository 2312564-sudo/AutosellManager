FROM dunglas/frankenphp:1-php8.4-alpine

WORKDIR /app

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && install-php-extensions pdo_pgsql pdo_sqlite curl mbstring

COPY Caddyfile /etc/caddy/Caddyfile
COPY . /app

ENV PORT=80
ENV QQ_DATA_DIR=/tmp/qq-data

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
