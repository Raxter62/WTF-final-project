<?php
// linebot_with_buttons.php - 帶按鈕的 LINE Bot

require_once 'config.php';

if (!$pdo) {
    http_response_code(500);
    exit;
}

$input = file_get_contents('php://input');
$events = json_decode($input, true);

if (!isset($events['events'])) {
    http_response_code(200);
    exit;
}

foreach ($events['events'] as $event) {
    if ($event['type'] == 'message' && $event['message']['type'] == 'text') {
        $text = trim($event['message']['text']);
        $replyToken = $event['replyToken'];
        $lineUserId = $event['source']['userId'];

        handleMessage($text, $replyToken, $lineUserId);
    }
}

http_response_code(200);

// ========== 處理訊息 ==========
function handleMessage($text, $replyToken, $lineUserId) {
    global $pdo;
    
    // 綁定帳號（輸入綁定碼）
    if (preg_match('/^綁定\s*(\d{6})$/', $text, $m)) {
        $response = bindAccount($lineUserId, $m[1]);
        replyText($replyToken, $response);
        return;
    }
    
    // 綁定帳號（按鈕觸發）
    if ($text === '綁定帳號' || strpos($text, '如何綁定') !== false) {
        replyText($replyToken, 
            "🔗 綁定 FitConnect 帳號\n\n" .
            "步驟：\n" .
            "1️⃣ 登入 FitConnect 網站\n" .
            "2️⃣ 前往「LINE 綁定」頁面\n" .
            "3️⃣ 點擊「產生綁定碼」\n" .
            "4️⃣ 回到這裡輸入：\n" .
            "   綁定 123456\n\n" .
            "💡 綁定碼有效期限 10 分鐘\n\n" .
            "輸入任何文字顯示主選單"
        );
        return;
    }
    
    // 查看記錄
    if (strpos($text, '記錄') !== false || strpos($text, '查看') !== false) {
        $response = getRecords($lineUserId);
        replyText($replyToken, $response);
        return;
    }
    
    // 排行榜
    if (strpos($text, '排行') !== false) {
        $response = getLeaderboard();
        replyText($replyToken, $response);
        return;
    }
    
    // 幫助
    if (strpos($text, '幫助') !== false || strpos($text, '說明') !== false || $text === '?') {
        replyWithButtons($replyToken);
        return;
    }
    
    // 預設：顯示主選單按鈕
    replyWithMainMenu($replyToken);
}

// ========== 回覆主選單（按鈕版）==========
function replyWithMainMenu($replyToken) {
    $message = [
        "type" => "template",
        "altText" => "FitConnect 主選單",
        "template" => [
            "type" => "buttons",
            "title" => "FitConnect",
            "text" => "請選擇功能",
            "actions" => [
                [
                    "type" => "message",
                    "label" => "🔗 綁定帳號",
                    "text" => "綁定帳號"
                ],
                [
                    "type" => "message",
                    "label" => "📊 查看記錄",
                    "text" => "記錄"
                ],
                [
                    "type" => "message",
                    "label" => "🏆 排行榜",
                    "text" => "排行"
                ],
                [
                    "type" => "uri",
                    "label" => "🌐 開啟網站",
                    "uri" => "https://your-railway-url.railway.app"
                ]
            ]
        ]
    ];
    
    replyMessage($replyToken, [$message]);
}

// ========== 回覆幫助（快速回覆按鈕）==========
function replyWithButtons($replyToken) {
    $message = [
        "type" => "text",
        "text" => "📱 FitConnect 使用說明\n\n" .
                 "🔗 綁定帳號\n" .
                 "   輸入：綁定 123456\n\n" .
                 "📊 查看記錄\n" .
                 "   點擊下方按鈕\n\n" .
                 "🏆 排行榜\n" .
                 "   點擊下方按鈕\n\n" .
                 "選擇功能：",
        "quickReply" => [
            "items" => [
                [
                    "type" => "action",
                    "action" => [
                        "type" => "message",
                        "label" => "📊 查看記錄",
                        "text" => "記錄"
                    ]
                ],
                [
                    "type" => "action",
                    "action" => [
                        "type" => "message",
                        "label" => "🏆 排行榜",
                        "text" => "排行"
                    ]
                ],
                [
                    "type" => "action",
                    "action" => [
                        "type" => "uri",
                        "label" => "🌐 開啟網站",
                        "uri" => "https://your-railway-url.railway.app"
                    ]
                ],
                [
                    "type" => "action",
                    "action" => [
                        "type" => "message",
                        "label" => "🔗 如何綁定",
                        "text" => "如何綁定"
                    ]
                ]
            ]
        ]
    ];
    
    replyMessage($replyToken, [$message]);
}

// ========== 綁定帳號 ==========
function bindAccount($lineUserId, $code) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT id, display_name 
        FROM users 
        WHERE line_bind_code = ? 
        AND line_bind_code_expires_at > NOW()
    ");
    $stmt->execute([$code]);
    $user = $stmt->fetch();

    if ($user) {
        $update = $pdo->prepare("
            UPDATE users 
            SET line_user_id = ?, 
                line_bind_code = NULL, 
                line_bind_code_expires_at = NULL 
            WHERE id = ?
        ");
        $update->execute([$lineUserId, $user['id']]);
        
        return "✅ 綁定成功！\n\n" .
               "歡迎 {$user['display_name']}！\n" .
               "現在可以透過 LINE 查看記錄了 💪\n\n" .
               "輸入任何文字顯示主選單";
    }
    
    return "❌ 綁定碼錯誤或已過期\n\n" .
           "請到網站重新產生綁定碼\n\n" .
           "輸入任何文字顯示主選單";
}

// ========== 查看記錄 ==========
function getRecords($lineUserId) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT id FROM users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        return "❌ 尚未綁定帳號\n\n" .
               "請輸入：綁定 123456\n" .
               "（到網站產生綁定碼）\n\n" .
               "輸入任何文字顯示主選單";
    }
    
    $stmt = $pdo->prepare("
        SELECT type, minutes, calories, 
               TO_CHAR(date, 'MM/DD') as date
        FROM workouts
        WHERE user_id = ?
        AND date >= NOW() - INTERVAL '7 days'
        ORDER BY date DESC
        LIMIT 5
    ");
    $stmt->execute([$user['id']]);
    $records = $stmt->fetchAll();
    
    if (count($records) === 0) {
        return "📊 最近 7 天還沒有記錄\n\n" .
               "快去運動吧！💪\n\n" .
               "輸入任何文字顯示主選單";
    }
    
    $total = $pdo->prepare("
        SELECT SUM(minutes) as total
        FROM workouts
        WHERE user_id = ?
        AND date >= NOW() - INTERVAL '7 days'
    ");
    $total->execute([$user['id']]);
    $totalMin = $total->fetch()['total'];
    
    $msg = "📊 最近 7 天記錄\n\n";
    $msg .= "總時間：{$totalMin} 分鐘\n\n";
    
    $icons = [
        '跑步' => '🏃',
        '重訓' => '🏋️',
        '腳踏車' => '🚴',
        '游泳' => '🏊',
        '瑜珈' => '🧘',
        '其他' => '🤸'
    ];
    
    foreach ($records as $r) {
        $icon = $icons[$r['type']] ?? '🤸';
        $msg .= "{$icon} {$r['type']} {$r['minutes']}分\n";
        $msg .= "   {$r['date']} ({$r['calories']} kcal)\n\n";
    }
    
    $msg .= "輸入任何文字顯示主選單";
    
    return $msg;
}

// ========== 查看排行榜 ==========
function getLeaderboard() {
    global $pdo;
    
    $stmt = $pdo->query("
        SELECT u.display_name, SUM(w.minutes) as total
        FROM users u
        JOIN workouts w ON u.id = w.user_id
        WHERE w.date >= DATE_TRUNC('month', CURRENT_DATE)
        GROUP BY u.id, u.display_name
        ORDER BY total DESC
        LIMIT 5
    ");
    $ranks = $stmt->fetchAll();
    
    if (count($ranks) === 0) {
        return "🏆 本月排行榜\n\n" .
               "目前還沒有記錄\n\n" .
               "輸入任何文字顯示主選單";
    }
    
    $msg = "🏆 本月排行榜\n\n";
    $medals = ['🥇', '🥈', '🥉'];
    
    foreach ($ranks as $i => $r) {
        $rank = $i < 3 ? $medals[$i] : ($i+1).'.';
        $msg .= "{$rank} {$r['display_name']} - {$r['total']}分\n";
    }
    
    $msg .= "\n繼續加油！💪\n\n";
    $msg .= "輸入任何文字顯示主選單";
    
    return $msg;
}

// ========== 回覆文字訊息 ==========
function replyText($replyToken, $text) {
    $message = [
        "type" => "text",
        "text" => $text
    ];
    
    replyMessage($replyToken, [$message]);
}

// ========== 回覆訊息（通用）==========
function replyMessage($replyToken, $messages) {
    $accessToken = LINE_CHANNEL_TOKEN;
    if (!$accessToken) return;

    $ch = curl_init("https://api.line.me/v2/bot/message/reply");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        "replyToken" => $replyToken,
        "messages" => $messages
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . $accessToken
    ]);
    curl_exec($ch);
    curl_close($ch);
}