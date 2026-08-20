# 同梱ライブラリ

ダッシュボードが参照するフロントエンドのライブラリ一式。
クローンしてすぐ動かせるようリポジトリに同梱している（すべて MIT / SIL OFL で再配布可）。

| ファイル | ライブラリ | バージョン | ライセンス |
|---|---|---|---|
| `chart.umd.min.js` | Chart.js | **4.5.1** | MIT |
| `chartjs-adapter-moment.min.js` | Chart.js 時間軸アダプタ（moment） | 1.0.1 | MIT |
| `chartjs-plugin-zoom.min.js` | chartjs-plugin-zoom | 2.2.0 | MIT |
| `hammer.min.js` | Hammer.js（タッチ操作。zoom プラグインが依存） | 2.0.8※ | MIT |

※ npm パッケージは 2.0.8 だが、配布されている dist のバナーは 2.0.7（上流のビルド由来。CDN 読み込み時と同じもの）。
| `jquery.min.js` | jQuery | 3.2.1 | MIT |
| `bootstrap.min.js` / `bootstrap.min.css` | Bootstrap | 4.2.1 | MIT |
| `moment.min.js` | Moment.js | — | MIT |
| `ja.js` | Moment.js 日本語ロケール | — | MIT |
| `tempusdominus-bootstrap-4.min.js` / `.css` | Tempus Dominus | 5.0.1 | MIT |
| `font-awesome-4.7.0/` | Font Awesome | 4.7.0 | コード: MIT / フォント: SIL OFL 1.1 |

外部 CDN への依存はない（すべてこのディレクトリから配信する）。

## 読み込み順序を崩さないこと

`dashboard.php` の `<script>` は次の順序に依存している。

```
moment → jquery → bootstrap → ja.js → tempusdominus
       → chart.umd.min.js → chartjs-adapter-moment.min.js
       → hammer.min.js → chartjs-plugin-zoom.min.js
```

- **アダプタは moment より後**: `chartjs-adapter-moment` は UMD で `window.moment` と
  `window.Chart` を参照する。Chart.js v4 は moment を同梱しないため、時間軸(`type: 'time'`)を
  使うにはアダプタが必須。moment 自体は Tempus Dominus が元々必要としている。
- **zoom プラグインは最後**: `window.Chart` / `window.Hammer` / `Chart.helpers` を参照し、
  読み込み時に `Chart.register()` で自動登録される（明示的な登録呼び出しは不要）。
- Hammer.js は zoom プラグインに同梱されていない（npm 上も外部依存のまま）ので個別に必要。

## グラフのドラッグ操作が依存している API

pan/zoom と過去への遅延ロードは、以下の Chart.js v3+ / plugin v2 の仕様に依存している。
バージョンを上げる際はここを確認すること。

- **表示範囲は `options.scales.x.min` / `.max`**。plugin の `updateRange()` もパン/ズーム結果を
  同じ `scaleOpts.min/max` へ書き戻すため、アプリ側とプラグイン側で参照先が一致する。
- **`chart.update('none')`** でアニメーション無しの即時再描画。
- **`resetZoom()` の戻り先は自動追従する**。plugin の `shouldUpdateScaleLimits()` が
  「プラグインが最後に書いた min/max」と現在の options を比較するため、アプリ側が
  `scales.x.min/max` を書き換えると次の pan/zoom で基準範囲を取り直す。
  内部プロパティに触る回避策は不要。

（Chart.js 2.7.3 + plugin 0.7.7 の時代は `time.min/max` の二重書き・`update(0)`・
`chart.$zoom._originalOptions` の直接リセットが必要だったが、いずれも解消済み。）
