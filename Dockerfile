FROM php:8.2-apache

# 安裝 PDO MySQL 和 PostgreSQL 依賴
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# 啟用 Apache mod_rewrite

# 🔍 偵錯：列出目前 Apache 啟用的 MPM 設定檔
RUN echo "=== DEBUG: MPM files in mods-enabled ===" \
    && ls -l /etc/apache2/mods-enabled/mpm_* || true \
    && echo "=== DEBUG: grep LoadModule mpm_ in all Apache configs ===" \
    && grep -R "LoadModule mpm_" -n /etc/apache2 || true \
    && echo "=== DEBUG END ==="


# 複製專案檔案到容器
COPY . /var/www/html/

# 設定工作目錄
WORKDIR /var/www/html/

# 由 Railway 管理
EXPOSE 80
