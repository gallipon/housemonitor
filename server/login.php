<?php
/**
 * ダッシュボードログインページ
 * パスワード認証後、セッションを発行してダッシュボードにリダイレクトする
 */

// HTTPS強制
if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
    $redirectURL = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header("Location: $redirectURL");
    exit();
}

// セッション設定（90日間有効）
$session_lifetime = 90 * 24 * 60 * 60; // 90日（秒）
ini_set('session.gc_maxlifetime', $session_lifetime);
session_set_cookie_params([
    'lifetime' => $session_lifetime,
    'path'     => '/',
    'secure'   => true,
    'httponly'  => true,
    'samesite'  => 'Strict',
]);
session_start();

// セキュリティヘッダー
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

// 既にログイン済みならダッシュボードへ
if (!empty($_SESSION['authenticated'])) {
    header('Location: dashboard.php');
    exit();
}

$error = '';

// POST: ログイン処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRFトークン検証
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        $error = '不正なリクエストです。';
    } else {
        $password = $_POST['password'] ?? '';
        $expected = getenv('HOUSEMONITOR_DASHBOARD_PASSWORD');

        if ($expected && hash_equals($expected, $password)) {
            // セッション固定攻撃対策
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            $_SESSION['login_time'] = time();
            header('Location: dashboard.php');
            exit();
        } else {
            $error = 'パスワードが正しくありません。';
        }
    }
}

// CSRFトークン生成
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>ログイン - お部屋モニタリング</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="assets/bootstrap.min.css">
    <style>
        body {
            background-color: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .login-form {
            width: 100%;
            max-width: 360px;
            padding: 15px;
        }
    </style>
</head>
<body>
<div class="login-form">
    <h3 class="text-center mb-4">お部屋モニタリング</h3>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
        <div class="form-group">
            <label for="password">パスワード</label>
            <input type="password" class="form-control" id="password" name="password" required autofocus>
        </div>
        <button type="submit" class="btn btn-primary btn-block">ログイン</button>
    </form>
</div>
</body>
</html>
