<?php
// config.php - 資料庫連線與全域設定

// 1. 從環境變數取得 DATABASE_URL
$databaseUrl = getenv('DATABASE_URL');
$pdo = null;
$scheme = null;

if ($databaseUrl) {
    // 解析 URL (例如：
    //   postgres://user:pass@host:5432/dbname?sslmode=require
    //   mysql://user:pass@host:3306/dbname
    $dbConfig = parse_url($databaseUrl);

    $scheme = $dbConfig['scheme'] ?? 'mysql';
    $host   = $dbConfig['host'] ?? 'localhost';
    $port   = $dbConfig['port'] ?? null;
    $user   = $dbConfig['user'] ?? '';
    $pass   = $dbConfig['pass'] ?? '';
    $path   = $dbConfig['path'] ?? '';
    $query  = $dbConfig['query'] ?? '';

    $dbname = ltrim($path, '/');

    // 預設 port
    if (!$port) {
        if (strpos($scheme, 'mysql') === 0) {
            $port = 3306;
        } else {
            $port = 5432;
        }
    }

    // 從 query 組出額外的 DSN 參數（目前主要用在 sslmode）
    $extraDsn = '';
    if ($query) {
        // sslmode=require&xxx=yyy -> ;sslmode=require;xxx=yyy
        $extraDsn = ';' . str_replace('&', ';', $query);
    }

    if (strpos($scheme, 'mysql') === 0) {
        // 本機如果要用 XAMPP + MySQL，可以給 mysql://... 的 DATABASE_URL
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    } else {
        // Railway + Neon：使用 PostgreSQL
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}{$extraDsn}";
    }

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        die('DB connection failed: ' . $e->getMessage());
    }
} else {
    // 本機測試若沒有 DATABASE_URL，可以在這裡自行填入連線資訊
    /*
    $scheme = 'pgsql';
    $dsn  = 'pgsql:host=localhost;port=5432;dbname=fitconnect';
    $user = 'postgres';
    $pass = 'password';
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        die('Local DB connection failed: ' . $e->getMessage());
    }
    */
}

// 1.5 自動建立 PostgreSQL 資料表（若尚未存在）
// 只在使用 PostgreSQL（例如 Neon）時執行，若是本機 MySQL 則略過
if ($pdo && $scheme && strpos($scheme, 'pgsql') === 0) {
    try {
        // 使用者基本資料（含身高體重與 Line 綁定資訊）
        $pdo->exec("
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
        ");

        // 運動紀錄：每一筆運動上傳紀錄
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS workouts (
                id BIGSERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                date TIMESTAMPTZ NOT NULL,
                type TEXT NOT NULL,
                minutes INTEGER NOT NULL,
                calories INTEGER NOT NULL,
                created_at TIMESTAMPTZ DEFAULT NOW()
            );
        ");

        // 依使用者與日期的索引，加速統計與排行
        $pdo->exec("
            CREATE INDEX IF NOT EXISTS idx_workouts_user_date
            ON workouts (user_id, date);
        ");

        // 每位使用者的累積總消耗卡路里
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_totals (
                user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                total_calories BIGINT NOT NULL DEFAULT 0
            );
        ");
    } catch (PDOException $e) {
        // Schema 初始化失敗不影響主程式執行，可視需要記錄 log
        // error_log('DB schema init failed: ' . $e->getMessage());
    }
}

// 2. 定義 API Keys 為常數
define('OPENAI_API_KEY',      getenv('OPENAI_API_KEY') ?: '');
define('LINE_CHANNEL_SECRET', getenv('LINE_CHANNEL_SECRET') ?: '');
define('LINE_CHANNEL_TOKEN',  getenv('LINE_CHANNEL_TOKEN') ?: '');
define('RESEND_API_KEY',      getenv('RESEND_API_KEY') ?: '');
