# syntax=docker/dockerfile:1
# Production image: FrankenPHP in worker mode, Symfony booted once per worker
# (runtime/frankenphp-symfony), opcache preload, no dev dependencies.
FROM dunglas/frankenphp:1-php8.4 AS app

WORKDIR /app

ENV APP_ENV=prod
ENV FRANKENPHP_CONFIG="worker ./public/index.php"
ENV SERVER_NAME=":8080"
ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update && apt-get install -y --no-install-recommends \
		acl file gettext git \
	&& rm -rf /var/lib/apt/lists/*

RUN set -eux; \
	install-php-extensions \
		@composer \
		apcu \
		intl \
		opcache \
		zip \
		pdo_pgsql \
	;

COPY --link docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY --link docker/frankenphp/app.ini docker/frankenphp/app.prod.ini $PHP_INI_DIR/conf.d/
COPY --link docker/frankenphp/entrypoint.sh /usr/local/bin/docker-entrypoint
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" && chmod +x /usr/local/bin/docker-entrypoint

# Dependencies first for layer caching
COPY --link composer.json composer.lock symfony.lock ./
RUN set -eux; \
	composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

COPY --link . ./
RUN rm -rf docker

RUN set -eux; \
	mkdir -p var/cache var/log config/jwt; \
	composer dump-autoload --classmap-authoritative --no-dev; \
	composer dump-env prod; \
	composer run-script --no-dev post-install-cmd; \
	chmod +x bin/console; sync;

HEALTHCHECK --start-period=60s CMD curl -f http://localhost:8080/health || exit 1

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
