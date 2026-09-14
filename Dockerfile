FROM php:8.5-alpine

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
