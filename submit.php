<?php
// submit.php - 主要 API 入口點
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/LLM/coach.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

function getJsonInput() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
function sendResponse($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

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

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    if ($stmt->fetch()) {
        sendResponse(['success' => false, 'message' => '此 Email 已被註冊']);
    }

    $hash = password_hash($pass, PASSWORD_DEFAULT);

    // PostgreSQL 安全取得 id：RETURNING
    $stmt = $pdo->prepare("
        INSERT INTO users (email, password_hash, display_name)
        VALUES (:email, :hash, :name)
        RETURNING id
    ");

    if ($stmt->execute([
        ':email' => $email,
        ':hash'  => $hash,
        ':name'  => $name,
    ])) {
        $row = $stmt->fetch();
        $userId = isset($row['id']) ? (int)$row['id'] : 0;
        if ($userId <= 0) {
            sendResponse(['success' => false, 'message' => '註冊成功但取得ID失敗']);
        }
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
            // 不影響主流程
        }
        sendResponse(['success' => true]);
    } else {
        sendResponse(['success' => false, 'message' => '寫入失敗']);
    }

} elseif ($action === 'get_stats') {
    $sql = "
        SELECT date::date AS date, SUM(minutes) AS total
        FROM workouts
        WHERE user_id = :uid
          AND date >= CURRENT_DATE - INTERVAL '7 days'
        GROUP BY date::date
        ORDER BY date::date ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $userId]);
    $daily = $stmt->fetchAll();

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
    $sql = "
        SELECT u.display_name, SUM(w.minutes) AS total
        FROM workouts w
        JOIN users u ON w.user_id = u.id
        WHERE w.date >= CURRENT_DATE - INTERVAL '30 days'
        GROUP BY u.id, u.display_name
        ORDER BY total DESC
        LIMIT 10
    ";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();

    foreach ($rows as $i => &$row) {
        $row['rank'] = $i + 1;
    }
    sendResponse(['success' => true, 'data' => $rows]);
}

// 其餘 action 你原本後段還有（line 綁定/解除等），請把你原 submit.php 後半段接回去即可。
// 若你要我「完整合併不缺任何 action」，把你 submit.php 後半段剩下內容再貼一次，我會整份補齊。
sendResponse(['success' => false, 'message' => 'Unknown action']);

// --- 需登入後的動作 ---

// 檢查後續所有動作是否已登入
if (!isset($_SESSION['user_id'])) {
    sendResponse(['success' => false, 'message' => 'not_logged_in']);
}
$userId = $_SESSION['user_id'];

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