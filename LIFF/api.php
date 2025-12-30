<?php
// LIFF/api.php
require_once '../config.php';

header('Content-Type: application/json');

// Helper to get raw input
$input = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? $input['action'] ?? '';
$lineUserId = $input['lineUserId'] ?? '';

if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'DB Connection Failed']);
    exit;
}

if (!$lineUserId) {
    echo json_encode(['success' => false, 'message' => 'Missing Line User ID']);
    exit;
}

// Check binding status helper
function getUserByLineId($pdo, $lineId) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE line_user_id = ?");
    $stmt->execute([$lineId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Actions
try {
    if ($action === 'get_user_status') {
        $user = getUserByLineId($pdo, $lineUserId);
        if ($user) {
            echo json_encode([
                'success' => true,
                'bound' => true,
                'user' => [
                    'display_name' => $user['display_name'],
                    'height' => $user['height'],
                    'weight' => $user['weight']
                ]
            ]);
        } else {
            echo json_encode(['success' => true, 'bound' => false]);
        }
    }
    
    elseif ($action === 'bind_user') {
        $code = strtoupper(trim($input['code'] ?? ''));
        if (!$code) {
            throw new Exception("請輸入綁定碼");
        }
        
        $stmt = $pdo->prepare("SELECT id FROM users WHERE line_bind_code = ? AND line_bind_code_expires_at > NOW()");
        $stmt->execute([$code]);
        $user = $stmt->fetch();

        if ($user) {
            $update = $pdo->prepare("UPDATE users SET line_user_id = ?, line_bind_code = NULL, line_bind_code_expires_at = NULL WHERE id = ?");
            $update->execute([$lineUserId, $user['id']]);
            echo json_encode(['success' => true, 'message' => '綁定成功']);
        } else {
            throw new Exception("驗證碼錯誤或已過期");
        }
    }

    elseif ($action === 'unbind_user') {
        $update = $pdo->prepare("UPDATE users SET line_user_id = NULL WHERE line_user_id = ?");
        $update->execute([$lineUserId]);
        echo json_encode(['success' => true, 'message' => '已解除綁定']);
    }

    elseif ($action === 'add_workout') {
        $user = getUserByLineId($pdo, $lineUserId);
        if (!$user) throw new Exception("尚未綁定帳號");

        $type = $input['type'] ?? '';
        $minutes = (int)($input['minutes'] ?? 0);
        
        if (!$type || $minutes <= 0) throw new Exception("資料不完整");

        // Calculate Calories
        $MET_VALUES = [
            '跑步' => 10, '重訓' => 4, '腳踏車' => 8, '游泳' => 6, '瑜珈' => 3, '其他' => 2
        ];
        $met = $MET_VALUES[$type] ?? 2;
        $weight = (float)($user['weight'] ?? 0);
        
        $calories = 0;
        if ($weight > 0) {
            $calories = round((($met * 3.5 * $weight) / 200) * $minutes);
        }

        $stmt = $pdo->prepare("INSERT INTO workouts (user_id, date, type, minutes, calories) VALUES (?, NOW(), ?, ?, ?)");
        $stmt->execute([$user['id'], $type, $minutes, $calories]);

        // Trigger Achievement Check (Optional: copy/paste logic or include mail.php)
        // Ideally should refactor mail.php logic to be shared, but for now simple insert is consistent with linebot_webhook
        
        echo json_encode(['success' => true, 'message' => '紀錄已新增', 'calories' => $calories]);
    }

    elseif ($action === 'update_profile') {
        $user = getUserByLineId($pdo, $lineUserId);
        if (!$user) throw new Exception("尚未綁定帳號");

        $name = $input['display_name'] ?? $user['display_name'];
        $height = (float)($input['height'] ?? $user['height']);
        $weight = (float)($input['weight'] ?? $user['weight']);

        $stmt = $pdo->prepare("UPDATE users SET display_name = ?, height = ?, weight = ? WHERE id = ?");
        $stmt->execute([$name, $height, $weight, $user['id']]);

        echo json_encode(['success' => true, 'message' => '個人資料已更新']);
    }

    else {
        echo json_encode(['success' => false, 'message' => 'Invalid Action']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
