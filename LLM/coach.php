<?php
// LLM/coach.php
require_once __DIR__ . '/config.php';

function askCoach($historyText, $userMessage) {
    $apiKey = OPENAI_API_KEY;
    if (!$apiKey) {
        return "錯誤：未設定 OPENAI_API_KEY。";
    }

    $systemPrompt = "你是一位專屬運動教練，只能回答運動、健身、飲食、睡眠等相關問題。
如果問題與運動無關，請固定回答一句：「這個問題和運動無關，我沒辦法回答喔。」
以下是使用者的近期運動紀錄，請參考這些資訊給予建議：
" . $historyText;

    $url = "https://api.openai.com/v1/chat/completions";
    
    $data = [
        "model" => "gpt-4o-mini", // 或 gpt-3.5-turbo
        "messages" => [
            ["role" => "system", "content" => $systemPrompt],
            ["role" => "user", "content" => $userMessage]
        ],
        "temperature" => 0.7
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . $apiKey
    ]);

    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        return "連線錯誤：" . curl_error($ch);
    }
    

    $json = json_decode($response, true);
    
    if (isset($json['choices'][0]['message']['content'])) {
        return $json['choices'][0]['message']['content'];
    } else {
        // 若需要除錯可在此紀錄錯誤
        return "AI 暫時無法回應，請稍後再試。";
    }
}
