
FROM php:7.3.6-apache-stretch

COPY apache2-foreground /usr/local/bin/apache2-foreground

# install apache, mysql and tracing
RUN chmod +x /usr/local/bin/apache2-foreground; \
    apt-get update; \
    # for troubleshooting, can add vim and iputils-ping the following command line
    apt-get install -y libmcrypt-dev git curl wget vim; \
    docker-php-ext-install mysqli pdo_mysql sockets; \
    echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf; a2enconf servername; \
    apt-get clean && rm -rf /var/cache/apt/archives; 

# install elasticcache client
RUN wget -O /tmp/latest-64bit https://elasticache-downloads.s3.amazonaws.com/ClusterClient/PHP-7.3/latest-64bit; \
    tar -C /tmp/ -zxvf /tmp/latest-64bit; \
    mv /tmp/amazon-elasticache-cluster-client.so /usr/local/lib/php/extensions/no-debug-non-zts-20180731; \
    echo "extension=/usr/local/lib/php/extensions/no-debug-non-zts-20180731/amazon-elasticache-cluster-client.so" | tee --append /usr/local/etc/php/conf.d/memcached.ini; \
    rm -rf /tmp/*;


# clone app code to apache-directory
COPY app-code/ /var/www/html/; 



ENTRYPOINT ["/usr/local/bin/apache2-foreground"]
