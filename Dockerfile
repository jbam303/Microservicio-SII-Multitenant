FROM php:8.3-apache

# Habilitar mod_rewrite para Slim
RUN a2enmod rewrite

# Actualizar e instalar dependencias del sistema
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libonig-dev \
    libxml2-dev \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# Instalar extensiones de PHP requeridas
RUN docker-php-ext-install \
    curl \
    mbstring \
    soap \
    dom \
    xml

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar el DocumentRoot de Apache a /var/www/html/public
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Permitir el uso de .htaccess sobreescribiendo la configuración AllowOverride
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Configurar Apache para usar la variable de entorno PORT (requerido por Cloud Run)
ENV PORT 8080
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Copiar el código de la aplicación
WORKDIR /var/www/html
COPY . .

# Instalar dependencias de Composer
RUN composer install --no-dev --optimize-autoloader --ignore-platform-req=php

# Crear y configurar permisos para la carpeta de almacenamiento
RUN mkdir -p storage/caf storage/certificados && \
    chown -R www-data:www-data storage && \
    chmod -R 775 storage

EXPOSE 8080

CMD ["apache2-foreground"]
