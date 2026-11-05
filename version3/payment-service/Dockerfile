FROM dunglas/frankenphp:1-php8.2-alpine
RUN install-php-extensions pdo_sqlite
WORKDIR /app
COPY php-common/http.php /app/common/http.php
COPY payment-service/Caddyfile /etc/caddy/Caddyfile
COPY payment-service/init.sql /app/init.sql
COPY payment-service/public /app/public
RUN mkdir -p /data /data/caddy /config/caddy && addgroup -S app && adduser -S app -G app && chown -R app:app /app /data /config
USER app
EXPOSE 8080
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
