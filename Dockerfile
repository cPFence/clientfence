# syntax=docker/dockerfile:1.25@sha256:0adf442eae370b6087e08edc7c50b552d80ddf261576f4ebd6421006b2461f12

ARG PHP_VERSION=8.5
ARG NODE_VERSION=24

FROM php:${PHP_VERSION}-apache AS php-base

RUN set -eux; \
  savedAptMark="$(apt-mark showmanual)"; \
  apt-get update; \
  apt-get install -y --no-install-recommends \
    ca-certificates \
    cron \
    curl \
    libbz2-dev \
    libfreetype6-dev \
    libicu-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libzip-dev \
    unzip \
    zlib1g-dev; \
  docker-php-ext-configure gd --with-freetype --with-jpeg; \
  docker-php-ext-install -j"$(nproc)" bz2 gd intl pdo_mysql zip; \
  a2enmod rewrite; \
  apt-mark auto '.*' > /dev/null; \
  apt-mark manual \
    ${savedAptMark} \
    ca-certificates \
    cron \
    curl \
    unzip; \
  find /usr/local -type f \( -perm /0111 -o -name '*.so' \) -exec sh -c 'ldd "$@" 2>/dev/null' sh '{}' + \
    | awk 'NF == 4 && $2 == "=>" { print $3 } NF == 2 && $1 ~ /^\// { print $1 }' \
    | sort -u \
    | while read -r library; do \
        library="$(readlink -e "${library}")"; \
        dpkg-query --search "${library}" 2>/dev/null | grep -v '^diversion ' | head -n1 | cut -d: -f1; \
      done \
    | sort -u \
    | xargs -r apt-mark manual; \
  apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
  apt-get clean; \
  rm -rf /var/lib/apt/lists/* /var/cache/apt/*

FROM php-base AS composer-base

WORKDIR /app

COPY --from=composer:2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
  && apt-get install -y --no-install-recommends git \
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/* /var/cache/apt/*

FROM composer-base AS php-vendor

COPY composer.json composer.lock ./
COPY src/library ./src/library
COPY src/modules ./src/modules

RUN --mount=type=cache,target=/tmp/composer-cache,id=composer-prod \
  set -eux; \
  find ./src/modules -type d -name tests -prune -exec rm -rf {} +; \
  COMPOSER_CACHE_DIR=/tmp/composer-cache \
  composer install --prefer-dist --no-dev --optimize-autoloader --no-interaction --no-progress

FROM composer-base AS php-dev-vendor
COPY composer.json composer.lock ./
COPY src/library ./src/library
COPY src/modules ./src/modules

RUN --mount=type=cache,target=/tmp/composer-cache,id=composer-dev \
  COMPOSER_CACHE_DIR=/tmp/composer-cache \
  composer install --prefer-dist --optimize-autoloader --no-interaction --no-progress

FROM node:${NODE_VERSION}-bookworm-slim AS frontend-assets

WORKDIR /app

COPY package.json package-lock.json ./
COPY src/themes/admin_default/package.json src/themes/admin_default/package.json

RUN --mount=type=cache,target=/root/.npm CYPRESS_INSTALL_BINARY=0 npm ci

COPY tsconfig.json ./
COPY src/themes/admin_default ./src/themes/admin_default
COPY src/modules ./src/modules
COPY frontend ./frontend
# Palisade, the ClientFence client theme: the icon check of the core build scans it; it is built on
# its own, with its own package-lock.json.
COPY src/themes/palisade ./src/themes/palisade

RUN NODE_ENV=production npm run build

RUN --mount=type=cache,target=/root/.npm cd src/themes/palisade && npm ci --no-audit --no-fund && npm run build

FROM php-base AS release-tree

WORKDIR /app

ARG CLIENTFENCE_BASE_VERSION=0.8.8
ARG CLIENTFENCE_BASE_VERSION_TRUNCATE=0
# Translations are in the repository (src/locale: FOSSBilling 0.8.8 catalogues with the ClientFence texts merged by tools/locales.py).

COPY --from=composer:2@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 /usr/bin/composer /usr/bin/composer
COPY composer.json ./composer.json
COPY src ./src
COPY README.md LICENSE NOTICE ./src/
COPY --from=php-vendor /app/src/vendor ./src/vendor
COPY --from=frontend-assets /app/src/public/assets ./src/public/assets
COPY --from=frontend-assets /app/src/themes/admin_default/assets/build ./src/themes/admin_default/assets/build
COPY --from=frontend-assets /app/src/themes/palisade/assets/build ./src/themes/palisade/assets/build
COPY --from=frontend-assets /app/src/themes/palisade/html/partials/asset_version.html.twig ./src/themes/palisade/html/partials/asset_version.html.twig
COPY tools/build-pack-manifest.php tools/core-patch-markers.json ./tools/

RUN set -eux; \
  find ./src/modules -type d -name tests -prune -exec rm -rf {} +; \
  cp ./src/themes/palisade/assets/src/fonts/*OFL.txt ./src/themes/palisade/assets/build/css/; \
  rm -f ./src/themes/palisade/.gitignore; \
  rm -rf ./src/themes/palisade/assets/src ./src/themes/palisade/locale ./src/themes/palisade/build.mjs ./src/themes/palisade/package.json ./src/themes/palisade/package-lock.json ./src/themes/palisade/icon-manifest.json; \
  cat ./src/themes/admin_default/assets/clientfence/clientfence-admin.css ./src/themes/admin_default/assets/clientfence/clientfence-admin.js ./src/themes/admin_default/assets/build/js/clientfence.js ./src/themes/admin_default/assets/build/css/vendor.css ./src/themes/admin_default/assets/build/css/vendor.rtl.css ./src/themes/admin_default/assets/build/css/clientfence.css | sha256sum | cut -c1-12 > ./src/themes/admin_default/html_custom/partial_clientfence_asset_version.html.twig; \
  php ./tools/build-pack-manifest.php ./src; \
  rm -rf ./tools; \
  mkdir -p ./src/locale; \
  CLIENTFENCE_BASE_VERSION="${CLIENTFENCE_BASE_VERSION}" \
  CLIENTFENCE_BASE_VERSION_TRUNCATE="${CLIENTFENCE_BASE_VERSION_TRUNCATE}" \
  php -r '$version = getenv("CLIENTFENCE_BASE_VERSION") ?: "0.0.1"; $truncate = (int) (getenv("CLIENTFENCE_BASE_VERSION_TRUNCATE") ?: 0); if ($truncate > 0) { $version = substr($version, 0, $truncate); } $versionFile = "./src/library/ClientFence/Version.php"; $contents = file_get_contents($versionFile); $quote = chr(39); $pattern = "/public const string VERSION = " . $quote . "[^" . $quote . "]+" . $quote . ";/"; $replacement = "public const string VERSION = " . var_export($version, true) . ";"; $contents = preg_replace($pattern, $replacement, $contents, 1, $count); if ($contents === null || $count !== 1) { fwrite(STDERR, "Failed to replace FOSSBilling version.\n"); exit(1); } file_put_contents($versionFile, $contents);'; \
  php -r '$composer = json_decode(file_get_contents("./composer.json"), true, 512, JSON_THROW_ON_ERROR); $composer["autoload"]["psr-4"]["ClientFence\\Mod\\"] = "modules/"; $composer["autoload"]["classmap"] = ["library/"]; $composer["config"]["vendor-dir"] = "vendor"; file_put_contents("./src/composer.json", json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);'; \
  composer --no-plugins dump-autoload --working-dir=./src --no-dev --optimize --no-interaction; \
  rm ./composer.json ./src/composer.json; \
  chmod -R u=rwX,go=rX ./src

FROM scratch AS release-artifact

COPY --from=release-tree /app/src /

FROM php-base AS runtime

WORKDIR /var/www/html

COPY --from=release-tree --chown=www-data:www-data /app/src/ ./

RUN set -eux; \
  mkdir -p data/cache data/log data/uploads; \
  touch /var/log/cron.log; \
  chown -R www-data:www-data data /var/log/cron.log; \
  echo '*/5 * * * * /usr/local/bin/php /var/www/html/cron.php >> /var/log/cron.log 2>&1' > /tmp/www-data.cron; \
  crontab -u www-data /tmp/www-data.cron; \
  rm /tmp/www-data.cron

CMD ["sh", "-c", "cron & exec apache2-foreground"]

FROM runtime AS test

WORKDIR /workspace

COPY --from=release-tree /app/src ./src
COPY src/modules ./src/modules
COPY --from=php-dev-vendor /app/src/vendor ./src/vendor
COPY composer.json composer.lock phpstan.neon phpstan-baseline.neon phpstan-baseline-clientfence.neon phpunit.xml.dist ./
COPY tests ./tests
COPY tools/core-patch-markers.json ./tools/core-patch-markers.json
COPY src/themes/palisade/locale ./src/themes/palisade/locale
# Theme tests read the script sources; the release tree ships only the built bundle.
COPY src/themes/palisade/assets/src/js ./src/themes/palisade/assets/src/js

RUN set -eux; \
  php -r '$config = require "./src/config-sample.php"; file_put_contents("./src/config.php", "<?php\nreturn " . var_export($config, true) . ";\n");'; \
  mkdir -p ./src/data/cache ./src/data/log ./src/data/uploads; \
  chown -R www-data:www-data ./src/data ./src/config.php
