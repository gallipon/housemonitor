<?php
/**
 * BME280データ受信API（認証付き）
 * Raspberry PiからPOSTされた温度・湿度・気圧データをDBに保存する
 */
require_once __DIR__ . '/../db_config.php';

// API Key 認証
$api_key = getenv('HOUSEMONITOR_API_KEY');
$request_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!$api_key || !hash_equals($api_key, $request_key)) {
    http_response_code(401);
    exit;
}

// POSTメソッドのみ受け付ける
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// DB接続
$mysqli = getDbConnection();
if (!$mysqli) {
    http_response_code(500);
    exit;
}

// リクエストボディをJSONとしてパース
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// 必須フィールドのバリデーション
$required = ['temperature', 'humidity', 'pressure', 'measured'];
if (!$data || array_diff($required, array_keys($data))) {
    http_response_code(400);
    exit;
}

// データ挿入
$temperature = $data['temperature'];
$humidity    = $data['humidity'];
$pressure    = $data['pressure'];
$measured_at = $data['measured'];
$created_at  = date('Y-m-d H:i:s');

$stmt = $mysqli->prepare(
    "INSERT INTO bme280 (temperature, humidity, pressure, measured_at, created_at) VALUES (?, ?, ?, ?, ?)"
);
$stmt->bind_param('dddss', $temperature, $humidity, $pressure, $measured_at, $created_at);

if (!$stmt->execute()) {
    error_log("BME280 insert error: " . $stmt->error);
    http_response_code(500);
}

$stmt->close();
$mysqli->close();

http_response_code(200);
