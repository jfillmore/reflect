FROM php:7.4-cli
RUN pecl install \
    && pecl install xdebug-2.8.1 \
    && docker-php-ext-enable xdebug

RUN mkdir -p /opt/php/reflect
ADD reflect.php /opt/php/reflect/reflect.php

WORKDIR /opt/php/reflect

ENTRYPOINT ["env", "-i", "/usr/local/bin/php", "-S", "0.0.0.0:8123", "/opt/php/reflect/reflect.php"]
