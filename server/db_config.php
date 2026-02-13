<?php
// データベース接続設定（環境変数から読み込み）
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'raspi');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'raspi');

function getDbConnection() {
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($mysqli->connect_errno) {
        error_log("Database connection error: " . $mysqli->connect_error);
        return null;
    }
    $mysqli->set_charset('utf8');
    return $mysqli;
}
