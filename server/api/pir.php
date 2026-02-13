<?php
/**
 * PIR（人感センサー）データ受信API（認証付き）
 * Raspberry PiからPOSTされた動体検知回数をDBに保存する
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
$required = ['count', 'sensor_no', 'measured'];
if (!$data || array_diff($required, array_keys($data))) {
    http_response_code(400);
    exit;
}

// データ挿入
$count       = $data['count'];
$sensor_no   = $data['sensor_no'];
$measured_at = $data['measured'];
$created_at  = date('Y-m-d H:i:s');

$stmt = $mysqli->prepare(
    "INSERT INTO hc_sr501 (sensor_no, count, measured_at, created_at) VALUES (?, ?, ?, ?)"
);
$stmt->bind_param('iiss', $sensor_no, $count, $measured_at, $created_at);

if (!$stmt->execute()) {
    error_log("PIR insert error: " . $stmt->error);
    http_response_code(500);
}

$stmt->close();
$mysqli->close();

http_response_code(200);
