<?php
// config.php - 直接指定連線參數（避免 parse_url 解析錯誤）

// ========== 直接設定連線參數 ==========
$db_type = 'pgsql';
$db_host = 'aws-1-ap-northeast-1.pooler.supabase.com';
$db_port = 5432;
$db_name = 'postgres';
$db_user = 'postgres.gyxzbbedauyglqskcsqk';  // 注意：包含專案 ID
$db_pass = 'bkeqYKv$9@IOGkBt';                // 原始密碼（不編碼）

// ========== 建立連線 ==========
$pdo = null;

try {
    $dsn = "$db_type:host=$db_host;port=$db_port;dbname=$db_name";
    $pdo = new PDO($dsn, $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // 連線成功（開發時可取消註解）
    // echo "✅ 資料庫連線成功\n";
    
} catch (PDOException $e) {
    error_log("DB connection failed: " . $e->getMessage());
    die("資料庫連線失敗: " . $e->getMessage() . "\n");
}

// ========== API Keys 常數 ==========
define('OPENAI_API_KEY', getenv('OPENAI_API_KEY') ?: '');
define('LINE_CHANNEL_SECRET', getenv('LINE_CHANNEL_SECRET') ?: '');
define('LINE_CHANNEL_TOKEN', getenv('LINE_CHANNEL_TOKEN') ?: '');
define('RESEND_API_KEY', getenv('RESEND_API_KEY') ?: '');