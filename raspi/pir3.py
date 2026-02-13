# coding: utf-8
"""
PIR（人感センサー HC-SR501）監視・送信スクリプト
一定時間GPIO監視し、動体検知回数をカウントしてサーバーへPOSTする
"""

import sys
import time
import datetime
import json
import urllib.request
import RPi.GPIO as GPIO
from retry import retry
from config import PIR_ENDPOINT, LOG_FILE, API_KEY

# --- 定数 ---
SLEEP_TIME = 0.1       # サンプリング間隔（秒）
SENSOR_GPIO = 18       # PIRセンサーのGPIOピン番号
MONITOR_TIME = 595     # 監視時間（秒）- cronの10分間隔に対し余裕を持たせる
SENSOR_NO = 1          # センサー識別番号


def main():
    """PIRセンサーを監視し、検知回数をログ記録・サーバー送信する"""
    try:
        # GPIO初期化
        GPIO.setwarnings(False)
        GPIO.setmode(GPIO.BCM)
        GPIO.setup(SENSOR_GPIO, GPIO.IN)

        # 監視ループ
        prev_value = GPIO.input(SENSOR_GPIO)
        start = time.time()
        count = 0

        print("PIRセンサー監視を開始します...")
        print(f"初期センサー値: {prev_value}")

        while (time.time() - start) < MONITOR_TIME:
            current_value = GPIO.input(SENSOR_GPIO)

            if current_value != prev_value:
                print(f"センサー値変化: {prev_value} → {current_value}")

            # 動き検出（LOW→HIGHの立ち上がりエッジ）
            if prev_value == 0 and current_value == 1:
                count += 1
                print(f"動き検出: {count}")

            prev_value = current_value
            time.sleep(SLEEP_TIME)

        print(f"監視終了。合計動作回数: {count}")

        # ローカルログに記録
        write_log(count)

        # サーバーへ送信
        measured_at = datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        payload = {
            'count': count,
            'sensor_no': SENSOR_NO,
            'measured': measured_at,
        }
        json_data = json.dumps(payload).encode('utf-8')

        try:
            send_data(PIR_ENDPOINT, json_data)
        except Exception as e:
            print(f"最終送信エラー ({PIR_ENDPOINT}): {e}")

    except KeyboardInterrupt:
        print("\nプログラムが中断されました")
    except Exception as e:
        print(f"エラーが発生しました: {e}")
    finally:
        try:
            GPIO.cleanup()
            print("GPIOリソースを解放しました")
        except Exception as e:
            print(f"GPIOクリーンアップエラー: {e}")
        sys.exit(0)


def write_log(count):
    """検知回数をローカルログファイルに追記する"""
    now = datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    count_str = f" {count}" if count > 0 else ""
    with open(LOG_FILE, 'a') as f:
        f.write(f"{now}{count_str}\n")


@retry(tries=3, delay=5, backoff=2)
def send_data(url, json_data):
    """サーバーへJSONデータをPOSTする（リトライ付き）"""
    try:
        request = urllib.request.Request(url, data=json_data, method='POST')
        if API_KEY:
            request.add_header('X-API-Key', API_KEY)
        with urllib.request.urlopen(request, timeout=30) as response:
            response.read()
            print(f"データ送信完了: {url}")
        return True
    except Exception as e:
        print(f"送信エラー ({url}): {e}")
        raise  # リトライのために再送出


if __name__ == '__main__':
    main()
