<?php
// update_db.php
// 這支檔案保留「初始化/確認資料表」用途（不刪原功能），但改成以 config.php 的建表邏輯為準，避免表結構不一致。

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// config.php 已經在連線後自動 CREATE TABLE IF NOT EXISTS
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'success' => true,
    'message' => 'DB schema ensured (via config.php).'
], JSON_UNESCAPED_UNICODE);
