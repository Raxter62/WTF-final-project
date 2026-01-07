<?php
// submit.php - 主要 API 入口點
session_start();
require_once 'config.php';
require_once __DIR__ . '/LLM/coach.php';

ob_start();

try {
    // Check and load config.php
    if (!file_exists(__DIR__ . '/config.php')) {
        throw new Exception('config.php not found in ' . __DIR__);
    }
    require_once __DIR__ . '/config.php';

    // Check and load coach.php (optional)
    $coachAvailable = false;
    if (file_exists(__DIR__ . '/LLM/coach.php')) {
        require_once __DIR__ . '/LLM/coach.php';
        $coachAvailable = true;
    }

    session_start();
    header('Content-Type: application/json; charset=utf-8');

    // Check database connection
    if (!isset($pdo) || !$pdo) {
        throw new Exception('Database connection failed - $pdo not available');
    }

    // Test database
    try {
        $pdo->query('SELECT 1');
        // Set Timezone for current session
        $pdo->exec("SET TIME ZONE 'Asia/Taipei'");
    } catch (PDOException $e) {
        throw new Exception('Database query test failed: ' . $e->getMessage());
    }

    // Helper functions
    function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    function sendResponse(array $data): void
    {
        ob_end_clean();
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = getJsonInput();
    $action = $input['action'] ?? ($_GET['action'] ?? '');

    // === Authentication Actions ===
    if ($action === 'register') {
        $email = trim($input['email'] ?? '');
        $pass = $input['password'] ?? '';
        $name = trim($input['display_name'] ?? 'User');

        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            sendResponse(['success' => false, 'message' => '這個email已註冊過了']);
        }

        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            'INSERT INTO users (email, password_hash, display_name) VALUES (:email, :hash, :name) RETURNING id'
        );

        if ($stmt->execute([':email' => $email, ':hash' => $hash, ':name' => $name])) {
            $newUser = $stmt->fetch(PDO::FETCH_ASSOC);
            $_SESSION['user_id'] = $newUser['id'];
            sendResponse(['success' => true, 'message' => 'Registration successful']);
        }

        sendResponse(['success' => false, 'message' => 'Registration failed']);
    }

    if ($action === 'login') {
        $email = trim($input['email'] ?? '');
        $pass = $input['password'] ?? '';

        if ($email === '' || $pass === '') {
            sendResponse(['success' => false, 'message' => 'Please enter email and password']);
        }

        $stmt = $pdo->prepare('SELECT id, password_hash, display_name FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($pass, $user['password_hash'])) {
            sendResponse(['success' => false, 'message' => 'Invalid email or password']);
        }

        $_SESSION['user_id'] = $user['id'];
        sendResponse(['success' => true, 'message' => 'Login successful']);
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

        if ($action === 'get_user_info') {
            if (!isset($_SESSION['user_id'])) {
                sendResponse(['success' => false, 'message' => 'Not logged in']);
            }

            $stmt = $pdo->prepare('SELECT id, email, display_name, height, weight, avatar_id FROM users WHERE id = :id');
            $stmt->execute([':id' => $_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                sendResponse(['success' => false, 'message' => 'User not found']);
            }

            sendResponse(['success' => true, 'data' => $user]);
        }

        // === Profile Actions ===
        if ($action === 'update_profile') {
            if (!isset($_SESSION['user_id'])) {
                sendResponse(['success' => false, 'message' => 'Not logged in']);
            }

            $name = trim($input['display_name'] ?? '');
            $height = intval($input['height'] ?? 0);
            $weight = intval($input['weight'] ?? 0);

            $stmt = $pdo->prepare(
                'UPDATE users SET display_name = :name, height = :height, weight = :weight WHERE id = :id'
            );
            $stmt->execute([
                ':name' => $name,
                ':height' => $height,
                ':weight' => $weight,
                ':id' => $_SESSION['user_id']
            ]);

            sendResponse(['success' => true, 'message' => 'Profile updated']);
        }

        if ($action === 'update_avatar') {
            if (!isset($_SESSION['user_id'])) {
                sendResponse(['success' => false, 'message' => 'Not logged in']);
            }

            $avatarId = intval($input['avatar_id'] ?? 1);

            $stmt = $pdo->prepare('UPDATE users SET avatar_id = :aid WHERE id = :id');
            $stmt->execute([':aid' => $avatarId, ':id' => $_SESSION['user_id']]);

            sendResponse(['success' => true, 'message' => 'Avatar updated']);
        }

        // === Workout Actions ===
        if ($action === 'add_workout') {
            if (!isset($_SESSION['user_id'])) {
                sendResponse(['success' => false, 'message' => 'Not logged in']);
            }

            $date = $input['date'] ?? '';
            $type = $input['type'] ?? '';
            $minutes = intval($input['minutes'] ?? 0);
            $calories = intval($input['calories'] ?? 0);

            if ($date === '' || $type === '' || $minutes <= 0) {
                sendResponse(['success' => false, 'message' => 'Invalid workout data']);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO workouts (user_id, date, type, minutes, calories) VALUES (:uid, :date, :type, :min, :cal)'
            );
            $stmt->execute([
                ':uid' => $_SESSION['user_id'],
                ':date' => $date,
                ':type' => $type,
                ':min' => $minutes,
                ':cal' => $calories
            ]);

            // Update User Totals (Postgres UPSERT)
            $stmt = $pdo->prepare(
                'INSERT INTO user_totals (user_id, total_calories) VALUES (:uid, :cal) 
                ON CONFLICT (user_id) DO UPDATE SET total_calories = user_totals.total_calories + :cal'
            );
            $stmt->execute([':uid' => $_SESSION['user_id'], ':cal' => $calories]);

            sendResponse(['success' => true, 'message' => 'Workout added']);
        }

        if ($action === 'get_stats') {
            if (!isset($_SESSION['user_id'])) {
                sendResponse(['success' => false, 'message' => 'Not logged in']);
            }

            $range = $_GET['range'] ?? '1d';

            // --- 1. 定義時間範圍與分組邏輯 ---
            $startDate = '';
            $groupBy = '';
            $dateFormat = ''; // 用於 PHP 後處理或 SQL 顯示

            if ($range === '1d') {
                // 最近 1 天 (00:00 - 24:00)，每 3 小時一組
                // SQL: floor(extract(hour from date) / 3) * 3
                $startDate = date('Y-m-d 00:00:00'); // 今天 0點
                // Postgres logic for checking today
            } elseif ($range === '1wk') {
                // 最近 1 週 (MON-SUN) or 7 days
                // User said "Recent MON~SUN". Standard ISO week logic.
                // Let's use date_trunc('week', current_date)
            } elseif ($range === '1m') {
                // 最近 1 月 (01-30)，每 6 天一組
                // User said "Recent (current month) 01~30".
                // Let's use date_trunc('month', current_date)
            } elseif ($range === '3m') {
                // 最近 3 個月，每 1 個月一組
            }

            // Postgres Queries
            $timeData = [];
            $calData = [];
            $typeData = [];

            try {
                if ($range === '1d') {
                    // 1天: 只要今天的資料，每3小時一格 (0, 3, 6, ..., 21)
                    $stmt = $pdo->prepare(
                        "SELECT floor(extract(hour from date) / 3) * 3 as label_start, 
                                SUM(minutes) as total_min, 
                                SUM(calories) as total_cal
                        FROM workouts 
                        WHERE user_id = :uid AND date >= CURRENT_DATE 
                        GROUP BY 1 ORDER BY 1 ASC"
                    );
                    $stmt->execute([':uid' => $_SESSION['user_id']]);
                    $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // 補齊 0-21 的空缺
                    // 補齊 0-21 的空缺，產生 0:00~3:00, 3:00~6:00 等標籤
                    for ($h = 0; $h < 24; $h += 3) {
                        $found = false;
                        $label = sprintf("%d:00~%d:00", $h, $h + 3);

                        foreach ($raw as $r) {
                            if (intval($r['label_start']) === $h) {
                                $timeData[] = ['label' => $label, 'total' => $r['total_min']];
                                $calData[] = ['label' => $label, 'total' => $r['total_cal']];
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            $timeData[] = ['label' => $label, 'total' => 0];
                            $calData[] = ['label' => $label, 'total' => 0];
                        }
                    }

                    // Type Chart (Today)
                    $stmt = $pdo->prepare(
                        "SELECT type, SUM(minutes) as total FROM workouts 
                        WHERE user_id = :uid AND date >= CURRENT_DATE 
                        GROUP BY type ORDER BY total DESC"
                    );
                    $stmt->execute([':uid' => $_SESSION['user_id']]);
                    $typeData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } elseif ($range === '1wk') {
                    // 1週: 本週一開始
                    $stmt = $pdo->prepare(
                        "SELECT to_char(date, 'Dy') as day_label, 
                                extract(isodow from date) as day_num,
                                SUM(minutes) as total_min, 
                                SUM(calories) as total_cal
                        FROM workouts 
                        WHERE user_id = :uid AND date >= date_trunc('week', CURRENT_DATE)
                        GROUP BY 1, 2 ORDER BY 2 ASC"
                    );
                    $stmt->execute([':uid' => $_SESSION['user_id']]);
                    $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Map 1-7 to data, simple fill or just return raw if chart.js handles it. 
                    // Let's standardise to Mon, Tue...
                    $weekDays = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
                    foreach ($weekDays as $num => $txt) {
                        $found = null;
                        foreach ($raw as $r) {
                            if (intval($r['day_num']) === $num) $found = $r;
                        }
                        $timeData[] = ['label' => $txt, 'total' => $found ? $found['total_min'] : 0];
                        $calData[] = ['label' => $txt, 'total' => $found ? $found['total_cal'] : 0];
                    }

                    // Type Chart (This Week)
                    $stmt = $pdo->prepare(
                        "SELECT type, SUM(minutes) as total FROM workouts 
                        WHERE user_id = :uid AND date >= date_trunc('week', CURRENT_DATE)
                        GROUP BY type ORDER BY total DESC"
                    );
                    $stmt->execute([':uid' => $_SESSION['user_id']]);
                    $typeData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }

                sendResponse(['success' => true, 'timeData' => $timeData, 'calData' => $calData, 'typeData' => $typeData]);
            } catch (PDOException $e) {
                sendResponse(['success' => false, 'message' => 'Stats error: ' . $e->getMessage()]);
            }
        }
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
} catch (PDOException $e) {
    sendResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
