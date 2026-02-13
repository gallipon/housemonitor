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

// セッション認証チェック
if (empty($_SESSION['authenticated'])) {
    header('Location: login.php');
    exit();
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

// データベース接続
require_once __DIR__ . '/db_config.php';
$mysqli = getDbConnection();
if (!$mysqli) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// 入力値検証関数
function validateInput($value, $type) {
    switch ($type) {
        case 'action':
            return in_array($value, ['thp', 'pir'], true) ? $value : null;
        case 'offset':
            $offset = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => 10000]
            ]);
            return $offset !== false ? $offset : 0;
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
        default:
            return null;
    }
}

// ===== Ajax API 部分（セキュリティ強化） =====
if (isset($_GET['action'])) {
    // 入力値検証
    $action = validateInput($_GET['action'] ?? '', 'action');
    $offset = validateInput($_GET['offset'] ?? 0, 'offset');
    $from = validateInput(urldecode($_GET['from'] ?? ''), 'datetime');

    if (!$action) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Invalid action']);
        exit;
    }

    try {
        if ($action === 'thp') {
            $stmt = $mysqli->prepare("SELECT * FROM bme280 WHERE measured_at <= ? ORDER BY measured_at DESC LIMIT ?, 144");
            $stmt->bind_param('si', $from, $offset);
            $stmt->execute();
            $result = $stmt->get_result();

            $temps = $humids = $pressures = $measures = [];
            while ($row = $result->fetch_assoc()) {
                $temps[]     = (float)$row['temperature'];
                $humids[]    = (float)$row['humidity'];
                $pressures[] = (float)$row['pressure'];
                $measures[]  = htmlspecialchars($row['measured_at'], ENT_QUOTES, 'UTF-8');
            }
            $stmt->close();

            // SQLはDESC（新しい順）で取っているため、古い→新しいに並べ替える
            $temps = array_reverse($temps);
            $humids = array_reverse($humids);
            $pressures = array_reverse($pressures);
            $measures = array_reverse($measures);

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['temps'=>$temps,'humids'=>$humids,'pressures'=>$pressures,'measures'=>$measures]);
            exit;
        }

        if ($action === 'pir') {
            $stmt = $mysqli->prepare("SELECT * FROM hc_sr501 WHERE measured_at <= ? ORDER BY measured_at DESC LIMIT ?, 144");
            $stmt->bind_param('si', $from, $offset);
            $stmt->execute();
            $result = $stmt->get_result();

            $counts = $measures = [];
            while ($row = $result->fetch_assoc()) {
                $counts[] = (int)$row['count'];
                $measures[] = htmlspecialchars($row['measured_at'], ENT_QUOTES, 'UTF-8');
            }
            $stmt->close();

            $counts = array_reverse($counts);
            $measures = array_reverse($measures);

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
  <script src="assets/Chart.bundle.min.js"></script>

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
  </style>
</head>
<body>
<div class="container">
  <!-- タブコンテンツ -->
  <div class="tab-content">
    <!-- 温湿度・気圧 -->
    <div class="tab-pane fade show active" id="thp">
      <div id="chart-thp"></div>
      <div class="row latest-values text-white mt-2">
        <p class="col-sm-3 p-1 bg-info text-center" id="latest-measured-thp"></p>
        <p class="col-sm-3 p-1 bg-danger text-center" id="latest-temp"></p>
        <p class="col-sm-3 p-1 bg-primary text-center" id="latest-humid"></p>
        <p class="col-sm-3 p-1 bg-success text-center" id="latest-press"></p>
      </div>
    </div>

    <!-- PIR -->
    <div class="tab-pane fade" id="pir">
      <div id="chart-pir"></div>
      <div class="row latest-values text-dark mt-2">
        <p class="col-sm-6 p-1 bg-info text-white text-center" id="latest-measured-pir"></p>
        <p class="col-sm-6 p-1 bg-warning text-dark text-center" id="latest-count"></p>
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
      <a class="nav-link active" data-toggle="tab" href="#thp">温度・湿度・気圧</a>
    </li>
    <li class="nav-item">
      <a class="nav-link" data-toggle="tab" href="#pir">人感センサー</a>
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
let chartPIR = null;

// チャート設定の共通オプション
const CHART_COMMON_OPTIONS = {
  animation: { duration: 250 },
  scales: {
    xAxes: [{
      type: 'time',
      distribution: 'linear',
      time: {
        unit: 'hour',
        displayFormats: { hour: 'MM/DD HH:mm' }
      }
    }]
  },
  tooltips: { mode: 'index', intersect: false },
  responsive: true
};

function createTHPChart(canvas) {
  return new Chart(canvas, {
    type: 'line',
    data: {
      labels: [],
      datasets: [
        {
          label: '温度',
          data: [],
          borderColor: 'rgba(220,40,20,0.8)',
          backgroundColor: 'rgba(220,40,20,0.15)',
          fill: false,
          yAxisID: "y1"
        },
        {
          label: '湿度',
          data: [],
          borderColor: 'rgba(60,90,220,0.8)',
          backgroundColor: 'rgba(60,90,220,0.15)',
          fill: false,
          yAxisID: "y2"
        },
        {
          label: '気圧',
          data: [],
          borderColor: 'rgba(60,240,20,0.8)',
          backgroundColor: 'rgba(60,240,20,0.15)',
          fill: false,
          yAxisID: "y3"
        }
      ]
    },
    options: {
      ...CHART_COMMON_OPTIONS,
      scales: {
        ...CHART_COMMON_OPTIONS.scales,
        yAxes: [
          { id: 'y1', position: 'left', ticks: { fontColor: 'rgba(220,40,20,0.8)' } },
          { id: 'y2', position: 'right', ticks: { fontColor: 'rgba(60,90,220,0.8)' }, gridLines: { drawOnChartArea: false } },
          { id: 'y3', position: 'right', ticks: { fontColor: 'rgba(60,240,20,0.8)' }, gridLines: { drawOnChartArea: false } }
        ]
      }
    }
  });
}

function createPIRChart(canvas) {
  return new Chart(canvas, {
    type: 'line',
    data: {
      labels: [],
      datasets: [
        {
          label: '人感',
          data: [],
          borderColor: 'rgba(240,180,20,0.9)',
          backgroundColor: 'rgba(240,180,20,0.25)',
          fill: false
        }
      ]
    },
    options: {
      ...CHART_COMMON_OPTIONS,
      scales: {
        ...CHART_COMMON_OPTIONS.scales,
        yAxes: [{ ticks: { beginAtZero: true } }]
      }
    }
  });
}

// Ajax: dashboard.php?action=thp or pir
function fetchSensor(sensor, from, cb) {
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

  $.getJSON(scriptName, {
    action: sensor,
    from: encodeURIComponent(from),
    offset: 0
  }, function(json){
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

function updateTHP(json) {
  if (!json) return;

  // チャートデータ更新
  chartTHP.data.labels = json.measures;
  chartTHP.data.datasets[0].data = json.temps;
  chartTHP.data.datasets[1].data = json.humids;
  chartTHP.data.datasets[2].data = json.pressures;
  chartTHP.update();

  if (json.measures.length === 0) {
    $('#latest-measured-thp').text('');
    $('#latest-temp').text('温度 : -');
    $('#latest-humid').text('湿度 : -');
    $('#latest-press').text('気圧 : -');
    return;
  }

  // 最新は配列の末尾（古い→新しい順なので末尾が最新）
  const last = json.measures.length - 1;
  const prev = Math.max(0, last - 1);

  const current = {
    temp: json.temps[last],
    humid: json.humids[last],
    pressure: json.pressures[last]
  };

  const previous = {
    temp: json.temps[prev],
    humid: json.humids[prev],
    pressure: json.pressures[prev]
  };

  // UI更新
  $('#latest-measured-thp').text(formatTimeDisplay(json.measures[last]));
  $('#latest-temp').text(`温度 : ${current.temp}${getChangeArrow(current.temp, previous.temp)}`);
  $('#latest-humid').text(`湿度 : ${current.humid}${getChangeArrow(current.humid, previous.humid)}`);
  $('#latest-press').text(`気圧 : ${current.pressure}${getChangeArrow(current.pressure, previous.pressure)}`);
}

function updatePIR(json) {
  if (!json) return;

  // チャートデータ更新
  chartPIR.data.labels = json.measures;
  chartPIR.data.datasets[0].data = json.counts;
  chartPIR.update();

  if (json.measures.length === 0) {
    $('#latest-measured-pir').text('');
    $('#latest-count').text('カウント : -');
    return;
  }

  // 直近で0以上だった時間と回数を検索
  const latestDetection = findLatestNonZeroDetection(json.measures, json.counts);

  if (latestDetection) {
    $('#latest-measured-pir').text(formatTimeDisplay(latestDetection.time));
    $('#latest-count').text(`カウント : ${latestDetection.count}`);
  } else {
    $('#latest-measured-pir').text('検出なし');
    $('#latest-count').text('カウント : 0');
  }
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

function updateTimeAndFetch(newMoment) {
  // 日時ピッカーを更新
  $('#datetimepicker-common').datetimepicker('date', newMoment);

  // データを取得
  const from = formatForAjaxMoment(newMoment);
  const activeTab = $('.nav-tabs .active').attr('href') || '#thp';

  if (activeTab === '#thp') {
    fetchSensor('thp', from, updateTHP);
  } else {
    fetchSensor('pir', from, updatePIR);
  }
}

// 時間操作のヘルパー関数
function adjustTime(amount, unit) {
  const current = $('#datetimepicker-common').datetimepicker('date');
  updateTimeAndFetch(current.add(amount, unit));
}

/* ---- 初期化 / イベント ---- */
$(function(){
  // canvas作成とChart生成
  const thpCanvas = document.createElement('canvas');
  $('#chart-thp').append(thpCanvas);
  chartTHP = createTHPChart(thpCanvas.getContext('2d'));

  const pirCanvas = document.createElement('canvas');
  $('#chart-pir').append(pirCanvas);
  chartPIR = createPIRChart(pirCanvas.getContext('2d'));

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
    // 現在表示されているタブに応じて取得
    const activeTab = $('.nav-tabs .active').attr('href') || '#thp';
    if (activeTab === '#thp') {
      fetchSensor('thp', from, updateTHP);
    } else {
      fetchSensor('pir', from, updatePIR);
    }
  });

  // 下部タブが切り替わったらそのタブに合わせて取得
  $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
    const target = $(e.target).attr('href'); // '#thp' or '#pir'
    const from = formatForAjaxMoment($('#datetimepicker-common').datetimepicker('date') || moment());

    // タブ状態を保存
    saveActiveTab(target);

    if (target === '#thp') {
      fetchSensor('thp', from, updateTHP);
    } else if (target === '#pir') {
      fetchSensor('pir', from, updatePIR);
    }
  });

  // 時間ナビゲーションボタンのイベント
  $('#time-back-1h').on('click', () => adjustTime(-1, 'hour'));
  $('#time-back-10m').on('click', () => adjustTime(-10, 'minutes'));
  $('#time-now').on('click', () => updateTimeAndFetch(moment()));
  $('#time-forward-10m').on('click', () => adjustTime(10, 'minutes'));
  $('#time-forward-1h').on('click', () => adjustTime(1, 'hour'));

  // 初期ロード：現在時刻を基準に両方読み込む
  const nowFrom = formatForAjaxMoment(moment());
  fetchSensor('thp', nowFrom, updateTHP);
  fetchSensor('pir', nowFrom, updatePIR);
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
  let activeTab = '#thp'; // デフォルト

  try {
    // URLハッシュから復元を試行
    if (window.location.hash && ['#thp', '#pir'].includes(window.location.hash)) {
      activeTab = window.location.hash;
    }
    // URLハッシュがない場合はsessionStorageから復元
    else if (typeof(Storage) !== "undefined") {
      const savedTab = sessionStorage.getItem('activeTab');
      if (savedTab && ['#thp', '#pir'].includes(savedTab)) {
        activeTab = savedTab;
      }
    }

    // タブを復元
    if (activeTab !== '#thp') {
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
