# Use the Nginx image based on Alpine for a lightweight container
FROM nginx:alpine

# Define a variable for the PHP version
ARG PHP_VERSION=82
ENV PHP_VERSION=${PHP_VERSION}

# Add PHP and modules to the image
RUN apk add --no-cache \
    git \
    "php${PHP_VERSION}" \
    "php${PHP_VERSION}-fpm" \
    "php${PHP_VERSION}-session" \
    "php${PHP_VERSION}-mbstring" \
    "php${PHP_VERSION}-pdo" \
    "php${PHP_VERSION}-pdo_pgsql" \
    "php${PHP_VERSION}-openssl" \
    "php${PHP_VERSION}-ldap" \
    # Additional PHP extensions can be added here if necessary
    # The following lines create symbolic links to ensure commands like 'php' and 'php-fpm'
    # are available globally without specifying the PHP version.
    && ln -s "/usr/bin/php${PHP_VERSION}" /usr/bin/php \
    && ln -s "/etc/php${PHP_VERSION}" /etc/php \
    && ln -s "/usr/sbin/php-fpm${PHP_VERSION}" /usr/sbin/php-fpm

# Copy the Nginx configuration file
COPY docker/nginx/default.conf /etc/nginx/conf.d/
#COPY docker/php-fpm-logging.conf /etc/php"${PHP_VERSION}"/php-fpm.d
#RUN sed -i '/^;catch_workers_output/s/^;//' /etc/php82/php-fpm.d/www.conf && sed -i '/^;decorate_workers_output/s/^;//' /etc/php82/php-fpm.d/www.conf && sed -i 's/;access.log = log\/php82\/\$pool.access.log/access.log = \/proc\/self\/fd\/2/' /etc/php82/php-fpm.d/www.conf

# This section configures PHP-FPM to not discard stdout, ensuring logs can be captured by Docker logging. It redirects error logs and access logs to stdout, enabling the 'catch_workers_output' and disabling 'decorate_workers_output' for cleaner log output.
RUN mkdir -p /etc/php/php-fpm.d/ && \
    { \
    echo '[global]'; \
    echo 'error_log = /proc/self/fd/2'; \
    echo ''; \
    echo '[www]'; \
    echo 'access.log = /proc/self/fd/2'; \
    echo 'catch_workers_output = yes'; \
    echo 'decorate_workers_output = no'; \
    } > /etc/php/php-fpm.d/php-fpm-logging.conf



# Set the working directory to /app
WORKDIR /app

# Copy everything from the current directory (where the Dockerfile is located) into the /app directory in the container
COPY . .
# Later: use git clone and not COPY
# RUN git clone https://github.com/Cyberzone24/Portflow.git /app

# Set permissions for directories and files
RUN chown -R nginx:nobody /app && \
    find /app -type d -exec chmod 775 {} \; && \
    find /app -type f -exec chmod 664 {} \; && \
    mkdir -p /var/log/portflow && \
    chown -R nobody:nobody /var/log/portflow

# Start PHP-FPM and Nginx
CMD ["sh", "-c", "php-fpm && nginx -g 'daemon off;'"]
