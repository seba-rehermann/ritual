FROM php:8.2-apache

# Habilitar módulos Apache necesarios
RUN a2enmod rewrite deflate expires headers

# Copiar configuración personalizada que activa AllowOverride y desactiva Indexes
COPY docker/apache.conf /etc/apache2/conf-available/ritual.conf
RUN a2enconf ritual

# docker-socket-access: permitir que www-data hable con /var/run/docker.sock montado
ARG DOCKER_GID=989
RUN getent group docker >/dev/null || groupadd -g ${DOCKER_GID} docker \
    && usermod -aG docker www-data

EXPOSE 80
