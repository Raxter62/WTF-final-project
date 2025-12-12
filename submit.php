<?php
// submit.php - 主要 API 入口點
require_once 'config.php';
require_once __DIR__ . '/LLM/coach.php';
session_start();

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

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = getJsonInput();

// Ensure DB connection
if (!$pdo) {
    sendResponse(['success' => false, 'message' => 'DB 連線失敗']);
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
}

// --- 需登入後的動作 ---

// 檢查後續所有動作是否已登入
if (!isset($_SESSION['user_id'])) {
    sendResponse(['success' => false, 'message' => 'not_logged_in']);
}
$userId = $_SESSION['user_id'];

if ($action === 'get_user_info') {
    $stmt = $pdo->prepare("SELECT display_name, email, line_user_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $data = $stmt->fetch();
    sendResponse(['success' => true, 'data' => $data]);

} elseif ($action === 'add_workout') {
    $date = $input['date'] ?? date('Y-m-d');
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

} elseif ($action === 'get_stats') {
    // 1. 最近 7 天的每日分鐘數
    $stmt = $pdo->prepare("SELECT date, SUM(minutes) as total FROM workouts WHERE user_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY date ORDER BY date ASC");
    $stmt->execute([$userId]);
    $daily = $stmt->fetchAll();

    // 2. 運動類型分佈
    $stmt = $pdo->prepare("SELECT type, SUM(minutes) as total FROM workouts WHERE user_id = ? GROUP BY type");
    $stmt->execute([$userId]);
    $types = $stmt->fetchAll();

    sendResponse(['success' => true, 'daily' => $daily, 'types' => $types]);

} elseif ($action === 'get_leaderboard') {
    // 取出最近 30 天運動總分鐘數的前 10 名
    $sql = "SELECT u.display_name, SUM(w.minutes) as total 
            FROM workouts w 
            JOIN users u ON w.user_id = u.id 
            WHERE w.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) 
            GROUP BY u.id 
            ORDER BY total DESC 
            LIMIT 10";
    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll();
    
    // 加上排名
    foreach ($data as $i => &$row) {
        $row['rank'] = $i + 1;
    }

    sendResponse(['success' => true, 'data' => $data]);

} elseif ($action === 'generate_bind_code') {
    $code = str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);
    // 10 分鐘內有效
    $sql = "UPDATE users SET line_bind_code = ?, line_bind_code_expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$code, $userId]);

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

} else {
    sendResponse(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
}
