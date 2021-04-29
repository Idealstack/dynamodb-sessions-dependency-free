FROM php:5-cli
RUN apt-get update && apt-get install -y unzip 
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
