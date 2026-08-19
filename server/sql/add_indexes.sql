-- センサーテーブルの検索用インデックス
--
-- ダッシュボードは measured_at の範囲検索でデータを取得するが、
-- 初期スキーマには主キー(id)しかないため全件スキャンになる。
-- 数十万行規模では体感できるレベルで遅くなるので、インデックスを追加する。
--
-- 適用:
--   mysql -u <user> -p <dbname> < add_indexes.sql
--
-- MySQL 8 のオンラインDDLで実行されるため、データ受信を止める必要はない。

ALTER TABLE bme280   ADD INDEX idx_measured_at (measured_at);

-- PIR は sensor_no で絞ってから measured_at の範囲を見るため複合インデックスにする
ALTER TABLE hc_sr501 ADD INDEX idx_sensor_measured (sensor_no, measured_at);
