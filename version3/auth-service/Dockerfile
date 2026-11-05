FROM composer:2 AS dependencies
WORKDIR /build
COPY auth-service/composer.json ./composer.json
COPY auth-service/composer.lock ./composer.lock
RUN composer install --no-dev --no-interaction --prefer-dist --classmap-authoritative

FROM dunglas/frankenphp:1-php8.2-alpine
WORKDIR /app
COPY --from=dependencies /build/vendor /app/vendor
COPY auth-service/Caddyfile /etc/caddy/Caddyfile
COPY auth-service/public /app/public
RUN addgroup -S app && adduser -S app -G app && mkdir -p /data/caddy /config/caddy && chown -R app:app /app /data /config
USER app
EXPOSE 8080
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
