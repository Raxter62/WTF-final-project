<?php
// submit.php - 主要 API 入口點
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/LLM/coach.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function sendResponse(array $data): void {
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
    $email = trim($input['email'] ?? '');
    $pass  = $input['password'] ?? '';
    $name  = trim($input['display_name'] ?? 'User');

    if ($email === '' || $pass === '') {
        sendResponse(['success' => false, 'message' => '請輸入 Email 和密碼']);
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    if ($stmt->fetch()) {
        sendResponse(['success' => false, 'message' => '此 Email 已被註冊']);
    }

    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        'INSERT INTO users (email, password_hash, display_name) VALUES (:email, :hash, :name) RETURNING id'
    );

    if ($stmt->execute([
        ':email' => $email,
        ':hash'  => $hash,
        ':name'  => $name,
    ])) {
        $row = $stmt->fetch();
        $userId = isset($row['id']) ? (int) $row['id'] : 0;
        if ($userId <= 0) {
            sendResponse(['success' => false, 'message' => '註冊成功但取得ID失敗']);
        }
        $_SESSION['user_id'] = $userId;
        sendResponse(['success' => true]);
    }

    sendResponse(['success' => false, 'message' => '註冊失敗']);
}

if ($action === 'login') {
    $email = trim($input['email'] ?? '');
    $pass  = $input['password'] ?? '';

    if ($email === '' || $pass === '') {
        sendResponse(['success' => false, 'message' => '請輸入 Email 和密碼']);
    }

    $stmt = $pdo->prepare('SELECT id, password_hash, display_name FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if ($user && password_verify($pass, $user['password_hash'])) {
        $_SESSION['user_id'] = (int) $user['id'];
        sendResponse([
            'success'      => true,
            'display_name' => $user['display_name'],
        ]);
    }

    sendResponse(['success' => false, 'message' => '帳號或密碼錯誤']);
}

if ($action === 'logout') {
    session_destroy();
    sendResponse(['success' => true]);
}

// --- 之後的動作都需要登入 ---
if (!isset($_SESSION['user_id'])) {
    sendResponse(['success' => false, 'message' => '尚未登入']);
}
$userId = (int) $_SESSION['user_id'];

// --- 需登入後的動作 ---
if ($action === 'get_user_info') {
    $stmt = $pdo->prepare('SELECT id, display_name, email, line_user_id, height, weight, COALESCE(avatar_id, 1) AS avatar_id FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $data = $stmt->fetch();

    if (!$data) {
        sendResponse(['success' => false, 'message' => '找不到使用者']);
    }

    sendResponse(['success' => true, 'data' => $data]);
}

if ($action === 'update_profile') {
    $name   = trim($input['display_name'] ?? 'User');
    $height = isset($input['height']) ? (float) $input['height'] : null;
    $weight = isset($input['weight']) ? (float) $input['weight'] : null;

    $stmt = $pdo->prepare(
        'UPDATE users SET display_name = :name, height = :height, weight = :weight WHERE id = :id'
    );

    if ($stmt->execute([
        ':name'   => $name,
        ':height' => $height,
        ':weight' => $weight,
        ':id'     => $userId,
    ])) {
        sendResponse(['success' => true]);
    }

    sendResponse(['success' => false, 'message' => '更新失敗']);
}

if ($action === 'add_workout') {
    $date     = $input['date'] ?? date('Y-m-d H:i:s');
    $type     = $input['type'] ?? 'General';
    $minutes  = (int) ($input['minutes'] ?? 0);
    $calories = (int) ($input['calories'] ?? 0);

    $stmt = $pdo->prepare(
        'INSERT INTO workouts (user_id, date, type, minutes, calories) VALUES (:uid, :date, :type, :minutes, :calories)'
    );
    if ($stmt->execute([
        ':uid'      => $userId,
        ':date'     => $date,
        ':type'     => $type,
        ':minutes'  => $minutes,
        ':calories' => $calories,
    ])) {
        try {
            $stmtTotals = $pdo->prepare(
                'INSERT INTO user_totals (user_id, total_calories) VALUES (:uid, :calories)
                 ON CONFLICT (user_id)
                 DO UPDATE SET total_calories = user_totals.total_calories + EXCLUDED.total_calories'
            );
            $stmtTotals->execute([
                ':uid'      => $userId,
                ':calories' => $calories,
            ]);
        } catch (PDOException $e) {
            // 不影響主流程
        }
        sendResponse(['success' => true]);
    }

    sendResponse(['success' => false, 'message' => '寫入失敗']);
}

if ($action === 'get_stats') {
    $range = $_GET['range'] ?? '1d';
    $daily = [];

    if ($range === '1d') {
        $sql = '
            SELECT DATE(date) as date, SUM(minutes) AS total
            FROM workouts
            WHERE user_id = :uid
            GROUP BY DATE(date)
            ORDER BY DATE(date) ASC
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        $daily = $stmt->fetchAll();
    } elseif ($range === '1wk') {
        $sql = '
            SELECT
                DATE_TRUNC(\'week\', date) as week_start,
                SUM(minutes) AS total
            FROM workouts
            WHERE user_id = :uid
            GROUP BY DATE_TRUNC(\'week\', date)
            ORDER BY week_start ASC
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        $weeklyData = $stmt->fetchAll();

        foreach ($weeklyData as $row) {
            $daily[] = [
                'date'  => date('Y-m-d', strtotime($row['week_start'])),
                'total' => (int) $row['total'],
            ];
        }
    } elseif ($range === '1m') {
        $sql = '
            SELECT
                DATE_TRUNC(\'month\', date) as month_start,
                SUM(minutes) AS total
            FROM workouts
            WHERE user_id = :uid
            GROUP BY DATE_TRUNC(\'month\', date)
            ORDER BY month_start ASC
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        $monthlyData = $stmt->fetchAll();

        foreach ($monthlyData as $row) {
            $daily[] = [
                'date'  => date('Y-m', strtotime($row['month_start'])),
                'total' => (int) $row['total'],
            ];
        }
    } elseif ($range === '3m') {
        $sql = '
            SELECT
                DATE_TRUNC(\'quarter\', date) as quarter_start,
                SUM(minutes) AS total
            FROM workouts
            WHERE user_id = :uid
            GROUP BY DATE_TRUNC(\'quarter\', date)
            ORDER BY quarter_start ASC
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        $quarterlyData = $stmt->fetchAll();

        foreach ($quarterlyData as $row) {
            $date = new DateTime($row['quarter_start']);
            $quarter = ceil(($date->format('n')) / 3);
            $daily[] = [
                'date'  => $date->format('Y') . 'Q' . $quarter,
                'total' => (int) $row['total'],
            ];
        }
    }

    $sql = '
        SELECT type, SUM(minutes) AS total
        FROM workouts
        WHERE user_id = :uid
        GROUP BY type
        ORDER BY total DESC
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $userId]);
    $types = $stmt->fetchAll();

    sendResponse(['success' => true, 'daily' => $daily, 'types' => $types, 'range' => $range]);
}

if ($action === 'get_leaderboard') {
    $sql = '
        SELECT u.display_name, SUM(w.minutes) AS total
        FROM workouts w
        JOIN users u ON w.user_id = u.id
        WHERE w.date >= CURRENT_DATE - INTERVAL \"30 days\"
        GROUP BY u.id, u.display_name
        ORDER BY total DESC
        LIMIT 10
    ';
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();

    foreach ($rows as $i => &$row) {
        $row['rank'] = $i + 1;
    }

    sendResponse(['success' => true, 'data' => $rows]);
}

if ($action === 'generate_bind_code') {
    $code = str_pad((string) mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);

    $sql = '
        UPDATE users
        SET line_bind_code = :code,
            line_bind_code_expires_at = NOW() + INTERVAL \"10 minutes\"
        WHERE id = :id
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':code' => $code,
        ':id'   => $userId,
    ]);

    sendResponse(['success' => true, 'code' => $code]);
}

if ($action === 'line_unbind') {
    $sql = 'UPDATE users SET line_user_id = NULL WHERE id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    sendResponse(['success' => true]);
}

if ($action === 'ai_coach') {
    $msg = $input['message'] ?? '';
    $reply = askCoachFromDb($userId, $msg);
    sendResponse(['success' => true, 'reply' => $reply]);
}

if ($action === 'update_avatar') {
    $avatar_id = $input['avatar_id'] ?? 1;
    if ($avatar_id < 1 || $avatar_id > 11) {
        sendResponse(['success' => false, 'message' => 'invalid_avatar_id']);
    }

    $sql = 'UPDATE users SET avatar_id = ? WHERE id = ?';
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([$avatar_id, $userId]);

    if ($result) {
        sendResponse(['success' => true, 'avatar_id' => $avatar_id]);
    }

    sendResponse(['success' => false, 'message' => 'update_failed']);
}

sendResponse(['success' => false, 'message' => 'Unknown action']);
