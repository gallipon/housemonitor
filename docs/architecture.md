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
│   ├── login.php           ← ダッシュボードログインページ
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
- GETパラメータ: action=thp|pir, offset(ページング), from(日時)
- プリペアドステートメントで144件取得（12時間分/5分間隔想定）
- フロントエンド: Bootstrap 4, Chart.js, Moment.js, jQuery, Tempus Dominus
- タブ切替（THP / PIR）、時間ナビゲーション（±1時間, ±10分, Now）
- 最新値表示（上下矢印でトレンド表示）

## 技術スタック

- **センサー側**: Python 3 / smbus2 / RPi.GPIO / urllib / retry / python-dotenv
- **サーバー側**: PHP 7+ / MySQL (mysqli) / Bootstrap 4 / Chart.js / Moment.js / jQuery / Font Awesome 4.7
