<?php
// linebot_webhook.php - 按鈕版本（啟用過期檢查）

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
    $replyToken = $event['replyToken'];
    $lineUserId = $event['source']['userId'];
    
    if ($event['type'] == 'message' && $event['message']['type'] == 'text') {
        $text = trim($event['message']['text']);
        handleMessage($text, $replyToken, $lineUserId);
    }
    
    if ($event['type'] == 'postback') {
        handlePostback($event['postback']['data'], $replyToken, $lineUserId);
    }
}

http_response_code(200);

// ========== 處理訊息 ==========
function handleMessage($text, $replyToken, $lineUserId) {
    global $pdo;
    
    // 檢查是否為 6 位數綁定碼
    if (preg_match('/^\d{6}$/', $text)) {
        bindAccount($lineUserId, $text, $replyToken);
        return;
    }
    
    // 檢查是否為「選單」指令
    if (in_array(strtolower($text), ['選單', 'menu', '主選單'])) {
        showMainMenu($replyToken, $lineUserId);
        return;
    }
    
    // 檢查是否為運動時長輸入（純數字）
    if (preg_match('/^\d+$/', $text)) {
        $number = intval($text);
        
        // 檢查是否有暫存的運動資料
        $stmt = $pdo->prepare("
            SELECT line_bind_code 
            FROM users 
            WHERE line_user_id = ?
        ");
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch();
        
        if ($user && !empty($user['line_bind_code'])) {
            $tempData = $user['line_bind_code'];
            
            // 如果暫存資料包含兩個 |，表示正在等待卡路里輸入
            if (substr_count($tempData, '|') == 2) {
                handleCaloriesInput($lineUserId, $number, $replyToken);
                return;
            }
            // 如果暫存資料包含一個 |，表示正在等待時長輸入
            else if (substr_count($tempData, '|') == 1) {
                handleDurationInput($lineUserId, $number, $replyToken);
                return;
            }
        }
    }
    
    // 預設：顯示主選單
    showMainMenu($replyToken, $lineUserId);
}

// ========== 處理 Postback ==========
function handlePostback($data, $replyToken, $lineUserId) {
    global $pdo;
    
    parse_str($data, $params);
    $action = $params['action'] ?? '';
    
    switch ($action) {
        case 'add_workout':
            showWorkoutTypeSelection($replyToken);
            break;
            
        case 'workout_type':
            $type = $params['type'] ?? '';
            showDateTimePicker($replyToken, $type);
            break;
            
        case 'workout_datetime':
            // 從日期時間選擇器返回
            $datetime = $params['datetime'] ?? '';
            $type = $params['type'] ?? '';
            promptDuration($replyToken, $lineUserId, $type, $datetime);
            break;
            
        case 'view_profile':
            showProfileInfo($replyToken, $lineUserId);
            break;
            
        case 'edit_name':
            showEditNameOptions($replyToken, $lineUserId);
            break;
            
        case 'set_name':
            $name = $params['value'] ?? '';
            updateProfile($lineUserId, 'display_name', $name, $replyToken);
            break;
            
        case 'edit_height':
            showEditHeightOptions($replyToken, $lineUserId);
            break;
            
        case 'set_height':
            $height = $params['value'] ?? 0;
            updateProfile($lineUserId, 'height', intval($height), $replyToken);
            break;
            
        case 'edit_weight':
            showEditWeightOptions($replyToken, $lineUserId);
            break;
            
        case 'set_weight':
            $weight = $params['value'] ?? 0;
            updateProfile($lineUserId, 'weight', intval($weight), $replyToken);
            break;
            
        case 'bind':
            showBindForm($replyToken, $lineUserId);
            break;
            
        case 'bound_menu':
            showBoundMenu($replyToken, $lineUserId);
            break;
            
        case 'unbind_confirm':
            showUnbindConfirmation($replyToken);
            break;
            
        case 'unbind_yes':
            unbindAccount($lineUserId, $replyToken);
            break;
            
        case 'unbind_no':
            replyText($replyToken, "❌ 已取消解除綁定\n\n輸入「選單」返回主選單");
            break;
    }
}

// ========== 處理運動時長輸入 ==========
function handleDurationInput($lineUserId, $duration, $replyToken) {
    global $pdo;
    
    // 取得暫存的運動類型和日期時間
    $stmt = $pdo->prepare("
        SELECT line_bind_code 
        FROM users 
        WHERE line_user_id = ?
    ");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        replyText($replyToken, "❌ 請先綁定帳號");
        return;
    }
    
    // 從 line_bind_code 暫存解析資料（格式：type|datetime）
    $tempData = $user['line_bind_code'];
    if (empty($tempData) || strpos($tempData, '|') === false) {
        replyText($replyToken, 
            "❌ 找不到運動資訊\n\n" .
            "請重新開始：\n" .
            "1. 輸入「選單」\n" .
            "2. 點選「📝 輸入運動」"
        );
        return;
    }
    
    list($type, $datetime) = explode('|', $tempData, 2);
    
    // 驗證時長
    if ($duration <= 0 || $duration > 1440) {
        replyText($replyToken, 
            "❌ 時長需在 1-1440 分鐘之間\n\n" .
            "請重新輸入時長（分鐘）："
        );
        return;
    }
    
    // 請使用者輸入卡路里
    // 暫存：type|datetime|duration
    $stmt = $pdo->prepare("
        UPDATE users 
        SET line_bind_code = ? 
        WHERE line_user_id = ?
    ");
    $stmt->execute([$type . '|' . $datetime . '|' . $duration, $lineUserId]);
    
    replyText($replyToken, 
        "⏱️ 時長：{$duration} 分鐘\n\n" .
        "請輸入消耗的卡路里：\n\n" .
        "範例：300\n\n" .
        "💡 可在網站使用計算機計算\n" .
        "或輸入「選單」取消"
    );
}

// ========== 處理卡路里輸入 ==========
function handleCaloriesInput($lineUserId, $calories, $replyToken) {
    global $pdo;
    
    // 驗證卡路里
    if ($calories < 0 || $calories > 10000) {
        replyText($replyToken, 
            "❌ 卡路里需在 0-10000 之間\n\n" .
            "請重新輸入卡路里："
        );
        return;
    }
    
    // 取得暫存的運動資料
    $stmt = $pdo->prepare("
        SELECT id, line_bind_code 
        FROM users 
        WHERE line_user_id = ?
    ");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch();
    
    if (!$user || empty($user['line_bind_code'])) {
        replyText($replyToken, "❌ 找不到運動資訊，請重新開始");
        return;
    }
    
    // 解析暫存資料（格式：type|datetime|duration）
    $parts = explode('|', $user['line_bind_code']);
    if (count($parts) != 3) {
        replyText($replyToken, "❌ 資料格式錯誤，請重新開始");
        return;
    }
    
    list($type, $datetime, $duration) = $parts;
    
    // 儲存運動記錄
    try {
        $insert = $pdo->prepare("
            INSERT INTO workouts (user_id, date, type, minutes, calories) 
            VALUES (?, ?::timestamptz, ?, ?, ?)
        ");
        
        $insert->execute([
            $user['id'],
            $datetime,
            $type,
            intval($duration),
            $calories
        ]);
        
        // 清除暫存
        $clear = $pdo->prepare("
            UPDATE users 
            SET line_bind_code = NULL 
            WHERE id = ?
        ");
        $clear->execute([$user['id']]);
        
        // 取得運動圖示
        $icons = [
            '跑步' => '🏃',
            '重訓' => '🏋️',
            '腳踏車' => '🚴',
            '游泳' => '🏊',
            '瑜珈' => '🧘',
            '其他' => '💪'
        ];
        $icon = $icons[$type] ?? '🏃';
        
        // 格式化日期時間
        $dt = new DateTime($datetime);
        $displayDate = $dt->format('Y-m-d H:i');
        
        replyText($replyToken, 
            "✅ 運動記錄已儲存！\n\n" .
            "{$icon} 類型：{$type}\n" .
            "📅 時間：{$displayDate}\n" .
            "⏱️ 時長：{$duration} 分鐘\n" .
            "🔥 卡路里：{$calories} kcal\n\n" .
            "繼續加油 💪\n\n" .
            "輸入「選單」返回主選單"
        );
    } catch (PDOException $e) {
        error_log("Save workout failed: " . $e->getMessage());
        replyText($replyToken, "❌ 儲存失敗：" . $e->getMessage());
    }
}

// ========== 主選單 ==========
function showMainMenu($replyToken, $lineUserId) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT id FROM users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $isBound = $stmt->fetch() ? true : false;
    
    $message = [
        "type" => "template",
        "altText" => "FitConnect 主選單",
        "template" => [
            "type" => "buttons",
            "title" => "FitConnect",
            "text" => "請選擇功能",
            "actions" => [
                [
                    "type" => "postback",
                    "label" => "📝 輸入運動",
                    "data" => "action=add_workout"
                ],
                [
                    "type" => "postback",
                    "label" => "👤 個人資料",
                    "data" => "action=view_profile"
                ],
                [
                    "type" => "postback",
                    "label" => $isBound ? "✅ 已綁定" : "🔗 綁定",
                    "data" => $isBound ? "action=bound_menu" : "action=bind"
                ],
                [
                    "type" => "uri",
                    "label" => "🌐 跳至網站",
                    "uri" => "https://your-railway-url.railway.app"
                ]
            ]
        ]
    ];
    
    replyMessage($replyToken, [$message]);
}

// ========== A. 選擇運動類型 ==========
function showWorkoutTypeSelection($replyToken) {
    $message1 = [
        "type" => "template",
        "altText" => "選擇運動類型",
        "template" => [
            "type" => "buttons",
            "title" => "📝 輸入運動",
            "text" => "請選擇運動類型",
            "actions" => [
                [
                    "type" => "postback",
                    "label" => "🏃 跑步",
                    "data" => "action=workout_type&type=跑步"
                ],
                [
                    "type" => "postback",
                    "label" => "🏋️ 重訓",
                    "data" => "action=workout_type&type=重訓"
                ],
                [
                    "type" => "postback",
                    "label" => "🚴 腳踏車",
                    "data" => "action=workout_type&type=腳踏車"
                ],
                [
                    "type" => "postback",
                    "label" => "🏊 游泳",
                    "data" => "action=workout_type&type=游泳"
                ]
            ]
        ]
    ];
    
    $message2 = [
        "type" => "template",
        "altText" => "選擇運動類型",
        "template" => [
            "type" => "buttons",
            "title" => "📝 輸入運動（續）",
            "text" => "其他運動類型",
            "actions" => [
                [
                    "type" => "postback",
                    "label" => "🧘 瑜珈",
                    "data" => "action=workout_type&type=瑜珈"
                ],
                [
                    "type" => "postback",
                    "label" => "💪 其他",
                    "data" => "action=workout_type&type=其他"
                ],
                [
                    "type" => "message",
                    "label" => "返回主選單",
                    "text" => "選單"
                ]
            ]
        ]
    ];
    
    replyMessage($replyToken, [$message1, $message2]);
}

// ========== A. 顯示日期時間選擇器 ==========
function showDateTimePicker($replyToken, $type) {
    $today = date('Y-m-d\TH:i');
    
    $message = [
        "type" => "template",
        "altText" => "選擇運動日期時間",
        "template" => [
            "type" => "buttons",
            "title" => "📅 選擇日期時間",
            "text" => "運動類型：{$type}\n\n請選擇運動的日期和開始時間",
            "actions" => [
                [
                    "type" => "datetimepicker",
                    "label" => "📅 選擇日期時間",
                    "data" => "action=workout_datetime&type={$type}",
                    "mode" => "datetime",
                    "initial" => $today,
                    "max" => $today
                ],
                [
                    "type" => "message",
                    "label" => "取消",
                    "text" => "選單"
                ]
            ]
        ]
    ];
    
    replyMessage($replyToken, [$message]);
}

// ========== A. 提示輸入時長 ==========
function promptDuration($replyToken, $lineUserId, $type, $datetime) {
    global $pdo;
    
    // 暫存運動類型和日期時間到 line_bind_code 欄位
    // 格式：type|datetime
    $stmt = $pdo->prepare("
        UPDATE users 
        SET line_bind_code = ? 
        WHERE line_user_id = ?
    ");
    $stmt->execute([$type . '|' . $datetime, $lineUserId]);
    
    // 格式化顯示日期時間
    $dt = new DateTime($datetime);
    $displayDate = $dt->format('Y-m-d H:i');
    
    replyText($replyToken, 
        "🏃 類型：{$type}\n" .
        "📅 時間：{$displayDate}\n\n" .
        "請輸入運動時長（分鐘）：\n\n" .
        "範例：\n" .
        "• 30（30 分鐘）\n" .
        "• 45（45 分鐘）\n" .
        "• 60（60 分鐘）\n\n" .
        "💡 直接輸入數字即可\n" .
        "或輸入「選單」取消"
    );
}

// ========== B. 個人資料 ==========
function showProfileInfo($replyToken, $lineUserId) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT display_name, height, weight 
        FROM users 
        WHERE line_user_id = ?
    ");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        replyText($replyToken, "❌ 請先綁定帳號");
        return;
    }
    
    $name = $user['display_name'] ?? '未設定';
    $height = $user['height'] ?? 0;
    $weight = $user['weight'] ?? 0;
    
    $heightText = $height > 0 ? "{$height} cm" : "未設定";
    $weightText = $weight > 0 ? "{$weight} kg" : "未設定";
    
    $message = [
        "type" => "template",
        "altText" => "個人資料",
        "template" => [
            "type" => "buttons",
            "title" => "👤 個人資料",
            "text" => "姓名：{$name}\n身高：{$heightText}\n體重：{$weightText}\n\n請選擇要編輯的項目：",
            "actions" => [
                [
                    "type" => "postback",
                    "label" => "✏️ 編輯姓名",
                    "data" => "action=edit_name"
                ],
                [
                    "type" => "postback",
                    "label" => "📏 編輯身高",
                    "data" => "action=edit_height"
                ],
                [
                    "type" => "postback",
                    "label" => "⚖️ 編輯體重",
                    "data" => "action=edit_weight"
                ],
                [
                    "type" => "message",
                    "label" => "返回主選單",
                    "text" => "選單"
                ]
            ]
        ]
    ];
    
    replyMessage($replyToken, [$message]);
}

// ========== B. 編輯姓名 ==========
function showEditNameOptions($replyToken, $lineUserId) {
    $message = [
        "type" => "template",
        "altText" => "編輯姓名",
        "template" => [
            "type" => "buttons",
            "title" => "✏️ 編輯姓名",
            "text" => "請選擇或自訂姓名",
            "actions" => [
                [
                    "type" => "postback",
                    "label" => "Ray",
                    "data" => "action=set_name&value=Ray"
                ],
                [
                    "type" => "postback",
                    "label" => "Alex",
                    "data" => "action=set_name&value=Alex"
                ],
                [
                    "type" => "postback",
                    "label" => "Jordan",
                    "data" => "action=set_name&value=Jordan"
                ],
                [
                    "type" => "message",
                    "label" => "返回",
                    "text" => "選單"
                ]
            ]
        ]
    ];
    
    replyMessage($replyToken, [$message]);
}

// ========== B. 編輯身高 ==========
function showEditHeightOptions($replyToken, $lineUserId) {
    $message = [
        "type" => "template",
        "altText" => "編輯身高",
        "template" => [
            "type" => "buttons",
            "title" => "📏 編輯身高",
            "text" => "請選擇身高（公分）",
            "actions" => [
                [
                    "type" => "postback",
                    "label" => "160 cm",
                    "data" => "action=set_height&value=160"
                ],
                [
                    "type" => "postback",
                    "label" => "170 cm",
                    "data" => "action=set_height&value=170"
                ],
                [
                    "type" => "postback",
                    "label" => "175 cm",
                    "data" => "action=set_height&value=175"
                ],
                [
                    "type" => "postback",
                    "label" => "180 cm",
                    "data" => "action=set_height&value=180"
                ]
            ]
        ]
    ];
    
    replyMessage($replyToken, [$message]);
}

// ========== B. 編輯體重 ==========
function showEditWeightOptions($replyToken, $lineUserId) {
    $message = [
        "type" => "template",
        "altText" => "編輯體重",
        "template" => [
            "type" => "buttons",
            "title" => "⚖️ 編輯體重",
            "text" => "請選擇體重（公斤）",
            "actions" => [
                [
                    "type" => "postback",
                    "label" => "50 kg",
                    "data" => "action=set_weight&value=50"
                ],
                [
                    "type" => "postback",
                    "label" => "60 kg",
                    "data" => "action=set_weight&value=60"
                ],
                [
                    "type" => "postback",
                    "label" => "70 kg",
                    "data" => "action=set_weight&value=70"
                ],
                [
                    "type" => "postback",
                    "label" => "80 kg",
                    "data" => "action=set_weight&value=80"
                ]
            ]
        ]
    ];
    
    replyMessage($replyToken, [$message]);
}

// ========== B. 更新個人資料 ==========
function updateProfile($lineUserId, $field, $value, $replyToken) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT id FROM users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        replyText($replyToken, "❌ 請先綁定帳號");
        return;
    }
    
    $allowedFields = ['display_name', 'height', 'weight'];
    if (!in_array($field, $allowedFields)) {
        replyText($replyToken, "❌ 無效的欄位");
        return;
    }
    
    try {
        $update = $pdo->prepare("UPDATE users SET {$field} = ? WHERE id = ?");
        $update->execute([$value, $user['id']]);
        
        $fieldNames = [
            'display_name' => '姓名',
            'height' => '身高',
            'weight' => '體重'
        ];
        
        $fieldName = $fieldNames[$field] ?? $field;
        $unit = ($field == 'height') ? ' cm' : (($field == 'weight') ? ' kg' : '');
        
        replyText($replyToken, 
            "✅ {$fieldName}已更新為 {$value}{$unit}\n\n" .
            "輸入「選單」顯示主選單"
        );
    } catch (PDOException $e) {
        error_log("Update profile failed: " . $e->getMessage());
        replyText($replyToken, "❌ 更新失敗，請稍後再試");
    }
}

// ========== C. 綁定表單 ==========
function showBindForm($replyToken, $lineUserId) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT id FROM users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    
    if ($stmt->fetch()) {
        replyText($replyToken, "✅ 您已經綁定過了！");
        return;
    }
    
    replyText($replyToken, 
        "🔗 LINE 綁定說明\n\n" .
        "步驟：\n" .
        "1️⃣ 登入網站\n" .
        "2️⃣ 進入個人資料頁面\n" .
        "3️⃣ 點選「產生綁定碼」\n" .
        "4️⃣ 將 6 位數綁定碼傳送給我\n\n" .
        "⏰ 綁定碼 15 分鐘內有效\n\n" .
        "網站：https://your-railway-url.railway.app"
    );
}

// ========== C. 已綁定選單 ==========
function showBoundMenu($replyToken, $lineUserId) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT display_name 
        FROM users 
        WHERE line_user_id = ?
    ");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        replyText($replyToken, "❌ 未綁定");
        return;
    }
    
    $name = $user['display_name'] ?? '未設定';
    
    $message = [
        "type" => "template",
        "altText" => "綁定資訊",
        "template" => [
            "type" => "buttons",
            "title" => "✅ 已綁定",
            "text" => "帳號：{$name}\n\n要解除綁定嗎？",
            "actions" => [
                [
                    "type" => "postback",
                    "label" => "🔓 解除綁定",
                    "data" => "action=unbind_confirm"
                ],
                [
                    "type" => "message",
                    "label" => "返回主選單",
                    "text" => "選單"
                ]
            ]
        ]
    ];
    
    replyMessage($replyToken, [$message]);
}

// ========== C. 解除綁定確認 ==========
function showUnbindConfirmation($replyToken) {
    $message = [
        "type" => "template",
        "altText" => "確認解除綁定",
        "template" => [
            "type" => "confirm",
            "text" => "⚠️ 確定要解除綁定嗎？\n\n解除後將無法使用 LINE Bot 功能，但網站資料不會被刪除。\n\n如需重新使用，請再次綁定。",
            "actions" => [
                [
                    "type" => "postback",
                    "label" => "確定解除",
                    "data" => "action=unbind_yes"
                ],
                [
                    "type" => "postback",
                    "label" => "取消",
                    "data" => "action=unbind_no"
                ]
            ]
        ]
    ];
    
    replyMessage($replyToken, [$message]);
}

// ========== C. 執行解除綁定 ==========
function unbindAccount($lineUserId, $replyToken) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT id, display_name 
        FROM users 
        WHERE line_user_id = ?
    ");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        replyText($replyToken, "❌ 未找到綁定資訊");
        return;
    }
    
    try {
        $update = $pdo->prepare("
            UPDATE users 
            SET line_user_id = NULL 
            WHERE id = ?
        ");
        $update->execute([$user['id']]);
        
        replyText($replyToken, 
            "✅ 解除綁定成功！\n\n" .
            "您的帳號 {$user['display_name']} 已解除 LINE 綁定。\n\n" .
            "💡 網站資料仍然保留\n" .
            "💡 如需再次使用 LINE Bot，請重新綁定\n\n" .
            "感謝使用 FitConnect！"
        );
    } catch (PDOException $e) {
        error_log("Unbind failed: " . $e->getMessage());
        replyText($replyToken, "❌ 解除綁定失敗，請稍後再試");
    }
}

// ========== C. 綁定帳號 ==========
function bindAccount($lineUserId, $code, $replyToken) {
    global $pdo;
    
    // 檢查是否已綁定
    $checkBound = $pdo->prepare("SELECT id FROM users WHERE line_user_id = ?");
    $checkBound->execute([$lineUserId]);
    if ($checkBound->fetch()) {
        replyText($replyToken, "✅ 您已經綁定過了！");
        return;
    }
    
    // 查詢綁定碼並檢查過期時間
    $stmt = $pdo->prepare("
        SELECT id, display_name, line_bind_code_expires_at 
        FROM users 
        WHERE line_bind_code = ?
    ");
    $stmt->execute([$code]);
    $user = $stmt->fetch();
    
    if ($user) {
        // 檢查綁定碼是否過期
        $expiresAt = $user['line_bind_code_expires_at'];
        if ($expiresAt) {
            $expiresTime = strtotime($expiresAt);
            $now = time();
            
            if ($expiresTime < $now) {
                replyText($replyToken, 
                    "❌ 綁定碼已過期\n\n" .
                    "請到網站重新產生綁定碼\n" .
                    "⏰ 綁定碼有效期限為 15 分鐘"
                );
                return;
            }
        }
        
        try {
            // 執行綁定
            $update = $pdo->prepare("
                UPDATE users 
                SET line_user_id = ?, 
                    line_bind_code = NULL,
                    line_bind_code_expires_at = NULL
                WHERE id = ?
            ");
            $update->execute([$lineUserId, $user['id']]);
            
            replyText($replyToken, 
                "✅ 綁定成功！\n\n" .
                "歡迎 {$user['display_name']}！\n" .
                "現在可以使用所有功能了 💪\n\n" .
                "輸入「選單」顯示主選單"
            );
        } catch (PDOException $e) {
            error_log("Bind failed: " . $e->getMessage());
            replyText($replyToken, "❌ 綁定失敗，請稍後再試");
        }
    } else {
        replyText($replyToken, 
            "❌ 綁定碼錯誤或已使用\n\n" .
            "請確認：\n" .
            "1️⃣ 綁定碼是否正確（6 位數字）\n" .
            "2️⃣ 綁定碼是否已經使用過\n" .
            "3️⃣ 綁定碼是否在 15 分鐘內\n\n" .
            "請到網站重新產生綁定碼"
        );
    }
}

// ========== 回覆文字訊息 ==========
function replyText($replyToken, $text) {
    replyMessage($replyToken, [["type" => "text", "text" => $text]]);
}

// ========== 回覆訊息 ==========
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