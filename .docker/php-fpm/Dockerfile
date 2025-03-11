FROM php:8.3-fpm

# Update package list and install required packages including libonig-dev
RUN apt-get update && apt-get install -y \
    git \
    nano \
    curl \
    sudo \
    locales \
    unzip \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libicu-dev \
    libzip-dev \
    libxml2-dev \
    exiftool \
    libonig-dev \
 && rm -rf /var/lib/apt/lists/*

# Configure locale
RUN echo "en_US.UTF-8 UTF-8" >> /etc/locale.gen && \
    locale-gen en_US.UTF-8
ENV LC_ALL=en_US.UTF-8 \
    LANG=en_US.UTF-8 \
    LANGUAGE=en_US.UTF-8 \
    WWW_HOME=/var/www

# Adjust user settings
RUN usermod -u 1000 www-data && \
    chsh -s /bin/bash www-data && \
    mkdir -p ${WWW_HOME} && \
    chown www-data:1000 ${WWW_HOME}

# Install and enable PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install -j$(nproc) gd && \
    docker-php-ext-configure intl && \
    docker-php-ext-install -j$(nproc) intl && \
    docker-php-ext-configure zip && \
    docker-php-ext-install -j$(nproc) zip && \
    docker-php-ext-install -j$(nproc) xml && \
    docker-php-ext-configure pdo_mysql --with-pdo-mysql=mysqlnd && \
    docker-php-ext-install -j$(nproc) pdo pdo_mysql && \
    docker-php-ext-install -j$(nproc) mbstring && \
    docker-php-ext-install -j$(nproc) exif

# Install Composer
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install Node\.js (via Nodesource)
RUN curl -sL https://deb.nodesource.com/setup_14.x | bash - && \
    apt-get update && apt-get install -y nodejs && rm -rf /var/lib/apt/lists/*

USER www-data