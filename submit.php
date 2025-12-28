<?php
// submit.php - 主要 API 入口點
session_start();
require_once 'config.php';
require_once __DIR__ . '/LLM/coach.php';

header('Content-Type: application/json; charset=utf-8');

// 取得輸入資料的輔助函式
function getJsonInput() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

// 回傳回應的輔助函式
function sendResponse($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

<<<<<<< HEAD
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = getJsonInput();

// Ensure DB connection
if (!$pdo) {
    sendResponse(['success' => false, 'message' => 'DB 連線失敗']);
=======
function normalizeRange(?string $range): string {
    $range = $range ?: '1d';
    return in_array($range, ['1d', '1wk', '1m', '3m'], true) ? $range : '1d';
>>>>>>> 7d28427ba262fed486bc3f5c299c9f97af78287c
}

// --- 驗證相關動作 ---

if ($action === 'register') {
    $email = $input['email'] ?? '';
    $pass = $input['password'] ?? '';
    $name = $input['display_name'] ?? 'User';

    if (!$email || !$pass) {
        sendResponse(['success' => false, 'message' => '請輸入 Email 和密碼']);
    }

    // 檢查 email 是否已存在
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        sendResponse(['success' => false, 'message' => '此 Email 已被註冊']);
    }

    // 建立使用者
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, display_name) VALUES (?, ?, ?)");
    if ($stmt->execute([$email, $hash, $name])) {
        // 自動登入
        $_SESSION['user_id'] = $pdo->lastInsertId();
        sendResponse(['success' => true]);
    } else {
        sendResponse(['success' => false, 'message' => '註冊失敗']);
    }

} elseif ($action === 'login') {
    $email = $input['email'] ?? '';
    $pass = $input['password'] ?? '';

    $stmt = $pdo->prepare("SELECT id, password_hash, display_name FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($pass, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        sendResponse(['success' => true]);
    } else {
        sendResponse(['success' => false, 'message' => '帳號或密碼錯誤']);
    }

} elseif ($action === 'logout') {
    session_destroy();
    sendResponse(['success' => true]);

} elseif ($action === 'get_leaderboard') {
    // 排行榜不需要登入也能查看（Demo 模式可用）
    $sql = "
        SELECT u.display_name,
               SUM(w.calories) AS total
        FROM workouts w
        JOIN users u ON w.user_id = u.id
        WHERE w.date >= CURRENT_DATE - INTERVAL '30 days'
        GROUP BY u.id, u.display_name
        ORDER BY total DESC
        LIMIT 10
    ";
    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll();
    
    // 加上排名
    foreach ($data as $i => &$row) {
        $row['rank'] = $i + 1;
    }

    sendResponse(['success' => true, 'data' => $data]);
}

// --- 需登入後的動作 ---

<<<<<<< HEAD
// 檢查後續所有動作是否已登入
if (!isset($_SESSION['user_id'])) {
    sendResponse(['success' => false, 'message' => 'not_logged_in']);
}
$userId = $_SESSION['user_id'];
=======
function buildDashboardData(PDO $pdo, int $userId, string $driver, string $range): array {
    $range = normalizeRange($range);
>>>>>>> 7d28427ba262fed486bc3f5c299c9f97af78287c

if ($action === 'get_user_info') {
    $stmt = $pdo->prepare("SELECT id, display_name, email, line_user_id, avatar_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $data = $stmt->fetch();
    sendResponse(['success' => true, 'data' => $data]);

} elseif ($action === 'add_workout') {
    $date = $input['date'] ?? date('Y-m-d H:i:s');
    $type = $input['type'] ?? 'General';
    $minutes = (int) ($input['minutes'] ?? 0);
    $calories = (int) ($input['calories'] ?? 0);

    $stmt = $pdo->prepare("INSERT INTO workouts (user_id, date, type, minutes, calories) VALUES (?, ?, ?, ?, ?)");
    if ($stmt->execute([$userId, $date, $type, $minutes, $calories])) {
        // TODO: 在此檢查成就邏輯
        sendResponse(['success' => true]);
    } else {
        sendResponse(['success' => false, 'message' => '寫入失敗']);
    }

<<<<<<< HEAD
=======
    // Pie：區間內各類型 calories
    if ($range === '1d') {
        $where = "date >= (NOW() - INTERVAL '24 hours')";
    } else {
        $days = ($range === '1wk') ? 7 : (($range === '1m') ? 30 : 90);
        $where = "date >= (CURRENT_DATE - INTERVAL '{$days} days')";
    }

    $sqlPie = "
        SELECT type, COALESCE(SUM(calories),0) AS total_calories
        FROM workouts
        WHERE user_id = :uid
          AND {$where}
        GROUP BY type
    ";
    $stmt = $pdo->prepare($sqlPie);
    $stmt->execute([':uid' => $userId]);
    $pieRows = $stmt->fetchAll();

    // 讓 pie 的 label 順序固定，對應前端圖示/色彩
    $fixedLabels = ['跑步', '重訓', '腳踏車', '游泳', '瑜珈', '其他'];
    $pieMap = [];
    foreach ($pieRows as $r) {
        $t = (string)$r['type'];
        $pieMap[$t] = (int)$r['total_calories'];
    }
    $pieData = [];
    foreach ($fixedLabels as $lab) {
        $pieData[] = $pieMap[$lab] ?? 0;
    }

    return [
        'bar' => ['labels' => $labels, 'data' => $bar],
        'line' => ['labels' => $labels, 'data' => $line],
        'pie' => ['labels' => $fixedLabels, 'data' => $pieData],
    ];
}

function requireLogin(): int {
    if (!isset($_SESSION['user_id'])) {
        sendResponse(['success' => false, 'message' => '尚未登入']);
    }
    return (int)$_SESSION['user_id'];
}

// 讀取輸入
$input  = getJsonInput();
$action = $input['action'] ?? ($_GET['action'] ?? null);

// --- 公開動作（不需登入） ---

if ($action === 'register') {
    global $pdo;

    $email = trim((string)($input['email'] ?? ''));
    $pass  = (string)($input['password'] ?? '');
    $name  = trim((string)($input['display_name'] ?? 'User'));

    if ($email === '' || $pass === '') {
        sendResponse(['success' => false, 'message' => '請輸入 Email 和密碼']);
    }

    // 檢查 email 是否已存在
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    if ($stmt->fetch()) {
        sendResponse(['success' => false, 'message' => '此 Email 已被註冊']);
    }

    $hash = password_hash($pass, PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO users (email, password_hash, display_name)
            VALUES (:email, :hash, :name)
            RETURNING id
        ");
        $stmt->execute([':email' => $email, ':hash' => $hash, ':name' => $name]);
        $userId = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        sendResponse(['success' => false, 'message' => '註冊失敗', 'error' => $e->getMessage()]);
    }

    // 自動登入
    $_SESSION['user_id'] = $userId;

    // 回傳使用者資訊（main.js 需要 id 來存 avatar localStorage key）
    sendResponse([
        'success' => true,
        'data' => [
            'id' => $userId,
            'display_name' => $name,
            'email' => $email,
            'height' => null,
            'weight' => null,
        ]
    ]);

>>>>>>> 7d28427ba262fed486bc3f5c299c9f97af78287c
} elseif ($action === 'get_stats') {
    // 取得時間範圍參數，預設 1d
    $range = $_GET['range'] ?? '1d';
    
    $daily = [];
    $types = [];
    
    if ($range === '1d') {
        // 1天：按天分組（所有紀錄）
        $sql = "
            SELECT DATE(date) as date, SUM(minutes) AS total
            FROM workouts
            WHERE user_id = :uid
            GROUP BY DATE(date)
            ORDER BY DATE(date) ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        $daily = $stmt->fetchAll();
        
    } elseif ($range === '1wk') {
        // 1周：按週分組（所有紀錄）
        $sql = "
            SELECT 
                DATE_TRUNC('week', date) as week_start,
                SUM(minutes) AS total
            FROM workouts
            WHERE user_id = :uid
            GROUP BY DATE_TRUNC('week', date)
            ORDER BY week_start ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        $weeklyData = $stmt->fetchAll();
        
        // 格式化週標籤
        foreach ($weeklyData as $row) {
            $daily[] = [
                'date' => date('Y-m-d', strtotime($row['week_start'])),
                'total' => (int)$row['total']
            ];
        }
        
    } elseif ($range === '1m') {
        // 1月：按月分組（所有紀錄）
        $sql = "
            SELECT 
                DATE_TRUNC('month', date) as month_start,
                SUM(minutes) AS total
            FROM workouts
            WHERE user_id = :uid
            GROUP BY DATE_TRUNC('month', date)
            ORDER BY month_start ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        $monthlyData = $stmt->fetchAll();
        
        // 格式化月標籤
        foreach ($monthlyData as $row) {
            $daily[] = [
                'date' => date('Y-m', strtotime($row['month_start'])),
                'total' => (int)$row['total']
            ];
        }
        
    } elseif ($range === '3m') {
        // 3月：按季分組（所有紀錄）
        $sql = "
            SELECT 
                DATE_TRUNC('quarter', date) as quarter_start,
                SUM(minutes) AS total
            FROM workouts
            WHERE user_id = :uid
            GROUP BY DATE_TRUNC('quarter', date)
            ORDER BY quarter_start ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        $quarterlyData = $stmt->fetchAll();
        
        // 格式化季標籤
        foreach ($quarterlyData as $row) {
            $date = new DateTime($row['quarter_start']);
            $quarter = ceil(($date->format('n')) / 3);
            $daily[] = [
                'date' => $date->format('Y') . 'Q' . $quarter,
                'total' => (int)$row['total']
            ];
        }
    }

    // 2. 運動類型分佈（所有紀錄）
    $sql = "
        SELECT type, SUM(minutes) AS total
        FROM workouts
        WHERE user_id = :uid
        GROUP BY type
        ORDER BY total DESC
    ";
<<<<<<< HEAD
=======

} elseif ($action === 'get_leaderboard') {
    global $pdo, $DB_DRIVER;

    $rows = buildLeaderboard($pdo, $DB_DRIVER);
    sendResponse(['success' => true, 'data' => $rows]);

} elseif ($action === 'get_dashboard_data') {
    // ✅ main.js 會用這個 action 來更新：
    // - Bar：分鐘數
    // - Line：卡路里
    // - Pie：各運動種類卡路里分佈
    global $pdo, $DB_DRIVER;

    $range = normalizeRange((string)($_GET['range'] ?? '1d'));
    $data = buildDashboardData($pdo, $userId, $DB_DRIVER, $range);

    sendResponse([
        'success' => true,
        'data' => $data,
    ]);

} elseif ($action === 'get_stats') {
    // 保留舊 action（如果你哪裡還在用），但修正 MySQL/PGSQL 相容
    global $pdo;

    $sql = "
        SELECT
            date::date AS date,
            SUM(minutes) AS total
        FROM workouts
        WHERE user_id = :uid
          AND date >= CURRENT_DATE - INTERVAL '7 days'
        GROUP BY date::date
        ORDER BY date::date ASC
    ";

>>>>>>> 7d28427ba262fed486bc3f5c299c9f97af78287c
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $userId]);
    $types = $stmt->fetchAll();

    sendResponse(['success' => true, 'daily' => $daily, 'types' => $types, 'range' => $range]);

} elseif ($action === 'generate_bind_code') {
    $code = str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);

    // 10 分鐘內有效（Postgres 語法）
    $sql = "
        UPDATE users
        SET line_bind_code = :code,
            line_bind_code_expires_at = NOW() + INTERVAL '10 minutes'
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':code' => $code,
        ':id'   => $userId,
    ]);

    sendResponse(['success' => true, 'code' => $code]);

} elseif ($action === 'line_unbind') {
    $sql = "UPDATE users SET line_user_id = NULL WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    sendResponse(['success' => true]);

} elseif ($action === 'ai_coach') {
    $msg = $input['message'] ?? '';
    
    // 取得歷史紀錄
    $stmt = $pdo->prepare("SELECT date, type, minutes, calories FROM workouts WHERE user_id = ? ORDER BY date DESC LIMIT 10");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    $historyText = "最近運動紀錄：\n";
    foreach ($rows as $r) {
        $historyText .= "{$r['date']} - {$r['type']} ({$r['minutes']}分鐘, {$r['calories']}kcal)\n";
    }

    $reply = askCoach($historyText, $msg);
    sendResponse(['success' => true, 'reply' => $reply]);

} elseif ($action === 'update_avatar') {
    // 更新用戶頭像
    $avatar_id = $input['avatar_id'] ?? 1;
    
    // 驗證 avatar_id 範圍 (1-11)
    if ($avatar_id < 1 || $avatar_id > 11) {
        sendResponse(['success' => false, 'message' => 'invalid_avatar_id']);
    }
    
    // 更新資料庫
    $sql = "UPDATE users SET avatar_id = ? WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([$avatar_id, $userId]);
    
    if ($result) {
        sendResponse(['success' => true, 'avatar_id' => $avatar_id]);
    } else {
        sendResponse(['success' => false, 'message' => 'update_failed']);
    }

} else {
    sendResponse(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
}