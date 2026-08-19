# 同梱ライブラリ

ダッシュボードが参照するフロントエンドのライブラリ一式。
クローンしてすぐ動かせるようリポジトリに同梱している（すべて MIT / SIL OFL で再配布可）。

| ファイル | ライブラリ | バージョン | ライセンス |
|---|---|---|---|
| `Chart.bundle.min.js` | Chart.js（moment 同梱版） | **2.7.3** | MIT |
| `jquery.min.js` | jQuery | 3.2.1 | MIT |
| `bootstrap.min.js` / `bootstrap.min.css` | Bootstrap | 4.2.1 | MIT |
| `moment.min.js` | Moment.js | — | MIT |
| `ja.js` | Moment.js 日本語ロケール | — | MIT |
| `tempusdominus-bootstrap-4.min.js` / `.css` | Tempus Dominus | 5.0.1 | MIT |
| `font-awesome-4.7.0/` | Font Awesome | 4.7.0 | コード: MIT / フォント: SIL OFL 1.1 |

## ⚠️ Chart.js のバージョンを上げないこと

**`Chart.bundle.min.js` は 2.7.3 で固定する必要がある。**

グラフのドラッグ操作（pan/zoom と過去への遅延ロード）は Chart.js **v2 系の API に依存**している。

- 表示範囲の制御に `options.scales.xAxes[0].time.min/max` を使う
  （v2.7 の time 軸は `ticks.min/max` を無視する）
- 再描画は `chart.update(0)`（`update('none')` は v3 以降の書き方で、v2 では描画されない）
- 併用している `chartjs-plugin-zoom` は **0.7.7**（v2 互換版。CDN から読み込み）

v3 以降へ上げる場合は、上記に加えて全チャートの設定（`xAxes`/`yAxes` 配列 → キー方式、`tooltips` →
`plugins.tooltip`、`gridLines` → `grid` 等）の書き換えが必要になる。

## CDN から読み込んでいるもの

以下は `dashboard.php` 内で CDN（jsDelivr）から読み込んでいる。

- `chartjs-plugin-zoom@0.7.7` — グラフの pan/zoom
- `hammerjs@2.0.8` — タッチ操作（ピンチ／ドラッグ）。上記プラグインが依存する

オフライン運用が必要な場合は、これらもこのディレクトリに配置して参照先を変更する。
