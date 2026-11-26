-- ============================================================
-- Migrasi Batch 5: SLA & Escalation + Notification Center
-- Jalankan HANYA jika database sudah dibuat dari schema Batch 1–4.
-- Instalasi baru cukup import database/schema.sql.
-- ============================================================

USE lju_helpdesk;

-- 1) Kolom SLA pada tickets
ALTER TABLE tickets
  ADD COLUMN sla_response_due_at DATETIME DEFAULT NULL COMMENT 'Target respons awal' AFTER sla_due_at,
  ADD COLUMN sla_breached TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Sudah melewati SLA' AFTER sla_response_due_at,
  ADD COLUMN sla_warned_at DATETIME DEFAULT NULL COMMENT 'Kapan peringatan mendekati SLA dikirim' AFTER sla_breached,
  ADD COLUMN escalated_at DATETIME DEFAULT NULL COMMENT 'Kapan eskalasi otomatis terakhir' AFTER sla_warned_at,
  ADD COLUMN escalate_level TINYINT NOT NULL DEFAULT 0 COMMENT 'Jumlah eskalasi otomatis' AFTER escalated_at;

-- 2) Kanal notifikasi
ALTER TABLE notifications
  ADD COLUMN channel VARCHAR(20) NOT NULL DEFAULT 'app' COMMENT 'app|email|whatsapp' AFTER type,
  ADD COLUMN sent_at DATETIME DEFAULT NULL COMMENT 'Terisi bila kanal eksternal berhasil dikirim' AFTER is_read,
  ADD COLUMN send_status VARCHAR(20) DEFAULT NULL COMMENT 'pending|sent|failed|skipped' AFTER sent_at,
  ADD COLUMN send_error VARCHAR(255) DEFAULT NULL AFTER send_status,
  ADD KEY idx_notif_channel (channel, send_status);

-- 3) Log pengiriman eksternal
CREATE TABLE IF NOT EXISTS notification_logs (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  channel     VARCHAR(20)  NOT NULL COMMENT 'email|whatsapp',
  recipient   VARCHAR(190) NOT NULL,
  subject     VARCHAR(255) DEFAULT NULL,
  body        TEXT         DEFAULT NULL,
  ticket_id   INT UNSIGNED DEFAULT NULL,
  status      VARCHAR(20)  NOT NULL DEFAULT 'pending' COMMENT 'pending|sent|failed',
  response    TEXT         DEFAULT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_nlog_channel (channel, status),
  KEY idx_nlog_ticket (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4) Pengaturan baru (SLA, email, WhatsApp, cron)
INSERT INTO system_settings (setting_key, setting_value) VALUES
('sla_warning_minutes', '60'),
('sla_auto_escalate', '1'),
('sla_escalate_max_level', '2'),
('notify_email_enabled', '0'),
('notify_whatsapp_enabled', '0'),
('email_from', 'helpdesk@lju.co.id'),
('email_from_name', 'LJU IT Helpdesk'),
('whatsapp_api_url', 'https://api.fonnte.com/send'),
('whatsapp_token', ''),
('cron_token', 'ganti_token_cron_anda')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- 5) Isi target respons untuk tiket lama (berdasarkan waktu dibuat + kebijakan)
UPDATE tickets t
JOIN sla_policies s ON s.priority = t.priority
SET t.sla_response_due_at = DATE_ADD(t.created_at, INTERVAL s.response_minutes MINUTE)
WHERE t.sla_response_due_at IS NULL;

-- 6) Tandai tiket lama yang sudah melewati SLA
UPDATE tickets
SET sla_breached = 1
WHERE sla_due_at IS NOT NULL
  AND sla_due_at < NOW()
  AND status NOT IN ('resolved','closed');
