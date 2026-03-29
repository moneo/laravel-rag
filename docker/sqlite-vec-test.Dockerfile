FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive

# Build deps
RUN apt-get update && apt-get install -y \
    build-essential autoconf bison re2c pkg-config \
    libxml2-dev libsqlite3-dev libonig-dev libcurl4-openssl-dev \
    libzip-dev libreadline-dev zlib1g-dev libssl-dev \
    wget git unzip curl sqlite3

# Build SQLite from source WITH load extension support
RUN wget -q https://www.sqlite.org/2024/sqlite-autoconf-3460100.tar.gz && \
    tar xzf sqlite-autoconf-3460100.tar.gz && \
    cd sqlite-autoconf-3460100 && \
    CFLAGS="-DSQLITE_ENABLE_LOAD_EXTENSION -O2" ./configure --prefix=/opt/sqlite3 && \
    make -j$(nproc) && make install && \
    cd / && rm -rf sqlite-autoconf-*

# Build PHP from source linked to our custom SQLite
RUN wget -q https://www.php.net/distributions/php-8.3.20.tar.gz && \
    tar xzf php-8.3.20.tar.gz && \
    cd php-8.3.20 && \
    PKG_CONFIG_PATH=/opt/sqlite3/lib/pkgconfig ./configure \
        --with-pdo-sqlite=/opt/sqlite3 \
        --with-sqlite3=/opt/sqlite3 \
        --enable-mbstring \
        --with-curl \
        --with-openssl \
        --with-zip \
        --with-zlib \
        --with-readline \
        --enable-pcntl \
    && make -j$(nproc) && make install && \
    cd / && rm -rf php-8.3.20*

# Download sqlite-vec
RUN ARCH=$(dpkg --print-architecture) && \
    if [ "$ARCH" = "arm64" ]; then \
        URL="https://github.com/asg017/sqlite-vec/releases/download/v0.1.7/sqlite-vec-0.1.7-loadable-linux-aarch64.tar.gz"; \
    else \
        URL="https://github.com/asg017/sqlite-vec/releases/download/v0.1.7/sqlite-vec-0.1.7-loadable-linux-x86_64.tar.gz"; \
    fi && \
    wget -q "$URL" -O /tmp/sv.tar.gz && \
    cd /tmp && tar xzf sv.tar.gz && \
    mkdir -p /usr/lib/sqlite-vec && cp vec0.so /usr/lib/sqlite-vec/ && \
    rm -rf /tmp/sv* /tmp/vec0*

# PHP config for sqlite-vec
RUN echo "sqlite3.extension_dir=/usr/lib/sqlite-vec" >> /usr/local/lib/php.ini

# Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app
