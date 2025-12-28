<?php
// submit.php - 主要 API 入口點
require_once __DIR__ . '/config.php';

/**
 * AI Coach 模組（保留原功能）
 * - 若 LLM/coach.php 存在，照原本使用 askCoach()
 * - 若不存在，提供 fallback，避免整個 API 因 require fatal 而回傳 HTML（前端就會顯示「API 不是 JSON」）
 */
$coachPath = __DIR__ . '/LLM/coach.php';
if (file_exists($coachPath)) {
    require_once $coachPath;
} else {
    if (!function_exists('askCoach')) {
        function askCoach(string $historyText, string $msg): string {
            return "AI 教練模組尚未部署（缺少 LLM/coach.php）。請先把 LLM 資料夾部署到 Railway，或暫時關閉 AI 教練功能。";
        }
    }
}

session_start();
header('Content-Type: application/json; charset=utf-8');

/**
 * JSON 錯誤保護：避免 PHP Warning/Fatal 直接輸出 HTML，導致前端 JSON.parse 失敗。
 * 不改變原本功能，只是讓錯誤時也能維持 JSON 乾淨。
 */
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_exception_handler(function ($e) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    echo json_encode([
        'success' => false,
        'message' => 'Server exception',
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

set_error_handler(function ($severity, $message, $file, $line) {
    // 目的：不要讓 warning/notice 直接輸出到 HTTP body（會污染 JSON）
    // 不改變原本流程：只記錄到 log，並抑制輸出。
    if (!(error_reporting() & $severity)) return false;

    $nonFatal = [
        E_WARNING, E_NOTICE, E_USER_WARNING, E_USER_NOTICE,
        E_DEPRECATED, E_USER_DEPRECATED, E_STRICT
    ];

    if (in_array($severity, $nonFatal, true)) {
        error_log("[PHP] {$message} in {$file}:{$line}");
        return true; // 已處理（抑制輸出）
    }

    // 其他較嚴重的錯誤交回 PHP 預設處理（通常會變成 fatal -> shutdown handler 會接住）
    return false;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode([
            'success' => false,
            'message' => 'Server fatal error',
            'error'   => $err['message'],
            'where'   => basename($err['file']) . ':' . $err['line'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
});

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
        SELECT id, display_name, email, line_user_id, height, weight
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

} elseif ($action === 'ai_coach') {
    $msg = $input['message'] ?? '';
    $historyText = $input['history'] ?? '';

    if (!$msg) {
        sendResponse(['success' => false, 'message' => 'empty_message']);
    }

    $reply = askCoach($historyText, $msg);
    sendResponse(['success' => true, 'reply' => $reply]);

} else {
    sendResponse(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
}
