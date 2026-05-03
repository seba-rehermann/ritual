FROM php:8.2-apache

# Habilitar módulos Apache necesarios
RUN a2enmod rewrite deflate expires headers

# Override del vhost por defecto: omitir docker-health.txt del CustomLog combinado (menos churn en disco/CPU por healthchecks).
COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf

# Permite que los .htaccess de cada contenedor tengan efecto
# y desactiva el listado de directorios en todo el webroot.
COPY docker/apache.conf /etc/apache2/conf-available/ritual.conf
RUN a2enconf ritual

# docker-socket-access: permitir que www-data hable con /var/run/docker.sock montado
ARG DOCKER_GID=989
RUN getent group docker >/dev/null || groupadd -g ${DOCKER_GID} docker \
    && usermod -aG docker www-data

EXPOSE 80
