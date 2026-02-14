-- Remember Me機能のためのテーブル作成
-- 実行方法: mysql -u root -p housemonitor < setup_remember_me.sql

-- ユーザーテーブル（1レコードのみ、将来の拡張用）
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- デフォルトユーザーを作成（パスワードは環境変数で管理するため保存しない）
INSERT INTO users (id, username) VALUES (1, 'admin')
ON DUPLICATE KEY UPDATE username = 'admin';

-- Remember Meトークンテーブル（複数デバイス対応）
CREATE TABLE IF NOT EXISTS remember_tokens (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    token VARCHAR(64) UNIQUE NOT NULL,      -- ランダムトークン（一意）
    expires_at DATETIME NOT NULL,           -- 有効期限（90日後）
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME,                  -- 最終利用日時
    device_info VARCHAR(255),               -- User-Agent（識別用）
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_expires (user_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 期限切れトークンの削除（メンテナンス用）
-- DELETE FROM remember_tokens WHERE expires_at < NOW();
