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
python bme_280_raw.py  # 温湿度・気圧の送信
python pir3.py         # PIR 検知カウントの送信
```

### サーバー側

1. `server/` を PHP 実行環境に配置し、MySQL にテーブルを作成
2. `server/.env.example` を参考に、Web サーバーの環境変数（`SetEnv` / `fastcgi_param`）で DB 接続情報・API キー・ダッシュボードパスワードを設定

## 開発について

機能追加・セキュリティ強化は Claude Code を併用して実施。

## ライセンス

MIT
