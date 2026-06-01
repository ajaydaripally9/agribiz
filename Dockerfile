# Use the official PHP image with Apache pre-installed
FROM php:8.2-apache

# Install the mysqli extension (required for database connectivity in your project)
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

# Enable Apache mod_rewrite (useful for .htaccess and routing)
RUN a2enmod rewrite

# Set the working directory to the Apache document root
WORKDIR /var/www/html

# Copy the entire project code into the container
COPY . /var/www/html

# Set correct permissions for the Apache web server
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Expose port 80 (standard Apache port)
EXPOSE 80

# Start the Apache server in the foreground
CMD ["apache2-foreground"]
