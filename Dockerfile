FROM php:8.2-apache

# 安裝 PDO MySQL / PostgreSQL
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# 🔧 強制只啟用 mpm_prefork，避免 "More than one MPM loaded"
# 1. 刪掉 mods-enabled 裡所有 mpm_* 的 symlink
# 2. 只重新連回 mpm_prefork
# 3. 啟用 rewrite 模組
RUN rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf \
    && ln -s /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load \
    && ln -s /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf \
    && a2enmod rewrite

# 複製專案檔案
WORKDIR /var/www/html
COPY . /var/www/html

# 由 Railway 管理
EXPOSE 80
