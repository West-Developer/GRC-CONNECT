FROM php:8.3-fpm-alpine

# Install system dependencies & PHP extensions required by Laravel
RUN apk add --no-cache \
    nginx \
    curl \
    git \
    unzip \
    libpng-dev \
    libxml2-dev \
    libzip-dev \
    oniguruma-dev \
    ca-certificates \
    && docker-php-ext-install bcmath pdo_mysql mbstring pcntl xml zip

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Run composer install for production
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Setup Nginx configuration
COPY nginx.conf /etc/nginx/http.d/default.conf

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Setup startup script
COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

EXPOSE 8080

CMD ["/usr/local/bin/start.sh"]

