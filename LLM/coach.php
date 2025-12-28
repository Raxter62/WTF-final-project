<?php
// LLM/coach.php
require_once __DIR__ . '/config.php';

/**
 * 你可以在 Railway Variables 設：
 * - OPENAI_API_KEY
 * - OPENAI_MODEL (optional, default gpt-4.1-mini)
 * - COACH_REFUSAL_TEXT (optional)
 * - USERS_TABLE / WORKOUTS_TABLE (optional, 不設也會自動猜)
 */

function coach_refusal_text(): string {
    return getenv('COACH_REFUSAL_TEXT') ?: '這個問題和運動無關，我沒辦法回答喔。';
}

function openai_model(): string {
    return getenv('OPENAI_MODEL') ?: 'gpt-4.1-mini';
}

/**
 * 本地判斷是否為教練相關（越嚴格越不會亂回）
 */
function is_coach_related(string $text): bool {
    $t = mb_strtolower($text, 'UTF-8');

    $keywords = [
        // 運動/訓練
        '運動','健身','訓練','跑步','慢跑','衝刺','重訓','重量訓練','深蹲','硬舉','臥推',
        '游泳','腳踏車','自行車','瑜珈','有氧','無氧','核心','拉伸','伸展','恢復',
        // 熱量/營養/身體數據
        '卡路里','熱量','消耗','tdee','bmr','代謝','減脂','增肌','體脂','bmi','體重','身高',
        '蛋白質','碳水','脂肪','飲食','補給','睡眠','休息','心率','配速','步數',
        // 你的專題語境
        'ai教練','教練','菜單','訓練計畫','運動建議','熱量比例'
    ];

    foreach ($keywords as $k) {
        if (mb_strpos($t, mb_strtolower($k, 'UTF-8')) !== false) return true;
    }
    return false;
}

/**
 * 建立 PDO（優先吃 DATABASE_URL；沒有就吃 PGHOST/PGPORT/...）
 * 你也可以把這段搬去 config.php 做成 get_pdo()，這裡先寫成獨立函式方便你直接用。
 */
function get_pdo(): PDO {
    $url = getenv('DATABASE_URL');
    if ($url) {
        $parts = parse_url($url);
        $host = $parts['host'] ?? 'localhost';
        $port = $parts['port'] ?? 5432;
        $user = $parts['user'] ?? '';
        $pass = $parts['pass'] ?? '';
        $db   = ltrim($parts['path'] ?? '', '/');
        $dsn = "pgsql:host={$host};port={$port};dbname={$db};sslmode=require";
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    // Railway Postgres 也會提供這些變數：PGHOST/PGPORT/PGUSER/PGPASSWORD/PGDATABASE :contentReference[oaicite:7]{index=7}
    $host = getenv('PGHOST') ?: 'localhost';
    $port = getenv('PGPORT') ?: '5432';
    $user = getenv('PGUSER') ?: '';
    $pass = getenv('PGPASSWORD') ?: '';
    $db   = getenv('PGDATABASE') ?: '';
    $dsn  = "pgsql:host={$host};port={$port};dbname={$db};sslmode=require";

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function table_exists(PDO $pdo, string $table): bool {
    $sql = "SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name = :t LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':t' => $table]);
    return (bool)$stmt->fetchColumn();
}

function pick_existing_table(PDO $pdo, array $candidates): ?string {
    foreach ($candidates as $t) {
        if (table_exists($pdo, $t)) return $t;
    }
    return null;
}

/**
 * 抓使用者資料 + 近期運動紀錄，組成給模型的 context
 * - 會先看 env 指定 USERS_TABLE/WORKOUTS_TABLE
 * - 沒指定則猜常見表名
 */
function build_user_context(PDO $pdo, int $userId): array {
    $usersTableEnv   = getenv('USERS_TABLE') ?: '';
    $workoutsTableEnv= getenv('WORKOUTS_TABLE') ?: '';

    $usersTable = $usersTableEnv && table_exists($pdo, $usersTableEnv)
        ? $usersTableEnv
        : pick_existing_table($pdo, ['users','user','member','members','accounts','account']);

    $workoutsTable = $workoutsTableEnv && table_exists($pdo, $workoutsTableEnv)
        ? $workoutsTableEnv
        : pick_existing_table($pdo, [
            'exercise_logs','exercise_log','exercise_records','exercise_record',
            'workouts','workout','records','record'
        ]);

    $profileText = "（找不到使用者資料表）";
    if ($usersTable) {
        // 盡量用常見欄位名：id/name/height/weight/goal
        $sql = "SELECT * FROM {$usersTable} WHERE id = :id LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $userId]);
        $u = $stmt->fetch();

        if ($u) {
            $name   = $u['name']   ?? $u['username'] ?? $u['display_name'] ?? "User{$userId}";
            $height = $u['height'] ?? $u['height_cm'] ?? null;
            $weight = $u['weight'] ?? $u['weight_kg'] ?? null;
            $goal   = $u['goal']   ?? $u['target'] ?? null;

            $profileText = "使用者：{$name}\n";
            if ($height !== null) $profileText .= "身高：{$height}\n";
            if ($weight !== null) $profileText .= "體重：{$weight}\n";
            if ($goal !== null)   $profileText .= "目標：{$goal}\n";
        } else {
            $profileText = "（users 表有，但找不到該使用者 id={$userId}）";
        }
    }

    $historyText = "（找不到運動紀錄表）";
    if ($workoutsTable) {
        // 嘗試抓近 14 天，最多 30 筆；欄位用常見名稱猜
        $sql = "SELECT * FROM {$workoutsTable} WHERE user_id = :id OR uid = :id OR id = :id
                ORDER BY COALESCE(input_time, created_at, time, date, id) DESC
                LIMIT 30";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $userId]);
        $rows = $stmt->fetchAll();

        if ($rows) {
            $lines = [];
            foreach ($rows as $r) {
                $type = $r['sport'] ?? $r['exercise_type'] ?? $r['type'] ?? $r['category'] ?? '（未知運動）';
                $min  = $r['duration'] ?? $r['minutes'] ?? $r['time_minutes'] ?? $r['workout_minutes'] ?? null;
                $kcal = $r['calories'] ?? $r['kcal'] ?? $r['burned_calories'] ?? null;
                $ts   = $r['input_time'] ?? $r['created_at'] ?? $r['time'] ?? $r['date'] ?? null;

                $line = "- " . ($ts ? "{$ts} " : "") . "{$type}";
                if ($min !== null) $line .= " / {$min} 分";
                if ($kcal !== null) $line .= " / {$kcal} kcal";
                $lines[] = $line;
            }
            $historyText = implode("\n", $lines);
        } else {
            $historyText = "（運動紀錄表存在，但目前抓不到該使用者的資料）";
        }
    }

    return [$profileText, $historyText];
}

/**
 * 從 Responses API 回傳 output_text
 */
function extract_output_text(array $json): ?string {
    if (!isset($json['output']) || !is_array($json['output'])) return null;
    foreach ($json['output'] as $item) {
        if (($item['type'] ?? '') === 'message' && ($item['role'] ?? '') === 'assistant') {
            foreach (($item['content'] ?? []) as $c) {
                if (($c['type'] ?? '') === 'output_text' && isset($c['text'])) {
                    return $c['text'];
                }
            }
        }
    }
    return null;
}

/**
 * 主要入口：從 DB 抓 context + 限定領域回答
 */
function askCoachFromDb(int $userId, string $userMessage): string {
    if (!is_coach_related($userMessage)) {
        return coach_refusal_text();
    }

    $apiKey = getenv('OPENAI_API_KEY') ?: (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '');
    if (!$apiKey) return "錯誤：未設定 OPENAI_API_KEY。";

    try {
        $pdo = get_pdo();
        [$profileText, $historyText] = build_user_context($pdo, $userId);
    } catch (Throwable $e) {
        // DB 掛了也要能回覆（但會少個人化）
        $profileText = "（DB 連線失敗：{$e->getMessage()}）";
        $historyText = "（無法取得運動紀錄）";
    }

    $instructions =
"你是一位專屬運動/健康 AI 教練。你只能回答以下範圍：
- 運動建議、訓練計畫、動作/恢復
- 熱量消耗、運動消耗比例、營養（蛋白質/碳水/脂肪）、睡眠恢復
- 與 AI 教練或此平台紀錄/分析相關
如果使用者問題不在以上範圍，你必須只回：".coach_refusal_text()."

【使用者資料】\n{$profileText}\n
【近期運動紀錄】\n{$historyText}\n
回答請：1) 先給結論 2) 再給具體建議（條列） 3) 如果缺少資料，問 1~2 個最關鍵的問題。";

    $input = [
        ["role" => "user", "content" => $userMessage]
    ];

    $payload = [
        "model" => openai_model(),
        "instructions" => $instructions,
        "input" => $input,
        "temperature" => 0.4,
        "max_output_tokens" => 400
    ];

    $ch = curl_init("https://api.openai.com/v1/responses");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer {$apiKey}",
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) return "連線錯誤：{$err}";

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        // 常見原因：你上游 echo 了 warning/HTML，或反向代理回傳非 JSON
        return "AI 暫時無法回應（上游回傳非 JSON），請檢查伺服器 log。";
    }

    // OpenAI 失敗時通常會有 error 欄位
    if ($http >= 400) {
        $msg = $json['error']['message'] ?? "HTTP {$http}";
        return "AI 呼叫失敗：{$msg}";
    }

    $text = extract_output_text($json);
    return $text ?: "AI 暫時無法回應，請稍後再試。";
}
