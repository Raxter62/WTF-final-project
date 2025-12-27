<?php
/**
 * config.php
 * - 讀取 DATABASE_URL（Neon / Railway / 本機）
 * - 建立 PDO 連線（PostgreSQL / MySQL）
 */
declare(strict_types=1);

// 避免把 warning/notice 直接噴到前端造成「Unexpected token '<'」
ini_set('display_errors', '0');
error_reporting(E_ALL);

function fc_parse_database_url(string $databaseUrl): array {
    $cfg = parse_url($databaseUrl);
    if ($cfg === false) {
        throw new RuntimeException('Invalid DATABASE_URL');
    }

    $scheme = strtolower($cfg['scheme'] ?? '');
    $host   = $cfg['host'] ?? 'localhost';
    $port   = isset($cfg['port']) ? (int)$cfg['port'] : null;
    $user   = isset($cfg['user']) ? rawurldecode($cfg['user']) : '';
    $pass   = isset($cfg['pass']) ? rawurldecode($cfg['pass']) : '';
    $path   = $cfg['path'] ?? '';
    $dbname = rawurldecode(ltrim($path, '/'));

    // query 參數（sslmode=require 等）
    $queryParams = [];
    if (!empty($cfg['query'])) {
        parse_str($cfg['query'], $queryParams);
    }

    // 正規化 driver 名稱：postgres / postgresql / pgsql 都視為 pgsql
    $driver = 'mysql';
    if ($scheme === 'postgres' || $scheme === 'postgresql' || $scheme === 'pgsql') {
        $driver = 'pgsql';
    } elseif (str_starts_with($scheme, 'mysql')) {
        $driver = 'mysql';
    }

    if ($port === null) {
        $port = ($driver === 'pgsql') ? 5432 : 3306;
    }

    return [
        'driver' => $driver,
        'host' => $host,
        'port' => $port,
        'dbname' => $dbname,
        'user' => $user,
        'pass' => $pass,
        'query' => $queryParams,
    ];
}

function fc_make_pdo(array $db): PDO {
    if ($db['driver'] === 'pgsql') {
        // Neon 通常需要 sslmode=require
        $sslmode = $db['query']['sslmode'] ?? 'require';
        $dsn = sprintf(
            "pgsql:host=%s;port=%d;dbname=%s;sslmode=%s",
            $db['host'], $db['port'], $db['dbname'], $sslmode
        );
    } else {
        $dsn = sprintf(
            "mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4",
            $db['host'], $db['port'], $db['dbname']
        );
    }

    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

/**
 * 建表（兼容 PostgreSQL / MySQL）
 * 表結構（符合你原本需求）：
 * - users: id, name, height_cm, weight_kg, 4位驗證碼(line_bind_code), line_user_id, created_at
 * - workouts: id, user_id, sport_type, input_time, duration_min, calories
 * - user_totals: user_id, total_calories, updated_at
 */
function fc_ensure_schema(PDO $pdo, string $driver): void {
    if ($driver === 'pgsql') {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id BIGSERIAL PRIMARY KEY,
                name TEXT NOT NULL UNIQUE,
                height_cm NUMERIC(5,2),
                weight_kg NUMERIC(5,2),
                line_bind_code CHAR(4),
                line_user_id TEXT,
                created_at TIMESTAMPTZ DEFAULT NOW()
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS workouts (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                sport_type TEXT NOT NULL,
                input_time TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                duration_min INTEGER NOT NULL CHECK (duration_min >= 0),
                calories INTEGER NOT NULL CHECK (calories >= 0)
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_totals (
                user_id BIGINT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                total_calories BIGINT NOT NULL DEFAULT 0,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );
        ");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_workouts_user_time ON workouts(user_id, input_time);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_workouts_sport ON workouts(sport_type);");

    } else {
        // MySQL（給本機 XAMPP 測試用）
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                height_cm DECIMAL(5,2) NULL,
                weight_kg DECIMAL(5,2) NULL,
                line_bind_code CHAR(4) NULL,
                line_user_id VARCHAR(255) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS workouts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                sport_type VARCHAR(50) NOT NULL,
                input_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                duration_min INT NOT NULL,
                calories INT NOT NULL,
                CONSTRAINT fk_workouts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_workouts_user_time (user_id, input_time),
                INDEX idx_workouts_sport (sport_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_totals (
                user_id BIGINT UNSIGNED PRIMARY KEY,
                total_calories BIGINT NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_totals_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
}

// --- 建立 $pdo 供其他檔案使用 ---
$pdo = null;
$DB_DRIVER = null;

$databaseUrl = getenv('DATABASE_URL') ?: '';

try {
    if ($databaseUrl) {
        $db = fc_parse_database_url($databaseUrl);
        $DB_DRIVER = $db['driver'];
        $pdo = fc_make_pdo($db);
        fc_ensure_schema($pdo, $DB_DRIVER);
    } else {
        // 沒有 DATABASE_URL 時（本機可改這裡）
        // 建議你本機用 XAMPP：先在 phpMyAdmin 建一個 DB（例如 fitconnect），然後填進來
        $DB_DRIVER = 'mysql';
        $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=fitconnect;charset=utf8mb4", "root", "", [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        fc_ensure_schema($pdo, $DB_DRIVER);
    }
} catch (Throwable $e) {
    // 這裡不要 echo HTML，避免前端解析爆炸；改用 JSON 錯誤格式
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'DB connection/init failed',
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
