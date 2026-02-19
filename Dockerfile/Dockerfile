FROM php:8.3-fpm

# 1. Sistem paketlerini ve bağımlılıkları yükle
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    nginx \
    supervisor \
    libzip-dev \
    default-mysql-client

# 2. Node.js yükle (Frontend build için)
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

# 3. Cache temizle
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# 4. PHP eklentilerini yükle
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd intl zip opcache

# 5. Composer'ı kopyala
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 6. Çalışma dizinini ayarla
WORKDIR /var/www/html

# 7. Proje dosyalarını kopyala
COPY . .

# 8. İzinleri ayarla (www-data)
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

# 9. Bağımlılıkları yükle (Composer & NPM)
RUN composer install --no-interaction --optimize-autoloader --no-dev
RUN npm install && npm run build

# 10. Konfigürasyonları kopyala
COPY .docker/nginx.conf /etc/nginx/sites-available/default
COPY .docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY .docker/php.ini /usr/local/etc/php/conf.d/custom.ini

# 11. Portu aç
EXPOSE 80

# 12. Başlatma komutu
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
