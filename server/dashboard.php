<?php
/**
 * ダッシュボード（グラフ表示UI）- セッション認証付き
 * ログイン済みユーザーのみアクセス可能
 */

// HTTPS強制
if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
    $redirectURL = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header("Location: $redirectURL");
    exit();
}

// セッション設定（90日間有効）
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

// データベース接続（トークン認証用）
require_once __DIR__ . '/db_config.php';
$mysqli = getDbConnection();

// セッション認証チェック
if (empty($_SESSION['authenticated'])) {
    // セッション認証が無効な場合、Remember Tokenをチェック
    $token_valid = false;

    if (isset($_COOKIE['remember_token']) && $mysqli) {
        $token = $_COOKIE['remember_token'];

        // DBでトークンを検証（有効期限内かつ存在するか）
        $stmt = $mysqli->prepare("
            SELECT user_id
            FROM remember_tokens
            WHERE token = ? AND expires_at > NOW()
        ");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            // トークンが有効 → セッション発行
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['login_time'] = time();
            $token_valid = true;

            // 最終利用日時を更新
            $stmt_update = $mysqli->prepare("UPDATE remember_tokens SET last_used_at = NOW() WHERE token = ?");
            $stmt_update->bind_param('s', $token);
            $stmt_update->execute();
            $stmt_update->close();

            // 最終ログイン日時を更新
            $stmt_login = $mysqli->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
            $stmt_login->bind_param('i', $row['user_id']);
            $stmt_login->execute();
            $stmt_login->close();
        }
        $stmt->close();
    }

    // トークンも無効ならログインページへ
    if (!$token_valid) {
        // 無効なトークンCookieがあれば削除
        if (isset($_COOKIE['remember_token'])) {
            setcookie('remember_token', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
        header('Location: login.php');
        exit();
    }
}

// セキュリティヘッダー設定
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

// CSRF トークン生成
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// データベース接続確認（トークン認証で既に接続済みの場合はスキップ）
if (!isset($mysqli) || !$mysqli) {
    require_once __DIR__ . '/db_config.php';
    $mysqli = getDbConnection();
    if (!$mysqli) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
        exit;
    }
}

// 入力値検証関数
function validateInput($value, $type) {
    switch ($type) {
        case 'action':
            return in_array($value, ['thp', 'pir'], true) ? $value : null;
        case 'sensor_no':
            $sensor_no = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 10]
            ]);
            return $sensor_no !== false ? $sensor_no : 1;
        case 'datetime':
            if (empty($value)) return date("Y-m-d H:i:s");
            // 日時形式の検証
            $datetime = DateTime::createFromFormat('Y-m-d H:i:s', $value);
            if ($datetime && $datetime->format('Y-m-d H:i:s') === $value) {
                // 未来の日時や異常な日時を制限
                $now = new DateTime();
                $min_date = new DateTime('2020-01-01');
                if ($datetime <= $now && $datetime >= $min_date) {
                    return $value;
                }
            }
            return date("Y-m-d H:i:s");
        case 'range_datetime':
            // 範囲取得(from/to)用の厳格な日時検証。不正なら null を返す
            // （'datetime' と異なり現在時刻へフォールバックしない）。
            if (!is_string($value) || $value === '') {
                return null;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
                return null;
            }
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $value);
            return ($dt && $dt->format('Y-m-d H:i:s') === $value) ? $value : null;
        default:
            return null;
    }
}

// BME280 データの参照元。既定は bme280 テーブル。
// センサー個体のドリフト補正ビュー等に差し替えたい場合は環境変数で指定する。
//   例) SetEnv HOUSEMONITOR_BME280_TABLE bme280_corrected
// 値はサーバー設定由来だが、識別子として使うため念のため書式を検証する。
$bme_source = getenv('HOUSEMONITOR_BME280_TABLE') ?: 'bme280';
if (!preg_match('/^[A-Za-z0-9_]+$/', $bme_source)) {
    $bme_source = 'bme280';
}
$bme_is_adjusted = ($bme_source !== 'bme280');

// ===== Ajax API 部分（セキュリティ強化） =====
if (isset($_GET['action'])) {
    // 入力値検証
    $action = validateInput($_GET['action'] ?? '', 'action');

    if (!$action) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Invalid action']);
        exit;
    }

    // --- 取得モード判定 ---
    // `to` が指定されていれば from/to の範囲取得モード（ドラッグで過去へ遡る遅延ロード用）。
    // `to` が無ければ従来どおり基準時刻(from)から過去24時間ウィンドウ（後方互換）。
    $range_mode = isset($_GET['to']) && $_GET['to'] !== '';
    $range_from = null;
    $range_to   = null;
    $downsample = false;
    $from       = null;

    if ($range_mode) {
        $range_from = validateInput(urldecode($_GET['from'] ?? ''), 'range_datetime');
        $range_to   = validateInput(urldecode($_GET['to'] ?? ''), 'range_datetime');
        // 片側のみ・書式不正・to<=from はいずれも 400
        if ($range_from === null || $range_to === null
            || strtotime($range_to) <= strtotime($range_from)) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Invalid from/to range']);
            exit;
        }
        // 窓長が2時間(7200秒)を超えるときはデータを間引く。
        // THP(連続値)は5分平均、PIR(離散イベント)は生データのまま返す。
        $downsample = (strtotime($range_to) - strtotime($range_from)) > 7200;
    } else {
        // 従来動作: 基準時刻。不正時は現在時刻へフォールバック。
        $from = validateInput(urldecode($_GET['from'] ?? ''), 'datetime');
    }

    try {
        if ($action === 'thp') {
            if ($range_mode) {
                if ($downsample) {
                    // 5分バケット平均。派生テーブルで bucket 列を先に作り、
                    // 外側で bucket により GROUP/SELECT する。SELECT と GROUP BY に
                    // 同一の複合式を直接書くと MySQL8 の only_full_group_by が
                    // 関数従属を認識せず Fatal error になるため、必ず派生テーブル方式にする。
                    $stmt = $mysqli->prepare(
                        "SELECT FROM_UNIXTIME(bucket * 300) AS measured_at,
                                AVG(temperature) AS temperature,
                                AVG(humidity)    AS humidity,
                                AVG(pressure)    AS pressure
                         FROM (
                             SELECT FLOOR(UNIX_TIMESTAMP(measured_at) / 300) AS bucket,
                                    temperature, humidity, pressure
                             FROM {$bme_source}
                             WHERE measured_at >= ? AND measured_at < ?
                         ) t
                         GROUP BY bucket
                         ORDER BY bucket ASC"
                    );
                } else {
                    $stmt = $mysqli->prepare(
                        "SELECT measured_at, temperature, humidity, pressure
                         FROM {$bme_source}
                         WHERE measured_at >= ? AND measured_at < ?
                         ORDER BY measured_at ASC"
                    );
                }
                $stmt->bind_param('ss', $range_from, $range_to);
            } else {
                // 基準時刻から過去24時間の時間範囲で取得（件数固定だと行数差で表示期間がずれるため）
                $stmt = $mysqli->prepare("SELECT * FROM {$bme_source} WHERE measured_at <= ? AND measured_at > DATE_SUB(?, INTERVAL 24 HOUR) ORDER BY measured_at ASC");
                $stmt->bind_param('ss', $from, $from);
            }
            $stmt->execute();
            $result = $stmt->get_result();

            // ダウンサンプル(平均)のときのみ小数丸め。生データ(従来動作含む)は精度を変えない。
            $round = ($range_mode && $downsample);
            $temps = $humids = $pressures = $measures = [];
            while ($row = $result->fetch_assoc()) {
                $temps[]     = $round ? round((float)$row['temperature'], 2) : (float)$row['temperature'];
                $humids[]    = $round ? round((float)$row['humidity'], 2)    : (float)$row['humidity'];
                $pressures[] = $round ? round((float)$row['pressure'], 2)     : (float)$row['pressure'];
                $measures[]  = htmlspecialchars($row['measured_at'], ENT_QUOTES, 'UTF-8');
            }
            $stmt->close();

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['temps'=>$temps,'humids'=>$humids,'pressures'=>$pressures,'measures'=>$measures]);
            exit;
        }

        if ($action === 'pir') {
            $sensor_no = validateInput($_GET['sensor_no'] ?? 1, 'sensor_no');
            if ($range_mode) {
                // PIRは離散イベントのため平均は不適切。範囲内の生データをそのまま返す。
                // `count` は予約語のためバッククォートで囲む。
                $stmt = $mysqli->prepare("SELECT `count`, measured_at FROM hc_sr501 WHERE sensor_no = ? AND measured_at >= ? AND measured_at < ? ORDER BY measured_at ASC");
                $stmt->bind_param('iss', $sensor_no, $range_from, $range_to);
            } else {
                // 基準時刻から過去24時間の時間範囲で取得（件数固定だと行数差で表示期間がずれるため）
                $stmt = $mysqli->prepare("SELECT * FROM hc_sr501 WHERE sensor_no = ? AND measured_at <= ? AND measured_at > DATE_SUB(?, INTERVAL 24 HOUR) ORDER BY measured_at ASC");
                $stmt->bind_param('iss', $sensor_no, $from, $from);
            }
            $stmt->execute();
            $result = $stmt->get_result();

            $counts = $measures = [];
            while ($row = $result->fetch_assoc()) {
                $counts[] = (int)$row['count'];
                $measures[] = htmlspecialchars($row['measured_at'], ENT_QUOTES, 'UTF-8');
            }
            $stmt->close();

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['counts'=>$counts,'measures'=>$measures]);
            exit;
        }
    } catch (Exception $e) {
        error_log("Database query error: " . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Database query failed']);
        exit;
    }
}
// ===== end Ajax =====
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>お部屋モニタリング</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="theme-color" content="#f4f3f0" media="(prefers-color-scheme: light)">
  <meta name="theme-color" content="#121211" media="(prefers-color-scheme: dark)">
  <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
  <meta name="script-name" content="<?php echo htmlspecialchars(basename($_SERVER['PHP_SELF']), ENT_QUOTES, 'UTF-8'); ?>">

  <!-- CSS -->
  <link rel="stylesheet" href="assets/bootstrap.min.css">
  <link rel="stylesheet" href="assets/font-awesome-4.7.0/css/font-awesome.min.css">
  <link href="assets/tempusdominus-bootstrap-4.min.css" rel="stylesheet" type="text/css">

  <!-- JS (順序注意: moment -> jquery -> bootstrap -> ja.js -> tempusdominus -> Chart) -->
  <script src="assets/moment.min.js"></script>
  <script src="assets/jquery.min.js"></script>
  <script src="assets/bootstrap.min.js"></script>
  <script src="assets/ja.js"></script>
  <script src="assets/tempusdominus-bootstrap-4.min.js"></script>
  <!-- Chart.js v4（UMD）。v4 は moment を同梱しないので時間軸アダプタを別途読み込む。
       moment 自体は Tempus Dominus が既に必要としているため追加コストは実質ゼロ。
       アダプタは window.moment と window.Chart の両方を参照するので、この順序を崩さないこと。 -->
  <script src="assets/chart.umd.min.js"></script>
  <script src="assets/chartjs-adapter-moment.min.js"></script>
  <!-- pan/zoom + iPadピンチ用。plugin は window.Chart / window.Hammer / Chart.helpers を
       参照し、読み込み時に Chart.register() で自動登録されるので明示登録は不要。 -->
  <script src="assets/hammer.min.js"></script>
  <script src="assets/chartjs-plugin-zoom.min.js"></script>

  <style>
    /* ===== デザイントークン =====
       ウォームグレー基調（純グレーより柔らかい）＋ 系列ごとの固定色。
       グラフの色も JS がここから読むので、色を変えるときはこのブロックだけ触ればよい。 */
    :root {
      color-scheme: light dark;
      --bg: #f4f3f0;
      --surface: #ffffff;
      --surface-2: #efede9;
      --border: #e3e0da;
      --text: #23221e;
      --text-2: #6b6862;
      --text-3: #9a968e;
      --accent: #2f6bd8;
      --accent-contrast: #ffffff;
      --grid: rgba(35, 34, 30, 0.07);
      --bar-bg: rgba(255, 255, 255, 0.86);
      --tooltip-bg: rgba(35, 34, 30, 0.92);
      --tooltip-text: #ffffff;
      --c-temp: #e0533b;
      --c-humid: #2f7fe0;
      --c-press: #13977a;
      --c-pir-a: #d9950a;
      --c-pir-b: #8a5cf0;
      --shadow: 0 1px 2px rgba(35, 34, 30, 0.04), 0 4px 14px rgba(35, 34, 30, 0.05);
      --radius: 14px;
      /* 和文: macOS/iOS はヒラギノ、Windows は system-ui 経由で Yu Gothic UI、Android は Noto Sans JP */
      --font-sans: system-ui, -apple-system, BlinkMacSystemFont, "Hiragino Sans", "Hiragino Kaku Gothic ProN",
                   "Noto Sans JP", "Yu Gothic UI", "Yu Gothic", Meiryo, sans-serif;
      --tabbar-h: 64px;
    }
    @media (prefers-color-scheme: dark) {
      :root {
        --bg: #121211;
        --surface: #1c1b19;
        --surface-2: #272623;
        --border: #34322e;
        --text: #efede8;
        --text-2: #aaa69e;
        --text-3: #7f7b74;
        --accent: #6c9dff;
        --accent-contrast: #0c1424;
        --grid: rgba(239, 237, 232, 0.08);
        --bar-bg: rgba(28, 27, 25, 0.86);
        --tooltip-bg: rgba(58, 56, 52, 0.96);
        --tooltip-text: #f5f3ef;
        --c-temp: #ff7b63;
        --c-humid: #62a6ff;
        --c-press: #3fcaa6;
        --c-pir-a: #f3b73e;
        --c-pir-b: #ad8dff;
        --shadow: 0 1px 2px rgba(0, 0, 0, 0.35);
      }
    }

    /* ===== ベース ===== */
    body {
      background: var(--bg);
      color: var(--text);
      font-family: var(--font-sans);
      line-height: 1.6;
      -webkit-font-smoothing: antialiased;
      -webkit-text-size-adjust: 100%;
    }
    button { font-family: inherit; }
    .num { font-variant-numeric: tabular-nums; }

    /* ===== ヘッダー ===== */
    .app-header {
      position: sticky;
      top: 0;
      z-index: 1020;
      background: var(--bar-bg);
      -webkit-backdrop-filter: saturate(1.4) blur(16px);
      backdrop-filter: saturate(1.4) blur(16px);
      border-bottom: 1px solid var(--border);
      padding-top: env(safe-area-inset-top);
    }
    .app-header__inner {
      max-width: 1320px;
      margin: 0 auto;
      padding: 0 16px;
      height: 56px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }
    .brand { display: flex; align-items: center; gap: 10px; min-width: 0; }
    .brand__mark {
      flex: none;
      width: 32px; height: 32px;
      border-radius: 9px;
      background: var(--accent);
      color: var(--accent-contrast);
      display: grid;
      place-items: center;
      font-size: 16px;
    }
    .brand__title {
      margin: 0;
      font-size: 16px;
      font-weight: 700;
      letter-spacing: 0.04em;
      font-feature-settings: "palt";
      white-space: nowrap;
    }
    .icon-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      height: 36px;
      padding: 0 12px;
      border-radius: 10px;
      border: 1px solid var(--border);
      background: var(--surface);
      color: var(--text-2);
      font-size: 13px;
      font-weight: 600;
      white-space: nowrap;
    }
    .icon-btn:hover, .icon-btn:focus { color: var(--text); background: var(--surface-2); text-decoration: none; }

    /* ===== メイン ===== */
    /* 画面の主役はグラフ。見出しや枠の余白は最小限にして、残りの高さをグラフに回す。 */
    .app-main {
      max-width: 1320px;
      margin: 0 auto;
      padding-inline: 16px;
      padding-block: 12px calc(var(--tabbar-h) + 20px + env(safe-area-inset-bottom));
    }
    /* タブ状態を URL の #roomA 等に保存しているため、リロード時にブラウザがその位置へスクロールする。
       固定ヘッダーの下に隠れないよう、ヘッダー高さ＋上余白ぶんずらす（= 実質スクロールしない位置）。 */
    .tab-pane { scroll-margin-top: calc(68px + env(safe-area-inset-top)); }
    .section-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 6px 12px;
      margin-bottom: 8px;
    }
    .section-meta { font-size: 12px; color: var(--text-3); font-variant-numeric: tabular-nums; }

    .panel {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 10px 8px 6px;
    }
    .panel + .panel, .stack > * + * { margin-top: 8px; }
    .panel__head {
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      gap: 8px;
      padding: 0 4px 6px;
    }
    .panel__title {
      margin: 0;
      font-size: 13px;
      font-weight: 700;
      letter-spacing: 0.04em;
      font-feature-settings: "palt";
    }
    /* 高さは「画面の高さの残り（ヘッダー・最新値・タブバーを引いた分）」と「横幅の40%」の大きい方。
       横長の低い画面でも、元の版（横幅の半分の高さ）に近い縦横比を保つ。上限は横幅の75%。
       dvh 非対応ブラウザ向けに vh 版を先に書く。 */
    .chart-box {
      position: relative;
      height: clamp(320px, max(calc(100vh - 270px), 40vw), min(760px, 75vw));
      height: clamp(320px, max(calc(100dvh - 270px), 40vw), min(760px, 75vw));
    }
    /* 注意: canvas に touch-action: pan-y を付けて縦スワイプをページスクロールに回す案は採らない。
       iPad Safari でピンチが途中で打ち切られ、縮小できなくなった（2026-09-14 実機で確認）。
       Hammer.js が付ける touch-action: none のままにしておくこと。 */
    .chart-box--sm {
      height: clamp(220px, max(calc((100vh - 330px) / 2), 26vw), min(440px, 45vw));
      height: clamp(220px, max(calc((100dvh - 330px) / 2), 26vw), min(440px, 45vw));
    }
    .note {
      display: flex;
      gap: 6px;
      margin: 8px 4px 0;
      font-size: 12px;
      color: var(--text-2);
      line-height: 1.5;
    }
    .note .fa { color: var(--text-3); margin-top: 3px; }

    /* ===== 最新値（1本の帯に区切り線で並べる。カードを分けるより縦横の余白が少ない） ===== */
    .stat-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      overflow: hidden;
    }
    .stat-grid--2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .stat {
      --stat-color: var(--accent);
      position: relative;
      padding: 8px 14px 9px;
      min-width: 0;
    }
    .stat + .stat { border-left: 1px solid var(--border); }
    .stat--temp  { --stat-color: var(--c-temp); }
    .stat--humid { --stat-color: var(--c-humid); }
    .stat--press { --stat-color: var(--c-press); }
    .stat--pir-a { --stat-color: var(--c-pir-a); }
    .stat--pir-b { --stat-color: var(--c-pir-b); }
    .stat__label {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 12px;
      font-weight: 600;
      color: var(--text-2);
      letter-spacing: 0.04em;
      font-feature-settings: "palt";
      white-space: nowrap;
    }
    .stat__label .fa { color: var(--stat-color); width: 14px; text-align: center; }
    .stat__value {
      display: flex;
      align-items: baseline;
      gap: 3px;
      margin-top: 2px;
      font-variant-numeric: tabular-nums;
      white-space: nowrap;
    }
    .stat__num {
      font-size: 26px;
      font-weight: 700;
      line-height: 1.2;
      letter-spacing: -0.01em;
    }
    .stat__num--time { font-size: 20px; }
    .stat__unit { font-size: 12px; font-weight: 600; color: var(--text-2); }
    .stat__trend { margin-left: auto; font-size: 15px; line-height: 1; color: var(--text-3); }
    .stat__trend.is-up, .stat__trend.is-down { color: var(--stat-color); }
    /* パネル内に置く帯（まとめタブ）は枠を消して面の色だけで区切る */
    .stat-grid--compact { background: var(--surface-2); border: 0; box-shadow: none; border-radius: 10px; margin: 0 2px; }
    .stat-grid--compact .stat + .stat { border-left-color: var(--bg); border-left-width: 2px; }
    .stat-grid--compact .stat { padding: 6px 12px 7px; }
    .stat-grid--compact .stat__num { font-size: 20px; }
    .stat-grid--compact .stat__num--time { font-size: 17px; }

    /* ===== PC: ホイールはグラフ上だと拡大に使われるので、ページをスクロールできる余白を左右に残す =====
       最大幅は元の版（Bootstrap container）と同じ 1140px。枠の内側の余白もスクロール可能な領域になる。 */
    @media (min-width: 992px) {
      .app-header__inner, .app-main { max-width: 1140px; padding-inline: 24px; }
      .panel { padding: 12px 20px 8px; }
      .stat-grid--compact { margin: 0; }
      .chart-box { height: clamp(320px, calc(100vh - 270px), 600px); height: clamp(320px, calc(100dvh - 270px), 600px); }
      .chart-box--sm { height: clamp(220px, calc((100vh - 330px) / 2), 360px); height: clamp(220px, calc((100dvh - 330px) / 2), 360px); }
    }

    /* ===== スマホ: 余白を詰めて、グラフの幅と高さを最大にする ===== */
    @media (max-width: 575.98px) {
      .app-header__inner { height: 48px; padding: 0 10px; }
      .brand__mark { width: 28px; height: 28px; font-size: 14px; border-radius: 8px; }
      /* 左右の余白はグラフ外でスクロールできる「つかみしろ」も兼ねるので、詰めすぎない */
      .app-main { padding-inline: 14px; padding-top: 8px; }
      .tab-pane { scroll-margin-top: calc(56px + env(safe-area-inset-top)); }
      .panel { padding: 8px 4px 4px; border-radius: 12px; }
      .stat-grid { border-radius: 12px; }
      .stat { padding: 6px 8px 7px; }
      .stat__label { font-size: 11px; gap: 4px; }
      .stat__label .fa { width: 11px; }
      .stat__num { font-size: 21px; }
      .stat__num--time { font-size: 17px; }
      .stat__unit { font-size: 11px; }
      .stat__trend { font-size: 13px; }
      .stat-grid--compact .stat { padding: 5px 8px 6px; }
      .stat-grid--compact .stat__num { font-size: 17px; }
      .stat-grid--compact .stat__num--time { font-size: 15px; }
      /* 縦長の画面で伸びすぎないよう、上限を横幅の125%にする（時刻操作が画面外へ押し出されないように） */
      .chart-box { height: clamp(280px, calc(100vh - 250px), 125vw); height: clamp(280px, calc(100dvh - 250px), 125vw); }
      .chart-box--sm { height: clamp(200px, calc((100vh - 300px) / 2), 75vw); height: clamp(200px, calc((100dvh - 300px) / 2), 75vw); }
      .time-panel { margin-top: 8px; }
    }

    /* ===== セグメント切替（部屋A のサブタブ） ===== */
    .segmented {
      display: inline-flex;
      gap: 2px;
      padding: 3px;
      border-radius: 10px;
      background: var(--surface-2);
    }
    .segmented__btn {
      appearance: none;
      border: 0;
      border-radius: 8px;
      background: transparent;
      color: var(--text-2);
      font-size: 13px;
      font-weight: 600;
      line-height: 1.4;
      padding: 6px 12px;
      cursor: pointer;
    }
    .segmented__btn.active {
      background: var(--surface);
      color: var(--text);
      box-shadow: 0 0 0 1px var(--border), 0 1px 2px rgba(0, 0, 0, 0.08);
    }
    .segmented__btn:focus { outline: none; }
    .segmented__btn:focus-visible { outline: 2px solid var(--accent); outline-offset: 1px; }

    /* ===== 時刻操作パネル ===== */
    .time-panel { margin-top: 12px; padding: 10px; }
    .time-nav { display: grid; grid-template-columns: 1fr 1fr 1.25fr 1fr 1fr; gap: 6px; }
    .tn-btn {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 5px;
      height: 42px;
      padding: 0 4px;
      border: 1px solid var(--border);
      border-radius: 10px;
      background: var(--surface);
      color: var(--text);
      font-size: 13px;
      font-weight: 600;
      white-space: nowrap;
      cursor: pointer;
      transition: background-color .15s ease, transform .08s ease;
    }
    .tn-btn .fa { color: var(--text-3); font-size: 15px; }
    .tn-btn:hover { background: var(--surface-2); }
    .tn-btn:active { transform: scale(0.97); }
    .tn-btn:focus { outline: none; }
    .tn-btn:focus-visible { outline: 2px solid var(--accent); outline-offset: 1px; }
    .tn-btn--now { background: var(--accent); border-color: var(--accent); color: var(--accent-contrast); }
    .tn-btn--now .fa { color: inherit; }
    .tn-btn--now:hover { background: var(--accent); filter: brightness(1.06); }
    @media (max-width: 399.98px) {
      .tn-btn:not(.tn-btn--now) .fa { display: none; }
    }
    .picker-row { display: flex; align-items: center; gap: 12px; margin-top: 10px; }
    .picker-label { flex: none; margin: 0; font-size: 12px; font-weight: 600; color: var(--text-2); }
    .picker-row .input-group { flex: 1; min-width: 0; }
    .picker-row .form-control,
    .picker-row .input-group-text {
      height: 42px;
      border-color: var(--border);
      background: var(--surface-2);
      color: var(--text);
    }
    .picker-row .form-control {
      border-radius: 10px 0 0 10px;
      font-size: 15px;
      font-variant-numeric: tabular-nums;
    }
    .picker-row .form-control:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(47, 107, 216, 0.18); }
    .picker-row .input-group-text { border-radius: 0 10px 10px 0; color: var(--text-2); cursor: pointer; }
    .hint { margin: 10px 2px 0; font-size: 11px; color: var(--text-3); text-align: center; line-height: 1.5; }

    /* Tempus Dominus のポップアップをテーマに合わせる */
    .bootstrap-datetimepicker-widget.dropdown-menu {
      background: var(--surface);
      color: var(--text);
      border: 1px solid var(--border);
      border-radius: 12px;
      box-shadow: 0 12px 32px rgba(0, 0, 0, 0.18);
    }
    .bootstrap-datetimepicker-widget.dropdown-menu.bottom:before { border-bottom-color: var(--border); }
    .bootstrap-datetimepicker-widget.dropdown-menu.bottom:after  { border-bottom-color: var(--surface); }
    .bootstrap-datetimepicker-widget.dropdown-menu.top:before    { border-top-color: var(--border); }
    .bootstrap-datetimepicker-widget.dropdown-menu.top:after     { border-top-color: var(--surface); }
    .bootstrap-datetimepicker-widget table td.day:hover,
    .bootstrap-datetimepicker-widget table td.hour:hover,
    .bootstrap-datetimepicker-widget table td.minute:hover,
    .bootstrap-datetimepicker-widget table td span:hover,
    .bootstrap-datetimepicker-widget table thead tr:first-child th:hover,
    .bootstrap-datetimepicker-widget .btn[data-action]:hover { background: var(--surface-2); }
    .bootstrap-datetimepicker-widget table td.active,
    .bootstrap-datetimepicker-widget table td.active:hover,
    .bootstrap-datetimepicker-widget table td span.active { background: var(--accent); color: var(--accent-contrast); }
    .bootstrap-datetimepicker-widget table td.old,
    .bootstrap-datetimepicker-widget table td.new { color: var(--text-3); }
    .bootstrap-datetimepicker-widget table td.today:before { border-bottom-color: var(--accent); }
    .bootstrap-datetimepicker-widget a[data-action],
    .bootstrap-datetimepicker-widget .btn { color: var(--accent); }
    .bootstrap-datetimepicker-widget .timepicker-hour,
    .bootstrap-datetimepicker-widget .timepicker-minute { color: var(--text); font-variant-numeric: tabular-nums; }

    /* ===== 下部タブバー ===== */
    .tabbar {
      position: fixed;
      left: 0; right: 0; bottom: 0;
      z-index: 1030;
      padding: 6px 12px calc(6px + env(safe-area-inset-bottom));
      background: var(--bar-bg);
      -webkit-backdrop-filter: saturate(1.4) blur(16px);
      backdrop-filter: saturate(1.4) blur(16px);
      border-top: 1px solid var(--border);
    }
    .tabbar .nav-tabs { max-width: 520px; margin: 0 auto; gap: 4px; border: 0; flex-wrap: nowrap; }
    .tabbar .nav-item { flex: 1; margin: 0; }
    .tabbar .nav-tabs .nav-link {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 3px;
      margin: 0;
      padding: 6px 4px;
      border: 0;
      border-radius: 10px;
      background: transparent;
      color: var(--text-3);
      font-size: 11px;
      font-weight: 600;
      letter-spacing: 0.04em;
      line-height: 1.2;
    }
    .tabbar .nav-tabs .nav-link .fa { font-size: 20px; line-height: 1; }
    .tabbar .nav-tabs .nav-link:hover { color: var(--text-2); }
    .tabbar .nav-tabs .nav-link.active { color: var(--accent); background: var(--surface-2); }

    /* ===== 通知（トースト） ===== */
    .alert-security {
      position: fixed;
      top: calc(12px + env(safe-area-inset-top));
      left: 50%;
      transform: translateX(-50%);
      z-index: 2000;
      width: max-content;
      max-width: min(92vw, 380px);
      border: 0;
      border-radius: 12px;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18);
      font-size: 14px;
    }
    @media (max-width: 399.98px) {
      .icon-btn__text { display: none; }
    }
  </style>
</head>
<body>
<header class="app-header">
  <div class="app-header__inner">
    <div class="brand">
      <span class="brand__mark" aria-hidden="true"><i class="fa fa-home"></i></span>
      <h1 class="brand__title">お部屋モニタリング</h1>
    </div>
    <a href="logout.php" class="icon-btn" aria-label="ログアウト">
      <i class="fa fa-sign-out" aria-hidden="true"></i><span class="icon-btn__text">ログアウト</span>
    </a>
  </div>
</header>

<main class="app-main">
  <!-- タブコンテンツ -->
  <div class="tab-content">
    <!-- 部屋A（温湿度・気圧 / 人感 切替） -->
    <section class="tab-pane fade show active" id="roomA">
      <div class="section-head">
        <div class="segmented" role="tablist" id="roomA-subtabs" aria-label="部屋A の表示切替">
          <button type="button" class="segmented__btn active" role="tab" aria-selected="true" data-subtab="thp">温湿度・気圧</button>
          <button type="button" class="segmented__btn" role="tab" aria-selected="false" data-subtab="pir-a">人感センサー</button>
        </div>
        <span class="section-meta" id="latest-measured-thp"></span>
      </div>

      <div id="roomA-thp" class="stack">
        <div class="stat-grid">
          <div class="stat stat--temp">
            <div class="stat__label"><i class="fa fa-thermometer-half" aria-hidden="true"></i>温度</div>
            <div class="stat__value"><span class="stat__num" id="latest-temp">-</span><span class="stat__unit">°C</span><span class="stat__trend" id="latest-temp-trend"></span></div>
          </div>
          <div class="stat stat--humid">
            <div class="stat__label"><i class="fa fa-tint" aria-hidden="true"></i>湿度</div>
            <div class="stat__value"><span class="stat__num" id="latest-humid">-</span><span class="stat__unit">%</span><span class="stat__trend" id="latest-humid-trend"></span></div>
          </div>
          <div class="stat stat--press">
            <div class="stat__label"><i class="fa fa-tachometer" aria-hidden="true"></i>気圧</div>
            <div class="stat__value"><span class="stat__num" id="latest-press">-</span><span class="stat__unit">hPa</span><span class="stat__trend" id="latest-press-trend"></span></div>
          </div>
        </div>
        <div class="panel">
          <div class="chart-box" id="chart-thp"></div>
<?php if ($bme_is_adjusted): ?>
          <p class="note" title="環境変数 HOUSEMONITOR_BME280_TABLE で補正済みデータ元が指定されています。">
            <i class="fa fa-info-circle" aria-hidden="true"></i><span>湿度はセンサー補正を適用した推定値です（生値ではありません）</span>
          </p>
<?php endif; ?>
        </div>
      </div>

      <div id="roomA-pir-a" class="stack" style="display: none;">
        <div class="stat-grid stat-grid--2">
          <div class="stat stat--pir-a">
            <div class="stat__label"><i class="fa fa-clock-o" aria-hidden="true"></i>最終検知</div>
            <div class="stat__value"><span class="stat__num stat__num--time" id="latest-measured-pir-a">-</span></div>
          </div>
          <div class="stat stat--pir-a">
            <div class="stat__label"><i class="fa fa-street-view" aria-hidden="true"></i>検知回数</div>
            <div class="stat__value"><span class="stat__num" id="latest-count-a">-</span><span class="stat__unit">回</span></div>
          </div>
        </div>
        <div class="panel">
          <div class="chart-box" id="chart-pir-a"></div>
        </div>
      </div>
    </section>

    <!-- 部屋B（人感のみ） -->
    <section class="tab-pane fade" id="roomB">
      <div class="stack">
        <div class="stat-grid stat-grid--2">
          <div class="stat stat--pir-b">
            <div class="stat__label"><i class="fa fa-clock-o" aria-hidden="true"></i>最終検知</div>
            <div class="stat__value"><span class="stat__num stat__num--time" id="latest-measured-pir-b">-</span></div>
          </div>
          <div class="stat stat--pir-b">
            <div class="stat__label"><i class="fa fa-street-view" aria-hidden="true"></i>検知回数</div>
            <div class="stat__value"><span class="stat__num" id="latest-count-b">-</span><span class="stat__unit">回</span></div>
          </div>
        </div>
        <div class="panel">
          <div class="chart-box" id="chart-pir-b"></div>
        </div>
      </div>
    </section>

    <!-- まとめ（温湿度気圧＋PIR比較） -->
    <section class="tab-pane fade" id="roomMix">
      <div class="stack">
        <div class="panel">
          <div class="panel__head">
            <h3 class="panel__title">温度・湿度・気圧（部屋A）</h3>
            <span class="section-meta num" id="mix-latest-measured"></span>
          </div>
          <div class="stat-grid stat-grid--compact">
            <div class="stat stat--temp">
              <div class="stat__label"><i class="fa fa-thermometer-half" aria-hidden="true"></i>温度</div>
              <div class="stat__value"><span class="stat__num" id="mix-latest-temp">-</span><span class="stat__unit">°C</span><span class="stat__trend" id="mix-latest-temp-trend"></span></div>
            </div>
            <div class="stat stat--humid">
              <div class="stat__label"><i class="fa fa-tint" aria-hidden="true"></i>湿度</div>
              <div class="stat__value"><span class="stat__num" id="mix-latest-humid">-</span><span class="stat__unit">%</span><span class="stat__trend" id="mix-latest-humid-trend"></span></div>
            </div>
            <div class="stat stat--press">
              <div class="stat__label"><i class="fa fa-tachometer" aria-hidden="true"></i>気圧</div>
              <div class="stat__value"><span class="stat__num" id="mix-latest-press">-</span><span class="stat__unit">hPa</span><span class="stat__trend" id="mix-latest-press-trend"></span></div>
            </div>
          </div>
          <div class="chart-box chart-box--sm mt-2" id="chart-mix-thp"></div>
        </div>

        <div class="panel">
          <div class="panel__head"><h3 class="panel__title">人感センサー比較（部屋A・B）</h3></div>
          <div class="stat-grid stat-grid--2 stat-grid--compact">
            <div class="stat stat--pir-a">
              <div class="stat__label"><i class="fa fa-circle" aria-hidden="true"></i>部屋A 最終検知</div>
              <div class="stat__value"><span class="stat__num stat__num--time" id="mix-latest-a">-</span><span class="stat__unit" id="mix-latest-a-count"></span></div>
            </div>
            <div class="stat stat--pir-b">
              <div class="stat__label"><i class="fa fa-circle" aria-hidden="true"></i>部屋B 最終検知</div>
              <div class="stat__value"><span class="stat__num stat__num--time" id="mix-latest-b">-</span><span class="stat__unit" id="mix-latest-b-count"></span></div>
            </div>
          </div>
          <div class="chart-box chart-box--sm mt-2" id="chart-mix-pir"></div>
        </div>
      </div>
    </section>
  </div>

  <!-- 時刻操作（時間ナビゲーション＋日時ピッカー） -->
  <section class="panel time-panel" aria-label="表示する時刻">
    <div class="time-nav" role="group" aria-label="表示時刻の移動">
      <button type="button" class="tn-btn" id="time-back-1h" aria-label="1時間前へ">
        <i class="fa fa-angle-double-left" aria-hidden="true"></i><span>1時間</span>
      </button>
      <button type="button" class="tn-btn" id="time-back-10m" aria-label="10分前へ">
        <i class="fa fa-angle-left" aria-hidden="true"></i><span>10分</span>
      </button>
      <button type="button" class="tn-btn tn-btn--now" id="time-now">
        <i class="fa fa-clock-o" aria-hidden="true"></i><span>現在</span>
      </button>
      <button type="button" class="tn-btn" id="time-forward-10m" aria-label="10分後へ">
        <span>10分</span><i class="fa fa-angle-right" aria-hidden="true"></i>
      </button>
      <button type="button" class="tn-btn" id="time-forward-1h" aria-label="1時間後へ">
        <span>1時間</span><i class="fa fa-angle-double-right" aria-hidden="true"></i>
      </button>
    </div>

    <div class="picker-row">
      <label class="picker-label" for="datetime-input">基準時刻</label>
      <div class="input-group date" id="datetimepicker-common" data-target-input="nearest">
        <input id="datetime-input" type="text" class="form-control datetimepicker-input" data-target="#datetimepicker-common"/>
        <div class="input-group-append" data-target="#datetimepicker-common" data-toggle="datetimepicker">
          <div class="input-group-text"><i class="fa fa-calendar" aria-hidden="true"></i></div>
        </div>
      </div>
    </div>
    <p class="hint">グラフはドラッグで過去へ移動・ピンチ／ホイールで拡大・ダブルクリックで元に戻ります</p>
  </section>
</main>

<!-- 下部タブ -->
<nav class="tabbar" aria-label="表示の切り替え">
  <ul class="nav nav-tabs">
    <li class="nav-item">
      <a class="nav-link active" data-toggle="tab" href="#roomA"><i class="fa fa-thermometer-half" aria-hidden="true"></i>部屋A</a>
    </li>
    <li class="nav-item">
      <a class="nav-link" data-toggle="tab" href="#roomB"><i class="fa fa-street-view" aria-hidden="true"></i>部屋B</a>
    </li>
    <li class="nav-item">
      <a class="nav-link" data-toggle="tab" href="#roomMix"><i class="fa fa-th-large" aria-hidden="true"></i>まとめ</a>
    </li>
  </ul>
</nav>

<script>
/* ---- セキュリティ設定 ---- */
// CSRFトークン取得
const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
// スクリプト名を動的取得
const scriptName = document.querySelector('meta[name="script-name"]').getAttribute('content');

// セキュリティ関連の設定
$.ajaxSetup({
    beforeSend: function(xhr, settings) {
        // CSRF保護（POSTリクエスト用）
        if (settings.type === 'POST') {
            xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken);
        }
    },
    timeout: 10000, // 10秒でタイムアウト
    error: function(xhr, status, error) {
        if (xhr.status >= 500) {
            showSecurityAlert('サーバーエラーが発生しました。', 'danger');
        } else if (status === 'timeout') {
            showSecurityAlert('リクエストがタイムアウトしました。', 'warning');
        }
    }
});

// セキュリティアラート表示
function showSecurityAlert(message, type = 'info') {
    const alertHtml = `
        <div class="alert alert-${type} alert-dismissible alert-security" role="alert">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            ${message}
        </div>
    `;
    $('body').append(alertHtml);

    // 5秒後に自動削除
    setTimeout(() => {
        $('.alert-security').fadeOut();
    }, 5000);
}

/* ---- Chart & Ajax handling ---- */
let chartTHP = null;
let chartPIR_A = null;
let chartPIR_B = null;
let chartMixTHP = null;
let chartMixPIR = null;

// チャート設定の共通オプション
// animation: false は必須。plugin 0.7.7 はズーム時に chart.update(0)（アニメ無し）を
// 呼んでいたが、plugin 2.x は chart.update('zoom') を呼ぶため animation.duration を継承し、
// ズーム中に全点が250msかけて横に滑るようになる。その最中に遅延ロードで点が増減すると
// 描画が乱れることがある。
// なお公式ドキュメントの transitions.zoom.animation.duration = 0 と animations.x = false は
// この組み合わせでは効かず（実測でズーム時も246msのアニメーションが残った）、
// animation: false だけが確実に無効化できた。副作用としてデータ読み込み時の演出も消える。
/* ---- テーマ（色は CSS 変数が唯一の正。ダーク/ライト切替に追従する） ---- */
// 色系のオプションはすべて「THEME を読む関数（scriptable option）」で渡す。
// OS のテーマが変わったら THEME を読み直して update するだけで全チャートに反映される。
function readTheme() {
  const cs = getComputedStyle(document.documentElement);
  const v = (name) => cs.getPropertyValue(name).trim();
  return {
    font: v('--font-sans'),
    text2: v('--text-2'),
    text3: v('--text-3'),
    grid: v('--grid'),
    border: v('--border'),
    surface: v('--surface'),
    tooltipBg: v('--tooltip-bg'),
    tooltipText: v('--tooltip-text'),
    series: {
      temp: v('--c-temp'), humid: v('--c-humid'), press: v('--c-press'),
      pirA: v('--c-pir-a'), pirB: v('--c-pir-b')
    }
  };
}
let THEME = readTheme();

// "#rrggbb" に透明度を付ける（CSS 変数は16進で定義している前提）
function withAlpha(hex, a) {
  const m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);
  if (!m) return hex;
  return `rgba(${parseInt(m[1], 16)},${parseInt(m[2], 16)},${parseInt(m[3], 16)},${a})`;
}

Chart.defaults.font.family = THEME.font;

// 軸の文字サイズ。スマホ幅では1px小さくして、目盛りに取られる幅を描画領域へ回す。
function isNarrowChart(ctx) {
  return ctx.chart && ctx.chart.width < 520;
}
function axisFont(ctx) {
  return { size: isNarrowChart(ctx) ? 10 : 11 };
}

// ツールチップの1行: 「温度 24.3°C」。単位と小数桁は dataset に持たせた unit / decimals を使う。
function tooltipLabel(ctx) {
  const d = ctx.dataset;
  const y = ctx.parsed.y;
  if (y === null || y === undefined || isNaN(y)) return d.label;
  const val = (typeof d.decimals === 'number') ? y.toFixed(d.decimals) : y;
  return ` ${d.label}  ${val}${d.unit || ''}`;
}

const CHART_COMMON_OPTIONS = {
  animation: false,
  // 高さは親の .chart-box（CSS）で決める。画面幅ごとに高さを変えられるようにするため。
  maintainAspectRatio: false,
  interaction: { mode: 'index', intersect: false },
  layout: { padding: { top: 2, right: 2 } },
  scales: {
    x: {
      type: 'time',
      time: {
        unit: 'hour',
        displayFormats: { hour: 'MM/DD HH:mm' },
        tooltipFormat: 'YYYY-MM-DD HH:mm:ss'
      },
      ticks: { maxRotation: 0 }
    }
  },
  plugins: {
    legend: {
      align: 'end',
      labels: {
        color: () => THEME.text2,
        usePointStyle: true,
        pointStyle: 'circle',
        boxWidth: 8,
        boxHeight: 8,
        padding: 10,
        font: { size: 12, weight: '600' }
      }
    },
    tooltip: {
      mode: 'index',
      intersect: false,
      backgroundColor: () => THEME.tooltipBg,
      titleColor: () => THEME.tooltipText,
      bodyColor: () => THEME.tooltipText,
      titleFont: { weight: '600' },
      padding: 10,
      cornerRadius: 8,
      usePointStyle: true,
      boxPadding: 4,
      callbacks: { label: tooltipLabel }
    }
  },
  responsive: true
};

// 線グラフ1系列の共通スタイル。点は描かず（数千点あると潰れて見えるため）、ホバー時だけ出す。
function lineDataset(label, seriesKey, unit, decimals, extra) {
  return Object.assign({
    label: label,
    data: [],
    unit: unit,
    decimals: decimals,
    borderColor: () => THEME.series[seriesKey],
    backgroundColor: () => withAlpha(THEME.series[seriesKey], 0.14),
    borderWidth: 2,
    pointRadius: 0,
    pointHitRadius: 8,
    pointHoverRadius: 4,
    pointHoverBorderWidth: 2,
    pointHoverBackgroundColor: () => THEME.surface,
    fill: false
  }, extra || {});
}

// 値軸（y）。seriesKey を渡すと目盛りをその系列色にする（3軸グラフで対応が分かるように）。
function makeValueAxis(position, seriesKey, extra) {
  return Object.assign({
    position: position,
    ticks: {
      color: () => (seriesKey ? THEME.series[seriesKey] : THEME.text3),
      maxTicksLimit: 6,
      padding: 4,
      font: axisFont,
      // 既定の書式は桁区切り付き（"1,010.4"）で、右側の軸が幅を取るため区切りを外す
      callback: (value) => String(Math.round(value * 100) / 100)
    },
    grid: { color: () => THEME.grid, drawTicks: false },
    border: { display: false }
  }, extra || {});
}

// OS のテーマ変更に追従（ページを開いたまま夜間にダークへ切り替わる端末向け）
function refreshChartTheme() {
  THEME = readTheme();
  [chartTHP, chartPIR_A, chartPIR_B, chartMixTHP, chartMixPIR].forEach(c => { if (c) c.update('none'); });
}
if (window.matchMedia) {
  const mql = window.matchMedia('(prefers-color-scheme: dark)');
  if (mql.addEventListener) mql.addEventListener('change', refreshChartTheme);
  else if (mql.addListener) mql.addListener(refreshChartTheme);
}

/* ---- pan/zoom 共通ヘルパー（Chart.js 4.5.1 + chartjs-plugin-zoom@2.2.0） ---- */
// zoomKey を渡すと pan/zoom + 遅延ロードを有効化。v4 では表示範囲が scales.x.min/max に
// 一本化されており、プラグインもそこへ書き戻すのでダミーの ticks は不要。
// tooltipFormat 未指定だとアダプタ既定の英語書式（"Aug 20, 2026, 6:32:59 pm" / "12 PM"）に
// なるため明示する。軸の目盛りは displayFormats、ツールチップは tooltipFormat が担当。
//
// maxRotation: 0 は v2.7.3 と同じ見た目を保つために必要。ラベルが入り切らないとき、
// v2 は「間引く」だけだったが v3 以降は先に「傾ける」ため、既定のままだと1時間おきの
// ラベルが 34度 傾いて並ぶ。0 を指定すると傾けられなくなり、v2 と同じく autoSkip が
// 間引いて（24h 表示なら2時間おき）水平に並ぶ。表示幅に応じた自動調整は維持される。
function makeTimeXAxis() {
  return {
    type: 'time',
    time: {
      unit: 'hour',
      displayFormats: { hour: 'MM/DD HH:mm' },
      tooltipFormat: 'YYYY-MM-DD HH:mm:ss'
    },
    ticks: { maxRotation: 0, autoSkipPadding: 12, color: () => THEME.text3, font: axisFont },
    grid: { display: false },
    border: { color: () => THEME.border }
  };
}

// zoom プラグイン設定（options.plugins.zoom）。完了コールバックは引数形状に依存せず、
// zoomKey で登録済みのローダーを registry から引いて起動する。
// v2 では zoom.enabled が廃止され、wheel / pinch / drag を個別に指定する。
/* ---- グラフ上のタッチ縦スクロール ---- */
// Hammer.js が canvas に touch-action: none を付けるため、グラフ上で縦にスワイプしても
// ブラウザはページをスクロールしない。touch-action: pan-y にすると iPad Safari でピンチが
// 途中で打ち切られた（2026-09-14）ので、touch-action には触らずに次のようにする:
//   - 縦横の判定は Hammer の pan 開始時（zoom プラグインの onPanStart）に行う
//   - 縦なら pan を取り消し、以後の touchmove の移動量だけ JS でページをスクロールする
//   - 指を離したときの速度で慣性スクロールする
// 2本指（ピンチ）とマウス操作は判定の対象外なので、従来どおりグラフが受け取る。
const touchScroll = (function() {
  let active = false;   // この指の操作をスクロールとして扱っているか
  let lastY = 0;
  let lastT = 0;
  let velocity = 0;     // px/ms（上方向スクロールが正）
  let raf = 0;

  function stopInertia() {
    if (raf) cancelAnimationFrame(raf);
    raf = 0;
  }
  function onStart(e) {
    active = false;
    if (e.touches.length === 1) {
      lastY = e.touches[0].clientY;
      lastT = e.timeStamp;
      velocity = 0;
    }
  }
  function onMove(e) {
    if (e.touches.length !== 1) { active = false; return; }
    const y = e.touches[0].clientY;
    if (active) {
      const dy = lastY - y;
      window.scrollBy(0, dy);
      const dt = Math.max(1, e.timeStamp - lastT);
      velocity = 0.8 * (dy / dt) + 0.2 * velocity;
    }
    lastY = y;
    lastT = e.timeStamp;
  }
  function onEnd(e) {
    if (!active) return;
    active = false;
    if (e.timeStamp - lastT > 80) return;  // 指を止めてから離したときは滑らせない
    let v = velocity;
    let prev = performance.now();
    const step = (now) => {
      const dt = now - prev;
      prev = now;
      window.scrollBy(0, v * dt);
      v *= Math.pow(0.95, dt / 16);
      raf = Math.abs(v) > 0.02 ? requestAnimationFrame(step) : 0;
    };
    raf = requestAnimationFrame(step);
  }
  // 慣性スクロール中にどこかを触ったら止める（ネイティブのスクロールと同じ振る舞い）
  document.addEventListener('touchstart', stopInertia, { passive: true });

  return {
    attach: function(canvas) {
      canvas.addEventListener('touchstart', onStart, { passive: true });
      canvas.addEventListener('touchmove', onMove, { passive: true });
      canvas.addEventListener('touchend', onEnd, { passive: true });
      canvas.addEventListener('touchcancel', () => { active = false; }, { passive: true });
    },
    // Hammer の panstart イベントを受け取り、縦方向のタッチなら true（= pan を取り消す）。
    claim: function(hammerEvent) {
      if (!hammerEvent || hammerEvent.pointerType !== 'touch') return false;
      if (Math.abs(hammerEvent.deltaY) <= Math.abs(hammerEvent.deltaX)) return false;
      active = true;
      return true;
    }
  };
})();

/* ---- タッチ操作時のツールチップ ---- */
// Chart.js は touchstart / touchmove でツールチップを出すため、スクロールやパンのために触っただけで
// ポップアップが出て、指を離しても残り、下のグラフを隠していた。タッチのときだけ次のように変える:
//   - 指を置いた・動かしただけでは出さない（ドラッグやピンチを始めたら表示中のものも消す）
//   - 動かさずに離した（タップ）ときだけ、その位置の値を出す
//   - 表示中にもう一度タップすると消す。グラフの外をタップしても消す
// マウス（PC）のホバー表示は従来どおり。
const TAP_MOVE_PX = 10;
let lastTouchAt = 0;  // タップ後にブラウザが出す互換マウスイベント（mousemove/click）を無視するため

function isTooltipVisible(chart) {
  return !!(chart.tooltip && chart.tooltip.getActiveElements().length > 0);
}
function hideTooltip(chart) {
  if (!isTooltipVisible(chart)) return;
  chart.tooltip.setActiveElements([], { x: 0, y: 0 });
  chart.setActiveElements([]);
  chart.update('none');
}
// canvas 内座標 (x, y) に最も近い時刻の値をツールチップで出す（interaction: index と同じ選び方）
function showTooltipAt(chart, x, y) {
  // 'native' を持つオブジェクトは Chart.js が座標をそのまま使う（ChartEvent と同じ扱い）
  const found = chart.getElementsAtEventForMode({ native: null, x: x, y: y }, 'index', { intersect: false, axis: 'x' }, false);
  if (!found.length) return;
  const active = found.map(el => ({ datasetIndex: el.datasetIndex, index: el.index }));
  chart.setActiveElements(active);
  chart.tooltip.setActiveElements(active, { x: x, y: y });
  chart.update('none');
}

Chart.register({
  id: 'touchTooltip',
  beforeEvent: function(chart, args) {
    // ChartEvent.type は touchstart→mousedown / touchmove→mousemove に読み替えられているので、
    // 元のブラウザイベントの種類（native.type）で判定する。
    const native = args.event && args.event.native;
    const type = native && native.type;
    // タッチでの表示は attachTouchTooltip が担当するので、Chart.js 自身には処理させない
    if (type === 'touchstart' || type === 'touchmove') return false;
    if ((type === 'mousemove' || type === 'click' || type === 'mouseout') && Date.now() - lastTouchAt < 1000) return false;
  }
});

function attachTouchTooltip(chart) {
  const canvas = chart.canvas;
  let start = null;  // { x, y, wasVisible }。タップ候補でなくなったら null

  canvas.addEventListener('touchstart', (e) => {
    lastTouchAt = Date.now();
    if (e.touches.length !== 1) { start = null; hideTooltip(chart); return; }
    const t = e.touches[0];
    start = { x: t.clientX, y: t.clientY, wasVisible: isTooltipVisible(chart) };
  }, { passive: true });

  canvas.addEventListener('touchmove', (e) => {
    lastTouchAt = Date.now();
    if (!start) return;
    const t = e.touches[0];
    if (e.touches.length !== 1 || Math.hypot(t.clientX - start.x, t.clientY - start.y) > TAP_MOVE_PX) {
      start = null;
      hideTooltip(chart);
    }
  }, { passive: true });

  canvas.addEventListener('touchend', () => {
    lastTouchAt = Date.now();
    if (!start) return;
    const s = start;
    start = null;
    if (s.wasVisible) { hideTooltip(chart); return; }
    const r = canvas.getBoundingClientRect();
    showTooltipAt(chart, s.x - r.left, s.y - r.top);
  }, { passive: true });

  canvas.addEventListener('touchcancel', () => { start = null; }, { passive: true });
}

function allCharts() {
  return [chartTHP, chartPIR_A, chartPIR_B, chartMixTHP, chartMixPIR].filter(Boolean);
}
// グラフの外をタップしたら、表示中のツールチップをすべて閉じる
document.addEventListener('touchstart', (e) => {
  lastTouchAt = Date.now();
  if (e.target && e.target.tagName === 'CANVAS') return;
  allCharts().forEach(hideTooltip);
}, { passive: true });

function makeZoomPlugin(zoomKey) {
  return {
    pan: {
      enabled: true,
      mode: 'x',
      // false を返すとプラグインはこの pan を開始しない
      onPanStart: (ctx) => !touchScroll.claim(ctx.event),
      onPanComplete: () => triggerLazy(zoomKey)
    },
    zoom: {
      wheel: { enabled: true, speed: 0.1 },
      pinch: { enabled: true },
      drag: { enabled: false },
      mode: 'x',
      onZoomComplete: () => triggerLazy(zoomKey)
    }
  };
}

// zoomKey -> ローダー。チャート生成とローダー生成の順序に依存しないための遅延束縛。
const lazyLoaders = {};
function triggerLazy(key) {
  const l = lazyLoaders[key];
  if (l) l.onComplete();
}

// zoom プラグイン設定を共通 plugins へ合成する（tooltip 設定を潰さないため）。
function withZoom(options, zoomKey) {
  if (zoomKey) {
    options.plugins = { ...CHART_COMMON_OPTIONS.plugins, zoom: makeZoomPlugin(zoomKey) };
  }
  return options;
}

function createTHPChart(canvas, zoomKey) {
  const options = withZoom({
    ...CHART_COMMON_OPTIONS,
    scales: {
      x: makeTimeXAxis(),
      y1: makeValueAxis('left', 'temp'),
      y2: makeValueAxis('right', 'humid', { grid: { drawOnChartArea: false, drawTicks: false } }),
      y3: makeValueAxis('right', 'press', { grid: { drawOnChartArea: false, drawTicks: false } })
    }
  }, zoomKey);

  // monotone は点を通る滑らかな補間で、実測値を超える山や谷を作らない
  const smooth = { cubicInterpolationMode: 'monotone' };
  return new Chart(canvas, {
    type: 'line',
    data: {
      labels: [],
      datasets: [
        lineDataset('温度', 'temp', '°C', 1, Object.assign({ yAxisID: 'y1' }, smooth)),
        lineDataset('湿度', 'humid', '%', 1, Object.assign({ yAxisID: 'y2' }, smooth)),
        lineDataset('気圧', 'press', 'hPa', 1, Object.assign({ yAxisID: 'y3' }, smooth))
      ]
    },
    options: options
  });
}

// seriesKey: 'pirA' / 'pirB'（部屋ごとの色。まとめタブの比較グラフと色を揃える）
function createPIRChart(canvas, zoomKey, seriesKey) {
  const options = withZoom({
    ...CHART_COMMON_OPTIONS,
    scales: {
      x: makeTimeXAxis(),
      y: makeValueAxis('left', null, { beginAtZero: true })
    }
  }, zoomKey);
  options.plugins = { ...options.plugins, legend: { display: false } };

  return new Chart(canvas, {
    type: 'line',
    data: {
      labels: [],
      datasets: [
        lineDataset('人感', seriesKey || 'pirA', '回', 0, { fill: 'origin' })
      ]
    },
    options: options
  });
}

function createMixPIRChart(canvas, zoomKey) {
  const options = withZoom({
    ...CHART_COMMON_OPTIONS,
    scales: {
      x: makeTimeXAxis(),
      y: makeValueAxis('left', null, { beginAtZero: true })
    }
  }, zoomKey);

  return new Chart(canvas, {
    type: 'line',
    data: {
      datasets: [
        lineDataset('部屋A', 'pirA', '回', 0),
        lineDataset('部屋B', 'pirB', '回', 0)
      ]
    },
    options: options
  });
}

// Ajax: dashboard.php?action=thp or pir
function fetchSensor(sensor, from, cb, sensorNo) {
  // 入力値検証
  if (!['thp', 'pir'].includes(sensor)) {
    console.error('無効なセンサータイプ:', sensor);
    cb(null);
    return;
  }

  // 日時形式の検証
  const dateRegex = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/;
  if (!dateRegex.test(from)) {
    console.error('無効な日時形式:', from);
    cb(null);
    return;
  }

  const params = {
    action: sensor,
    from: encodeURIComponent(from)
  };
  if (sensor === 'pir' && sensorNo) {
    params.sensor_no = sensorNo;
  }

  $.getJSON(scriptName, params, function(json){
    // レスポンスの基本検証
    if (!json || typeof json !== 'object') {
      console.error('無効なレスポンス形式');
      cb(null);
      return;
    }

    // データ形式の検証
    if (sensor === 'thp') {
      if (!Array.isArray(json.temps) || !Array.isArray(json.humids) ||
          !Array.isArray(json.pressures) || !Array.isArray(json.measures)) {
        console.error('THPデータ形式エラー');
        cb(null);
        return;
      }
    } else if (sensor === 'pir') {
      if (!Array.isArray(json.counts) || !Array.isArray(json.measures)) {
        console.error('PIRデータ形式エラー');
        cb(null);
        return;
      }
    }

    cb(json);
  }).fail(function(xhr, status, error){
    console.error('データ取得エラー:', sensor, status, error);

    if (xhr.responseJSON && xhr.responseJSON.error) {
      showSecurityAlert('エラー: ' + xhr.responseJSON.error, 'danger');
    }

    cb(null);
  });
}

/* ---- update UI ---- */
// 時間表示用のヘルパー関数
// "YYYY-MM-DD HH:MM:SS" -> "MM/DD HH:MM"（グラフの時間軸と同じ書式）
function formatTimeDisplay(timeString) {
  return timeString.substr(5, 2) + '/' + timeString.substr(8, 2) + ' ' + timeString.substr(11, 5);
}

// 表示用の数値整形。センサー値は小数1桁で十分（生値はツールチップでも1桁）。
function formatValue(value, decimals) {
  const n = Number(value);
  return Number.isFinite(n) ? n.toFixed(decimals) : '-';
}

// 直前の計測値との比較を矢印アイコンで表示する
function setTrend(el, current, previous) {
  const $el = $(el).removeClass('is-up is-down').removeAttr('title');
  if (current > previous) {
    $el.addClass('is-up').attr('title', '直前の計測から上昇').html('<i class="fa fa-caret-up"></i>');
  } else if (current < previous) {
    $el.addClass('is-down').attr('title', '直前の計測から下降').html('<i class="fa fa-caret-down"></i>');
  } else {
    $el.empty();
  }
}

// 温度・湿度・気圧カードの更新。prefix は '#latest' / '#mix-latest'
function renderTHPStats(json, prefix, elMeasured) {
  const keys = [['temp', 'temps'], ['humid', 'humids'], ['press', 'pressures']];
  if (json.measures.length === 0) {
    keys.forEach(([k]) => { $(`${prefix}-${k}`).text('-'); $(`${prefix}-${k}-trend`).empty(); });
    $(elMeasured).text('データなし');
    return;
  }
  const last = json.measures.length - 1;
  const prev = Math.max(0, last - 1);
  keys.forEach(([k, field]) => {
    $(`${prefix}-${k}`).text(formatValue(json[field][last], 1));
    setTrend(`${prefix}-${k}-trend`, json[field][last], json[field][prev]);
  });
  $(elMeasured).text(formatTimeDisplay(json.measures[last]) + ' 時点');
}

/* ---- pan/zoom + 過去への遅延ロード（汎用ファクトリ） ---- */
// 各チャートは {t:Date, ...} 点列を系列ごとに内部保持し、パンで左端(過去)へ近づいたら
// チャンクを追加取得して先頭に prepend する（tapo からの移植を全チャートに一般化）。

// JST文字列 "YYYY-MM-DD HH:MM:SS" -> Date（Safari対策で ' '→'T'）
function parseJst(str) {
  return new Date(str.replace(' ', 'T'));
}

// Date -> "YYYY-MM-DD HH:MM:SS"（ローカル=JST運用前提。parseJst の逆変換）
function toJstParam(date) {
  const p = (n) => String(n).padStart(2, '0');
  return date.getFullYear() + '-' + p(date.getMonth() + 1) + '-' + p(date.getDate()) + ' ' +
         p(date.getHours()) + ':' + p(date.getMinutes()) + ':' + p(date.getSeconds());
}

// 水平(x)スケールを取得（v4 の既定id は 'x'）。null安全＋フォールバック付き。
function getXScale(chart) {
  if (!chart || !chart.scales) return null;
  for (const id in chart.scales) {
    const s = chart.scales[id];
    if (s && typeof s.isHorizontal === 'function' && s.isHorizontal()) return s;
  }
  return chart.scales.x || null;
}

// 時間軸の表示ウィンドウを設定する。
// Chart.js v3 以降は time.min/max が廃止され、表示範囲は scales.x.min/max に一本化された。
// chartjs-plugin-zoom@2.x の updateRange() もパン/ズーム結果を同じ scaleOpts.min/max へ
// 書き戻すため、アプリ側とプラグイン側で参照先が一致する（v2.7.3 の二重書きは不要）。
function setChartWindow(chart, minMs, maxMs) {
  const xAxis = chart.options.scales.x;
  xAxis.min = minMs;
  xAxis.max = maxMs;
}

// 汎用の pan/zoom 遅延ローダー。1インスタンスが1チャート分の状態を保持する。
// config:
//   key        : registry用キー（zoomプラグインのコールバックから triggerLazy(key) で起動）
//   tag        : ログ用ラベル
//   getChart   : () => Chart（生成順に依存しないための遅延取得）
//   windowMs   : 初期表示幅（既定24h）
//   chunkMs    : 1回の取得チャンク（既定6h）
//   maxSpanMs  : 1リクエストの上限スパン（既定3日）
//   thresholdRatio : 可視左端が残りこの割合以内で追加ロード（既定0.2）
//   render     : (chart, seriesPoints) => void  内部点列をデータセットへ描画
//   fetchOlder : (fromStr, toStr, done) => void  done(arrays) を呼ぶ。
//                arrays は系列ごとの配列（各要素: 点配列 or null=エラー）。
function createLazyPanLoader(config) {
  const windowMs = config.windowMs || 24 * 60 * 60 * 1000;
  const chunkMs = config.chunkMs || 6 * 60 * 60 * 1000;
  const maxSpanMs = config.maxSpanMs || 3 * 24 * 60 * 60 * 1000;
  const thresholdRatio = config.thresholdRatio || 0.2;
  const tag = config.tag || 'lazy';
  const getChart = config.getChart;

  let seriesPoints = [];   // 系列ごとの [{t:Date, ...}] 昇順
  let oldest = null;       // ロード済み最古境界(ms)。要求済み境界であり実データ有無は問わない
  let isLoading = false;
  let reachedStart = false;
  let timer = null;

  function check() {
    const chart = getChart();
    if (!chart) return;
    if (isLoading || reachedStart || oldest === null) return;
    const xs = getXScale(chart);
    if (!xs) return;
    const span = xs.max - xs.min;
    if (!(span > 0)) return;
    const remaining = xs.min - oldest;
    if (remaining <= span * thresholdRatio) loadOlder();
  }

  function loadOlder() {
    const chart = getChart();
    if (isLoading || reachedStart || oldest === null || !chart) return;
    isLoading = true;

    const toMs = oldest;
    // 可視左端(さらに1チャンク分の余白付き)まで一度に取得し、速い/長いドラッグでも追いつく。
    // ただし1リクエストは最大 maxSpanMs に制限し、足りない分は末尾で連続取得して埋める。
    const xs = getXScale(chart);
    const visibleMin = xs ? xs.min : toMs;
    let fromMs = Math.min(toMs - chunkMs, visibleMin - chunkMs);
    fromMs = Math.max(fromMs, toMs - maxSpanMs);
    const fromStr = toJstParam(new Date(fromMs));
    const toStr = toJstParam(new Date(toMs));

    config.fetchOlder(fromStr, toStr, function(arrays) {
      if (!arrays) { isLoading = false; return; }

      let gotAny = false;    // いずれかの系列が1点以上返した
      arrays.forEach(function(newPts, i) {
        if (newPts === null || !newPts.length) return; // null=エラー / []=有効な空
        gotAny = true;
        const existing = seriesPoints[i] || [];
        // 既存最古より前の点だけを採用して重複を除外し、先頭に prepend（昇順維持）
        const oldestExisting = existing.length ? existing[0].t.getTime() : Infinity;
        const dedup = newPts.filter(p => p.t.getTime() < oldestExisting);
        seriesPoints[i] = dedup.concat(existing);
      });

      // 全系列が「有効な空(=null無し)」のときのみ、これ以上過去が無いと確定。
      const anyError = arrays.some(a => a === null);
      if (gotAny) {
        oldest = fromMs;
      } else if (!anyError) {
        reachedStart = true;
      }
      // エラーのみ(進捗も確定も無し)のときは oldest 据え置き＆reachedStart据え置き→次のパンで再試行。

      // 現在の表示範囲(パン/ズーム位置)を保ったままデータだけ差し替える。
      const xs2 = getXScale(chart);
      const curMin = xs2 ? xs2.min : null;
      const curMax = xs2 ? xs2.max : null;
      config.render(chart, seriesPoints);
      if (curMin !== null) setChartWindow(chart, curMin, curMax);
      chart.update('none'); // v3+ のアニメ無し即時再描画（v2 の update(0) 相当）
      isLoading = false;

      // 進捗があり、まだ埋めきれていない場合のみ連続取得（oldest が過去へ進むので自然停止）。
      // エラー時は据え置きのため連鎖せず、無限リトライを防ぐ。
      if (gotAny && !reachedStart) check();
    });
  }

  const api = {
    // 基準ロード: 表示範囲を [from-windowMs, from] に固定し状態を初期化する。
    base: function(fromStr, initialSeriesPoints) {
      const chart = getChart();
      if (!chart) return;
      const end = parseJst(fromStr).getTime();
      const start = end - windowMs;
      seriesPoints = initialSeriesPoints.map(a => (a ? a.slice() : []));
      oldest = start;
      reachedStart = false;
      isLoading = false;
      // resetZoom の戻り先はプラグイン側が自動追従する。plugin@2.x の
      // shouldUpdateScaleLimits() が「プラグインが最後に書いた min/max」と現在の
      // options を比較し、下の setChartWindow による外部変更を検知して基準を取り直すため、
      // v0.7.7 で必要だった内部プロパティ($zoom._originalOptions)のリセットは不要。
      config.render(chart, seriesPoints);
      setChartWindow(chart, start, end);
      chart.update();
    },
    // pan/zoom 完了時に呼ぶ（連続発火するので軽くデバウンス）
    onComplete: function() {
      clearTimeout(timer);
      timer = setTimeout(check, 150);
    },
    check: check
  };
  if (config.key) lazyLoaders[config.key] = api;
  return api;
}

/* ---- JSON -> 点列 変換 ---- */
function thpPointsFromJson(json) {
  return json.measures.map((m, i) => ({ t: parseJst(m), temp: json.temps[i], humid: json.humids[i], press: json.pressures[i] }));
}
function pirPointsFromJson(json) {
  return json.measures.map((m, i) => ({ t: parseJst(m), count: json.counts[i] }));
}

/* ---- 点列 -> データセット 描画 ---- */
function renderTHP3(chart, sp) {
  const pts = sp[0] || [];
  chart.data.labels = [];
  chart.data.datasets[0].data = pts.map(p => ({ x: p.t, y: p.temp }));
  chart.data.datasets[1].data = pts.map(p => ({ x: p.t, y: p.humid }));
  chart.data.datasets[2].data = pts.map(p => ({ x: p.t, y: p.press }));
}
function renderPIR1(chart, sp) {
  const pts = sp[0] || [];
  chart.data.labels = [];
  chart.data.datasets[0].data = pts.map(p => ({ x: p.t, y: p.count }));
}
function renderMixPIR2(chart, sp) {
  chart.data.labels = [];
  chart.data.datasets[0].data = (sp[0] || []).map(p => ({ x: p.t, y: p.count }));
  chart.data.datasets[1].data = (sp[1] || []).map(p => ({ x: p.t, y: p.count }));
}

/* ---- 範囲取得（遅延ロード用）。点配列 or null(エラー) を cb で返す ---- */
function fetchThpRange(fromStr, toStr, cb) {
  $.getJSON(scriptName, { action: 'thp', from: encodeURIComponent(fromStr), to: encodeURIComponent(toStr) }, function(json) {
    if (!json || !Array.isArray(json.measures) || !Array.isArray(json.temps) ||
        !Array.isArray(json.humids) || !Array.isArray(json.pressures)) { cb(null); return; }
    cb(thpPointsFromJson(json));
  }).fail(function() { cb(null); });
}
function fetchPirRange(sensorNo, fromStr, toStr, cb) {
  $.getJSON(scriptName, { action: 'pir', sensor_no: sensorNo, from: encodeURIComponent(fromStr), to: encodeURIComponent(toStr) }, function(json) {
    if (!json || !Array.isArray(json.measures) || !Array.isArray(json.counts)) { cb(null); return; }
    cb(pirPointsFromJson(json));
  }).fail(function() { cb(null); });
}

// 最新値ラベルの更新（json 配列の末尾が最新）
function updateTHPLatest(json) {
  renderTHPStats(json, '#latest', '#latest-measured-thp');
}

/* ---- 遅延ローダー・インスタンス（チャートは init で生成、getChart で遅延取得） ---- */
const thpLoader = createLazyPanLoader({
  key: 'roomA-thp', tag: 'roomA-thp', getChart: () => chartTHP,
  render: renderTHP3,
  fetchOlder: (f, t, done) => fetchThpRange(f, t, pts => done([pts]))
});
const pirALoader = createLazyPanLoader({
  key: 'roomA-pir', tag: 'roomA-pir', getChart: () => chartPIR_A,
  render: renderPIR1,
  fetchOlder: (f, t, done) => fetchPirRange(1, f, t, pts => done([pts]))
});
const pirBLoader = createLazyPanLoader({
  key: 'roomB-pir', tag: 'roomB-pir', getChart: () => chartPIR_B,
  render: renderPIR1,
  fetchOlder: (f, t, done) => fetchPirRange(2, f, t, pts => done([pts]))
});
const mixThpLoader = createLazyPanLoader({
  key: 'mix-thp', tag: 'mix-thp', getChart: () => chartMixTHP,
  render: renderTHP3,
  fetchOlder: (f, t, done) => fetchThpRange(f, t, pts => done([pts]))
});
// Mix PIR は2系列（A=sensor1, B=sensor2）。1回のパンで両方の過去を取得し、
// それぞれ datasets[0]/[1] に prepend。両方が空になったときだけ reachedStart。
const mixPirLoader = createLazyPanLoader({
  key: 'mix-pir', tag: 'mix-pir', getChart: () => chartMixPIR,
  render: renderMixPIR2,
  fetchOlder: (f, t, done) => {
    let a, b, n = 0;
    const fin = () => { if (n === 2) done([a, b]); };
    fetchPirRange(1, f, t, pts => { a = pts; n++; fin(); });
    fetchPirRange(2, f, t, pts => { b = pts; n++; fin(); });
  }
});

/* ---- 基準ロード（ピッカー/時間ナビ/タブ切替から呼ばれる初期表示） ---- */
function loadTHPBase(from) {
  fetchSensor('thp', from, function(json) {
    if (!json) return;
    thpLoader.base(from, [ thpPointsFromJson(json) ]);
    updateTHPLatest(json);
  });
}

function loadPIRBase(from, sensorNo, loader, elMeasured, elCount) {
  fetchSensor('pir', from, function(json) {
    if (!json) return;
    loader.base(from, [ pirPointsFromJson(json) ]);
    updatePIRLatest(json, elMeasured, elCount);
  }, sensorNo);
}

function loadMixTHPBase(from) {
  fetchSensor('thp', from, function(json) {
    if (!json) return;
    mixThpLoader.base(from, [ thpPointsFromJson(json) ]);
    updateMixTHPLatest(json);
  });
}

function loadMixPIRBase(from) {
  let a = null, b = null, n = 0;
  function fin() {
    if (n < 2) return;
    mixPirLoader.base(from, [ a ? a.pts : [], b ? b.pts : [] ]);
    updateMixPIRLatest(a ? a.json : null, 'A');
    updateMixPIRLatest(b ? b.json : null, 'B');
  }
  fetchSensor('pir', from, function(json) { if (json) a = { json, pts: pirPointsFromJson(json) }; n++; fin(); }, 1);
  fetchSensor('pir', from, function(json) { if (json) b = { json, pts: pirPointsFromJson(json) }; n++; fin(); }, 2);
}

/* ---- 最新値ラベル更新（基準ロードの JSON から算出） ---- */
function updatePIRLatest(json, elMeasured, elCount) {
  if (json.measures.length === 0) {
    $(elMeasured).text('-');
    $(elCount).text('-');
    return;
  }
  const latestDetection = findLatestNonZeroDetection(json.measures, json.counts);
  if (latestDetection) {
    $(elMeasured).text(formatTimeDisplay(latestDetection.time));
    $(elCount).text(latestDetection.count);
  } else {
    $(elMeasured).text('検出なし');
    $(elCount).text('0');
  }
}

function updateMixPIRLatest(json, room) {
  const elId = (room === 'A') ? '#mix-latest-a' : '#mix-latest-b';
  const latestDetection = json ? findLatestNonZeroDetection(json.measures, json.counts) : null;
  if (latestDetection) {
    $(elId).text(formatTimeDisplay(latestDetection.time));
    $(`${elId}-count`).text(`${latestDetection.count}回`);
  } else {
    $(elId).text('検出なし');
    $(`${elId}-count`).text('');
  }
}

function updateMixTHPLatest(json) {
  renderTHPStats(json, '#mix-latest', '#mix-latest-measured');
}

// PIRセンサーの最新検出を見つけるヘルパー関数
function findLatestNonZeroDetection(measures, counts) {
  for (let i = measures.length - 1; i >= 0; i--) {
    if (counts[i] > 0) {
      return {
        time: measures[i],
        count: counts[i]
      };
    }
  }
  return null;
}

/* ---- Utility ---- */
function formatForAjaxMoment(m) {
  return m.format('YYYY-MM-DD HH:mm:ss');
}

function fetchRoomData(from, room) {
  if (room === '#roomA') {
    loadTHPBase(from);
    loadPIRBase(from, 1, pirALoader, '#latest-measured-pir-a', '#latest-count-a');
  } else if (room === '#roomB') {
    loadPIRBase(from, 2, pirBLoader, '#latest-measured-pir-b', '#latest-count-b');
  } else if (room === '#roomMix') {
    loadMixTHPBase(from);
    loadMixPIRBase(from);
  }
}

function updateTimeAndFetch(newMoment) {
  // 日時ピッカーを更新
  $('#datetimepicker-common').datetimepicker('date', newMoment);

  // データを取得
  const from = formatForAjaxMoment(newMoment);
  const activeTab = $('.nav-tabs .active').attr('href') || '#roomA';
  fetchRoomData(from, activeTab);
}

// 時間操作のヘルパー関数
function adjustTime(amount, unit) {
  const current = $('#datetimepicker-common').datetimepicker('date');
  updateTimeAndFetch(current.add(amount, unit));
}

/* ---- 初期化 / イベント ---- */
$(function(){
  // canvas作成とChart生成
  // ダブルクリックでズーム/パンをリセットし、直近の24時間プリセット表示に戻す
  const addDblClickReset = (canvas, getChart) => {
    canvas.addEventListener('dblclick', () => {
      const c = getChart();
      if (c && c.resetZoom) c.resetZoom();
    });
  };

  const thpCanvas = document.createElement('canvas');
  $('#chart-thp').append(thpCanvas);
  chartTHP = createTHPChart(thpCanvas.getContext('2d'), 'roomA-thp'); // pan/zoom + 遅延ロード有効
  addDblClickReset(thpCanvas, () => chartTHP);

  const pirCanvasA = document.createElement('canvas');
  $('#chart-pir-a').append(pirCanvasA);
  chartPIR_A = createPIRChart(pirCanvasA.getContext('2d'), 'roomA-pir', 'pirA');
  addDblClickReset(pirCanvasA, () => chartPIR_A);

  const pirCanvasB = document.createElement('canvas');
  $('#chart-pir-b').append(pirCanvasB);
  chartPIR_B = createPIRChart(pirCanvasB.getContext('2d'), 'roomB-pir', 'pirB');
  addDblClickReset(pirCanvasB, () => chartPIR_B);

  const mixThpCanvas = document.createElement('canvas');
  $('#chart-mix-thp').append(mixThpCanvas);
  chartMixTHP = createTHPChart(mixThpCanvas.getContext('2d'), 'mix-thp');
  addDblClickReset(mixThpCanvas, () => chartMixTHP);

  const mixPirCanvas = document.createElement('canvas');
  $('#chart-mix-pir').append(mixPirCanvas);
  chartMixPIR = createMixPIRChart(mixPirCanvas.getContext('2d'), 'mix-pir');
  addDblClickReset(mixPirCanvas, () => chartMixPIR);

  // グラフ上の縦スワイプでページをスクロールできるようにする（touchScroll 参照）
  [thpCanvas, pirCanvasA, pirCanvasB, mixThpCanvas, mixPirCanvas].forEach(c => touchScroll.attach(c));
  // タッチではタップしたときだけツールチップを出す（attachTouchTooltip 参照）
  allCharts().forEach(attachTouchTooltip);

  // Tempus Dominus 初期化（共通ピッカー）
  $('#datetimepicker-common').datetimepicker({
    locale: 'ja',
    format: 'YYYY-MM-DD HH:mm',  // 秒を除去
    stepping: 1,
    useCurrent: true,
    buttons: { showClose: true }
  });

  // 初期時刻セット（現在時刻）
  $('#datetimepicker-common').datetimepicker('date', moment());

  // タブ状態の復元（リロード時）
  restoreActiveTab();

  // ピッカー変更時（スクロール的に時間を変えるだけで取得が走る）
  $('#datetimepicker-common').on('change.datetimepicker', function(e){
    const from = formatForAjaxMoment(e.date || moment());
    const activeTab = $('.nav-tabs .active').attr('href') || '#roomA';
    fetchRoomData(from, activeTab);
  });

  // 下部タブが切り替わったらそのタブに合わせて取得
  $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
    const target = $(e.target).attr('href'); // '#roomA' or '#roomB'
    const from = formatForAjaxMoment($('#datetimepicker-common').datetimepicker('date') || moment());

    // タブ状態を保存
    saveActiveTab(target);

    fetchRoomData(from, target);
  });

  // 部屋Aサブタブ切替
  $('#roomA-subtabs button').on('click', function() {
    const subtab = $(this).data('subtab');
    $('#roomA-subtabs button').removeClass('active').attr('aria-selected', 'false');
    $(this).addClass('active').attr('aria-selected', 'true');
    $('#roomA-thp, #roomA-pir-a').hide();
    // 「〜時点」は温湿度の計測時刻なので、人感表示中は隠す
    $('#latest-measured-thp').toggle(subtab === 'thp');
    $('#roomA-' + subtab).show();
  });

  // 時間ナビゲーションボタンのイベント
  $('#time-back-1h').on('click', () => adjustTime(-1, 'hour'));
  $('#time-back-10m').on('click', () => adjustTime(-10, 'minutes'));
  $('#time-now').on('click', () => updateTimeAndFetch(moment()));
  $('#time-forward-10m').on('click', () => adjustTime(10, 'minutes'));
  $('#time-forward-1h').on('click', () => adjustTime(1, 'hour'));

  // 初期ロード：アクティブタブの部屋データを読み込む
  const nowFrom = formatForAjaxMoment(moment());
  const initialTab = $('.nav-tabs .active').attr('href') || '#roomA';
  fetchRoomData(nowFrom, initialTab);
});

// タブ状態の保存・復元機能
function saveActiveTab(tabId) {
  try {
    // URLハッシュに保存（優先）
    window.location.hash = tabId;

    // sessionStorageにもバックアップ保存（プライベートブラウジング対応）
    if (typeof(Storage) !== "undefined") {
      sessionStorage.setItem('activeTab', tabId);
    }
  } catch (e) {
    console.log('タブ状態の保存に失敗しました:', e);
  }
}

function restoreActiveTab() {
  let activeTab = '#roomA'; // デフォルト
  const validTabs = ['#roomA', '#roomB', '#roomMix'];

  try {
    // URLハッシュから復元を試行
    if (window.location.hash && validTabs.includes(window.location.hash)) {
      activeTab = window.location.hash;
    }
    // URLハッシュがない場合はsessionStorageから復元
    else if (typeof(Storage) !== "undefined") {
      const savedTab = sessionStorage.getItem('activeTab');
      if (savedTab && validTabs.includes(savedTab)) {
        activeTab = savedTab;
      }
    }

    // タブを復元
    if (activeTab !== '#roomA') {
      // デフォルト以外の場合のみ切り替え
      $('.nav-tabs a[href="' + activeTab + '"]').tab('show');
    }

    console.log('タブ状態を復元しました:', activeTab);
  } catch (e) {
    console.log('タブ状態の復元に失敗しました:', e);
  }
}
</script>

</body>
</html>
