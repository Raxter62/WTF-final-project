<?php
// config.php - 資料庫連線與全域設定

// 1. 從環境變數取得 DATABASE_URL
$databaseUrl = getenv('DATABASE_URL');
$pdo = null;

if ($databaseUrl) {
    // 解析 URL (例如 mysql://user:pass@host:port/dbname)
    $dbConfig = parse_url($databaseUrl);
    
    $scheme = $dbConfig['scheme'] ?? 'mysql';
    $host = $dbConfig['host'] ?? 'localhost';
    $port = $dbConfig['port'] ?? '';
    $user = $dbConfig['user'] ?? '';
    $pass = $dbConfig['pass'] ?? '';
    $path = ltrim($dbConfig['path'] ?? '', '/'); // 移除開頭的斜線

    try {
        $dsn = '';
        if (strpos($scheme, 'postgres') !== false) {
            // PostgreSQL
            $port = $port ?: 5432;
            $dsn = "pgsql:host=$host;port=$port;dbname=$path";
        } else {
            // MySQL
            $port = $port ?: 3306;
            $dsn = "mysql:host=$host;port=$port;dbname=$path;charset=utf8mb4";
        }

        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        // 輸出錯誤並停止
        die("DB connection failed: " . $e->getMessage());
    }
} else {
    // 若沒有 DATABASE_URL 的備案或錯誤 (本地測試若無環境變數，可在此寫死或略過)
    // die("DATABASE_URL environment variable not set.");
}

// 2. 定義 API Keys 為常數
define('OPENAI_API_KEY', getenv('OPENAI_API_KEY'));
define('LINE_CHANNEL_SECRET', getenv('LINE_CHANNEL_SECRET'));
define('LINE_CHANNEL_TOKEN', getenv('LINE_CHANNEL_TOKEN'));
define('RESEND_API_KEY', getenv('RESEND_API_KEY'));
