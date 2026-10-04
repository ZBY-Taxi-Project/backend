FROM php:8.2-cli-alpine

# Set working directory
WORKDIR /var/www/html

# Install system dependencies & PostgreSQL dev libraries
RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    netcat-openbsd \
    bash \
    git \
    unzip

# Install required PHP extensions
RUN docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    zip \
    bcmath \
    pcntl \
    intl \
    opcache

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy application files
COPY . /var/www/html

# Install PHP dependencies without dev packages
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# Copy entrypoint script and ensure execution permission
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Ensure storage directories exist and have proper permissions
RUN mkdir -p /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/framework/cache \
    /var/www/html/storage/logs \
    /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
