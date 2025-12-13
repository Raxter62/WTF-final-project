FROM php:8.2-apache

# 安裝 PDO MySQL 和 PostgreSQL 依賴
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*


# 🔍 DEBUG + 修正 MPM：先看目前有哪些 MPM，然後只保留 mpm_prefork
RUN echo "=== DEBUG BEFORE CLEANUP: MPM files in mods-enabled ===" \
    && ls -l /etc/apache2/mods-enabled/mpm_* || true \
    && echo "=== DEBUG BEFORE CLEANUP: grep LoadModule mpm_ in configs ===" \
    && grep -R "LoadModule mpm_" -n /etc/apache2 || true \
    && echo "=== DO CLEANUP: remove all mpm_* from mods-enabled, keep only prefork ===" \
    && rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf \
    && ln -s /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load \
    && ln -s /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf \
    && a2enmod rewrite \
    && echo "=== DEBUG AFTER CLEANUP: MPM files in mods-enabled ===" \
    && ls -l /etc/apache2/mods-enabled/mpm_* || true \
    && echo "=== DEBUG AFTER CLEANUP: grep LoadModule mpm_ in configs ===" \
    && grep -R "LoadModule mpm_" -n /etc/apache2 || true \
    && echo "=== DEBUG END ==="


# 複製專案檔案到容器
COPY . /var/www/html/

# 設定工作目錄
WORKDIR /var/www/html/

# 由 Railway 管理
EXPOSE 80
