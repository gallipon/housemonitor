# coding: utf-8
"""
BME280センサーデータ取得・送信スクリプト
I2C経由でBME280から温度・湿度・気圧を読み取り、サーバーへPOSTする
"""

import json
import datetime
import urllib.request
from smbus2 import SMBus
from retry import retry
from config import BME280_ENDPOINT, API_KEY

# --- I2C設定 ---
BUS_NUMBER = 1
I2C_ADDRESS = 0x76

bus = SMBus(BUS_NUMBER)

# キャリブレーションパラメータ（get_calib_param()で初期化）
dig_t = []
dig_p = []
dig_h = []

# 温度補償用の中間値（compensate_T で設定、compensate_P/H で参照）
t_fine = 0.0


def write_reg(reg_address, data):
    """BME280のレジスタに1バイト書き込む"""
    bus.write_byte_data(I2C_ADDRESS, reg_address, data)


def get_calib_param():
    """BME280からキャリブレーションデータを読み出し、補正係数を計算する"""
    calib = []

    # レジスタ 0x88-0x9F (温度・気圧) と 0xA1 (湿度) を読み出し
    for i in range(0x88, 0x88 + 24):
        calib.append(bus.read_byte_data(I2C_ADDRESS, i))
    calib.append(bus.read_byte_data(I2C_ADDRESS, 0xA1))

    # レジスタ 0xE1-0xE7 (湿度) を読み出し
    for i in range(0xE1, 0xE1 + 7):
        calib.append(bus.read_byte_data(I2C_ADDRESS, i))

    # 温度キャリブレーション (dig_T1-T3)
    dig_t.append((calib[1] << 8) | calib[0])
    dig_t.append((calib[3] << 8) | calib[2])
    dig_t.append((calib[5] << 8) | calib[4])

    # 気圧キャリブレーション (dig_P1-P9)
    dig_p.append((calib[7] << 8) | calib[6])
    dig_p.append((calib[9] << 8) | calib[8])
    dig_p.append((calib[11] << 8) | calib[10])
    dig_p.append((calib[13] << 8) | calib[12])
    dig_p.append((calib[15] << 8) | calib[14])
    dig_p.append((calib[17] << 8) | calib[16])
    dig_p.append((calib[19] << 8) | calib[18])
    dig_p.append((calib[21] << 8) | calib[20])
    dig_p.append((calib[23] << 8) | calib[22])

    # 湿度キャリブレーション (dig_H1-H6)
    dig_h.append(calib[24])
    dig_h.append((calib[26] << 8) | calib[25])
    dig_h.append(calib[27])
    dig_h.append((calib[28] << 4) | (0x0F & calib[29]))
    dig_h.append((calib[30] << 4) | ((calib[29] >> 4) & 0x0F))
    dig_h.append(calib[31])

    # 符号付き整数への変換
    for i in range(1, 2):
        if dig_t[i] & 0x8000:
            dig_t[i] = (-dig_t[i] ^ 0xFFFF) + 1

    for i in range(1, 8):
        if dig_p[i] & 0x8000:
            dig_p[i] = (-dig_p[i] ^ 0xFFFF) + 1

    for i in range(0, 6):
        if dig_h[i] & 0x8000:
            dig_h[i] = (-dig_h[i] ^ 0xFFFF) + 1


def read_data():
    """センサーからデータを読み取り、補正後にサーバーへ送信する"""
    data = []
    for i in range(0xF7, 0xF7 + 8):
        data.append(bus.read_byte_data(I2C_ADDRESS, i))

    # 生データの組み立て
    pres_raw = (data[0] << 12) | (data[1] << 4) | (data[2] >> 4)
    temp_raw = (data[3] << 12) | (data[4] << 4) | (data[5] >> 4)
    hum_raw = (data[6] << 8) | data[7]

    # 補正値の計算（温度→湿度→気圧の順。t_fineが温度で設定される）
    temperature = compensate_t(temp_raw)
    humidity = compensate_h(hum_raw)
    pressure = compensate_p(pres_raw)

    # 送信データを組み立て
    measured_at = datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    payload = {
        'temperature': temperature.strip(),
        'humidity': humidity.strip(),
        'pressure': pressure.strip(),
        'measured': measured_at,
    }
    json_data = json.dumps(payload).encode('utf-8')

    send_data(BME280_ENDPOINT, json_data)


@retry(tries=10, delay=10, backoff=2)
def send_data(url, json_data):
    """サーバーへJSONデータをPOSTする（リトライ付き）"""
    request = urllib.request.Request(url, data=json_data, method='POST')
    if API_KEY:
        request.add_header('X-API-Key', API_KEY)
    with urllib.request.urlopen(request, timeout=30) as response:
        return response.read()


def compensate_p(adc_p):
    """気圧の生データを補正し、hPa文字列として返す"""
    global t_fine

    v1 = (t_fine / 2.0) - 64000.0
    v2 = (((v1 / 4.0) * (v1 / 4.0)) / 2048) * dig_p[5]
    v2 = v2 + ((v1 * dig_p[4]) * 2.0)
    v2 = (v2 / 4.0) + (dig_p[3] * 65536.0)
    v1 = (((dig_p[2] * (((v1 / 4.0) * (v1 / 4.0)) / 8192)) / 8) + ((dig_p[1] * v1) / 2.0)) / 262144
    v1 = ((32768 + v1) * dig_p[0]) / 32768

    if v1 == 0:
        return "0"

    pressure = ((1048576 - adc_p) - (v2 / 4096)) * 3125
    if pressure < 0x80000000:
        pressure = (pressure * 2.0) / v1
    else:
        pressure = (pressure / v1) * 2

    v1 = (dig_p[8] * (((pressure / 8.0) * (pressure / 8.0)) / 8192.0)) / 4096
    v2 = ((pressure / 4.0) * dig_p[7]) / 8192.0
    pressure = pressure + ((v1 + v2 + dig_p[6]) / 16.0)

    return "%7.2f" % (pressure / 100)


def compensate_t(adc_t):
    """温度の生データを補正し、℃文字列として返す。t_fineも更新する"""
    global t_fine

    v1 = (adc_t / 16384.0 - dig_t[0] / 1024.0) * dig_t[1]
    v2 = (adc_t / 131072.0 - dig_t[0] / 8192.0) * (adc_t / 131072.0 - dig_t[0] / 8192.0) * dig_t[2]
    t_fine = v1 + v2
    temperature = t_fine / 5120.0

    return "%-6.2f" % temperature


def compensate_h(adc_h):
    """湿度の生データを補正し、%RH文字列として返す"""
    global t_fine

    var_h = t_fine - 76800.0
    if var_h == 0:
        return "0"

    var_h = (adc_h - (dig_h[3] * 64.0 + dig_h[4] / 16384.0 * var_h)) * \
            (dig_h[1] / 65536.0 * (1.0 + dig_h[5] / 67108864.0 * var_h * (1.0 + dig_h[2] / 67108864.0 * var_h)))
    var_h = var_h * (1.0 - dig_h[0] * var_h / 524288.0)

    if var_h > 100.0:
        var_h = 100.0
    elif var_h < 0.0:
        var_h = 0.0

    return "%6.2f" % var_h


def setup():
    """BME280の動作モードを設定する"""
    osrs_t   = 1  # Temperature oversampling x1
    osrs_p   = 1  # Pressure oversampling x1
    osrs_h   = 1  # Humidity oversampling x1
    mode     = 3  # Normal mode
    t_sb     = 5  # Standby 1000ms
    filter_c = 0  # Filter off
    spi3w_en = 0  # 3-wire SPI disabled

    ctrl_meas_reg = (osrs_t << 5) | (osrs_p << 2) | mode
    config_reg    = (t_sb << 5) | (filter_c << 2) | spi3w_en
    ctrl_hum_reg  = osrs_h

    write_reg(0xF2, ctrl_hum_reg)
    write_reg(0xF4, ctrl_meas_reg)
    write_reg(0xF5, config_reg)


# --- 初期化 ---
setup()
get_calib_param()

if __name__ == '__main__':
    try:
        read_data()
    except KeyboardInterrupt:
        pass
