<?php
/**
 * update_db.php
 * 這個檔案原本常被前端 AJAX 呼叫。
 * 為了「不改你原本前端」的情況下直接修復：
 * - 讓 update_db.php 變成 submit.php 的別名（同一套 JSON API）
 *
 * 你原本遇到的問題是：update_db.php 回傳純文字或 PHP 錯誤 HTML，
 * 前端拿去 JSON.parse 就會炸掉、圖表/排行榜就空白。
 */
require_once __DIR__ . '/submit.php';
