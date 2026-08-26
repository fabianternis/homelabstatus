FROM php:8.5-cli-alpine

RUN apk add --no-cache \
    curl \
    iputils \
    sqlite-libs \
    sqlite-dev \
    libzip-dev \
    icu-dev \
    && docker-php-ext-install pdo_sqlite pcntl intl opcache

# Set working directory
WORKDIR /app

# Copy files
COPY . /app

# Install composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader

# Expose web server port
EXPOSE 8080

# Start built-in server
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
