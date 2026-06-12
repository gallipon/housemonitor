<?php
/**
 * センサー死活監視スクリプト
 *
 * cronで定期実行し、データが一定時間届かなければntfy.shにアラートを送る。
 * 連続して通知はせず、復帰時にも通知する。
 *
 * 設定:
 *   環境変数 HOUSEMONITOR_NTFY_TOPIC にntfy.shのトピック名を設定すること
 *   Apache例: SetEnv HOUSEMONITOR_NTFY_TOPIC your-topic-name
 *
 * cron例（5分ごとに実行）:
 *   * /5 * * * * php /var/www/html/check_sensors.php >> /var/log/housemonitor_check.log 2>&1
 */

require_once __DIR__ . '/db_config.php';

/** データが届かなければアラートを出す閾値（分） */
const ALERT_THRESHOLD_MINUTES = 10;

/**
 * センサーの状態（ダウン中かどうか）を記録するファイル。
 * Webから直接アクセスできない場所（/tmp）に保存する。
 */
const STATE_FILE = '/tmp/housemonitor_sensor_state.json';

// ntfy.shトピック名は環境変数から取得
$ntfy_topic = getenv('HOUSEMONITOR_NTFY_TOPIC');
if (!$ntfy_topic) {
    log_msg('ERROR: HOUSEMONITOR_NTFY_TOPIC is not set');
    exit(1);
}

$mysqli = getDbConnection();
if (!$mysqli) {
    log_msg('ERROR: DB connection failed');
    exit(1);
}

// 監視対象センサーの定義
$sensors = [
    'bme280' => [
        'label' => 'BME280（温湿度・気圧）',
        'query' => 'SELECT TIMESTAMPDIFF(MINUTE, MAX(measured_at), NOW()) AS diff_min FROM bme280',
    ],
    'pir' => [
        'label' => 'PIR（人感センサー）',
        'query' => 'SELECT TIMESTAMPDIFF(MINUTE, MAX(measured_at), NOW()) AS diff_min FROM hc_sr501',
    ],
];

// 前回の状態をファイルから読み込む
$state = [];
if (file_exists(STATE_FILE)) {
    $state = json_decode(file_get_contents(STATE_FILE), true) ?? [];
}

foreach ($sensors as $key => $sensor) {
    $result   = $mysqli->query($sensor['query']);
    $row      = $result ? $result->fetch_assoc() : null;

    if (!$row || $row['diff_min'] === null) {
        // DBにデータが1件もない場合はスキップ
        continue;
    }

    $diff_min = (int)$row['diff_min'];

    $is_down  = $diff_min >= ALERT_THRESHOLD_MINUTES;
    $was_down = $state[$key]['is_down'] ?? false;

    if ($is_down && !$was_down) {
        // ダウン検知（初回のみ通知）
        log_msg("ALERT: {$sensor['label']} のデータが {$diff_min} 分間届いていません");
        send_ntfy(
            $ntfy_topic,
            'HouseMonitor アラート',
            "{$sensor['label']} のデータが {$diff_min} 分間届いていません",
            'high',
            'warning,no_entry'
        );
        $state[$key]['is_down'] = true;
    } elseif (!$is_down && $was_down) {
        // 復帰検知
        log_msg("RECOVERY: {$sensor['label']} が復帰しました");
        send_ntfy(
            $ntfy_topic,
            'HouseMonitor 復帰',
            "{$sensor['label']} のデータが再び届き始めました",
            'default',
            'white_check_mark'
        );
        $state[$key]['is_down'] = false;
    }
}

$mysqli->close();

// 状態をファイルに保存（次回実行時に参照）
file_put_contents(STATE_FILE, json_encode($state));


/**
 * ntfy.sh に通知を送る
 *
 * @param string $topic    ntfy.shのトピック名
 * @param string $title    通知タイトル
 * @param string $body     通知本文
 * @param string $priority 優先度（urgent/high/default/low/min）
 * @param string $tags     タグ（カンマ区切り、絵文字コード）
 */
function send_ntfy(string $topic, string $title, string $body, string $priority, string $tags): void
{
    $ch = curl_init("https://ntfy.sh/{$topic}");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            "Title: {$title}",
            "Priority: {$priority}",
            "Tags: {$tags}",
            'Content-Type: text/plain; charset=utf-8',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);

    curl_exec($ch);

    if (curl_errno($ch)) {
        log_msg('ERROR: ntfy send error: ' . curl_error($ch));
    }

    curl_close($ch);
}

/**
 * タイムスタンプ付きでログを出力する
 *
 * @param string $message ログメッセージ
 */
function log_msg(string $message): void
{
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] {$message}" . PHP_EOL;
}
