<?php
// linebot_with_datepicker.php - 帶日曆選擇器版本

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
    
    // 檢查是否為 4 位數綁定碼
    if (preg_match('/^\d{4}$/', $text)) {
        bindAccount($lineUserId, $text, $replyToken);
        return;
    }
    
    // 取得使用者狀態
    $stmt = $pdo->prepare("
        SELECT workout_type, workout_duration, edit_mode 
        FROM users 
        WHERE line_user_id = ?
    ");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        showMainMenu($replyToken, $lineUserId);
        return;
    }
    
    // === 運動輸入流程 ===
    
    // Step 2: 使用者選完類型，現在輸入時長
    if (!empty($user['workout_type']) && empty($user['workout_duration'])) {
        if (preg_match('/^\d+$/', $text)) {
            $duration = $text;
            // 儲存時長，顯示日期選擇器
            $update = $pdo->prepare("
                UPDATE users 
                SET workout_duration = ? 
                WHERE line_user_id = ?
            ");
            $update->execute([$duration, $lineUserId]);
            
            // 使用日期選擇器
            showDatePicker($replyToken, $user['workout_type'], $duration, $lineUserId);
            return;
        } else {
            replyText($replyToken, 
                "❌ 請輸入數字\n\n" .
                "例如：30（代表 30 分鐘）\n\n" .
                "或輸入「選單」返回主選單"
            );
            return;
        }
    }
    
    // === 個人資料編輯流程 ===
    
    // 編輯姓名
    if ($user['edit_mode'] == 'name') {
        saveProfileField($lineUserId, 'display_name', $text, $replyToken);
        return;
    }
    
    // 編輯身高
    if ($user['edit_mode'] == 'height') {
        if (preg_match('/^\d+$/', $text) && $text >= 1 && $text <= 300) {
            saveProfileField($lineUserId, 'height', $text, $replyToken);
            return;
        } else {
            replyText($replyToken, 
                "❌ 請輸入 1-300 之間的數字\n\n" .
                "例如：175\n\n" .
                "或輸入「選單」返回主選單"
            );
            return;
        }
    }
    
    // 編輯體重
    if ($user['edit_mode'] == 'weight') {
        if (preg_match('/^\d+$/', $text) && $text >= 1 && $text <= 500) {
            saveProfileField($lineUserId, 'weight', $text, $replyToken);
            return;
        } else {
            replyText($replyToken, 
                "❌ 請輸入 1-500 之間的數字\n\n" .
                "例如：70\n\n" .
                "或輸入「選單」返回主選單"
            );
            return;
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
            promptWorkoutDuration($replyToken, $lineUserId, $type);
            break;
            
        case 'workout_date':
            // 從日期選擇器返回
            $date = $params['date'] ?? '';
            saveWorkoutWithDate($lineUserId, $date, $replyToken);
            break;
            
        case 'edit_profile':
            showProfileEditOptions($replyToken, $lineUserId);
            break;
            
        case 'edit_name':
            promptNameInput($replyToken, $lineUserId);
            break;
            
        case 'edit_height':
            promptHeightInput($replyToken, $lineUserId);
            break;
            
        case 'edit_weight':
            promptWeightInput($replyToken, $lineUserId);
            break;
            
        case 'bind':
            showBindForm($replyToken, $lineUserId);
            break;
            
        case 'bound_menu':
            // 已綁定選單
            showBoundMenu($replyToken, $lineUserId);
            break;
            
        case 'unbind_confirm':
            // 確認解除綁定
            showUnbindConfirmation($replyToken);
            break;
            
        case 'unbind_yes':
            // 執行解除綁定
            unbindAccount($lineUserId, $replyToken);
            break;
            
        case 'unbind_no':
            // 取消解除綁定
            replyText($replyToken, "❌ 已取消解除綁定\n\n輸入「選單」返回主選單");
            break;
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
                    "data" => "action=edit_profile"
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
    $message = [
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
    
    replyMessage($replyToken, [$message]);
}

// ========== A. 提示輸入時長 ==========
function promptWorkoutDuration($replyToken, $lineUserId, $type) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        UPDATE users 
        SET workout_type = ?, workout_duration = NULL 
        WHERE line_user_id = ?
    ");
    $stmt->execute([$type, $lineUserId]);
    
    replyText($replyToken, 
        "📝 運動類型：{$type}\n\n" .
        "請輸入時長（分鐘）：\n\n" .
        "範例：\n" .
        "30\n" .
        "45\n" .
        "60\n\n" .
        "💡 直接輸入數字即可"
    );
}

// ========== A. 顯示日期選擇器 ==========
function showDatePicker($replyToken, $type, $duration, $lineUserId) {
    $today = date('Y-m-d');
    $maxDate = date('Y-m-d');
    $minDate = date('Y-m-d', strtotime('-30 days'));
    
    $message = [
        "type" => "template",
        "altText" => "選擇日期",
        "template" => [
            "type" => "buttons",
            "title" => "📅 選擇日期",
            "text" => "運動：{$type}\n時長：{$duration} 分鐘\n\n請選擇運動日期",
            "actions" => [
                [
                    "type" => "datetimepicker",
                    "label" => "📅 選擇日期",
                    "data" => "action=workout_date",
                    "mode" => "date",
                    "initial" => $today,
                    "max" => $maxDate,
                    "min" => $minDate
                ],
                [
                    "type" => "postback",
                    "label" => "今天",
                    "data" => "action=workout_date&date={$today}"
                ],
                [
                    "type" => "postback",
                    "label" => "昨天",
                    "data" => "action=workout_date&date=" . date('Y-m-d', strtotime('-1 day'))
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

// ========== A. 儲存運動（帶日期） ==========
function saveWorkoutWithDate($lineUserId, $date, $replyToken) {
    global $pdo;
    
    // 取得暫存的運動類型和時長
    $stmt = $pdo->prepare("
        SELECT workout_type, workout_duration 
        FROM users 
        WHERE line_user_id = ?
    ");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch();
    
    if (!$user || !$user['workout_type'] || !$user['workout_duration']) {
        replyText($replyToken, "❌ 發生錯誤，請重新輸入\n\n輸入「選單」返回主選單");
        return;
    }
    
    $type = $user['workout_type'];
    $duration = $user['workout_duration'];
    
    // 清除暫存
    $clear = $pdo->prepare("
        UPDATE users 
        SET workout_type = NULL, workout_duration = NULL 
        WHERE line_user_id = ?
    ");
    $clear->execute([$lineUserId]);
    
    // 儲存運動
    saveWorkout($lineUserId, $type, $duration, $date, $replyToken);
}

// ========== A. 儲存運動 ==========
function saveWorkout($lineUserId, $type, $duration, $date, $replyToken) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT id FROM users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        replyText($replyToken, "❌ 請先綁定帳號\n\n輸入「選單」顯示主選單");
        return;
    }
    
    $calories = $duration * 10;
    
    $stmt = $pdo->prepare("
        INSERT INTO workouts (user_id, date, type, minutes, calories)
        VALUES (?, ?, ?, ?, ?)
    ");
    
    try {
        $stmt->execute([$user['id'], $date, $type, $duration, $calories]);
        
        replyText($replyToken, 
            "✅ 運動記錄已新增！\n\n" .
            "🏃 {$type}\n" .
            "⏰ {$duration} 分鐘\n" .
            "🔥 {$calories} 大卡\n" .
            "📅 {$date}\n\n" .
            "輸入「選單」顯示主選單"
        );
    } catch (PDOException $e) {
        replyText($replyToken, "❌ 新增失敗，請稍後再試");
    }
}

// ========== B. 個人資料選項 ==========
function showProfileEditOptions($replyToken, $lineUserId) {
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
    $height = $user['height'] ? $user['height'] . ' cm' : '未設定';
    $weight = $user['weight'] ? $user['weight'] . ' kg' : '未設定';
    
    $message = [
        "type" => "template",
        "altText" => "個人資料",
        "template" => [
            "type" => "buttons",
            "title" => "👤 個人資料",
            "text" => "姓名：{$name}\n身高：{$height}\n體重：{$weight}\n\n請選擇要編輯的項目：",
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
function promptNameInput($replyToken, $lineUserId) {
    global $pdo;
    
    $stmt = $pdo->prepare("UPDATE users SET edit_mode = 'name' WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    
    replyText($replyToken, "✏️ 請輸入新的姓名：\n\n例如：Ray");
}

// ========== B. 編輯身高 ==========
function promptHeightInput($replyToken, $lineUserId) {
    global $pdo;
    
    $stmt = $pdo->prepare("UPDATE users SET edit_mode = 'height' WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    
    replyText($replyToken, 
        "📏 請輸入身高（公分）：\n\n" .
        "範例：\n" .
        "175\n" .
        "160\n" .
        "180\n\n" .
        "💡 直接輸入數字即可"
    );
}

// ========== B. 編輯體重 ==========
function promptWeightInput($replyToken, $lineUserId) {
    global $pdo;
    
    $stmt = $pdo->prepare("UPDATE users SET edit_mode = 'weight' WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    
    replyText($replyToken, 
        "⚖️ 請輸入體重（公斤）：\n\n" .
        "範例：\n" .
        "70\n" .
        "55\n" .
        "80\n\n" .
        "💡 直接輸入數字即可"
    );
}

// ========== B. 儲存個人資料 ==========
function saveProfileField($lineUserId, $field, $value, $replyToken) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT id FROM users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        replyText($replyToken, "❌ 請先綁定帳號");
        return;
    }
    
    $update = $pdo->prepare("UPDATE users SET {$field} = ?, edit_mode = NULL WHERE id = ?");
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
    
    replyText($replyToken, "🔗 請輸入 4 位數綁定碼");
}

// ========== C. 已綁定選單 ==========
function showBoundMenu($replyToken, $lineUserId) {
    global $pdo;
    
    // 取得使用者資訊
    $stmt = $pdo->prepare("
        SELECT display_name, line_bind_code 
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
    $code = $user['line_bind_code'] ?? '----';
    
    $message = [
        "type" => "template",
        "altText" => "綁定資訊",
        "template" => [
            "type" => "buttons",
            "title" => "✅ 已綁定",
            "text" => "帳號：{$name}\n綁定碼：{$code}\n\n要解除綁定嗎？",
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
    
    // 取得使用者資訊
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
    
    // 清除 LINE User ID
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
}

// ========== C. 綁定帳號 ==========
function bindAccount($lineUserId, $code, $replyToken) {
    global $pdo;
    
    $checkBound = $pdo->prepare("SELECT id FROM users WHERE line_user_id = ?");
    $checkBound->execute([$lineUserId]);
    if ($checkBound->fetch()) {
        replyText($replyToken, "✅ 您已經綁定過了！");
        return;
    }
    
    $stmt = $pdo->prepare("SELECT id, display_name FROM users WHERE line_bind_code = ?");
    $stmt->execute([$code]);
    $user = $stmt->fetch();
    
    if ($user) {
        $update = $pdo->prepare("UPDATE users SET line_user_id = ? WHERE id = ?");
        $update->execute([$lineUserId, $user['id']]);
        
        replyText($replyToken, 
            "✅ 綁定成功！\n\n" .
            "歡迎 {$user['display_name']}！\n" .
            "現在可以使用所有功能了 💪\n\n" .
            "輸入「選單」顯示主選單"
        );
    } else {
        replyText($replyToken, "❌ 綁定碼錯誤\n\n請確認綁定碼是否正確");
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