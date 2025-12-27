<?php
// config.php - 資料庫連線與全域設定
declare(strict_types=1);

// 避免 PHP Warning/Notice 直接噴到前端造成 JSON.parse 爆炸
ini_set('display_errors', '0');
error_reporting(E_ALL);

// 1. 從環境變數取得 DATABASE_URL
$databaseUrl = getenv('DATABASE_URL');
$pdo = null;
$scheme = null;
$DB_DRIVER = null;

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

if ($databaseUrl) {
    // 解析 URL (例如：
    //   postgres://user:pass@host:5432/dbname?sslmode=require
    //   postgresql://user:pass@host:5432/dbname?sslmode=require
    //   pgsql://user:pass@host:5432/dbname?sslmode=require
    //   mysql://user:pass@host:3306/dbname
    $dbConfig = parse_url($databaseUrl);
    if ($dbConfig === false) {
        fc_json_fatal('Invalid DATABASE_URL');
    }

    $scheme = strtolower($dbConfig['scheme'] ?? 'mysql');

    // ✅ 關鍵修正：Neon/Railway 常用 postgres:// 或 postgresql://，要視為 pgsql
    if ($scheme === 'postgres' || $scheme === 'postgresql') {
        $scheme = 'pgsql';
    }

    $host   = $dbConfig['host'] ?? 'localhost';
    $port   = $dbConfig['port'] ?? null;
    $user   = isset($dbConfig['user']) ? rawurldecode($dbConfig['user']) : '';
    $pass   = isset($dbConfig['pass']) ? rawurldecode($dbConfig['pass']) : '';
    $dbname = isset($dbConfig['path']) ? ltrim($dbConfig['path'], '/') : '';
    $dbname = rawurldecode($dbname);
    $query  = $dbConfig['query'] ?? '';

    if (!$port) {
        $port = (strpos($scheme, 'mysql') === 0) ? 3306 : 5432;
    }

    // 從 query 組出額外的 DSN 參數（目前主要用在 sslmode）
    $extraDsn = '';
    if ($query) {
        // sslmode=require&xxx=yyy -> ;sslmode=require;xxx=yyy
        $extraDsn = ';' . str_replace('&', ';', $query);
    }

    if (strpos($scheme, 'mysql') === 0) {
        $DB_DRIVER = 'mysql';
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    } else {
        $DB_DRIVER = 'pgsql';
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}{$extraDsn}";
    }

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        fc_json_fatal('DB connection failed', $e->getMessage());
    }
} else {
    // 本機測試若沒有 DATABASE_URL，可以在這裡自行填入連線資訊（不建議上線用）
    // fc_json_fatal('Missing DATABASE_URL');
    $scheme = 'mysql';
    $DB_DRIVER = 'mysql';
}

/**
 * 2. 初始化資料表（若不存在才建立）
 */
if ($pdo && $DB_DRIVER) {
    try {
        if ($DB_DRIVER === 'pgsql') {
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

            // leaderboard_snapshots（保留原先 schema 想法；目前主流程沒用到，但不刪）
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

            // email_notifications（保留原先 schema 想法）
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

        } else {
            // MySQL（本機 XAMPP 測試用）
            $pdo->exec("
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
            ");

            $pdo->exec("
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
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS user_totals (
                    user_id INT PRIMARY KEY,
                    total_calories BIGINT NOT NULL DEFAULT 0,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS leaderboard_snapshots (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    date DATE NOT NULL,
                    user_id INT NOT NULL,
                    rank INT NOT NULL,
                    total_minutes INT NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    INDEX idx_leaderboard_date (date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS email_notifications (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    type VARCHAR(50) NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    sent_at DATETIME DEFAULT NULL,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
        }
    } catch (PDOException $e) {
        // Schema 初始化失敗通常是權限/連線問題，回 JSON 避免前端炸掉
        fc_json_fatal('DB schema init failed', $e->getMessage());
    }
}

// 3. 定義 API Keys 為常數（保持你原本的功能/設定，不刪）
define('OPENAI_API_KEY',      getenv('OPENAI_API_KEY') ?: '');
define('LINE_CHANNEL_SECRET', getenv('LINE_CHANNEL_SECRET') ?: '');
define('LINE_CHANNEL_ACCESS', getenv('LINE_CHANNEL_ACCESS') ?: '');
