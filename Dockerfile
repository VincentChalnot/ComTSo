#syntax=docker/dockerfile:1

# BASE_IMAGE: where the stages below start from.
#  - default `php_base`: the stage defined right below, compiled locally (always works, also offline);
#  - CI and the dev compose file pass the published image ghcr.io/vincentchalnot/comtso-base:<hash> instead, so PHP extensions are
#    pulled, not compiled. <hash> = `docker/base-hash.sh` (hash of the block between the `base-image` markers).
ARG BASE_IMAGE=php_base

# The different stages of this Dockerfile are meant to be built into separate images
# https://docs.docker.com/build/building/multi-stage/#stop-at-a-specific-build-stage
# https://docs.docker.com/reference/compose-file/build/#target

###> base-image ###
# Everything between these markers defines the published base image: system packages + PHP extensions, nothing about the
# application, nothing dev- or prod-specific. Editing it changes the output of docker/base-hash.sh: run `docker/base-hash.sh --write`.
FROM dunglas/frankenphp:1-php8.5 AS php_base

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

WORKDIR /app

# hadolint ignore=DL3008
RUN <<-EOF
	apt-get update
	apt-get install -y --no-install-recommends \
		file \
		git
	install-php-extensions \
		@composer \
		apcu \
		intl \
		opcache \
		zip \
		exif \
		gd \
		pdo_mysql \
		pdo_pgsql \
		xdebug
	# Xdebug is compiled here once, for the dev image, but stays OFF: the dev stage enables it, the prod stages drop it.
	rm -f "$PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini"
	rm -rf /var/lib/apt/lists/*
EOF

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1

ENV PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"

LABEL org.opencontainers.image.source="https://github.com/VincentChalnot/ComTSo"
LABEL org.opencontainers.image.description="ComTSo base image: FrankenPHP + PHP extensions (no application code)"
###< base-image ###

# Application base: the base image + the files that configure FrankenPHP (cheap layers, rebuilt in seconds)
FROM ${BASE_IMAGE} AS frankenphp_base

COPY --link frankenphp/conf.d/10-app.ini $PHP_INI_DIR/app.conf.d/
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link frankenphp/Caddyfile /etc/frankenphp/Caddyfile

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=60s CMD php -r 'exit(false === @file_get_contents("http://localhost:2019/metrics", context: stream_context_create(["http" => ["timeout" => 5]])) ? 1 : 0);'
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]

# Dev FrankenPHP image
FROM frankenphp_base AS frankenphp_dev

ENV APP_ENV=dev
ENV XDEBUG_MODE=off
ENV FRANKENPHP_WORKER_CONFIG=watch

# dev dependencies (Xdebug itself is already compiled in the base image)
# hadolint ignore=DL3008
RUN <<-EOF
	mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
	apt-get update
	apt-get install -y --no-install-recommends \
		aggregate \
		curl \
		dnsmasq \
		dnsutils \
		iproute2 \
		ipset \
		iptables \
		jq \
		sudo
	docker-php-ext-enable xdebug
	rm -rf /var/lib/apt/lists/*
	useradd -m -s /bin/bash nonroot
	echo "nonroot ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/nonroot
	git config --system --add safe.directory /app
EOF

COPY --link frankenphp/conf.d/20-app.dev.ini $PHP_INI_DIR/app.conf.d/

CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch" ]

# Front-end build: Vite bundles assets/ and Tailwind scans templates/ for the classes in use
FROM node:26-alpine AS frontend_builder
WORKDIR /app
COPY --link package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci
COPY --link vite.config.js ./
COPY --link assets ./assets
COPY --link templates ./templates
RUN npm run build

# Shared libraries needed by FrankenPHP and the PHP extensions of the prod image. Depends on the base only (not on the sources):
# built once per base, in parallel with the PHP dependency and front-end stages.
FROM frankenphp_base AS prod_libs

# Xdebug is dev only
RUN rm -f "$(php -r 'echo ini_get("extension_dir");')"/xdebug.so

# hadolint ignore=DL3008,SC3054,DL4006
RUN <<-'EOF'
	apt-get update
	apt-get install -y --no-install-recommends libtree
	mkdir -p /tmp/libs
	BINARIES=(frankenphp php file)
	for target in $(printf '%s\n' "${BINARIES[@]}" | xargs -I{} which {}) \
		$(find "$(php -r 'echo ini_get("extension_dir");')" -maxdepth 2 -name "*.so"); do
		libtree -pv "$target" 2>/dev/null | grep -oP '(?:── )\K/\S+(?= \[)' | while IFS= read -r lib; do
			[ -f "$lib" ] && cp -n "$lib" /tmp/libs/
		done
	done
	rm -rf /var/lib/apt/lists/*
EOF

# Builder for the prod FrankenPHP image
FROM frankenphp_base AS frankenphp_prod_builder

# Configuration comes from real environment variables only (compose / orchestrator): no .env file exists in the image
# and symfony/dotenv is not installed.
ENV APP_ENV=prod
ENV APP_DEBUG=0

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --link frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/app.conf.d/

# prevent the reinstallation of vendors at every changes in the source code
COPY --link composer.* symfony.* ./
RUN --mount=type=cache,target=/tmp/composer-cache \
	COMPOSER_CACHE_DIR=/tmp/composer-cache composer install --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

# copy sources
COPY --link --exclude=frankenphp/ . ./

RUN <<-EOF
	# var/photos: mount point of the persistent photo library (must exist in the image to inherit the www-data ownership)
	mkdir -p var/cache var/log var/share var/photos/originals var/photos/cache
	composer dump-autoload --classmap-authoritative --no-dev
	# The cache warmup instantiates services, which resolves these two variables (nothing connects). Build-time placeholders for
	# THIS RUN ONLY: they are not stored in the image, the real values come from the environment at runtime.
	export DATABASE_URL='mysql://build:build@127.0.0.1:3306/build?serverVersion=mariadb-12.3.0&charset=utf8mb4'
	export MERCURE_URL='http://localhost/.well-known/mercure'
	composer run-script --no-dev post-install-cmd
	chmod +x bin/console
	chmod -R g=u var
	sync
EOF

# Compiled front-end (Vite + Tailwind): public/assets/main.{js,css}
COPY --link --from=frontend_builder /app/public/assets ./public/assets

# Prod FrankenPHP image
FROM debian:13-slim AS frankenphp_prod

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

ENV APP_ENV=prod
ENV APP_DEBUG=0
ENV PHP_INI_SCAN_DIR=":/usr/local/etc/php/app.conf.d"

COPY --from=frankenphp_prod_builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp
COPY --from=frankenphp_prod_builder /usr/local/bin/php /usr/local/bin/php
COPY --from=frankenphp_prod_builder /usr/local/bin/docker-php-entrypoint /usr/local/bin/docker-php-entrypoint
COPY --from=prod_libs /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=prod_libs /tmp/libs /usr/lib

COPY --from=frankenphp_prod_builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=frankenphp_prod_builder /usr/local/etc/php/php.ini /usr/local/etc/php/php.ini
COPY --from=frankenphp_prod_builder /usr/local/etc/php/app.conf.d /usr/local/etc/php/app.conf.d

COPY --from=frankenphp_prod_builder /etc/frankenphp/Caddyfile /etc/frankenphp/Caddyfile

# CA certificates for TLS, file/libmagic for Symfony MIME type detection
COPY --from=frankenphp_prod_builder /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/ca-certificates.crt
COPY --from=frankenphp_prod_builder /etc/ssl/openssl.cnf /etc/ssl/openssl.cnf
COPY --from=frankenphp_prod_builder /usr/bin/file /usr/bin/file
COPY --from=frankenphp_prod_builder /usr/lib/file/magic.mgc /usr/lib/file/magic.mgc

ENV  OPENSSL_CONF=/etc/ssl/openssl.cnf XDG_CONFIG_HOME=/config XDG_DATA_HOME=/data

RUN <<-EOF
	mkdir -p /data/caddy /config/caddy
	chown -R www-data:www-data /data /config
	# Remove setuid/setgid bits
	find / -perm /6000 -type f -exec chmod a-s {} + 2>/dev/null || true
EOF

COPY --link --exclude=var --from=frankenphp_prod_builder /app /app
# Group 0 + g=u for arbitrary-UID runtimes (e.g. OpenShift).
COPY --chown=www-data:0 --from=frankenphp_prod_builder /app/var /app/var
RUN chmod g=u /app/var

COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint

USER www-data

WORKDIR /app

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=60s CMD php -r 'exit(false === @file_get_contents("http://localhost:2019/metrics", context: stream_context_create(["http" => ["timeout" => 5]])) ? 1 : 0);'
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]
