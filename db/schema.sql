-- FitConnect 資料庫 Schema（可用於手動初始化/檢查）
-- 注意：你的專案已在 config.php 自動建表（CREATE TABLE IF NOT EXISTS）。
-- 這份檔案只是「可讀的完整 Schema」，不再放 ... 佔位符（那會讓 SQL 無法執行）。

/* =========================================================
   PostgreSQL 版本（Neon / Railway 常用）
   ========================================================= */

CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    display_name TEXT,
    line_user_id TEXT,
    line_bind_code VARCHAR(10),
    line_bind_code_expires_at TIMESTAMPTZ,
    height NUMERIC(5,2),
    weight NUMERIC(5,2),
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS workouts (
    id BIGSERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    date TIMESTAMPTZ NOT NULL,
    type TEXT NOT NULL,
    minutes INTEGER NOT NULL,
    calories INTEGER NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_workouts_user_date ON workouts(user_id, date);

/* =========================================================
   MySQL 版本（XAMPP / phpMyAdmin 常用）
   ========================================================= */

-- 若你在 MySQL 執行，請把上面的 PostgreSQL 區段分開執行或註解掉。

-- 使用者 (Users)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(100),
    line_user_id VARCHAR(255),
    line_bind_code VARCHAR(10),
    line_bind_code_expires_at DATETIME,
    height DECIMAL(5,2),
    weight DECIMAL(5,2),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 運動紀錄 (Workouts)
CREATE TABLE IF NOT EXISTS workouts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    date DATETIME NOT NULL,
    type VARCHAR(50) NOT NULL,
    minutes INT NOT NULL,
    calories INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_workouts_user_date (user_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
