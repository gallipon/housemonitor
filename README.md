# housemonitor

自宅の環境データ（温度・湿度・気圧・在室状況）を収集・可視化するモニタリングシステム。

```
センサーデバイス                  Web サーバー
┌──────────────────┐  HTTPS POST  ┌──────────────┐      MySQL
│ Raspberry Pi      │─JSON+APIKey─→│ PHP API      │────→ 計測データ
│  ├ BME280 (I2C)   │              └──────────────┘
│  └ PIR (GPIO)     │                     ↑ AJAX（セッション認証）
│ M5Atom (PIR) ※    │              ┌──────────────┐
└──────────────────┘              │ ダッシュボード │ Chart.js でグラフ表示
                                   └──────────────┘
```

※ 在室検出は [m5atom-clock](https://github.com/gallipon/m5atom-clock)（時計デバイスに内蔵した人感センサー）からも送信される。

## 構成

| | 役割 | 技術 |
|---|---|---|
| `raspi/` | センサー側。BME280 から温湿度・気圧を取得、PIR (HC-SR501) で在室検知をカウントし、API へ送信 | Python (smbus2 / python-dotenv) |
| `server/api/` | 計測データ受信 API（`X-API-Key` 認証） | PHP + MySQL |
| `server/dashboard.php` | 時系列グラフのダッシュボード（ログイン認証） | PHP + Chart.js |

詳細なデータフロー・コンポーネント解説は [docs/architecture.md](docs/architecture.md) を参照。

## 実装メモ

- **フレームワークレスの素の PHP**: サーバー側は小規模（API 2本 + ダッシュボード）のため、フレームワークを使わず素の PHP で実装
- **BME280 の生データ補正**: 32 個のキャリブレーションパラメータを読み出し、データシートの補正式（temperature / pressure / humidity、`t_fine` 共有）を実装
- **API 認証**: `X-API-Key` ヘッダー + `hash_equals()` によるタイミング攻撃対策。不一致は 401
- **ダッシュボード認証**: PHP セッション + Remember Me（Cookie は Secure / HttpOnly / SameSite=Strict）、CSRF 対策、`session_regenerate_id()` によるセッション固定攻撃対策
- **設定の外部化**: 認証キー・DB 接続情報はすべて環境変数（`.env.example` 参照）。コードに秘密情報を含まない

## セットアップ

### Raspberry Pi 側

```bash
cd raspi
pip install -r requirements.txt
cp .env.example .env   # API URL・API キーを設定
python bme_280_raw.py  # 温湿度・気圧の送信（1回実行して疎通確認）
python pir3.py         # PIR 検知カウントの送信
```

各スクリプトは **1 回実行につき 1 回計測・送信** して終了します。継続的に記録するには cron などで定期実行してください。

```cron
# 10 分ごとに計測（.env を読ませるため、スクリプトをラップするか WorkingDirectory を合わせる）
0,10,20,30,40,50 * * * * cd /path/to/raspi && /usr/bin/python3 bme_280_raw.py
0,10,20,30,40,50 * * * * cd /path/to/raspi && /usr/bin/python3 pir3.py
```

### サーバー側

1. `server/` を PHP 実行環境に配置

2. MySQL にテーブルを作成

   ```bash
   mysql -u <user> -p <dbname> < server/sql/schema.sql             # センサーデータ用（必須）
   mysql -u <user> -p <dbname> < server/sql/setup_remember_me.sql  # ログイン保持を使う場合
   ```

   既に稼働中のDBで `measured_at` のインデックスが無い場合は、`server/sql/add_indexes.sql` を適用すると範囲検索が大幅に速くなります。

3. `server/.env.example` を参考に、Web サーバーの環境変数（`SetEnv` / `fastcgi_param`）で DB 接続情報・API キー・ダッシュボードパスワードを設定

   > 環境変数はバーチャルホスト単位で有効です。同じドキュメントルートを複数のドメインで配信している場合は、**各バーチャルホストに設定してください**。

### センサー死活監視（任意）

`server/check_sensors.php` は、センサーからのデータが一定時間途絶えたときに [ntfy.sh](https://ntfy.sh/) 経由でスマートフォンへ通知します。cron で定期実行してください。

```cron
# CLI 実行では Web サーバーの SetEnv が効かないため、cron 側で環境変数を定義する
DB_HOST=localhost
DB_USER=your_db_user
DB_PASS=your_db_password
DB_NAME=your_db_name
HOUSEMONITOR_NTFY_TOPIC=your_ntfy_topic_name

*/5 * * * * /usr/bin/php /var/www/html/check_sensors.php >> /var/log/housemonitor_check.log 2>&1
```

`HOUSEMONITOR_NTFY_TOPIC` は通知の宛先を兼ねる秘密情報です。推測されにくい文字列を設定してください。

## 開発について

機能追加・セキュリティ強化は Claude Code を併用して実施。

## ライセンス

MIT
