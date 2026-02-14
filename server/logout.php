<?php
/**
 * ログアウト処理
 * セッション破棄 + Remember Tokenの削除
 */

// HTTPS強制
if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
    $redirectURL = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header("Location: $redirectURL");
    exit();
}

// セッション開始
$session_lifetime = 90 * 24 * 60 * 60;
ini_set('session.gc_maxlifetime', $session_lifetime);
session_set_cookie_params([
    'lifetime' => $session_lifetime,
    'path'     => '/',
    'secure'   => true,
    'httponly'  => true,
    'samesite'  => 'Strict',
]);
session_start();

// データベース接続
require_once __DIR__ . '/db_config.php';
$mysqli = getDbConnection();

// Remember Tokenの削除（このデバイスのトークンのみ）
if (isset($_COOKIE['remember_token']) && $mysqli) {
    $token = $_COOKIE['remember_token'];

    // DBからトークンを削除
    $stmt = $mysqli->prepare("DELETE FROM remember_tokens WHERE token = ?");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->close();

    // Cookieを削除
    setcookie('remember_token', '', time() - 3600, '/', '', true, true);
}

// セッションを破棄
$_SESSION = [];

// セッションCookieを削除
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// ログインページにリダイレクト
header('Location: login.php');
exit();
