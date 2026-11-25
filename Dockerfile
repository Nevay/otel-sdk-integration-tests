ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-alpine

# Parallel compile jobs used by install-php-extensions. Empty (the default) means
# "use every detected processor"; lower it on small machines — building grpc
# needs well over a gigabyte of RAM per compiler job.
# Example: IPE_PROCESSOR_COUNT=2 make build
ARG IPE_PROCESSOR_COUNT=
ENV IPE_PROCESSOR_COUNT=${IPE_PROCESSOR_COUNT}

RUN --mount=from=mlocati/php-extension-installer,dst=/build/extension-installer,src=/usr/bin/install-php-extensions \
    set -eux; \
    /build/extension-installer \
      ffi \
      gmp \
      grpc \
      inotify \
      opcache \
      opentelemetry \
      pcntl \
      protobuf \
      yaml \
    ;

RUN ln -f "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="${PATH}:/root/.composer/vendor/bin"
COPY --from=composer /usr/bin/composer /usr/bin/composer
