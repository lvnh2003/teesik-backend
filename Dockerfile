FROM php:8.2-apache

# Cài đặt các thư viện hệ thống cần thiết và PHP extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libonig-dev \
    zip \
    unzip \
    git \
    curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo pdo_mysql mbstring zip exif pcntl \
    && a2enmod rewrite

# Cài đặt Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Cài đặt Node.js và NPM (để build assets qua Vite)
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

# Thiết lập thư mục làm việc
WORKDIR /var/www/html

# Thay đổi DocumentRoot mặc định của Apache trỏ vào thư mục public của Laravel
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Cấp quyền cho user www-data (Apache)
RUN chown -R www-data:www-data /var/www/html
