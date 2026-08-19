# HouseMonitor アーキテクチャ

## ディレクトリ構成

```
housemonitor/
├── .gitignore
├── raspi/                  ← Raspberry Pi（センサー側）
│   ├── config.py           ← 設定（環境変数から読み込み、python-dotenv）
│   ├── .env.example        ← 環境変数テンプレート
│   ├── requirements.txt    ← Python依存パッケージ
│   ├── bme_280_raw.py      ← BME280から温度・湿度・気圧を取得
│   └── pir3.py             ← PIR人感センサー(HC-SR501)で検知回数をカウント
├── server/                 ← Webサーバー側
│   ├── .env.example        ← 環境変数テンプレート
│   ├── db_config.php       ← DB接続設定（環境変数から読み込み）
│   ├── api/
│   │   ├── bme280.php      ← BME280データ受信API（API Key認証付き）
│   │   └── pir.php         ← PIRデータ受信API（API Key認証付き）
│   ├── sql/
│   │   ├── schema.sql              ← センサーデータ用テーブル（必須）
│   │   ├── setup_remember_me.sql   ← ログイン保持用テーブル
│   │   └── add_indexes.sql         ← 既存DBへのインデックス追加（移行用）
│   ├── assets/             ← フロントエンドのライブラリ一式（同梱。README.md にバージョン記載）
│   ├── check_sensors.php   ← センサー死活監視（cron実行、ntfy.sh通知）
│   ├── login.php           ← ダッシュボードログインページ
│   ├── logout.php          ← ログアウト処理
│   └── dashboard.php       ← ダッシュボード（セッション認証付き）
└── docs/
    └── architecture.md     ← 構成・データフロー・技術スタック
```

## データフロー

```
Raspberry Pi                    Web Server
┌─────────────┐   HTTP POST    ┌──────────────┐     MySQL (db: raspi)
│ BME280(I2C) │──JSON+APIKey──→│ api/bme280   │────→ bme280テーブル
│ PIR(GPIO18) │──JSON+APIKey──→│ api/pir      │────→ hc_sr501テーブル
└─────────────┘                └──────────────┘
                                      ↑ AJAX（セッション認証済み）
                               ┌──────────────┐
                               │ dashboard    │ ← ダッシュボード
                               │ (Chart.js)   │   144件ずつ表示
                               └──────────────┘
                               ┌──────────────┐
                               │ login        │ ← ログインページ
                               └──────────────┘
```

## 設定管理

### Raspberry Pi 側（raspi/config.py）
python-dotenv で `.env` ファイルから読み込み、デフォルト値付き:
- `HOUSEMONITOR_API_URL` - APIサーバーのベースURL
- `HOUSEMONITOR_API_KEY` - API認証キー
- `HOUSEMONITOR_LOG_FILE` - PIRログファイルのパス

### サーバー側（server/db_config.php）
`getenv()` で環境変数から読み込み:
- `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`
- `HOUSEMONITOR_API_KEY` - センサーAPI認証キー
- `HOUSEMONITOR_DASHBOARD_PASSWORD` - ダッシュボードログインパスワード
- Apache: `SetEnv` または `.htaccess` で設定
- nginx: `fastcgi_param` で設定

## 認証方式

### センサーAPI（api/bme280.php, api/pir.php）
- `X-API-Key` カスタムヘッダーで認証
- サーバー側: `hash_equals()` でタイミング攻撃を防止
- 不一致時は 401 Unauthorized を返却

### ダッシュボード（dashboard.php）
- PHPセッションCookieによるログイン方式
- login.php でパスワード入力 → セッション発行（90日間有効）
- 未ログインなら login.php にリダイレクト
- CSRF対策、セッション固定攻撃対策（session_regenerate_id）対応
- Cookie設定: Secure, HttpOnly, SameSite=Strict

## コンポーネント詳細

### raspi/bme_280_raw.py
- I2Cバス1、アドレス0x76でBME280と通信（smbus2）
- 32個のキャリブレーションパラメータで生データを補正
- 主要関数: setup() → get_calib_param() → read_data() → send_data()
- 補正関数: compensate_t(), compensate_p(), compensate_h()（t_fineをグローバル共有）
- オーバーサンプリング: 1x、スタンバイ: 1000ms、フィルタ: off
- JSON POST → config.py の BME280_ENDPOINT
- 送信データ: `{"temperature", "humidity", "pressure", "measured"}`
- 1回実行で1回計測・送信（cronで定期実行を想定）
- リトライ: tries=10, delay=10, backoff=2 / timeout=30s

### raspi/pir3.py
- GPIO 18でPIRセンサー監視
- 595秒間、0.1秒間隔でLOW→HIGH遷移を検知しカウント
- 主要関数: main() → write_log() + send_data()
- 定数はモジュールレベルで定義（SLEEP_TIME, SENSOR_GPIO, MONITOR_TIME, SENSOR_NO）
- ローカルログ: config.py の LOG_FILE
- JSON POST → config.py の PIR_ENDPOINT
- 送信データ: `{"count", "sensor_no": 1, "measured"}`
- リトライ: tries=3, delay=5, backoff=2 / timeout=30s

### server/api/bme280.php（BME280 API・認証付き）
- X-API-Key 認証 → POSTメソッドチェック(405) → JSON解析 → バリデーション(400) → bme280テーブルにINSERT
- プリペアドステートメント・execute()エラーハンドリング対応済み

### server/api/pir.php（PIR API・認証付き）
- 構造はbme280.phpと同等（API Key認証・POSTチェック・バリデーション・エラーハンドリング済み）
- hc_sr501テーブルにINSERT

### server/login.php（ログインページ）
- パスワード認証 → セッション発行 → dashboard.php にリダイレクト
- CSRF対策、セッション固定攻撃対策、HTTPS強制

### server/dashboard.php（ダッシュボード・認証付き）
- セッション認証チェック、未ログインなら login.php にリダイレクト
- HTTPS強制、セキュリティヘッダー、CSRF対策、入力バリデーション
- GETパラメータ: `action=thp|pir`, `sensor_no`, `from`（基準日時）, `to`（範囲取得時）
  - `to` 省略時は `from` から過去24時間を返す（既定動作）
  - `from`/`to` 指定時はその範囲を返す。窓が2時間を超える場合、THPは5分平均にダウンサンプルする
    （派生テーブルで bucket を作ってから集計する。SELECT と GROUP BY に同じ複合式を直接書くと
    MySQL 8 の only_full_group_by で失敗するため）
- データ参照元は環境変数 `HOUSEMONITOR_BME280_TABLE` で差し替え可能（既定 `bme280`）。
  センサー個体のドリフト補正ビュー等を使う場合に指定する
- 部屋別タブ（部屋A/部屋B/まとめ）、時間ナビゲーション、最新値表示（上下矢印でトレンド）
- **グラフのドラッグ操作**: pan/zoom と、過去への遅延ロード（6時間チャンクを先頭に prepend）。
  `createLazyPanLoader` ファクトリで全5チャートに適用
- フロントエンド: Bootstrap 4, Chart.js **2.7.3**, Moment.js, jQuery, Tempus Dominus,
  chartjs-plugin-zoom 0.7.7 + hammerjs（CDN）

> **Chart.js は 2.7.3 固定。** pan/zoom の実装が v2 系 API（`time.min/max` による範囲制御、
> `update(0)` による再描画）に依存しているため、v3 以降へ上げると動作しない。
> 詳細は `server/assets/README.md` を参照。

### server/check_sensors.php（センサー死活監視）
- 各センサーの最終受信からの経過時間を `TIMESTAMPDIFF(MINUTE, MAX(measured_at), NOW())` で判定
  （PHP と MySQL でタイムゾーンがずれると誤検知するため、MySQL 側で差分を計算する）
- 閾値を超えたら ntfy.sh 経由でスマートフォンへ通知
- cron から CLI 実行する。Web サーバーの `SetEnv` は CLI に効かないため、
  DB接続情報と `HOUSEMONITOR_NTFY_TOPIC` は crontab 側で定義する

## 技術スタック

- **センサー側**: Python 3 / smbus2 / RPi.GPIO / urllib / retry / python-dotenv
- **サーバー側**: PHP 7+ / MySQL 8 (mysqli) / Bootstrap 4.2.1 / Chart.js 2.7.3 / Moment.js / jQuery 3.2.1 / Tempus Dominus 5.0.1 / Font Awesome 4.7
- **通知**: ntfy.sh（センサー死活監視）
