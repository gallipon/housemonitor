-- HouseMonitor 基本スキーマ（センサーデータ用テーブル）
--
-- 適用:
--   mysql -u <user> -p <dbname> < schema.sql
--
-- ダッシュボードのログイン機能（Remember Me）を使う場合は、
-- あわせて setup_remember_me.sql も実行すること。
--
-- 注: インデックスはこのファイルに含まれている。
--     既に運用中のDBへ後からインデックスだけ追加する場合は add_indexes.sql を使う。

-- ---------------------------------------------------------------
-- BME280: 温度・湿度・気圧
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bme280` (
  `id`          int NOT NULL AUTO_INCREMENT,
  `temperature` double(6,2) DEFAULT '0.00',  -- ℃
  `humidity`    double(6,2) DEFAULT '0.00',  -- %RH
  `pressure`    double(7,2) DEFAULT '0.00',  -- hPa
  `measured_at` datetime DEFAULT NULL,       -- センサー側の測定時刻
  `created_at`  datetime DEFAULT NULL,       -- サーバー側の受信時刻
  PRIMARY KEY (`id`),
  -- ダッシュボードは measured_at の範囲検索でデータを取得するため必須。
  -- 無いと数十万行の全件スキャンになる。
  KEY `idx_measured_at` (`measured_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- HC-SR501: 人感センサーの検知回数
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `hc_sr501` (
  `id`          int NOT NULL AUTO_INCREMENT,
  `sensor_no`   tinyint DEFAULT '0',   -- センサー識別番号（部屋ごとに割り当てる）
  -- 1計測周期あたりの検知回数。
  -- tinyint の上限は 127。10分周期での実測最大は 88 程度だったため足りているが、
  -- 周期を延ばす／感度の高いセンサーを使う場合は smallint への変更を検討すること。
  `count`       tinyint DEFAULT '0',
  `measured_at` datetime DEFAULT NULL,
  `created_at`  datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  -- sensor_no で絞ってから measured_at の範囲を見るため複合インデックスにする
  KEY `idx_sensor_measured` (`sensor_no`,`measured_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
