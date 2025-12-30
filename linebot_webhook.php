// linebot_webhook.php
require_once 'config.php';

// MET values shared logic
const MET_VALUES = [
    '跑步' => 10,
    '重訓' => 4,
    '腳踏車' => 8,
    '游泳' => 6,
    '瑜珈' => 3,
    '其他' => 2
];

if (!$pdo) {
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
    $replyToken = $event['replyToken'];
    $lineUserId = $event['source']['userId'];
    $type = $event['type'];

    // Handle Postback (Button Clicks)
    if ($type == 'postback') {
        $data = $event['postback']['data'];
        
        if ($data === 'action=bind_menu') {
            replyLineMessage($replyToken, "請輸入「綁定 驗證碼」\n例如：綁定 123456");
        } elseif ($data === 'action=workout_menu') {
            replyLineMessage($replyToken, "請依照格式輸入運動紀錄：\n項目 分鐘數\n例如：跑步 30\n(支援項目：跑步, 重訓, 腳踏車, 游泳, 瑜珈, 其他)");
        } elseif ($data === 'action=profile_menu') {
            replyLineMessage($replyToken, "請依照格式輸入身高體重：\n身高 體重\n例如：175 65");
        }
        continue;
    }

    // Handle Message
    if ($type == 'message' && $event['message']['type'] == 'text') {
        $text = trim($event['message']['text']);

        // Show Menu
        if ($text === '選單' || $text === 'Menu' || $text === 'menu') {
            replyMainMenu($replyToken);
            continue;
        }

        // 1. 綁定邏輯
        if (preg_match('/^綁定\s*([a-zA-Z0-9]+)$/i', $text, $matches)) {
            $code = strtoupper($matches[1]);
            
            $stmt = $pdo->prepare("SELECT id FROM users WHERE line_bind_code = ? AND line_bind_code_expires_at > NOW()");
            $stmt->execute([$code]);
            $user = $stmt->fetch();

            if ($user) {
                $update = $pdo->prepare("UPDATE users SET line_user_id = ?, line_bind_code = NULL, line_bind_code_expires_at = NULL WHERE id = ?");
                $update->execute([$lineUserId, $user['id']]);
                replyLineMessage($replyToken, "✅ 綁定成功！您現在可以接收運動通知並使用 LINE 記錄運動了。");
            } else {
                replyLineMessage($replyToken, "❌ 綁定失敗：驗證碼錯誤或已過期。");
            }
            continue;
        }

        // 2. 運動紀錄邏輯 (格式: 項目 分鐘)
        // Check if user is bound
        $stmt = $pdo->prepare("SELECT id, weight FROM users WHERE line_user_id = ?");
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Treat as potential command
            $parts = preg_split('/\s+/', $text);
            
            // Case: Workout (Item Minutes)
            if (count($parts) >= 2 && isset($MET_VALUES[$parts[0]]) && is_numeric($parts[1])) {
                $type = $parts[0];
                $minutes = intval($parts[1]);
                $weight = floatval($user['weight']);

                if ($minutes <= 0) {
                    replyLineMessage($replyToken, "❌ 分鐘數必須大於 0");
                    continue;
                }
                
                // Calorie Calc
                $cal = 0;
                if ($weight > 0) {
                    $met = $MET_VALUES[$type];
                    $cal = round((($met * 3.5 * $weight) / 200) * $minutes);
                }

                // Insert
                $stmt = $pdo->prepare("INSERT INTO workouts (user_id, date, type, minutes, calories) VALUES (?, NOW(), ?, ?, ?)");
                $stmt->execute([$user['id'], $type, $minutes, $cal]);

                // Reply
                $msg = "✅ 已新增運動紀錄！\n項目：$type\n時間：$minutes 分鐘";
                if ($cal > 0) $msg .= "\n消耗：$cal kcal";
                else $msg .= "\n(尚未設定體重，無法計算卡路里)";
                
                replyLineMessage($replyToken, $msg);
                continue;
            }

            // Case: Profile (Height Weight)
            if (count($parts) == 2 && is_numeric($parts[0]) && is_numeric($parts[1]) && !isset($MET_VALUES[$parts[0]])) {
                $h = floatval($parts[0]);
                $w = floatval($parts[1]);

                if ($h > 0 && $w > 0) {
                    $stmt = $pdo->prepare("UPDATE users SET height = ?, weight = ? WHERE id = ?");
                    $stmt->execute([$h, $w, $user['id']]);
                    replyLineMessage($replyToken, "✅ 個人資料已更新！\n身高：$h cm\n體重：$w kg");
                } else {
                    replyLineMessage($replyToken, "❌ 數值格式錯誤");
                }
                continue;
            }
        }
        
        // Default Fallback
        // replyMainMenu($replyToken); // Optional: Auto show menu on unknown text? maybe annoying.
    }
}

http_response_code(200);

// Functions
function replyLineMessage($replyToken, $text) {
    global $accessToken; 
    // Re-define token here or use constant
    if (!defined('LINE_CHANNEL_TOKEN')) return;
    $accessToken = LINE_CHANNEL_TOKEN;

    $url = "https://api.line.me/v2/bot/message/reply";
    $data = [
        "replyToken" => $replyToken,
        "messages" => [
            ["type" => "text", "text" => $text]
        ]
    ];

    postLineApi($url, $data, $accessToken);
}

function replyMainMenu($replyToken) {
    if (!defined('LINE_CHANNEL_TOKEN')) return;
    $accessToken = LINE_CHANNEL_TOKEN;

    $url = "https://api.line.me/v2/bot/message/reply";
    $data = [
        "replyToken" => $replyToken,
        "messages" => [
            [
                "type" => "template",
                "altText" => "FitConnect 選單",
                "template" => [
                    "type" => "buttons",
                    "thumbnailImageUrl" => "https://fitconnect.up.railway.app/public/image/logo/logo.png", // Must be HTTPS
                    "imageAspectRatio" => "rectangle",
                    "imageSize" => "cover",
                    "imageBackgroundColor" => "#FFFFFF",
                    "title" => "FitConnect 助手",
                    "text" => "請選擇功能",
                    "defaultAction" => [
                        "type" => "uri",
                        "label" => "View detail",
                        "uri" => "https://fitconnect.up.railway.app/"
                    ],
                    "actions" => [
                        [
                            "type" => "postback",
                            "label" => "🔗 帳號綁定",
                            "data" => "action=bind_menu"
                        ],
                        [
                            "type" => "postback",
                            "label" => "🏃 新增運動",
                            "data" => "action=workout_menu"
                        ],
                        [
                            "type" => "postback",
                            "label" => "📝 個人資料",
                            "data" => "action=profile_menu"
                        ],
                        [
                            "type" => "uri",
                            "label" => "🌐 開啟網頁",
                            "uri" => "https://fitconnect.up.railway.app/"
                        ]
                    ]
                ]
            ]
        ]
    ];

    postLineApi($url, $data, $accessToken);
}

function postLineApi($url, $data, $token) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . $token
    ]);
    $result = curl_exec($ch);
    // error_log("LINE API Result: " . $result);
    curl_close($ch);
}
