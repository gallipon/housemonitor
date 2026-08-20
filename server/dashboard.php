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
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
    body { padding-bottom: 80px; padding-top: 20px; }
    .bottom-tabs {
      position: fixed;
      bottom: 0; left: 0; right: 0;
      background: #f8f9fa;
      border-top: 1px solid #ddd;
    }
    .latest-values p { margin: 0; font-weight: bold; }
    canvas { max-width: 100%; height: 380px; }
    .alert-security {
      position: fixed;
      top: 10px;
      right: 10px;
      z-index: 9999;
      max-width: 300px;
    }
    .logout-btn {
      position: fixed;
      top: 10px;
      left: 10px;
      z-index: 9998;
    }
    #roomMix canvas { height: 260px; }
    .mix-section-label { font-size: 0.8rem; color: #6c757d; text-align: center; margin: 6px 0 2px; }
  </style>
</head>
<body>
<a href="logout.php" class="btn btn-sm btn-secondary logout-btn">
  <i class="fa fa-sign-out"></i> ログアウト
</a>
<div class="container">
  <!-- タブコンテンツ -->
  <div class="tab-content">
    <!-- 部屋A（温湿度・気圧 / 人感 切替） -->
    <div class="tab-pane fade show active" id="roomA">
      <div class="btn-group btn-group-sm w-100 mb-2" role="group" id="roomA-subtabs">
        <button type="button" class="btn btn-primary active" data-subtab="thp">温度・湿度・気圧</button>
        <button type="button" class="btn btn-outline-primary" data-subtab="pir-a">人感センサー</button>
      </div>
      <div id="roomA-thp">
        <div id="chart-thp"></div>
<?php if ($bme_is_adjusted): ?>
        <p class="text-muted small mt-1 mb-0" title="環境変数 HOUSEMONITOR_BME280_TABLE で補正済みデータ元が指定されています。">
          ※ 湿度はセンサー補正を適用した推定値です（生値ではありません）
        </p>
<?php endif; ?>
        <div class="row latest-values text-white mt-2">
          <p class="col-sm-3 p-1 bg-info text-center" id="latest-measured-thp"></p>
          <p class="col-sm-3 p-1 bg-danger text-center" id="latest-temp"></p>
          <p class="col-sm-3 p-1 bg-primary text-center" id="latest-humid"></p>
          <p class="col-sm-3 p-1 bg-success text-center" id="latest-press"></p>
        </div>
      </div>
      <div id="roomA-pir-a" style="display: none;">
        <div id="chart-pir-a"></div>
        <div class="row latest-values text-dark mt-2">
          <p class="col-sm-6 p-1 bg-info text-white text-center" id="latest-measured-pir-a"></p>
          <p class="col-sm-6 p-1 bg-warning text-dark text-center" id="latest-count-a"></p>
        </div>
      </div>
    </div>

    <!-- 部屋B（人感のみ） -->
    <div class="tab-pane fade" id="roomB">
      <div id="chart-pir-b"></div>
      <div class="row latest-values text-dark mt-2">
        <p class="col-sm-6 p-1 bg-info text-white text-center" id="latest-measured-pir-b"></p>
        <p class="col-sm-6 p-1 bg-warning text-dark text-center" id="latest-count-b"></p>
      </div>
    </div>

    <!-- まとめ（温湿度気圧＋PIR比較） -->
    <div class="tab-pane fade" id="roomMix">
      <p class="mix-section-label">温度・湿度・気圧（部屋A）</p>
      <div id="chart-mix-thp"></div>
      <div class="row latest-values text-white mt-1 mb-3">
        <p class="col-4 p-1 bg-danger text-center" id="mix-latest-temp">温度 : -</p>
        <p class="col-4 p-1 bg-primary text-center" id="mix-latest-humid">湿度 : -</p>
        <p class="col-4 p-1 bg-success text-center" id="mix-latest-press">気圧 : -</p>
      </div>
      <p class="mix-section-label">人感センサー比較（部屋A・B）</p>
      <div id="chart-mix-pir"></div>
      <div class="row latest-values mt-1">
        <p class="col-6 p-1 bg-warning text-dark text-center" id="mix-latest-a">部屋A : -</p>
        <p class="col-6 p-1 bg-light text-dark text-center" id="mix-latest-b">部屋B : -</p>
      </div>
    </div>
  </div>

  <!-- 時間ナビゲーション -->
  <div class="row my-3">
    <div class="col-12">
      <div class="btn-group w-100" role="group">
        <button type="button" class="btn btn-outline-primary" id="time-back-1h">
          <i class="fa fa-angle-double-left"></i> 1時間前
        </button>
        <button type="button" class="btn btn-outline-primary" id="time-back-10m">
          <i class="fa fa-angle-left"></i> 10分前
        </button>
        <button type="button" class="btn btn-primary" id="time-now">
          <i class="fa fa-clock-o"></i> 現在
        </button>
        <button type="button" class="btn btn-outline-primary" id="time-forward-10m">
          10分後 <i class="fa fa-angle-right"></i>
        </button>
        <button type="button" class="btn btn-outline-primary" id="time-forward-1h">
          1時間後 <i class="fa fa-angle-double-right"></i>
        </button>
      </div>
    </div>
  </div>

  <!-- 日時ピッカー（詳細設定用） -->
  <div class="input-group my-3" id="datetimepicker-wrapper">
    <div class="input-group date" id="datetimepicker-common" data-target-input="nearest">
      <input id="datetime-input" type="text" class="form-control datetimepicker-input" data-target="#datetimepicker-common"/>
      <div class="input-group-append" data-target="#datetimepicker-common" data-toggle="datetimepicker">
        <div class="input-group-text"><i class="fa fa-calendar"></i></div>
      </div>
    </div>
  </div>
</div>

<!-- 下部タブ -->
<div class="bottom-tabs">
  <ul class="nav nav-tabs nav-fill">
    <li class="nav-item">
      <a class="nav-link active" data-toggle="tab" href="#roomA">部屋A</a>
    </li>
    <li class="nav-item">
      <a class="nav-link" data-toggle="tab" href="#roomB">部屋B</a>
    </li>
    <li class="nav-item">
      <a class="nav-link" data-toggle="tab" href="#roomMix">まとめ</a>
    </li>
  </ul>
</div>

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
        <div class="alert alert-${type} alert-dismissible alert-security">
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
const CHART_COMMON_OPTIONS = {
  animation: false,
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
    tooltip: { mode: 'index', intersect: false }
  },
  responsive: true
};

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
    ticks: { maxRotation: 0 }
  };
}

// zoom プラグイン設定（options.plugins.zoom）。完了コールバックは引数形状に依存せず、
// zoomKey で登録済みのローダーを registry から引いて起動する。
// v2 では zoom.enabled が廃止され、wheel / pinch / drag を個別に指定する。
function makeZoomPlugin(zoomKey) {
  return {
    pan: { enabled: true, mode: 'x', onPanComplete: () => triggerLazy(zoomKey) },
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
      y1: { position: 'left', ticks: { color: 'rgba(220,40,20,0.8)' } },
      y2: { position: 'right', ticks: { color: 'rgba(60,90,220,0.8)' }, grid: { drawOnChartArea: false } },
      y3: { position: 'right', ticks: { color: 'rgba(60,240,20,0.8)' }, grid: { drawOnChartArea: false } }
    }
  }, zoomKey);

  return new Chart(canvas, {
    type: 'line',
    data: {
      labels: [],
      datasets: [
        { label: '温度', data: [], borderColor: 'rgba(220,40,20,0.8)', backgroundColor: 'rgba(220,40,20,0.15)', fill: false, yAxisID: "y1" },
        { label: '湿度', data: [], borderColor: 'rgba(60,90,220,0.8)', backgroundColor: 'rgba(60,90,220,0.15)', fill: false, yAxisID: "y2" },
        { label: '気圧', data: [], borderColor: 'rgba(60,240,20,0.8)', backgroundColor: 'rgba(60,240,20,0.15)', fill: false, yAxisID: "y3" }
      ]
    },
    options: options
  });
}

function createPIRChart(canvas, zoomKey) {
  const options = withZoom({
    ...CHART_COMMON_OPTIONS,
    scales: {
      x: makeTimeXAxis(),
      y: { beginAtZero: true }
    }
  }, zoomKey);

  return new Chart(canvas, {
    type: 'line',
    data: {
      labels: [],
      datasets: [
        { label: '人感', data: [], borderColor: 'rgba(240,180,20,0.9)', backgroundColor: 'rgba(240,180,20,0.25)', fill: false }
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
      y: { beginAtZero: true }
    }
  }, zoomKey);

  return new Chart(canvas, {
    type: 'line',
    data: {
      datasets: [
        { label: '部屋A', data: [], borderColor: 'rgba(240,180,20,0.9)', backgroundColor: 'rgba(240,180,20,0.15)', fill: false },
        { label: '部屋B', data: [], borderColor: 'rgba(100,60,200,0.9)', backgroundColor: 'rgba(100,60,200,0.15)', fill: false }
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
function formatTimeDisplay(timeString) {
  return timeString.substr(5, 11); // MM-DD HH:MM 形式
}

// 変化の矢印を取得するヘルパー関数
function getChangeArrow(current, previous) {
  if (current > previous) return ' ↑';
  if (current < previous) return ' ↓';
  return '';
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
  if (json.measures.length === 0) {
    $('#latest-measured-thp').text('');
    $('#latest-temp').text('温度 : -');
    $('#latest-humid').text('湿度 : -');
    $('#latest-press').text('気圧 : -');
    return;
  }
  const last = json.measures.length - 1;
  const prev = Math.max(0, last - 1);
  $('#latest-measured-thp').text(formatTimeDisplay(json.measures[last]));
  $('#latest-temp').text(`温度 : ${json.temps[last]}${getChangeArrow(json.temps[last], json.temps[prev])}`);
  $('#latest-humid').text(`湿度 : ${json.humids[last]}${getChangeArrow(json.humids[last], json.humids[prev])}`);
  $('#latest-press').text(`気圧 : ${json.pressures[last]}${getChangeArrow(json.pressures[last], json.pressures[prev])}`);
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
    $(elMeasured).text('');
    $(elCount).text('カウント : -');
    return;
  }
  const latestDetection = findLatestNonZeroDetection(json.measures, json.counts);
  if (latestDetection) {
    $(elMeasured).text(formatTimeDisplay(latestDetection.time));
    $(elCount).text(`カウント : ${latestDetection.count}`);
  } else {
    $(elMeasured).text('検出なし');
    $(elCount).text('カウント : 0');
  }
}

function updateMixPIRLatest(json, room) {
  const elId = (room === 'A') ? '#mix-latest-a' : '#mix-latest-b';
  const label = (room === 'A') ? '部屋A' : '部屋B';
  if (!json) { $(elId).text(`${label} : 検出なし`); return; }
  const latestDetection = findLatestNonZeroDetection(json.measures, json.counts);
  if (latestDetection) {
    $(elId).text(`${label} : ${formatTimeDisplay(latestDetection.time)} (${latestDetection.count}回)`);
  } else {
    $(elId).text(`${label} : 検出なし`);
  }
}

function updateMixTHPLatest(json) {
  if (json.measures.length === 0) {
    $('#mix-latest-temp').text('温度 : -');
    $('#mix-latest-humid').text('湿度 : -');
    $('#mix-latest-press').text('気圧 : -');
    return;
  }

  const last = json.measures.length - 1;
  const prev = Math.max(0, last - 1);
  $('#mix-latest-temp').text(`温度 : ${json.temps[last]}${getChangeArrow(json.temps[last], json.temps[prev])}`);
  $('#mix-latest-humid').text(`湿度 : ${json.humids[last]}${getChangeArrow(json.humids[last], json.humids[prev])}`);
  $('#mix-latest-press').text(`気圧 : ${json.pressures[last]}${getChangeArrow(json.pressures[last], json.pressures[prev])}`);
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
  chartPIR_A = createPIRChart(pirCanvasA.getContext('2d'), 'roomA-pir');
  addDblClickReset(pirCanvasA, () => chartPIR_A);

  const pirCanvasB = document.createElement('canvas');
  $('#chart-pir-b').append(pirCanvasB);
  chartPIR_B = createPIRChart(pirCanvasB.getContext('2d'), 'roomB-pir');
  addDblClickReset(pirCanvasB, () => chartPIR_B);

  const mixThpCanvas = document.createElement('canvas');
  $('#chart-mix-thp').append(mixThpCanvas);
  chartMixTHP = createTHPChart(mixThpCanvas.getContext('2d'), 'mix-thp');
  addDblClickReset(mixThpCanvas, () => chartMixTHP);

  const mixPirCanvas = document.createElement('canvas');
  $('#chart-mix-pir').append(mixPirCanvas);
  chartMixPIR = createMixPIRChart(mixPirCanvas.getContext('2d'), 'mix-pir');
  addDblClickReset(mixPirCanvas, () => chartMixPIR);

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
    $('#roomA-subtabs button').removeClass('btn-primary active').addClass('btn-outline-primary');
    $(this).removeClass('btn-outline-primary').addClass('btn-primary active');
    $('#roomA-thp, #roomA-pir-a').hide();
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
