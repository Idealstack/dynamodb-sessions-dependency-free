FROM php:7-cli
RUN apt-get update && apt-get install -y unzip 
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN pecl install xdebug 
# RUN docker-php-ext-enable xdebug
RUN   { \
            echo "xdebug.mode=debug"; \
            echo "xdebug.start_with_request=yes"; \
            echo "xdebug.client_host=host.docker.internal"; \
        } > /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini; 