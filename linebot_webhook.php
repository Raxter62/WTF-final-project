// linebot_webhook.php
require_once 'config.php';

if (!$pdo) {
    // 若 DB 連線失敗，無法處理並記錄
    error_log("linebot_webhook.php: DB connection failed");
    http_response_code(500);
    exit;
}

// 1. 取得原始輸入
$input = file_get_contents('php://input');
$events = json_decode($input, true);

if (!isset($events['events'])) {
    http_response_code(200);
    exit;
}

// 2. 遍歷事件
foreach ($events['events'] as $event) {
    // 我們只關心文字訊息
    if ($event['type'] == 'message' && $event['message']['type'] == 'text') {
        $text = trim($event['message']['text']);
        $replyToken = $event['replyToken'];
        $lineUserId = $event['source']['userId'];

        // 邏輯：檢查訊息是否為 "綁定 <CODE>"
        if (preg_match('/^綁定\s+(\d+)$/', $text, $matches)) {
            $code = $matches[1];
            
            // 在 DB 中檢查代碼
            // 檢查代碼是否相符且未過期 (假設 10 分鐘效期)
            $stmt = $pdo->prepare("SELECT id FROM users WHERE line_bind_code = ? AND line_bind_code_expires_at > NOW()");
            $stmt->execute([$code]);
            $user = $stmt->fetch();

            if ($user) {
                // 綁定成功
                $update = $pdo->prepare("UPDATE users SET line_user_id = ?, line_bind_code = NULL, line_bind_code_expires_at = NULL WHERE id = ?");
                $update->execute([$lineUserId, $user['id']]);
                
                replyLineMessage($replyToken, "✅ 綁定成功！您現在可以接收運動通知了。");
            } else {
                replyLineMessage($replyToken, "❌ 綁定失敗：驗證碼錯誤或已過期。");
            }
        } else {
            // 預設回覆 (可選)
            // replyLineMessage($replyToken, "請輸入「綁定 驗證碼」來連結帳號。");
        }
    }
}

// 3. 回傳 200 OK 給 LINE
http_response_code(200);

// 回覆訊息的輔助函式
function replyLineMessage($replyToken, $messageText) {
    $accessToken = LINE_CHANNEL_TOKEN;
    if (!$accessToken) return;

    $url = "https://api.line.me/v2/bot/message/reply";
    $data = [
        "replyToken" => $replyToken,
        "messages" => [
            ["type" => "text", "text" => $messageText]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . $accessToken
    ]);
    curl_exec($ch);
    curl_close($ch);
}
