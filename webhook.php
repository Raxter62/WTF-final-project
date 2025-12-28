<?php
/**
 * FitConnect LINE Bot Webhook Handler
 * 處理 LINE Bot 的訊息和綁定功能
 */

// 載入資料庫設定
require_once 'config.php';

// LINE Bot 設定
$channel_access_token = 'YOUR_CHANNEL_ACCESS_TOKEN'; // 需要在 LINE Console 產生
$channel_secret = '18a0229c8d75dc4f9bd65afbd4830cec';

// ========== 驗證請求來源 ==========

function verifySignature($body, $signature, $secret) {
    $hash = hash_hmac('sha256', $body, $secret, true);
    $hash_base64 = base64_encode($hash);
    return hash_equals($signature, $hash_base64);
}

// 取得 LINE 傳來的資料
$body = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

// 驗證簽章
if (!verifySignature($body, $signature, $channel_secret)) {
    http_response_code(400);
    exit('Invalid signature');
}

// 解析 JSON
$data = json_decode($body, true);

// 記錄 log（開發用）
file_put_contents('line_webhook.log', date('Y-m-d H:i:s') . " - " . $body . "\n", FILE_APPEND);

// ========== 處理事件 ==========

foreach ($data['events'] as $event) {
    $type = $event['type'];
    $replyToken = $event['replyToken'];
    
    if ($type === 'message') {
        handleMessage($event, $replyToken, $channel_access_token, $pdo);
    } elseif ($type === 'follow') {
        handleFollow($event, $replyToken, $channel_access_token);
    } elseif ($type === 'unfollow') {
        handleUnfollow($event, $pdo);
    }
}

http_response_code(200);
exit('OK');

// ========== 處理訊息 ==========

function handleMessage($event, $replyToken, $token, $pdo) {
    $messageType = $event['message']['type'];
    
    if ($messageType !== 'text') {
        return; // 只處理文字訊息
    }
    
    $text = trim($event['message']['text']);
    $lineUserId = $event['source']['userId'];
    
    // 檢查是否為 6 位數綁定碼
    if (preg_match('/^\d{6}$/', $text)) {
        handleBindCode($text, $lineUserId, $replyToken, $token, $pdo);
    } else {
        // 其他指令
        handleCommand($text, $lineUserId, $replyToken, $token, $pdo);
    }
}

// ========== 處理綁定碼 ==========

function handleBindCode($code, $lineUserId, $replyToken, $token, $pdo) {
    // 查詢綁定碼
    $sql = "
        SELECT id, display_name 
        FROM users 
        WHERE line_bind_code = :code 
          AND line_bind_code_expires_at > NOW()
          AND line_user_id IS NULL
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':code' => $code]);
    $user = $stmt->fetch();
    
    if (!$user) {
        replyMessage($replyToken, $token, [
            'type' => 'text',
            'text' => "❌ 綁定碼錯誤或已過期\n\n請重新在網站上產生綁定碼"
        ]);
        return;
    }
    
    // 更新綁定
    $sql = "
        UPDATE users 
        SET line_user_id = :line_user_id,
            line_bind_code = NULL,
            line_bind_code_expires_at = NULL
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':line_user_id' => $lineUserId,
        ':id' => $user['id']
    ]);
    
    // 發送成功訊息
    replyMessage($replyToken, $token, [
        'type' => 'text',
        'text' => "✅ 綁定成功！\n\n" . 
                  "哈囉 {$user['display_name']}！\n\n" .
                  "現在你可以接收運動提醒和數據分析了 💪"
    ]);
}

// ========== 處理指令 ==========

function handleCommand($text, $lineUserId, $replyToken, $token, $pdo) {
    $text = strtolower($text);
    
    // 查詢用戶
    $sql = "SELECT id, display_name FROM users WHERE line_user_id = :line_user_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':line_user_id' => $lineUserId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        // 未綁定
        replyMessage($replyToken, $token, [
            'type' => 'text',
            'text' => "👋 歡迎使用 FitConnect！\n\n" .
                      "請先在網站上登入，\n" .
                      "然後產生綁定碼並輸入到這裡\n\n" .
                      "🔗 網址：https://your-domain.com"
        ]);
        return;
    }
    
    // 已綁定用戶的指令
    if (in_array($text, ['統計', 'stats', '數據'])) {
        sendStats($user['id'], $lineUserId, $replyToken, $token, $pdo);
    } elseif (in_array($text, ['排行榜', 'rank', 'leaderboard'])) {
        sendLeaderboard($lineUserId, $replyToken, $token, $pdo);
    } elseif (in_array($text, ['幫助', 'help', '說明'])) {
        sendHelp($lineUserId, $replyToken, $token);
    } else {
        replyMessage($replyToken, $token, [
            'type' => 'text',
            'text' => "你可以試試這些指令：\n\n" .
                      "📊 統計 - 查看運動數據\n" .
                      "🏆 排行榜 - 查看排名\n" .
                      "❓ 幫助 - 查看所有指令"
        ]);
    }
}

// ========== 發送統計數據 ==========

function sendStats($userId, $lineUserId, $replyToken, $token, $pdo) {
    // 查詢本週數據
    $sql = "
        SELECT 
            COUNT(*) as workout_count,
            SUM(minutes) as total_minutes,
            SUM(calories) as total_calories
        FROM workouts
        WHERE user_id = :user_id
          AND date >= CURRENT_DATE - INTERVAL '7 days'
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':user_id' => $userId]);
    $stats = $stmt->fetch();
    
    $message = "📊 本週運動統計\n\n" .
               "🏃 運動次數：{$stats['workout_count']} 次\n" .
               "⏱️  運動時間：{$stats['total_minutes']} 分鐘\n" .
               "🔥 消耗熱量：{$stats['total_calories']} kcal\n\n" .
               "繼續加油！💪";
    
    replyMessage($replyToken, $token, [
        'type' => 'text',
        'text' => $message
    ]);
}

// ========== 發送排行榜 ==========

function sendLeaderboard($lineUserId, $replyToken, $token, $pdo) {
    $sql = "
        SELECT u.display_name, SUM(w.calories) as total
        FROM workouts w
        JOIN users u ON w.user_id = u.id
        WHERE w.date >= CURRENT_DATE - INTERVAL '30 days'
        GROUP BY u.id, u.display_name
        ORDER BY total DESC
        LIMIT 5
    ";
    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll();
    
    $message = "🏆 本月排行榜 TOP 5\n\n";
    $medals = ['🥇', '🥈', '🥉', '4️⃣', '5️⃣'];
    
    foreach ($data as $i => $row) {
        $message .= "{$medals[$i]} {$row['display_name']}\n";
        $message .= "   {$row['total']} kcal\n\n";
    }
    
    replyMessage($replyToken, $token, [
        'type' => 'text',
        'text' => $message
    ]);
}

// ========== 發送幫助訊息 ==========

function sendHelp($lineUserId, $replyToken, $token) {
    $message = "📱 FitConnect 使用說明\n\n" .
               "可用指令：\n\n" .
               "📊 統計 - 查看本週運動數據\n" .
               "🏆 排行榜 - 查看本月排名\n" .
               "❓ 幫助 - 顯示此訊息\n\n" .
               "也可以直接在網站上記錄運動喔！";
    
    replyMessage($replyToken, $token, [
        'type' => 'text',
        'text' => $message
    ]);
}

// ========== 處理加入好友 ==========

function handleFollow($event, $replyToken, $token) {
    replyMessage($replyToken, $token, [
        'type' => 'text',
        'text' => "👋 歡迎加入 FitConnect！\n\n" .
                  "請到網站上登入帳號，\n" .
                  "然後點擊「產生綁定碼」，\n" .
                  "將綁定碼輸入到這裡完成綁定\n\n" .
                  "綁定後就可以接收運動提醒囉！💪"
    ]);
}

// ========== 處理取消好友 ==========

function handleUnfollow($event, $pdo) {
    $lineUserId = $event['source']['userId'];
    
    // 解除綁定
    $sql = "UPDATE users SET line_user_id = NULL WHERE line_user_id = :line_user_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':line_user_id' => $lineUserId]);
}

// ========== 發送回覆訊息 ==========

function replyMessage($replyToken, $token, $message) {
    $url = 'https://api.line.me/v2/bot/message/reply';
    
    $data = [
        'replyToken' => $replyToken,
        'messages' => [$message]
    ];
    
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    return $result;
}

// ========== 主動推送訊息 ==========

function pushMessage($lineUserId, $token, $message) {
    $url = 'https://api.line.me/v2/bot/message/push';
    
    $data = [
        'to' => $lineUserId,
        'messages' => [$message]
    ];
    
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    return $result;
}