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

// データベース接続
require_once __DIR__ . '/db_config.php';
$mysqli = getDbConnection();

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
            $_SESSION['user_id'] = 1; // 固定ユーザーID
            $_SESSION['login_time'] = time();

            // 最終ログイン日時を更新
            if ($mysqli) {
                $stmt = $mysqli->prepare("UPDATE users SET last_login_at = NOW() WHERE id = 1");
                $stmt->execute();
                $stmt->close();
            }

            // Remember Me 処理
            $remember_me = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';

            if ($remember_me && $mysqli) {
                // ランダムトークン生成（64文字）
                $token = bin2hex(random_bytes(32));
                $expires_at = date('Y-m-d H:i:s', time() + (90 * 24 * 60 * 60)); // 90日後
                $device_info = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

                // トークンをDBに保存
                $stmt = $mysqli->prepare("INSERT INTO remember_tokens (user_id, token, expires_at, device_info, last_used_at) VALUES (?, ?, ?, ?, NOW())");
                $user_id = 1; // 固定ユーザーID
                $stmt->bind_param('isss', $user_id, $token, $expires_at, $device_info);

                if ($stmt->execute()) {
                    // Cookieに保存（90日有効）
                    setcookie('remember_token', $token, [
                        'expires' => time() + (90 * 24 * 60 * 60),
                        'path' => '/',
                        'secure' => true,
                        'httponly' => true,
                        'samesite' => 'Lax'
                    ]);
                }
                $stmt->close();

                // 古いトークンを削除（1ユーザーあたり最大10トークンまで）
                $stmt = $mysqli->prepare("
                    DELETE FROM remember_tokens
                    WHERE user_id = 1
                    AND id NOT IN (
                        SELECT id FROM (
                            SELECT id FROM remember_tokens
                            WHERE user_id = 1
                            ORDER BY created_at DESC
                            LIMIT 10
                        ) AS keep_tokens
                    )
                ");
                $stmt->execute();
                $stmt->close();
            }

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
        <div class="form-group form-check">
            <input type="checkbox" class="form-check-input" id="remember_me" name="remember_me" value="1" checked>
            <label class="form-check-label" for="remember_me">ログイン状態を保持する（90日間）</label>
        </div>
        <button type="submit" class="btn btn-primary btn-block">ログイン</button>
    </form>
</div>
</body>
</html>
