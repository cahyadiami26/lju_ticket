-- ============================================================
-- Migrasi Batch 7: Monitoring MikroTik REST API v7
-- Jalankan HANYA jika database sudah dibuat dari schema Batch 1–6.
-- Instalasi baru cukup import database/schema.sql.
-- ============================================================

USE lju_helpdesk;

-- 1) Tambahan kolom konfigurasi & status router
ALTER TABLE mikrotik_settings
  ADD COLUMN verify_ssl TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = verifikasi sertifikat HTTPS' AFTER password,
  ADD COLUMN last_check_at DATETIME DEFAULT NULL AFTER is_active,
  ADD COLUMN last_check_status VARCHAR(20) DEFAULT NULL COMMENT 'ok|error' AFTER last_check_at,
  ADD COLUMN last_check_message VARCHAR(255) DEFAULT NULL AFTER last_check_status,
  ADD COLUMN router_board VARCHAR(100) DEFAULT NULL AFTER last_check_message,
  ADD COLUMN router_version VARCHAR(50) DEFAULT NULL AFTER router_board,
  ADD COLUMN router_uptime VARCHAR(50) DEFAULT NULL AFTER router_version;

-- Password router kini disimpan terenkripsi (AES-256-CBC).
-- Kolom diperlebar karena hasil enkripsi base64 lebih panjang.
ALTER TABLE mikrotik_settings MODIFY COLUMN password VARCHAR(255) DEFAULT NULL COMMENT 'Terenkripsi AES-256 (ENCRYPT_KEY)';

-- 2) Riwayat traffic untuk grafik
CREATE TABLE IF NOT EXISTS mikrotik_traffic_history (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  interface_name VARCHAR(100) NOT NULL,
  rx_bps         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tx_bps         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  recorded_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_traffic_iface (interface_name, recorded_at),
  KEY idx_traffic_time (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3) Pengaturan interval polling & retensi riwayat
INSERT INTO system_settings (setting_key, setting_value) VALUES
('mikrotik_poll_seconds', '60'),
('mikrotik_history_days', '7')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- 4) Contoh interface umum (silakan sesuaikan, atau muat otomatis dari router
--    lewat menu Monitoring → Pengaturan → "Muat Daftar Interface dari Router")
INSERT INTO mikrotik_interfaces (interface_name, label, is_active) VALUES
('ether1', 'Uplink ISP', 1),
('bridge', 'Bridge LAN', 1)
ON DUPLICATE KEY UPDATE label = VALUES(label);
