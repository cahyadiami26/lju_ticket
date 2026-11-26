-- ============================================================
-- Migrasi Batch 4: Modul Network & Internet Complaint
-- Jalankan ini HANYA jika database sudah dibuat dengan schema Batch 1–3.
-- Untuk instalasi baru, cukup import database/schema.sql (sudah memuat semua).
-- ============================================================

USE lju_helpdesk;

-- 1) Tabel ruangan
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

-- 2) Kolom tambahan pada tickets
ALTER TABLE tickets
  ADD COLUMN technician_action TEXT DEFAULT NULL COMMENT 'Tindakan perbaikan yang dilakukan' AFTER technician_result,
  ADD COLUMN room_id INT UNSIGNED DEFAULT NULL COMMENT 'FK ke rooms.id' AFTER technician_action,
  ADD COLUMN checked_at DATETIME DEFAULT NULL COMMENT 'Waktu pemeriksaan teknisi' AFTER room_id,
  ADD KEY idx_tickets_room (room_id),
  ADD CONSTRAINT fk_tickets_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL;

-- 3) Checklist pemeriksaan jaringan
CREATE TABLE IF NOT EXISTS network_checks (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id   INT UNSIGNED NOT NULL,
  user_id     INT          DEFAULT NULL,
  user_name   VARCHAR(100) DEFAULT NULL,
  check_type  VARCHAR(50)  NOT NULL DEFAULT 'lainnya',
  result      VARCHAR(255) DEFAULT NULL,
  notes       TEXT         DEFAULT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_checks_ticket (ticket_id),
  CONSTRAINT fk_checks_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4) Seed ruangan (sesuaikan dengan denah kantor)
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

-- 5) Isi room_id untuk tiket jaringan lama berdasarkan nama ruangan
UPDATE tickets t
JOIN rooms r ON r.name = t.location_room
SET t.room_id = r.id
WHERE t.is_network_complaint = 1 AND t.room_id IS NULL;
