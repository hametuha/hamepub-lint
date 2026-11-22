# hamepub-lint: PHP (Slim) + JRE + ePubCheck in one image for Cloud Run.

# Versions of ePubCheck to bundle. The newest becomes the default of /validator.
ARG EPUBCHECK_VERSIONS="5.4.0 4.0.2"

# Base images are pinned by digest so that Dependabot opens a PR whenever upstream rebuilds them
# (security fixes of PHP, Java and Debian). COPY --from uses these stages for the same reason.
FROM composer:2@sha256:ca11f643d7791ebdd90a34fc20caf11380d434007ae539a9ea75cd9212e13dca AS composer
FROM eclipse-temurin:25-jre@sha256:a640968ae55027b322cedd5f8228b01a84baff0b9e9360e47601c6a111ba1699 AS jre

# ---- ePubCheck jars
FROM debian:trixie-slim@sha256:a72b4433de1cf7d6bae0c4cee74d0547f58601a643b3375f070c51600d63b6b2 AS epubcheck
ARG EPUBCHECK_VERSIONS
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl unzip \
    && rm -rf /var/lib/apt/lists/*
COPY bin/installer.sh /usr/local/bin/installer.sh
RUN for v in ${EPUBCHECK_VERSIONS}; do installer.sh "$v" /opt/epubcheck; done

# ---- Composer dependencies
FROM composer AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --ignore-platform-reqs --prefer-dist
COPY app app
RUN composer dump-autoload --no-dev --optimize

# ---- Runtime
FROM php:8.6-apache@sha256:0b06b358a7d59cca80e0528aad0e49b43208c3a74754ffcd8a12d7727058511e AS base
COPY --from=jre /opt/java/openjdk /opt/java/openjdk
ENV JAVA_HOME=/opt/java/openjdk \
    PATH="/opt/java/openjdk/bin:${PATH}" \
    EPUBCHECK_DIR=/opt/epubcheck \
    PORT=8080
COPY --from=epubcheck /opt/epubcheck /opt/epubcheck
# Build class data sharing archives to shorten JVM startup (see Validator::javaOptions()).
COPY docker/sample.epub /tmp/sample.epub
RUN for d in /opt/epubcheck/epubcheck-*; do \
        java -XX:TieredStopAtLevel=1 -XX:+UseSerialGC -XX:ArchiveClassesAtExit="$d/epubcheck.jsa" \
            -jar "$d/epubcheck.jar" /tmp/sample.epub -o /tmp/result.xml > /dev/null 2>&1; \
        test -f "$d/epubcheck.jsa"; \
    done \
    && rm -f /tmp/sample.epub /tmp/result.xml
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/hamepub.ini
RUN a2enmod rewrite \
    && sed -i 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
    && sed -i 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-available/security.conf
WORKDIR /var/www/app
EXPOSE 8080

# ---- Development / test (docker compose)
FROM base AS dev
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev unzip \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/bin/composer

# ---- Production
FROM base AS prod
COPY --from=vendor /app/vendor vendor
COPY app app
COPY includes includes
COPY public public
COPY templates templates
