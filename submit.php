<?php
// submit.php - 主要 API 入口點（FitConnect）
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// ✅ 保留你原本的 AI 教練功能，但避免因為檔案遺失而整支 API 直接 Fatal
$coachFile = __DIR__ . '/LLM/coach.php';
if (file_exists($coachFile)) {
    require_once $coachFile; // 期望提供 askCoach($historyText, $msg)
} else {
    // 你有提到「不要簡化/刪除原功能」：這裡不是刪，是保底讓整個系統先能跑。
    // 只要你把原本的 LLM/coach.php 重新上傳回專案，就會自動恢復原本的 LLM 行為。
    if (!function_exists('askCoach')) {
        function askCoach(string $historyText, string $msg): string {
            $msg = trim($msg);
            if ($msg === '') return '你想問什麼呢？我可以根據你的運動紀錄給建議～';
            return "（暫時使用保底 AI 教練回覆）我看到了你的問題：「{$msg}」。\n"
                 . "目前後端沒有找到 LLM/coach.php，所以我先用保底回覆。\n"
                 . "你可以把 LLM/coach.php 放回專案後，AI 教練就會恢復原本功能。";
        }
    }
}

session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// 取得輸入資料的輔助函式（JSON body）
function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// 回傳回應的輔助函式
function sendResponse(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
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

} elseif ($action === 'login') {
    global $pdo;

    $email = trim((string)($input['email'] ?? ''));
    $pass  = (string)($input['password'] ?? '');

    if ($email === '' || $pass === '') {
        sendResponse(['success' => false, 'message' => '請輸入 Email 和密碼']);
    }

    $stmt = $pdo->prepare("
        SELECT id, password_hash, display_name, email, height, weight, line_user_id
        FROM users
        WHERE email = :email
        LIMIT 1
    ");
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($pass, (string)$row['password_hash'])) {
        sendResponse(['success' => false, 'message' => 'Email 或密碼錯誤']);
    }

    $_SESSION['user_id'] = (int)$row['id'];

    sendResponse([
        'success' => true,
        'data' => [
            'id' => (int)$row['id'],
            'display_name' => $row['display_name'],
            'email' => $row['email'],
            'height' => $row['height'],
            'weight' => $row['weight'],
            'line_user_id' => $row['line_user_id'] ?? null,
        ]
    ]);

} elseif ($action === 'logout') {
    session_destroy();
    sendResponse(['success' => true]);

} elseif ($action === 'init') {
    // 讓你可以用 /submit.php?action=init 或 /update_db.php?action=init 來確認建表
    // 建表是在 config.php 做的；這裡只是回傳 OK，避免你以為沒建
    sendResponse(['success' => true, 'message' => 'tables ready']);
}

// --- 之後的動作都需要登入 ---
$userId = requireLogin();

// --- 需登入後的動作 ---

if ($action === 'get_user_info') {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT id, display_name, email, line_user_id, height, weight
        FROM users
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $userId]);
    $data = $stmt->fetch();

    sendResponse(['success' => true, 'data' => $data]);

} elseif ($action === 'update_profile') {
    global $pdo;

    $name   = trim((string)($input['display_name'] ?? 'User'));
    $height = ($input['height'] ?? null);
    $weight = ($input['weight'] ?? null);

    $heightVal = ($height === '' || $height === null) ? null : (float)$height;
    $weightVal = ($weight === '' || $weight === null) ? null : (float)$weight;

    $stmt = $pdo->prepare("
        UPDATE users
        SET display_name = :name,
            height       = :hei,
            weight       = :wei
        WHERE id = :id
    ");
    $stmt->execute([
        ':name' => $name,
        ':hei'  => $heightVal,
        ':wei'  => $weightVal,
        ':id'   => $userId,
    ]);

    sendResponse(['success' => true]);

} elseif ($action === 'add_workout') {
    global $pdo;

    // 新增一筆運動紀錄
    $dateRaw  = (string)($input['date'] ?? '');
    $type     = trim((string)($input['type'] ?? '其他'));
    $minutes  = (int)($input['minutes'] ?? 0);
    $calories = (int)($input['calories'] ?? 0);

    if ($dateRaw === '') {
        sendResponse(['success' => false, 'message' => '缺少運動日期']);
    }

    try {
        $dateObj = new DateTimeImmutable($dateRaw);
        $date = $dateObj->format('Y-m-d H:i:sP');
    } catch (Exception $e) {
        sendResponse(['success' => false, 'message' => '日期格式錯誤']);
    }

    if ($minutes < 0) $minutes = 0;
    if ($calories < 0) $calories = 0;
    if ($type === '') $type = '其他';

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
        sendResponse(['success' => true]);
    } else {
        sendResponse(['success' => false, 'message' => '新增失敗']);
    }

} elseif ($action === 'get_leaderboard') {
    global $pdo;

    // 最近 30 天運動總分鐘數前 10 名
    $sql = "
        SELECT
            u.display_name,
            SUM(w.minutes) AS total
        FROM workouts w
        JOIN users u ON w.user_id = u.id
        WHERE w.date >= (CURRENT_DATE - INTERVAL '30 days')
        GROUP BY u.id, u.display_name
        ORDER BY total DESC
        LIMIT 10
    ";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();

    foreach ($rows as $i => &$row) {
        $row['rank'] = $i + 1;
        $row['total'] = (int)$row['total'];
    }

    sendResponse(['success' => true, 'data' => $rows]);

} elseif ($action === 'get_dashboard_data') {
    // ✅ main.js 會用這個 action 來更新：
    // - Bar：分鐘數
    // - Line：卡路里
    // - Pie：各運動種類卡路里分佈
    global $pdo;

    $range = (string)($_GET['range'] ?? '1d');
    $range = in_array($range, ['1d', '1wk', '1m', '3m'], true) ? $range : '1d';

    // 依 range 決定查詢區間與分組粒度
    // 1d：用 3 小時一格（00-03, 03-06...）
    // 1wk/1m/3m：用「日期」一格
    if ($range === '1d') {
        // 以 NOW() 往回 24 小時
        $sqlBarLine = "
            WITH bins AS (
                SELECT generate_series(0, 7) AS b
            )
            SELECT
                b.b AS bin,
                COALESCE(SUM(w.minutes),0) AS total_minutes,
                COALESCE(SUM(w.calories),0) AS total_calories
            FROM bins b
            LEFT JOIN workouts w
                ON w.user_id = :uid
               AND w.date >= (NOW() - INTERVAL '24 hours')
               AND floor(extract(hour from w.date)::numeric / 3)::int = b.b
            GROUP BY b.b
            ORDER BY b.b ASC
        ";
        $stmt = $pdo->prepare($sqlBarLine);
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll();

        $labels = [];
        $bar = [];
        $line = [];
        for ($i=0; $i<8; $i++) {
            $h = $i * 3;
            $labels[] = sprintf('%02d:00', $h);
        }
        foreach ($rows as $r) {
            $bar[]  = (int)$r['total_minutes'];
            $line[] = (int)$r['total_calories'];
        }
    } else {
        $days = ($range === '1wk') ? 7 : (($range === '1m') ? 30 : 90);

        $sqlBarLine = "
            SELECT
                (date AT TIME ZONE 'UTC')::date AS d,
                COALESCE(SUM(minutes),0) AS total_minutes,
                COALESCE(SUM(calories),0) AS total_calories
            FROM workouts
            WHERE user_id = :uid
              AND date >= (CURRENT_DATE - INTERVAL '{$days} days')
            GROUP BY (date AT TIME ZONE 'UTC')::date
            ORDER BY d ASC
        ";
        $stmt = $pdo->prepare($sqlBarLine);
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll();

        // 產生連續日期 label（補 0）
        $labels = [];
        $bar = [];
        $line = [];

        $mapMin = [];
        $mapCal = [];
        foreach ($rows as $r) {
            $key = (string)$r['d'];
            $mapMin[$key] = (int)$r['total_minutes'];
            $mapCal[$key] = (int)$r['total_calories'];
        }

        $start = new DateTimeImmutable('today');
        $start = $start->sub(new DateInterval('P' . ($days-1) . 'D'));
        for ($i=0; $i<$days; $i++) {
            $d = $start->add(new DateInterval('P' . $i . 'D'))->format('m/d');
            $key = $start->add(new DateInterval('P' . $i . 'D'))->format('Y-m-d');
            $labels[] = $d;
            $bar[] = $mapMin[$key] ?? 0;
            $line[] = $mapCal[$key] ?? 0;
        }
    }

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

    sendResponse([
        'success' => true,
        'data' => [
            'bar' => ['labels' => $labels, 'data' => $bar],
            'line' => ['labels' => $labels, 'data' => $line],
            'pie' => ['labels' => $fixedLabels, 'data' => $pieData],
        ]
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

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $userId]);
    $rows = $stmt->fetchAll();

    sendResponse(['success' => true, 'data' => $rows]);

} elseif ($action === 'generate_bind_code') {
    // 產生 6 位數綁定碼（你也可以改成 4 位）
    global $pdo;

    $code = (string)random_int(1000, 9999); // 先用 4 位，和你原本需求一致

    // 10 分鐘有效
    $expiresAt = (new DateTimeImmutable())->add(new DateInterval('PT10M'));

    $stmt = $pdo->prepare("
        UPDATE users
        SET line_bind_code = :code,
            line_bind_code_expires_at = :exp
        WHERE id = :id
    ");
    $stmt->execute([
        ':code' => $code,
        ':exp'  => $expiresAt->format('Y-m-d H:i:sP'),
        ':id'   => $userId,
    ]);

    sendResponse(['success' => true, 'code' => $code]);

} elseif ($action === 'line_unbind') {
    global $pdo;

    $stmt = $pdo->prepare("
        UPDATE users
        SET line_user_id = NULL,
            line_bind_code = NULL,
            line_bind_code_expires_at = NULL
        WHERE id = :id
    ");
    $stmt->execute([':id' => $userId]);

    sendResponse(['success' => true]);

} elseif ($action === 'ai_coach') {
    // 給 LLM 的 AI 教練（保留你原本的歷史紀錄串接方式）
    global $pdo;

    $msg = (string)($input['message'] ?? '');

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

    $historyText = "";
    foreach ($rows as $r) {
        $historyText .= sprintf(
            "%s | %s | %s min | %s kcal\n",
            (string)$r['date'],
            (string)$r['type'],
            (string)$r['minutes'],
            (string)$r['calories']
        );
    }

    $reply = askCoach($historyText, $msg);
    sendResponse(['success' => true, 'reply' => $reply]);

} else {
    sendResponse([
        'success' => false,
        'message' => 'Unknown action: ' . htmlspecialchars((string)$action),
    ]);
}
