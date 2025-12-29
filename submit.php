<?php
// submit.php - Main API endpoint with full error handling
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

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
    } catch (PDOException $e) {
        throw new Exception('Database query test failed: ' . $e->getMessage());
    }
    
    // Helper functions
    function getJsonInput(): array {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
    
    function sendResponse(array $data): void {
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
        
        if ($email === '' || $pass === '') {
            sendResponse(['success' => false, 'message' => 'Please enter email and password']);
        }
        
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            sendResponse(['success' => false, 'message' => 'This email is already registered']);
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
    }
    
    if ($action === 'logout') {
        session_destroy();
        sendResponse(['success' => true, 'message' => 'Logged out']);
    }
    
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
        
        sendResponse(['success' => true, 'message' => 'Workout added']);
    }
    
    if ($action === 'get_stats') {
        if (!isset($_SESSION['user_id'])) {
            sendResponse(['success' => false, 'message' => 'Not logged in']);
        }
        
        $range = $_GET['range'] ?? '1d';
        
        // Calculate date range
        $days = 1;
        if ($range === '1wk') $days = 7;
        elseif ($range === '1m') $days = 30;
        elseif ($range === '3m') $days = 90;
        
        $startDate = date('Y-m-d', strtotime("-$days days"));
        
        // Daily stats
        $stmt = $pdo->prepare(
            'SELECT DATE(date) as date, SUM(minutes) as total FROM workouts 
             WHERE user_id = :uid AND DATE(date) >= :start 
             GROUP BY DATE(date) ORDER BY date ASC'
        );
        $stmt->execute([':uid' => $_SESSION['user_id'], ':start' => $startDate]);
        $daily = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Type stats
        $stmt = $pdo->prepare(
            'SELECT type, SUM(minutes) as total FROM workouts 
             WHERE user_id = :uid AND DATE(date) >= :start 
             GROUP BY type ORDER BY total DESC'
        );
        $stmt->execute([':uid' => $_SESSION['user_id'], ':start' => $startDate]);
        $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendResponse(['success' => true, 'daily' => $daily, 'types' => $types, 'range' => $range]);
    }
    
    if ($action === 'get_leaderboard') {
        $stmt = $pdo->prepare(
            'SELECT u.display_name, SUM(w.minutes) as total 
             FROM users u 
             JOIN workouts w ON u.id = w.user_id 
             WHERE DATE(w.date) >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY u.id 
             ORDER BY total DESC 
             LIMIT 10'
        );
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendResponse(['success' => true, 'data' => $data]);
    }
    
    // === LINE Bot Actions ===
    if ($action === 'generate_bind_code') {
        if (!isset($_SESSION['user_id'])) {
            sendResponse(['success' => false, 'message' => 'Not logged in']);
        }
        
        $code = strtoupper(bin2hex(random_bytes(3)));
        $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        
        $stmt = $pdo->prepare(
            'UPDATE users SET line_bind_code = :code, line_bind_code_expires_at = :exp WHERE id = :uid'
        );
        $stmt->execute([':uid' => $_SESSION['user_id'], ':code' => $code, ':exp' => $expires]);
        
        sendResponse(['success' => true, 'code' => $code, 'expires_at' => $expires]);
    }
    
    if ($action === 'line_unbind') {
        if (!isset($_SESSION['user_id'])) {
            sendResponse(['success' => false, 'message' => 'Not logged in']);
        }
        
        $stmt = $pdo->prepare('UPDATE users SET line_user_id = NULL WHERE id = :id');
        $stmt->execute([':id' => $_SESSION['user_id']]);
        
        sendResponse(['success' => true, 'message' => 'LINE account unbound']);
    }
    
    // === AI Coach Action ===
    if ($action === 'ai_coach') {
        if (!$coachAvailable) {
            sendResponse(['success' => false, 'message' => 'AI Coach not available']);
        }
        
        if (!isset($_SESSION['user_id'])) {
            sendResponse(['success' => false, 'message' => 'Not logged in']);
        }
        
        $message = trim($input['message'] ?? '');
        if ($message === '') {
            sendResponse(['success' => false, 'message' => 'Message cannot be empty']);
        }
        
        try {
            $response = handleCoachRequest($_SESSION['user_id'], $message, $pdo);
            sendResponse(['success' => true, 'response' => $response]);
        } catch (Exception $e) {
            sendResponse(['success' => false, 'message' => 'AI Coach error: ' . $e->getMessage()]);
        }
    }
    
    // Unknown action
    sendResponse(['success' => false, 'message' => 'Unknown action: ' . $action]);
    
} catch (PDOException $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'type' => 'PDOException'
    ], JSON_UNESCAPED_UNICODE);
    exit;
    
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'type' => 'Exception'
    ], JSON_UNESCAPED_UNICODE);
    exit;
    
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Fatal error: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'type' => 'Throwable'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}