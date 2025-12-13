FROM php:8.2-apache

# 安裝 PDO MySQL 和 PostgreSQL 依賴
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql

# 啟用 Apache mod_rewrite
# 解決 MPM 衝突並啟用 mod_rewrite
RUN a2dismod mpm_event mpm_worker || true \
    && a2enmod mpm_prefork rewrite

# 複製專案檔案到容器
COPY . /var/www/html/

# 設定工作目錄
WORKDIR /var/www/html/

# 由 Railway 管理
EXPOSE 80
