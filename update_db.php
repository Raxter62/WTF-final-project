<?php
// update_db.php

require_once __DIR__ . '/config.php';  // 確保這裡會建立 $pdo

try {
    // users 表：使用者基本資料 + Line 綁定
    $sqlUsers = "
        CREATE TABLE IF NOT EXISTS users (
            id              SERIAL PRIMARY KEY,
            name            TEXT NOT NULL,
            height_cm       NUMERIC(5,2),
            weight_kg       NUMERIC(5,2),
            line_bind_code  CHAR(4),
            line_user_id    TEXT,
            created_at      TIMESTAMPTZ DEFAULT NOW()
        );
    ";
    $pdo->exec($sqlUsers);

    // workouts 表：運動紀錄
    $sqlWorkouts = "
        CREATE TABLE IF NOT EXISTS workouts (
            id              BIGSERIAL PRIMARY KEY,
            user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            sport_type      TEXT NOT NULL,
            input_time      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            duration_min    INTEGER NOT NULL,
            calories        INTEGER NOT NULL,
            created_at      TIMESTAMPTZ DEFAULT NOW()
        );
    ";
    $pdo->exec($sqlWorkouts);

    // index（加速查詢）
    $sqlIndex = "
        CREATE INDEX IF NOT EXISTS idx_workouts_user_time
        ON workouts (user_id, input_time);
    ";
    $pdo->exec($sqlIndex);

    // user_totals 表：每位使用者總消耗卡路里
    $sqlTotals = "
        CREATE TABLE IF NOT EXISTS user_totals (
            user_id         INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
            total_calories  BIGINT NOT NULL DEFAULT 0
        );
    ";
    $pdo->exec($sqlTotals);

    echo 'Database tables are ready.';
} catch (PDOException $e) {
    http_response_code(500);
    echo 'DB init error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}
