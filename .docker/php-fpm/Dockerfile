FROM php:5.6-fpm

RUN apt-get clean && \
    apt-get update && \
    apt-get install -y git nano curl sudo locales unzip

RUN echo "en_US.UTF-8 UTF-8" >> /etc/locale.gen && \
  locale-gen en_US.UTF-8
ENV LC_ALL en_US.UTF-8
ENV LANG en_US.UTF-8
ENV LANGUAGE en_US.UTF-8

ENV WWW_HOME /var/www
# Make default unix user id match www-data
RUN usermod -u 1000 www-data
# Allow shell access for www-data
RUN chsh -s /bin/bash www-data
# Make www-data sudoer (without password)
RUN echo "www-data ALL=NOPASSWD: ALL" | (EDITOR="tee -a" visudo)
# Create home directory and set proper permissions
RUN mkdir -p $WWW_HOME
RUN chown www-data:1000 $WWW_HOME


RUN apt-get update \
    # php-gd
    && apt-get install -y libfreetype6-dev libjpeg62-turbo-dev libpng-dev \
	&& docker-php-ext-configure gd \
        --with-png-dir=/usr/include/ \
        --with-jpeg-dir=/usr/include/ \
        --with-freetype-dir=/usr/include/ \
	&& docker-php-ext-install -j$(nproc) gd \
    && docker-php-ext-enable gd \
    # php-intl
    && apt-get install -y libicu-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j$(nproc) intl \
    # php-zip
    && apt-get install -y libzip-dev \
    && docker-php-ext-configure zip --with-libzip \
    && docker-php-ext-install -j$(nproc) zip \
    # php-xml
    && apt-get install -y libxml2-dev \
    && docker-php-ext-install -j$(nproc) xml \
    # php-pdo_mysql
    && docker-php-ext-configure pdo_mysql --with-pdo-mysql=mysqlnd \
    && docker-php-ext-install -j$(nproc) pdo pdo_mysql \
    && docker-php-ext-enable pdo_mysql \
    # php-exif
    && apt-get install -y exiftool \
    && docker-php-ext-configure exif \
    && docker-php-ext-install -j$(nproc) exif \
    && docker-php-ext-enable exif \
    # mbstring
    && docker-php-ext-install mbstring

# Install composer
ENV COMPOSER_ALLOW_SUPERUSER 1
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --version=1.10.17

## DEV ONLY !!!
#RUN apt-get install -y php7.2-xdebug
#COPY php/conf.d/xdebug.ini /etc/php/7.2/conf.d/xdebug.ini
#
# Rollback default shell
USER www-data
