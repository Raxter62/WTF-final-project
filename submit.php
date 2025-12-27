<?php
// submit.php - 主要 API 入口點
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/LLM/coach.php';

session_start();

header('Content-Type: application/json; charset=utf-8');

// 取得輸入資料的輔助函式（JSON body）
function getJsonInput() {
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// 回傳回應的輔助函式
function sendResponse($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// 讀取輸入
$input  = getJsonInput();
$action = $input['action'] ?? ($_GET['action'] ?? '');

if (!$pdo) {
    sendResponse(['success' => false, 'message' => 'DB 連線失敗']);
}

// --- 驗證相關動作 ---

if ($action === 'register') {
    $email = $input['email'] ?? '';
    $pass  = $input['password'] ?? '';
    $name  = $input['display_name'] ?? 'User';

    if (!$email || !$pass) {
        sendResponse(['success' => false, 'message' => '請輸入 Email 和密碼']);
    }

    // 檢查 email 是否已存在
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    if ($stmt->fetch()) {
        sendResponse(['success' => false, 'message' => '此 Email 已被註冊']);
    }

    // 建立使用者
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("
        INSERT INTO users (email, password_hash, display_name)
        VALUES (:email, :hash, :name)
    ");

    if ($stmt->execute([
        ':email' => $email,
        ':hash'  => $hash,
        ':name'  => $name,
    ])) {
        // 自動登入
        $userId = (int)$pdo->lastInsertId();
        $_SESSION['user_id'] = $userId;
        sendResponse(['success' => true]);
    } else {
        sendResponse(['success' => false, 'message' => '註冊失敗']);
    }

} elseif ($action === 'login') {
    $email = $input['email'] ?? '';
    $pass  = $input['password'] ?? '';

    $stmt = $pdo->prepare("SELECT id, password_hash, display_name FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if ($user && password_verify($pass, $user['password_hash'])) {
        $_SESSION['user_id'] = (int)$user['id'];
        sendResponse([
            'success'      => true,
            'display_name' => $user['display_name'],
        ]);
    } else {
        sendResponse(['success' => false, 'message' => '帳號或密碼錯誤']);
    }

} elseif ($action === 'logout') {
    session_destroy();
    sendResponse(['success' => true]);
}

// --- 之後的動作都需要登入 ---

if (!isset($_SESSION['user_id'])) {
    sendResponse(['success' => false, 'message' => '尚未登入']);
}

$userId = (int)$_SESSION['user_id'];

// --- 需登入後的動作 ---

if ($action === 'get_user_info') {

    $stmt = $pdo->prepare("
        SELECT display_name, email, line_user_id, height, weight
        FROM users
        WHERE id = :id
    ");
    $stmt->execute([':id' => $userId]);
    $data = $stmt->fetch();

    sendResponse(['success' => true, 'data' => $data]);

} elseif ($action === 'update_profile') {
    $name   = $input['display_name'] ?? 'User';
    $height = isset($input['height']) ? (float)$input['height'] : null;
    $weight = isset($input['weight']) ? (float)$input['weight'] : null;

    $stmt = $pdo->prepare("
        UPDATE users
        SET display_name = :name,
            height       = :height,
            weight       = :weight
        WHERE id = :id
    ");
    if ($stmt->execute([
        ':name'   => $name,
        ':height' => $height,
        ':weight' => $weight,
        ':id'     => $userId,
    ])) {
        sendResponse(['success' => true]);
    } else {
        sendResponse(['success' => false, 'message' => '更新失敗']);
    }

} elseif ($action === 'add_workout') {
    // 新增一筆運動紀錄
    $date     = $input['date'] ?? date('Y-m-d H:i:s');
    $type     = $input['type'] ?? 'General';
    $minutes  = (int)($input['minutes'] ?? 0);
    $calories = (int)($input['calories'] ?? 0);

    $stmt = $pdo->prepare("
        INSERT INTO workouts (user_id, date, type, minutes, calories)
        VALUES (:uid, :date, :type, :minutes, :calories)
    ");
    if ($stmt->execute([
        ':uid'      => $userId,
        ':date'     => $date,
        ':type'     => $type,
        ':minutes'  => $minutes,
        ':calories' => $calories,
    ])) {
        // 更新累積總消耗卡路里 (user_totals)
        try {
            $stmtTotals = $pdo->prepare("
                INSERT INTO user_totals (user_id, total_calories)
                VALUES (:uid, :calories)
                ON CONFLICT (user_id)
                DO UPDATE SET total_calories = user_totals.total_calories + EXCLUDED.total_calories
            ");
            $stmtTotals->execute([
                ':uid'      => $userId,
                ':calories' => $calories,
            ]);
        } catch (PDOException $e) {
            // 若 user_totals 更新失敗，不影響主流程，可視需要記錄 log
            // error_log('update user_totals failed: ' . $e->getMessage());
        }

        // TODO: 未來可以在這裡檢查成就、寄 email 通知等
        sendResponse(['success' => true]);
    } else {
        sendResponse(['success' => false, 'message' => '寫入失敗']);
    }

} elseif ($action === 'get_stats') {
    // 給圖表用的資料

    // 1. 最近 7 天的每日總分鐘數
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
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $userId]);
    $daily = $stmt->fetchAll();

    // 2. 各運動類型分佈（全部期間）
    $sql = "
        SELECT type, SUM(minutes) AS total
        FROM workouts
        WHERE user_id = :uid
        GROUP BY type
        ORDER BY total DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $userId]);
    $types = $stmt->fetchAll();

    sendResponse([
        'success' => true,
        'daily'   => $daily,
        'types'   => $types,
    ]);

} elseif ($action === 'get_leaderboard') {
    // 最近 30 天運動總分鐘數前 10 名
    $sql = "
        SELECT
            u.display_name,
            SUM(w.minutes) AS total
        FROM workouts w
        JOIN users u ON w.user_id = u.id
        WHERE w.date >= CURRENT_DATE - INTERVAL '30 days'
        GROUP BY u.id, u.display_name
        ORDER BY total DESC
        LIMIT 10
    ";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();

    // 加上排名
    foreach ($rows as $i => &$row) {
        $row['rank'] = $i + 1;
    }

    sendResponse(['success' => true, 'data' => $rows]);

} elseif ($action === 'generate_bind_code') {
    // 產生 6 位數綁定碼（你也可以改成 4 位）
    $code = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);

    // 10 分鐘內有效
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
    // 手動解除 Line 綁定
    $sql = "UPDATE users SET line_user_id = NULL WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $userId]);

    sendResponse(['success' => true]);

} elseif ($action === 'ai_coach') {
    // 給 LLM 的 AI 教練

    $msg = $input['message'] ?? '';

    // 最近 10 筆運動紀錄
    $stmt = $pdo->prepare("
        SELECT date, type, minutes, calories
        FROM workouts
        WHERE user_id = :uid
        ORDER BY date DESC
        LIMIT 10
    ");
    $stmt->execute([':uid' => $userId]);
    $rows = $stmt->fetchAll();

    $historyText = "最近運動紀錄：\n";
    foreach ($rows as $r) {
        $historyText .= sprintf(
            "%s - %s (%d 分鐘, %d kcal)\n",
            $r['date'],
            $r['type'],
            $r['minutes'],
            $r['calories']
        );
    }

    $reply = askCoach($historyText, $msg);
    sendResponse(['success' => true, 'reply' => $reply]);

} else {
    sendResponse([
        'success' => false,
        'message' => 'Unknown action: ' . htmlspecialchars($action ?? ''),
    ]);
}
