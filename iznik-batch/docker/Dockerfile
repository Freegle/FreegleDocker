FROM php:8.4-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libgmp-dev \
    libicu-dev \
    zip \
    unzip \
    supervisor \
    default-mysql-client \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gmp \
    zip \
    intl

# Install Redis and PCOV PHP extensions
RUN pecl install redis pcov && docker-php-ext-enable redis pcov

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install Node.js for MJML
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install MJML globally
RUN npm install -g mjml

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Copy .env.example to .env for testing (will be overridden by environment variables)
RUN cp .env.example .env

# Install PHP dependencies including dev dependencies for testing
RUN composer install --no-interaction --prefer-dist --optimize-autoloader || true

# Generate application key
RUN php artisan key:generate || true

# Create storage directories with correct permissions
RUN mkdir -p storage/framework/{sessions,views,cache} \
    && mkdir -p storage/logs \
    && chmod -R 777 storage bootstrap/cache

# Copy supervisor config
COPY docker/supervisor.conf /etc/supervisor/conf.d/laravel-worker.conf

# Copy entrypoint script
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-n"]
