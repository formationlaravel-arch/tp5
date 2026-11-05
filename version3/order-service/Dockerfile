FROM dunglas/frankenphp:1-php8.2-alpine
RUN install-php-extensions pdo_mysql
WORKDIR /app
COPY php-common/http.php /app/common/http.php
COPY order-service/Caddyfile /etc/caddy/Caddyfile
COPY order-service/public /app/public
COPY benchmark /app/benchmark
RUN addgroup -S app && adduser -S app -G app && mkdir -p /data/caddy /config/caddy && chown -R app:app /app /data /config
USER app
EXPOSE 8080
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
