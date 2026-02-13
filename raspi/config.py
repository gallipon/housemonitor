#coding: utf-8
import os
from dotenv import load_dotenv

load_dotenv()

# API設定
API_BASE_URL = os.environ.get('HOUSEMONITOR_API_URL', 'https://example.com')
BME280_ENDPOINT = f'{API_BASE_URL}/qaqaqaa.php'
PIR_ENDPOINT = f'{API_BASE_URL}/qaqaqab.php'

# API認証
API_KEY = os.environ.get('HOUSEMONITOR_API_KEY', '')

# ログ設定
LOG_FILE = os.environ.get('HOUSEMONITOR_LOG_FILE', '/tmp/pirlog10.log')
