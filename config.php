<?php
// config.php - 資料庫連線與全域設定
declare(strict_types=1);

// 避免 PHP Warning/Notice 直接噴到前端造成 JSON.parse 爆炸
ini_set('display_errors', '0');
error_reporting(E_ALL);

// 1. 從環境變數取得 DATABASE_URL（Neon / Railway 提供的 PostgreSQL 連線字串）
$databaseUrl = getenv('DATABASE_URL');
$pdo = null;
$scheme = null;
$DB_DRIVER = 'pgsql';

function fc_json_fatal(string $msg, ?string $detail = null): void {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => $msg,
        'error'   => $detail,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$databaseUrl) {
    fc_json_fatal('Missing DATABASE_URL');
}

// 解析 URL (例如：
//   postgres://user:pass@host:5432/dbname?sslmode=require
//   postgresql://user:pass@host:5432/dbname?sslmode=require
//   pgsql://user:pass@host:5432/dbname?sslmode=require
$dbConfig = parse_url($databaseUrl);
if ($dbConfig === false) {
    fc_json_fatal('Invalid DATABASE_URL');
}

$scheme = strtolower($dbConfig['scheme'] ?? 'pgsql');
if ($scheme === 'postgres' || $scheme === 'postgresql') {
    $scheme = 'pgsql';
}

$host   = $dbConfig['host'] ?? 'localhost';
$port   = $dbConfig['port'] ?? 5432;
$user   = isset($dbConfig['user']) ? rawurldecode($dbConfig['user']) : '';
$pass   = isset($dbConfig['pass']) ? rawurldecode($dbConfig['pass']) : '';
$dbname = isset($dbConfig['path']) ? ltrim($dbConfig['path'], '/') : '';
$dbname = rawurldecode($dbname);
$query  = $dbConfig['query'] ?? '';

// 從 query 組出額外的 DSN 參數（目前主要用在 sslmode）
$extraDsn = '';
if ($query) {
    // sslmode=require&xxx=yyy -> ;sslmode=require;xxx=yyy
    $extraDsn = ';' . str_replace('&', ';', $query);
}

$dsn = "pgsql:host={$host};port={$port};dbname={$dbname}{$extraDsn}";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fc_json_fatal('DB connection failed', $e->getMessage());
}

/**
 * 2. 初始化資料表（若不存在才建立）
 */
if ($pdo && $DB_DRIVER === 'pgsql') {
    try {
        // users
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

        // workouts
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

        // user_totals
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_totals (
                user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                total_calories BIGINT NOT NULL DEFAULT 0
            );
        ");

        // leaderboard_snapshots
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS leaderboard_snapshots (
                id BIGSERIAL PRIMARY KEY,
                date DATE NOT NULL,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                rank INTEGER NOT NULL,
                total_minutes INTEGER NOT NULL,
                created_at TIMESTAMPTZ DEFAULT NOW()
            );
        ");

        // email_notifications
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS email_notifications (
                id BIGSERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                type VARCHAR(50) NOT NULL,
                created_at TIMESTAMPTZ DEFAULT NOW(),
                sent_at TIMESTAMPTZ DEFAULT NULL
            );
        ");

        // indexes
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_workouts_user_date ON workouts(user_id, date);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_leaderboard_date ON leaderboard_snapshots(date);");
    } catch (PDOException $e) {
        // Schema 初始化失敗通常是權限/連線問題，回 JSON 避免前端炸掉
        fc_json_fatal('DB schema init failed', $e->getMessage());
    }
}

// 3. 定義 API Keys 為常數（保持你原本的功能/設定，不刪）
define('OPENAI_API_KEY',      getenv('OPENAI_API_KEY') ?: '');
define('LINE_CHANNEL_SECRET', getenv('LINE_CHANNEL_SECRET') ?: '');
define('LINE_CHANNEL_ACCESS', getenv('LINE_CHANNEL_ACCESS') ?: '');
