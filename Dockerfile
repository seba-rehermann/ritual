FROM php:8.2-apache

# Habilitar módulos Apache necesarios
RUN a2enmod rewrite deflate expires headers

# Copiar configuración personalizada que activa AllowOverride y desactiva Indexes
COPY docker/apache.conf /etc/apache2/conf-available/ritual.conf
RUN a2enconf ritual

EXPOSE 80
