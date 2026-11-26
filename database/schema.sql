-- ============================================================
-- LJU IT Helpdesk & Ticketing System — Database Schema
-- Database: lju_helpdesk (tabel `users` berada di database terpisah)
-- ============================================================

CREATE DATABASE IF NOT EXISTS lju_helpdesk DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE lju_helpdesk;

-- ------------------------------------------------------------
-- Kategori pengaduan
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(100) NOT NULL,
  slug        VARCHAR(100) NOT NULL,
  icon        VARCHAR(50)  DEFAULT NULL,
  description TEXT         DEFAULT NULL,
  is_network  TINYINT(1)   NOT NULL DEFAULT 0,
  sort_order  INT          NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Ruangan / lokasi (untuk riwayat gangguan jaringan per ruangan)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rooms (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120) NOT NULL COMMENT 'Nama ruangan',
  building    VARCHAR(80)  DEFAULT NULL COMMENT 'Gedung',
  floor       VARCHAR(40)  DEFAULT NULL COMMENT 'Lantai',
  notes       VARCHAR(255) DEFAULT NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rooms_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Tiket utama
-- status: open | assigned | in_progress | waiting_user | resolved | closed
-- priority: low | medium | high | critical
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tickets (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_number       VARCHAR(30)  NOT NULL,
  user_id             INT          NOT NULL COMMENT 'FK logis ke users.id (database terpisah)',
  user_name           VARCHAR(100) DEFAULT NULL COMMENT 'Snapshot nama pelapor',
  user_email          VARCHAR(100) DEFAULT NULL,
  nama_bidang         VARCHAR(100) DEFAULT NULL COMMENT 'Snapshot users.division',
  nama_divisi         VARCHAR(100) DEFAULT NULL COMMENT 'Snapshot users.department',
  category_id         INT UNSIGNED DEFAULT NULL,
  title               VARCHAR(255) NOT NULL,
  description         TEXT         NOT NULL,
  priority            ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  status              ENUM('open','assigned','in_progress','waiting_user','resolved','closed') NOT NULL DEFAULT 'open',
  assigned_to         INT          DEFAULT NULL COMMENT 'FK logis ke users.id (petugas IT)',
  assigned_name       VARCHAR(100) DEFAULT NULL,
  is_network_complaint TINYINT(1)  NOT NULL DEFAULT 0,
  network_type        VARCHAR(100) DEFAULT NULL COMMENT 'internet_lambat|internet_putus|wifi|lan|tidak_stabil|akses_aplikasi|lainnya',
  location_room       VARCHAR(150) DEFAULT NULL COMMENT 'Lokasi/ruangan gangguan',
  affected_device     VARCHAR(255) DEFAULT NULL COMMENT 'Perangkat jaringan bermasalah',
  technician_result   TEXT         DEFAULT NULL COMMENT 'Hasil pemeriksaan teknisi',
  technician_action   TEXT         DEFAULT NULL COMMENT 'Tindakan perbaikan yang dilakukan',
  room_id             INT UNSIGNED DEFAULT NULL COMMENT 'FK ke rooms.id',
  checked_at          DATETIME     DEFAULT NULL COMMENT 'Waktu pemeriksaan teknisi',
  sla_due_at          DATETIME     DEFAULT NULL COMMENT 'Target penyelesaian',
  sla_response_due_at DATETIME     DEFAULT NULL COMMENT 'Target respons awal',
  sla_breached         TINYINT(1)  NOT NULL DEFAULT 0 COMMENT 'Sudah melewati SLA',
  sla_warned_at       DATETIME     DEFAULT NULL COMMENT 'Kapan peringatan mendekati SLA dikirim',
  escalated_at        DATETIME     DEFAULT NULL COMMENT 'Kapan eskalasi otomatis terakhir',
  escalate_level      TINYINT      NOT NULL DEFAULT 0 COMMENT 'Jumlah eskalasi otomatis',
  responded_at        DATETIME     DEFAULT NULL,
  resolved_at         DATETIME     DEFAULT NULL,
  closed_at           DATETIME     DEFAULT NULL,
  rating              TINYINT      DEFAULT NULL COMMENT '1-5',
  rating_comment      TEXT         DEFAULT NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tickets_number (ticket_number),
  KEY idx_tickets_user (user_id),
  KEY idx_tickets_status (status),
  KEY idx_tickets_priority (priority),
  KEY idx_tickets_assigned (assigned_to),
  KEY idx_tickets_created (created_at),
  KEY idx_tickets_room (room_id),
  CONSTRAINT fk_tickets_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_tickets_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Komentar / percakapan tiket (is_internal = catatan internal petugas)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ticket_comments (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id   INT UNSIGNED NOT NULL,
  user_id     INT          NOT NULL,
  user_name   VARCHAR(100) DEFAULT NULL,
  comment     TEXT         NOT NULL,
  is_internal TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_comments_ticket (ticket_id),
  CONSTRAINT fk_comments_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Lampiran tiket
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ticket_attachments (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id   INT UNSIGNED NOT NULL,
  user_id     INT          NOT NULL,
  file_name   VARCHAR(255) NOT NULL,
  file_path   VARCHAR(255) NOT NULL COMMENT 'Path relatif di uploads/tickets/',
  file_type   VARCHAR(100) DEFAULT NULL,
  file_size   INT UNSIGNED DEFAULT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attachments_ticket (ticket_id),
  CONSTRAINT fk_attachments_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Riwayat aktivitas tiket
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ticket_history (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id   INT UNSIGNED NOT NULL,
  user_id     INT          DEFAULT NULL,
  user_name   VARCHAR(100) DEFAULT NULL,
  action      VARCHAR(100) NOT NULL COMMENT 'created|assigned|status_changed|priority_changed|comment|attachment|escalated|transferred|reopened|resolved|closed|rated',
  old_value   VARCHAR(255) DEFAULT NULL,
  new_value   VARCHAR(255) DEFAULT NULL,
  notes       TEXT         DEFAULT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_history_ticket (ticket_id),
  CONSTRAINT fk_history_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Pemeriksaan jaringan oleh teknisi (checklist hasil pengujian)
-- check_type: ping | speedtest | wifi_signal | lan_port | traceroute | lainnya
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS network_checks (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id   INT UNSIGNED NOT NULL,
  user_id     INT          DEFAULT NULL,
  user_name   VARCHAR(100) DEFAULT NULL,
  check_type  VARCHAR(50)  NOT NULL DEFAULT 'lainnya',
  result      VARCHAR(255) DEFAULT NULL COMMENT 'Ringkasan hasil pengujian',
  notes       TEXT         DEFAULT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_checks_ticket (ticket_id),
  CONSTRAINT fk_checks_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Notifikasi in-app
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT          NOT NULL,
  title       VARCHAR(255) NOT NULL,
  message     TEXT         DEFAULT NULL,
  link        VARCHAR(255) DEFAULT NULL,
  type        VARCHAR(50)  DEFAULT 'info',
  channel     VARCHAR(20)  NOT NULL DEFAULT 'app' COMMENT 'app|email|whatsapp',
  is_read     TINYINT(1)   NOT NULL DEFAULT 0,
  sent_at     DATETIME     DEFAULT NULL COMMENT 'Terisi bila kanal eksternal berhasil dikirim',
  send_status VARCHAR(20)  DEFAULT NULL COMMENT 'pending|sent|failed|skipped',
  send_error  VARCHAR(255) DEFAULT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notif_user (user_id, is_read),
  KEY idx_notif_channel (channel, send_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Log pengiriman notifikasi eksternal (email / WhatsApp)
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- FAQ / Knowledge Base
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS faq_articles (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id  INT UNSIGNED DEFAULT NULL,
  title        VARCHAR(255) NOT NULL,
  content      TEXT         NOT NULL,
  keywords     VARCHAR(255) DEFAULT NULL,
  views        INT UNSIGNED NOT NULL DEFAULT 0,
  is_published TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_faq_category (category_id),
  CONSTRAINT fk_faq_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Kebijakan SLA per prioritas (menit)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sla_policies (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  priority            ENUM('low','medium','high','critical') NOT NULL,
  response_minutes    INT NOT NULL COMMENT 'Target respons awal',
  resolution_minutes  INT NOT NULL COMMENT 'Target penyelesaian',
  description         VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sla_priority (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Tim IT (daftar user_id yang bertugas sebagai petugas helpdesk)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS it_staff (
  user_id    INT NOT NULL COMMENT 'FK logis ke users.id',
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Pengaturan MikroTik (Batch 7) — satu baris konfigurasi
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mikrotik_settings (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  host_url          VARCHAR(255) DEFAULT NULL COMMENT 'Contoh: https://192.168.1.1',
  username          VARCHAR(100) DEFAULT NULL,
  password          VARCHAR(255) DEFAULT NULL COMMENT 'Terenkripsi (AES-256 via ENCRYPT_KEY)',
  verify_ssl        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = verifikasi sertifikat HTTPS',
  is_active         TINYINT(1)   NOT NULL DEFAULT 0,
  last_check_at     DATETIME     DEFAULT NULL,
  last_check_status VARCHAR(20)  DEFAULT NULL COMMENT 'ok|error',
  last_check_message VARCHAR(255) DEFAULT NULL,
  router_board      VARCHAR(100) DEFAULT NULL,
  router_version    VARCHAR(50)  DEFAULT NULL,
  router_uptime     VARCHAR(50)  DEFAULT NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS mikrotik_interfaces (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  interface_name VARCHAR(100) NOT NULL COMMENT 'Nama interface di MikroTik',
  label          VARCHAR(100) DEFAULT NULL,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order     INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mikrotik_iface (interface_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Riwayat traffic interface (untuk grafik, diisi cron/monitoring)
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- Pengaturan sistem (key-value)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS system_settings (
  setting_key   VARCHAR(100) NOT NULL,
  setting_value TEXT         DEFAULT NULL,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- SEED DATA
-- ============================================================

INSERT INTO rooms (name, building, floor) VALUES
('Ruang Direksi', 'Gedung Utama', 'Lantai 3'),
('Ruang Keuangan', 'Gedung Utama', 'Lantai 2'),
('Ruang Akuntansi', 'Gedung Utama', 'Lantai 2'),
('Ruang SDM', 'Gedung Utama', 'Lantai 2'),
('Ruang Umum', 'Gedung Utama', 'Lantai 1'),
('Ruang IT', 'Gedung Utama', 'Lantai 1'),
('Ruang Pelayanan Pelanggan', 'Gedung Utama', 'Lantai 1'),
('Ruang Rapat', 'Gedung Utama', 'Lantai 3'),
('Gudang', 'Gedung Belakang', 'Lantai 1'),
('Pos Keamanan', 'Area Depan', 'Lantai 1')
ON DUPLICATE KEY UPDATE building = VALUES(building), floor = VALUES(floor);

INSERT INTO categories (name, slug, icon, description, is_network, sort_order) VALUES
('Internet dan Jaringan', 'internet-jaringan', '◉', 'Gangguan koneksi internet, WiFi, LAN, dan infrastruktur jaringan.', 1, 1),
('Aplikasi Perusahaan', 'aplikasi-perusahaan', '▦', 'Permasalahan aplikasi internal perusahaan.', 0, 2),
('Komputer dan Laptop', 'komputer-laptop', '▭', 'Gangguan komputer, laptop, dan perangkat kerja.', 0, 3),
('Printer dan Scanner', 'printer-scanner', '⎙', 'Gangguan printer, scanner, dan perangkat cetak.', 0, 4),
('Email Perusahaan', 'email-perusahaan', '✉', 'Permasalahan email perusahaan dan webmail.', 0, 5),
('Akun dan Akses Pengguna', 'akun-akses', '⚿', 'Reset password, akses aplikasi, dan hak akses.', 0, 6),
('Perangkat Keras', 'perangkat-keras', '▣', 'Permintaan/perbaikan perangkat keras.', 0, 7),
('Permintaan Instalasi Aplikasi', 'instalasi-aplikasi', '＋', 'Permintaan instalasi aplikasi/software.', 0, 8),
('Layanan IT Lainnya', 'layanan-lainnya', '…', 'Permintaan layanan IT lainnya.', 0, 9)
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO sla_policies (priority, response_minutes, resolution_minutes, description) VALUES
('critical', 15, 120, 'Internet seluruh kantor mati'),
('high',     30, 240, 'Aplikasi utama tidak dapat diakses'),
('medium',  120, 1440, 'Internet satu ruangan bermasalah'),
('low',     240, 4320, 'Permintaan instalasi aplikasi')
ON DUPLICATE KEY UPDATE response_minutes = VALUES(response_minutes), resolution_minutes = VALUES(resolution_minutes);

INSERT INTO faq_articles (category_id, title, content, keywords) VALUES
(1, 'Cara mengatasi WiFi tidak terhubung', '1. Pastikan WiFi aktif di laptop. 2. Lupakan jaringan lalu hubungkan kembali. 3. Restart laptop. 4. Jika masih gagal, buat tiket kategori Internet dan Jaringan dengan lokasi ruangan Anda.', 'wifi, internet, tidak terhubung'),
(1, 'Internet lambat di ruangan saya', 'Periksa kabel LAN terpasang dengan benar, tutup aplikasi yang menyedot bandwidth, lalu uji kecepatan. Jika lambat di satu ruangan saja, laporkan via tiket dengan mencantumkan nama ruangan.', 'internet lambat, lemot, lan'),
(5, 'Cara mengakses webmail perusahaan', 'Buka webmail perusahaan dan login dengan akun yang diberikan IT. Jika lupa password, buat tiket kategori Akun dan Akses Pengguna.', 'webmail, email, password'),
(2, 'Aplikasi internal tidak bisa dibuka', 'Bersihkan cache browser (Ctrl+Shift+Delete), coba browser lain, lalu buat tiket kategori Aplikasi Perusahaan dan lampirkan screenshot error.', 'aplikasi, error, tidak bisa dibuka')
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO system_settings (setting_key, setting_value) VALUES
('app_name', 'LJU IT Helpdesk & Ticketing System'),
('ticket_prefix', 'LJU'),
('auto_close_days', '7'),
('sla_warning_minutes', '60'),
('sla_auto_escalate', '1'),
('sla_escalate_max_level', '2'),
('notify_email_enabled', '0'),
('notify_whatsapp_enabled', '0'),
('email_from', 'helpdesk@lju.co.id'),
('email_from_name', 'LJU IT Helpdesk'),
('whatsapp_api_url', 'https://api.fonnte.com/send'),
('whatsapp_token', ''),
('cron_token', 'ganti_token_cron_anda'),
('mikrotik_poll_seconds', '60'),
('mikrotik_history_days', '7')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
