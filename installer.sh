#!/bin/bash

# Update package list and upgrade all packages
sudo apt update && sudo apt upgrade -y

# Install Lighttpd
sudo apt install -y lighttpd

# Install the latest version of PHP and the necessary modules
sudo apt install -y php-fpm php-cli php-common php-mbstring php-ldap php-pgsql php-opcache

# Enable PHP-FPM in Lighttpd
sudo lighty-enable-mod fastcgi

# Disable the default fastcgi-php configuration to avoid duplicate array-key errors
if [ -f /etc/lighttpd/conf-enabled/15-fastcgi-php.conf ]; then
    sudo rm /etc/lighttpd/conf-enabled/15-fastcgi-php.conf
fi

# Find PHP-FPM socket file
PHP_FPM_SOCK=$(find /run/php/ -name "php*.sock" | head -n 1)

# Create a configuration file for PHP-FPM
cat << EOF | sudo tee /etc/lighttpd/conf-available/15-fastcgi-php-fpm.conf
server.modules += ( "mod_fastcgi" )

fastcgi.server = ( ".php" =>
    ( "localhost" =>
        (
            "socket" => "$PHP_FPM_SOCK",
            "broken-scriptfilename" => "enable"
        )
    )
)
EOF

# Enable the configuration
sudo lighty-enable-mod fastcgi-php-fpm

# Validate Lighttpd configuration
if ! sudo lighttpd -tt -f /etc/lighttpd/lighttpd.conf; then
    echo "Lighttpd configuration file has errors. Please check the configuration."
    exit 1
fi

# Restart Lighttpd to apply changes
sudo systemctl restart lighttpd

# Check if Lighttpd restarted successfully
if ! systemctl is-active --quiet lighttpd; then
    echo "Lighttpd failed to restart. Please check the configuration and logs."
    sudo journalctl -xeu lighttpd.service
    exit 1
fi

# Install PostgreSQL
sudo apt install -y postgresql postgresql-contrib

# Prompt for PostgreSQL user creation details
read -p "Enter the PostgreSQL username: " pg_username
read -s -p "Enter the PostgreSQL password: " pg_password
echo
read -p "Enter the PostgreSQL database name: " pg_dbname

# Create PostgreSQL user and database
sudo -u postgres bash <<EOF
psql -c "CREATE USER $pg_username WITH PASSWORD '$pg_password';"
psql -c "CREATE DATABASE $pg_dbname OWNER $pg_username;"
EOF

# Download the latest release from Cyberzone24/Portflow
TEMP_DIR=$(mktemp -d)
cd $TEMP_DIR
LATEST_RELEASE_URL=$(curl -s https://api.github.com/repos/Cyberzone24/Portflow/releases/latest | grep "tarball_url" | cut -d '"' -f 4)
curl -L $LATEST_RELEASE_URL -o portflow_latest.tar.gz

# Extract and copy to Lighttpd web directory
tar -xzf portflow_latest.tar.gz
EXTRACTED_DIR=$(find . -maxdepth 1 -type d -name "Cyberzone24-Portflow-*")
sudo rm -rf /var/www/html/*
sudo cp -r $EXTRACTED_DIR/* /var/www/html/

# Clean up temporary directory
cd
rm -rf $TEMP_DIR

# Set permissions for web directory
sudo chown -R www-data:www-data /var/www/html
sudo chmod -R 755 /var/www/html

# Output the installation status
echo "Installation complete. Lighttpd, PHP, PostgreSQL, and Portflow have been installed."

# Check the status of Lighttpd and PHP-FPM
sudo systemctl status lighttpd
sudo systemctl status php*-fpm  # Automatically detect PHP-FPM service

# Check the status of PostgreSQL
sudo systemctl status postgresql
