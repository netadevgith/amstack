FROM ubuntu:18.04

ENV DEBIAN_FRONTEND=noninteractive
ENV GOPATH=/root/go
ENV PATH=$PATH:/usr/local/go/bin:$GOPATH/bin

# Preconfigure postfix to avoid interactive prompts
RUN echo "postfix postfix/mailname string localhost" | debconf-set-selections && \
    echo "postfix postfix/main_mailer_type string 'Internet Site'" | debconf-set-selections

# Base packages
RUN apt-get update && apt-get install -y \
    software-properties-common \
    curl \
    wget \
    git \
    unzip \
    zip \
    build-essential \
    libssl-dev \
    cron \
    supervisor \
    nginx \
    redis-tools \
    socat \
    python3 \
    postfix \
    mailutils \
    libsasl2-modules \
    rsyslog \
    && rm -rf /var/lib/apt/lists/*

# PHP 7.2
RUN apt-get update && apt-get install -y \
    php7.2-bcmath \
    php7.2-cli \
    php7.2-common \
    php7.2-curl \
    php7.2-dev \
    php7.2-fpm \
    php7.2-gd \
    php7.2-intl \
    php7.2-json \
    php7.2-mbstring \
    php7.2-mysql \
    php7.2-opcache \
    php7.2-readline \
    php7.2-xml \
    php7.2-zip \
    pkg-php-tools \
    && rm -rf /var/lib/apt/lists/*

# Perl modules
RUN apt-get update && apt-get install -y \
    perl \
    libalgorithm-diff-perl \
    libalgorithm-diff-xs-perl \
    libalgorithm-merge-perl \
    libarchive-zip-perl \
    libcgi-fast-perl \
    libcgi-pm-perl \
    libclass-method-modifiers-perl \
    libcommon-sense-perl \
    libconfig-inifiles-perl \
    libdbd-mysql-perl \
    libdbi-perl \
    libencode-locale-perl \
    liberror-perl \
    libev-perl \
    libexporter-tiny-perl \
    libfcgi-perl \
    libfile-fcntllock-perl \
    libfile-slurp-perl \
    libhtml-parser-perl \
    libhtml-tagset-perl \
    libhtml-template-perl \
    libhttp-date-perl \
    libhttp-message-perl \
    libio-html-perl \
    libio-pty-perl \
    libio-socket-ssl-perl \
    libjson-xs-perl \
    liblist-moreutils-perl \
    liblwp-mediatypes-perl \
    libmail-sendmail-perl \
    libmojolicious-perl \
    libnet-openssh-perl \
    libnet-sftp-foreign-perl \
    libnet-ssleay-perl \
    libregexp-assemble-perl \
    librole-tiny-perl \
    libsys-hostname-long-perl \
    libterm-readkey-perl \
    libtext-charwidth-perl \
    libtext-iconv-perl \
    libtimedate-perl \
    libtry-tiny-perl \
    libtypes-serialiser-perl \
    liburi-perl \
    && rm -rf /var/lib/apt/lists/*

# Redis Perl module via CPAN
RUN apt-get update && apt-get install -y cpanminus && \
    cpanm Redis && \
    rm -rf /var/lib/apt/lists/*

# Go 1.23 (arm64-compatible, backwards-compatible with go 1.12 modules)
RUN ARCH=$(dpkg --print-architecture) && \
    wget -q https://dl.google.com/go/go1.23.6.linux-${ARCH}.tar.gz -O /tmp/go.tar.gz && \
    tar -C /usr/local -xzf /tmp/go.tar.gz && \
    rm /tmp/go.tar.gz

# MariaDB client (server runs in separate container)
RUN apt-get update && apt-get install -y \
    mariadb-client \
    && rm -rf /var/lib/apt/lists/*

# Composer for PHP
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Create app directory structure matching production
RUN mkdir -p /home/app/public_html \
    /home/app/taskrunner \
    /home/app/gosender-src \
    /home/app/mailsend \
    /home/app/campaign_logs \
    /home/app/backups \
    /home/app/CA \
    /opt/storage

WORKDIR /home/app

# Copy extracted application files
COPY extracted/acelle_esp/public_html/ /home/app/public_html/
COPY extracted/acelle_esp/gosender.json /home/app/gosender.json
COPY extracted/acelle_esp/go.mod /home/app/go.mod
COPY extracted/acelle_esp/go.sum /home/app/go.sum
COPY extracted/acelle_esp/Makefile /home/app/Makefile
COPY extracted/acelle_esp/LICENSING /home/app/LICENSING
COPY extracted/acelle_esp/LICENSE /home/app/LICENSE
COPY extracted/acelle_esp/names.txt /home/app/names.txt
COPY extracted/acelle_esp/CA/ /home/app/CA/
COPY extracted/acelle_esp/packr2.go /home/app/packr2.go
COPY extracted/acelle_esp/campaign_logs/ /home/app/campaign_logs/
COPY extracted/acelle_esp/mailsend/ /home/app/mailsend/

# Gosender source
COPY extracted/gosender-src/ /home/app/gosender-src/

# Taskrunner
COPY extracted/taskrunner/ /home/app/taskrunner/

# Storage service
COPY extracted/storage/ /opt/storage/

# Tools (Perl scripts, parseris, etc.)
COPY extracted/acelle_esp/public_html/tools/ /home/app/public_html/tools/

# Database dump
COPY mailsendas_testdev.sql.gz /home/app/mailsendas_testdev.sql.gz

# Nginx config for Laravel
COPY docker/nginx-app.conf /etc/nginx/sites-available/default

# PHP-FPM config
COPY docker/php-fpm-pool.conf /etc/php/7.2/fpm/pool.d/www.conf

# Supervisor config
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Init script
COPY docker/init.sh /home/app/init.sh
RUN chmod +x /home/app/init.sh

EXPOSE 80 8081 8082 8083

CMD ["/home/app/init.sh"]
