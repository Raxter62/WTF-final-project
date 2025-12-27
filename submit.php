<?php
/**
 * submit.php
 * ✅ API：新增紀錄 / 圖表資料 / 排行榜（Neon / Railway / 本機都可）
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function fc_json_input(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function fc_response(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function fc_rand_code4(): string {
    return str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
}

function fc_range_to_days(string $range): int {
    $range = strtolower(trim($range));
    return match ($range) {
        '1d', 'day', '1day' => 1,
        '1wk', '1w', 'week', '1week' => 7,
        '1m', 'month', '1month' => 30,
        '3m', '3month', 'quarter' => 90,
        default => 14,
    };
}

/**
 * 取得 / 建立 user，提供身高體重時會更新（可為 null）
 */
function fc_get_or_create_user(PDO $pdo, string $driver, string $name, ?float $height, ?float $weight): array {
    $name = trim($name);
    if ($name === '') throw new InvalidArgumentException('name is required');

    $stmt = $pdo->prepare("SELECT id, name, height_cm, weight_kg, line_bind_code, line_user_id FROM users WHERE name = :name");
    $stmt->execute([':name' => $name]);
    $row = $stmt->fetch();

    if ($row) {
        $needUpdate = false;
        $params = [':id' => $row['id']];
        $sets = [];
        if ($height !== null) { $sets[] = "height_cm = :h"; $params[':h'] = $height; $needUpdate = true; }
        if ($weight !== null) { $sets[] = "weight_kg = :w"; $params[':w'] = $weight; $needUpdate = true; }
        if ($needUpdate) {
            $pdo->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
            $stmt->execute([':name' => $name]);
            $row = $stmt->fetch();
        }
        return $row;
    }

    $code = fc_rand_code4();

    if ($driver === 'pgsql') {
        $stmt = $pdo->prepare("
            INSERT INTO users (name, height_cm, weight_kg, line_bind_code)
            VALUES (:name, :h, :w, :code)
            RETURNING id, name, height_cm, weight_kg, line_bind_code, line_user_id
        ");
        $stmt->execute([':name' => $name, ':h' => $height, ':w' => $weight, ':code' => $code]);
        $row = $stmt->fetch();
        $pdo->prepare("INSERT INTO user_totals (user_id, total_calories) VALUES (:uid, 0) ON CONFLICT (user_id) DO NOTHING")
            ->execute([':uid' => $row['id']]);
        return $row;
    }

    // mysql
    $pdo->prepare("
        INSERT INTO users (name, height_cm, weight_kg, line_bind_code)
        VALUES (:name, :h, :w, :code)
    ")->execute([':name' => $name, ':h' => $height, ':w' => $weight, ':code' => $code]);

    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT IGNORE INTO user_totals (user_id, total_calories) VALUES (:uid, 0)")
        ->execute([':uid' => $id]);

    $stmt2 = $pdo->prepare("SELECT id, name, height_cm, weight_kg, line_bind_code, line_user_id FROM users WHERE id = :id");
    $stmt2->execute([':id' => $id]);
    return $stmt2->fetch() ?: [];
}

/**
 * 新增運動紀錄
 */
function fc_add_workout(PDO $pdo, string $driver, array $input): array {
    $name = (string)($input['name'] ?? '');
    $sport = (string)($input['sport_type'] ?? $input['type'] ?? '其他');
    $duration = (int)($input['duration_min'] ?? $input['minutes'] ?? 0);
    $calories = (int)($input['calories'] ?? 0);
    $time = $input['input_time'] ?? $input['datetime'] ?? $input['date'] ?? null;

    $height = isset($input['height_cm']) ? (float)$input['height_cm'] : (isset($input['height']) ? (float)$input['height'] : null);
    $weight = isset($input['weight_kg']) ? (float)$input['weight_kg'] : (isset($input['weight']) ? (float)$input['weight'] : null);

    if ($duration < 0) $duration = 0;
    if ($calories < 0) $calories = 0;

    $user = fc_get_or_create_user($pdo, $driver, $name, $height, $weight);
    if (!$user || !isset($user['id'])) throw new RuntimeException('cannot create user');
    $uid = (int)$user['id'];

    if ($driver === 'pgsql') {
        if ($time) {
            $pdo->prepare("
                INSERT INTO workouts (user_id, sport_type, input_time, duration_min, calories)
                VALUES (:uid, :sport, :t, :dur, :cal)
            ")->execute([':uid' => $uid, ':sport' => $sport, ':t' => $time, ':dur' => $duration, ':cal' => $calories]);
        } else {
            $pdo->prepare("
                INSERT INTO workouts (user_id, sport_type, duration_min, calories)
                VALUES (:uid, :sport, :dur, :cal)
            ")->execute([':uid' => $uid, ':sport' => $sport, ':dur' => $duration, ':cal' => $calories]);
        }

        $pdo->prepare("
            INSERT INTO user_totals (user_id, total_calories)
            VALUES (:uid, :cal)
            ON CONFLICT (user_id) DO UPDATE
                SET total_calories = user_totals.total_calories + EXCLUDED.total_calories,
                    updated_at = NOW()
        ")->execute([':uid' => $uid, ':cal' => $calories]);

    } else {
        if ($time) {
            $pdo->prepare("
                INSERT INTO workouts (user_id, sport_type, input_time, duration_min, calories)
                VALUES (:uid, :sport, :t, :dur, :cal)
            ")->execute([':uid' => $uid, ':sport' => $sport, ':t' => $time, ':dur' => $duration, ':cal' => $calories]);
        } else {
            $pdo->prepare("
                INSERT INTO workouts (user_id, sport_type, duration_min, calories)
                VALUES (:uid, :sport, :dur, :cal)
            ")->execute([':uid' => $uid, ':sport' => $sport, ':dur' => $duration, ':cal' => $calories]);
        }

        $pdo->prepare("
            INSERT INTO user_totals (user_id, total_calories)
            VALUES (:uid, :cal)
            ON DUPLICATE KEY UPDATE
                total_calories = total_calories + VALUES(total_calories),
                updated_at = CURRENT_TIMESTAMP
        ")->execute([':uid' => $uid, ':cal' => $calories]);
    }

    $stmt = $pdo->prepare("SELECT total_calories FROM user_totals WHERE user_id = :uid");
    $stmt->execute([':uid' => $uid]);
    $total = (int)($stmt->fetchColumn() ?: 0);

    return [
        'user' => $user,
        'total_calories' => $total,
    ];
}

function fc_get_daily_metric(PDO $pdo, string $driver, ?string $name, int $days, string $metric): array {
    $days = max(1, min($days, 180));
    $metric = ($metric === 'minutes') ? 'minutes' : 'calories';

    $params = [':days' => $days];

    if ($driver === 'pgsql') {
        $sumExpr = $metric === 'minutes' ? 'SUM(w.duration_min)::bigint' : 'SUM(w.calories)::bigint';
        if ($name) {
            $sql = "
                SELECT DATE_TRUNC('day', w.input_time) AS day, {$sumExpr} AS v
                FROM workouts w
                JOIN users u ON u.id = w.user_id
                WHERE u.name = :name AND w.input_time >= NOW() - (:days || ' days')::interval
                GROUP BY 1
                ORDER BY 1 ASC
            ";
            $params[':name'] = $name;
        } else {
            $sql = "
                SELECT DATE_TRUNC('day', input_time) AS day, {$sumExpr} AS v
                FROM workouts
                WHERE input_time >= NOW() - (:days || ' days')::interval
                GROUP BY 1
                ORDER BY 1 ASC
            ";
        }
    } else {
        $sumExpr = $metric === 'minutes' ? 'SUM(w.duration_min)' : 'SUM(w.calories)';
        if ($name) {
            $sql = "
                SELECT DATE(w.input_time) AS day, {$sumExpr} AS v
                FROM workouts w
                JOIN users u ON u.id = w.user_id
                WHERE u.name = :name AND w.input_time >= (NOW() - INTERVAL :days DAY)
                GROUP BY 1
                ORDER BY 1 ASC
            ";
            $params[':name'] = $name;
        } else {
            $sql = "
                SELECT DATE(input_time) AS day, {$sumExpr} AS v
                FROM workouts
                WHERE input_time >= (NOW() - INTERVAL :days DAY)
                GROUP BY 1
                ORDER BY 1 ASC
            ";
        }
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $labels = [];
    $values = [];
    foreach ($rows as $r) {
        $day = is_string($r['day']) ? $r['day'] : (string)$r['day'];
        $labels[] = substr($day, 0, 10);
        $values[] = (int)$r['v'];
    }

    return ['labels' => $labels, 'values' => $values];
}

function fc_get_type_breakdown(PDO $pdo, string $driver, ?string $name, int $days): array {
    $days = max(1, min($days, 180));
    $params = [':days' => $days];

    if ($driver === 'pgsql') {
        if ($name) {
            $sql = "
                SELECT w.sport_type AS t, SUM(w.calories)::bigint AS v
                FROM workouts w
                JOIN users u ON u.id = w.user_id
                WHERE u.name = :name AND w.input_time >= NOW() - (:days || ' days')::interval
                GROUP BY 1
                ORDER BY v DESC
            ";
            $params[':name'] = $name;
        } else {
            $sql = "
                SELECT sport_type AS t, SUM(calories)::bigint AS v
                FROM workouts
                WHERE input_time >= NOW() - (:days || ' days')::interval
                GROUP BY 1
                ORDER BY v DESC
            ";
        }
    } else {
        if ($name) {
            $sql = "
                SELECT w.sport_type AS t, SUM(w.calories) AS v
                FROM workouts w
                JOIN users u ON u.id = w.user_id
                WHERE u.name = :name AND w.input_time >= (NOW() - INTERVAL :days DAY)
                GROUP BY 1
                ORDER BY v DESC
            ";
            $params[':name'] = $name;
        } else {
            $sql = "
                SELECT sport_type AS t, SUM(calories) AS v
                FROM workouts
                WHERE input_time >= (NOW() - INTERVAL :days DAY)
                GROUP BY 1
                ORDER BY v DESC
            ";
        }
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $labels = [];
    $values = [];
    foreach ($rows as $r) {
        $labels[] = (string)$r['t'];
        $values[] = (int)$r['v'];
    }

    $top = $labels[0] ?? '總覽';
    return ['labels' => $labels, 'values' => $values, 'top' => $top];
}

function fc_get_leaderboard(PDO $pdo, int $limit): array {
    $limit = max(1, min($limit, 100));
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, COALESCE(t.total_calories, 0) AS total_calories
        FROM users u
        LEFT JOIN user_totals t ON t.user_id = u.id
        ORDER BY COALESCE(t.total_calories, 0) DESC, u.id ASC
        LIMIT :lim
    ");
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

try {
    $input = ($_SERVER['REQUEST_METHOD'] === 'POST') ? fc_json_input() : [];
    $action = $_GET['action'] ?? ($input['action'] ?? '');

    if ($action === 'init') {
        fc_response(['success' => true, 'message' => 'tables ready']);
    }

    if ($action === 'generate_bind_code') {
        $name = (string)($_GET['name'] ?? $input['name'] ?? '');
        $user = fc_get_or_create_user($pdo, $DB_DRIVER, $name, null, null);
        $code = fc_rand_code4();
        $pdo->prepare("UPDATE users SET line_bind_code = :c WHERE id = :id")->execute([':c' => $code, ':id' => $user['id']]);
        fc_response(['success' => true, 'name' => $name, 'line_bind_code' => $code]);
    }

    if ($action === 'add_workout' || $action === 'add_record' || $action === 'submit') {
        $result = fc_add_workout($pdo, $DB_DRIVER, $input ?: $_POST);
        fc_response(['success' => true] + $result);
    }

    if ($action === 'get_analysis') {
        $name = $_GET['name'] ?? $input['name'] ?? null;
        $range = (string)($_GET['range'] ?? $input['range'] ?? '');
        $days = isset($_GET['days']) ? (int)$_GET['days'] : (isset($input['days']) ? (int)$input['days'] : fc_range_to_days($range));
        $dailyCalories = fc_get_daily_metric($pdo, $DB_DRIVER, $name ? (string)$name : null, $days, 'calories');
        $dailyMinutes  = fc_get_daily_metric($pdo, $DB_DRIVER, $name ? (string)$name : null, $days, 'minutes');
        $typeBreakdown = fc_get_type_breakdown($pdo, $DB_DRIVER, $name ? (string)$name : null, $days);
        $leaderboard   = fc_get_leaderboard($pdo, (int)($_GET['limit'] ?? $input['limit'] ?? 10));

        fc_response([
            'success' => true,
            'range_days' => $days,
            'daily_calories' => $dailyCalories,
            'daily_minutes' => $dailyMinutes,
            'type_breakdown' => $typeBreakdown,
            'leaderboard' => $leaderboard
        ]);
    }

    if ($action === 'get_leaderboard' || $action === 'leaderboard') {
        $limit = (int)($_GET['limit'] ?? $input['limit'] ?? 10);
        fc_response(['success' => true, 'leaderboard' => fc_get_leaderboard($pdo, $limit)]);
    }

    if ($action === 'get_stats' || $action === 'stats' || $action === 'chart') {
        $name = $_GET['name'] ?? $input['name'] ?? null;
        $days = (int)($_GET['days'] ?? $input['days'] ?? 14);
        fc_response(['success' => true, 'daily' => fc_get_daily_metric($pdo, $DB_DRIVER, $name ? (string)$name : null, $days, 'calories')]);
    }

    // 預設：給前端一包可用的資料（避免沒帶 action 就空白）
    $name = $_GET['name'] ?? $input['name'] ?? null;
    $days = fc_range_to_days('1wk');
    fc_response([
        'success' => true,
        'range_days' => $days,
        'daily_calories' => fc_get_daily_metric($pdo, $DB_DRIVER, $name ? (string)$name : null, $days, 'calories'),
        'daily_minutes'  => fc_get_daily_metric($pdo, $DB_DRIVER, $name ? (string)$name : null, $days, 'minutes'),
        'type_breakdown' => fc_get_type_breakdown($pdo, $DB_DRIVER, $name ? (string)$name : null, $days),
        'leaderboard'    => fc_get_leaderboard($pdo, 10)
    ]);

} catch (Throwable $e) {
    fc_response(['success' => false, 'message' => 'API error', 'error' => $e->getMessage()], 500);
}
