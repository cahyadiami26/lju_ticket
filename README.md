# LJU IT Helpdesk & Ticketing System

Pusat layanan pengaduan dan permintaan bantuan teknologi informasi bagi seluruh karyawan **PT Lampung Jasa Utama (LJU)**.

- **URL produksi:** https://helpdesk.lju.co.id
- **Stack:** PHP Native (tanpa framework) + MySQL (PDO) + domPDF (install manual, tanpa Composer)
- **Login:** SSO LJU (`LJU_APPS` session, domain `.lju.co.id`)
- **Logo:** https://dms.lju.co.id/assets/img/logo.png
- **Avatar user:** `https://dms.lju.co.id/uploads/avatars/{avatar}` (kolom `avatar` di tabel `users`), fallback inisial jika kosong

> **Catatan nomenclature:** kolom `division` pada tabel `users` ditampilkan sebagai **Nama Bidang**,
> kolom `department` ditampilkan sebagai **Nama Divisi** di dalam aplikasi ini.

---

## Cara Kerja Login (SSO)

Aplikasi ini **tidak memiliki login sendiri**. Session di-handle oleh Portal SSO LJU:

```php
ini_set('session.cookie_domain', '.lju.co.id');
session_name('LJU_APPS');
session_set_cookie_params([
    'lifetime' => 86400,
    'path'     => '/',
    'domain'   => '.lju.co.id',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly'  => true,
    'samesite' => 'Lax'
]);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['user_id'])) {
    header('Location: https://lju.co.id/dashboard/?target_system='
        . urlencode('https://helpdesk.lju.co.id')
        . '&redirect_after_login=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
```

Setelah login di SSO, session diisi ulang dari tabel `users` (database terpisah, satu mesin yang sama):

```php
$_SESSION['user_id']    = $userRow['id'];
$_SESSION['user_nik']   = $userRow['nik'];
$_SESSION['username']   = $userRow['username'];
$_SESSION['user_name']  = $userRow['name'];
$_SESSION['user_email'] = $userRow['email'];
$_SESSION['role']       = $userRow['role'];
$_SESSION['position']   = $userRow['position'];
$_SESSION['division']   = $userRow['division'];   // → Nama Bidang
$_SESSION['department'] = $userRow['department']; // → Nama Divisi
$_SESSION['avatar']     = $userRow['avatar'];
$_SESSION['photo']      = $userRow['photo'];
$_SESSION['signature']  = $userRow['signature'];
$_SESSION['rfid']       = $userRow['rfid'];
$_SESSION['webmail_user'] = $userRow['webmail_user'];
$_SESSION['webmail_pass'] = $userRow['webmail_pass'];
```

Implementasi ada di `includes/session.php` + `includes/auth.php`.
Setiap request, session di-refresh dari database `users` agar data selalu mutakhir.

**Mode pengembangan (DEV_MODE):** jika `DEV_MODE = true` di `config/config.php` dan belum ada session SSO,
aplikasi menampilkan halaman `demo-login.php` untuk memilih user dari database `users` (tanpa password).
**Wajib `DEV_MODE = false` di production.**

---

## Struktur Folder

```
lju_ticket/
├── config/
│   ├── config.example.php   # Template konfigurasi (copy ke config.php)
│   └── config.php           # Konfigurasi nyata (di-.gitignore, jangan commit)
├── includes/
│   ├── session.php          # SSO session + proteksi halaman
│   ├── db.php               # Koneksi PDO (helpdesk + users)
│   ├── url.php              # Base URL, url(), asset() untuk subfolder
│   ├── auth.php             # Helper user, role, avatar, refresh session
│   ├── functions.php        # Helper umum (escape, CSRF, tanggal, badge, SLA)
│   ├── ticket.php           # Nomor tiket, history, notifikasi, upload
│   ├── ticket_actions.php   # Aksi manajemen tiket (assign/status/eskalasi/…)
│   ├── network.php          # Modul jaringan (ruangan, jenis, checklist)
│   ├── settings.php         # Pengaturan system_settings
│   ├── notifier.php         # Notification Center (in-app/email/WhatsApp)
│   ├── sla.php              # Mesin SLA + eskalasi otomatis
│   ├── reports.php          # Query agregasi laporan
│   ├── pdf.php              # Integrasi domPDF (manual)
│   ├── header.php           # Layout header + topbar
│   ├── sidebar.php          # Layout sidebar
│   └── footer.php           # Layout footer
├── assets/
│   ├── css/app.css          # Stylesheet (clean, elegan, responsif)
│   └── js/app.js            # JavaScript vanilla
├── database/
│   ├── schema.sql           # Skema lengkap + seed (instalasi baru)
│   ├── migration_batch4.sql # Migrasi modul jaringan (DB lama)
│   └── migration_batch5.sql # Migrasi SLA & notifikasi (DB lama)
├── cron/
│   └── sla.php              # Cron SLA (tiap 10 menit, token-protected)
├── uploads/
│   └── tickets/             # Folder lampiran tiket
├── library/
│   └── dompdf/              # domPDF install manual (tanpa Composer)
├── tickets/
│   ├── create.php           # Form buat tiket
│   └── view.php             # Detail tiket + komunikasi + aksi IT
├── admin/
│   ├── tickets.php          # Manajemen tiket (meja kerja IT)
│   └── faq.php              # Kelola FAQ / Knowledge Base
├── network/
│   └── index.php            # Dashboard gangguan jaringan per ruangan
├── reports/
│   ├── index.php            # Laporan & analitik manajerial
│   ├── sla.php              # Laporan SLA bulanan
│   └── export.php           # Export PDF (domPDF) & CSV
├── settings/
│   └── index.php            # Pengaturan SLA, email, WhatsApp, cron
├── index.php                # Entry point → dashboard / SSO redirect
├── dashboard.php            # Dashboard karyawan + ringkasan IT
├── tickets.php              # Tracking & riwayat tiket
├── faq.php                  # FAQ / Knowledge Base (karyawan)
├── notifications.php        # Notification Center in-app
├── demo-login.php           # Login demo (DEV_MODE saja)
├── logout.php               # Keluar (hapus session lokal)
└── profile.php              # Profil user (dari session + tabel users)
```

---

## Instalasi

1. Copy konfigurasi:
   ```bash
   cp config/config.example.php config/config.php
   ```
   Isi `DB_HOST`, `DB_NAME` (database helpdesk), `DB_USER`, `DB_PASS`,
   serta `USERS_DB_NAME` (database tempat tabel `users` berada — satu mesin, database berbeda).
2. Import database:
   ```bash
   mysql -u root -p lju_helpdesk < database/schema.sql
   ```
3. Pastikan folder `uploads/tickets/` dan `library/dompdf/lib/fonts` writable oleh web server
   (`chmod 755` / `chown www-data`).
4. Install **domPDF manual** (untuk export PDF): lihat `library/dompdf/README.md`.
   Tanpa domPDF, menu export otomatis menampilkan preview siap cetak.
5. Set `DEV_MODE = false` untuk production, `true` hanya untuk development lokal.
6. Arahkan domain `helpdesk.lju.co.id` ke folder ini (document root).
7. Atur cron SLA tiap 10 menit (token ada di menu **Pengaturan**):
   ```bash
   */10 * * * * curl -s "https://helpdesk.lju.co.id/cron/sla.php?token=TOKEN" > /dev/null
   ```
8. Buka **Pengaturan** → isi SMTP email & WhatsApp API (opsional) → klik **Kirim Uji**.

---

## Roadmap Batch (dikerjakan bertahap, berhenti per batch untuk konfirmasi)

| Batch | Modul | Status |
|-------|-------|--------|
| **1** | **Fondasi** — Config, SSO session, layout, schema DB, dashboard skeleton, profil, README | ✅ Selesai |
| **2** | **Employee Portal** — Buat tiket, tracking, riwayat, komunikasi, upload lampiran, rating, FAQ | ✅ Selesai |
| **3** | **Ticket Management** — Assignment, prioritas, status, eskalasi, internal notes, transfer, reopen, history, SLA | ✅ Selesai |
| **4** | **Network & Internet Complaint** — Modul khusus jaringan, lokasi/ruangan, hasil pemeriksaan, perangkat, riwayat per ruangan | ✅ Selesai |
| **5** | **SLA & Escalation + Notification Center** — SLA otomatis, peringatan, notifikasi in-app/email/WA | ✅ Selesai |
| **6** | **Reporting & Analytics + domPDF** — Dashboard manajerial, laporan SLA, export PDF (domPDF manual) | ✅ Selesai |
| **7** | **Network Monitoring MikroTik** — REST API v7: traffic interface, PPPoE active, DHCP lease, simple queue | ⬜ Belum |

---

## Daftar Fitur Lengkap & Status

### A. Employee Portal (Portal Karyawan)
| Fitur | Keterangan | Status |
|-------|------------|--------|
| Dashboard karyawan | Ringkasan tiket yang dibuat | ✅ Batch 1 (skeleton, data real) |
| Buat tiket baru | Formulir pengaduan / permintaan bantuan | ✅ Batch 2 (`tickets/create.php`) |
| Tracking tiket | Memantau status penanganan | ✅ Batch 2 (`tickets.php` + `tickets/view.php`) |
| Riwayat pengaduan | Daftar seluruh tiket sebelumnya | ✅ Batch 2 (filter status/kategori/cari + pagination) |
| Komunikasi tiket | Percakapan pelapor ↔ petugas IT | ✅ Batch 2 (komentar + catatan internal IT) |
| Upload lampiran | Foto, screenshot, video, dokumen | ✅ Batch 2 (maks 10 MB/file, validasi ekstensi) |
| Rating layanan | Penilaian setelah tiket selesai | ✅ Batch 2 (1–5 bintang + komentar) |
| FAQ / Knowledge Base | Panduan masalah umum | ✅ Batch 2 (`faq.php`) |
| Kelola FAQ | Tambah/ubah/publikasi/hapus artikel oleh tim IT | ✅ Batch 2 (`admin/faq.php`) |

Kategori pengaduan: Internet & jaringan, Aplikasi perusahaan, Komputer & laptop, Printer & scanner,
Email perusahaan, Akun & akses pengguna, Perangkat keras, Permintaan instalasi aplikasi, Layanan IT lainnya.

### B. Ticket Management (Manajemen Tiket)
| Fitur | Keterangan | Status |
|-------|------------|--------|
| Ticket Number | Nomor tiket otomatis (`LJU-YYYYMMDD-XXXX`) | ✅ Batch 2 |
| Ticket Assignment | Penugasan ke petugas | ✅ Batch 3 (panel IT + bulk assign) |
| Ticket Priority | Low, Medium, High, Critical | ✅ Batch 2 dipilih pelapor · Batch 3 diubah IT (SLA dihitung ulang) |
| Ticket Status | Open, Assigned, In Progress, Waiting User, Resolved, Closed | ✅ Batch 3 (transisi + timestamp + notifikasi) |
| Ticket Escalation | Eskalasi ke penanggung jawab berikutnya | ✅ Batch 3 manual · Batch 5 eskalasi otomatis saat SLA terlewati |
| Internal Notes | Catatan internal petugas | ✅ Batch 2 komentar internal · Batch 3 tercatat di riwayat |
| Ticket Transfer | Pindah antar petugas | ✅ Batch 3 (wajib alasan) |
| Ticket Reopen | Buka kembali tiket belum tuntas | ✅ Batch 3 (oleh IT maupun pelapor) |
| Ticket History | Riwayat aktivitas tiket | ✅ Batch 3 (timeline semua perubahan) |
| SLA Monitoring | Target waktu penyelesaian | ✅ Batch 5 (peringatan H-60mnt, eskalasi otomatis, laporan bulanan) |

> **Resolved vs Closed:** *Resolved* = petugas menyatakan masalah selesai.
> *Closed* = penyelesaian dikonfirmasi pelapor / ditutup sesuai kebijakan.

### C. Network & Internet Complaint
| Fitur | Status |
|-------|--------|
| Pelaporan internet lambat / terputus / WiFi / LAN / tidak stabil | ✅ Batch 4 (7 jenis gangguan) |
| Gangguan akses aplikasi internal | ✅ Batch 4 |
| Identifikasi lokasi gangguan (ruangan) | ✅ Batch 4 (master `rooms` 10 ruangan + lokasi bebas) |
| Hasil pemeriksaan teknisi | ✅ Batch 4 (hasil + tindakan + checklist uji) |
| Perangkat jaringan bermasalah | ✅ Batch 4 (8 jenis perangkat) |
| Riwayat gangguan per ruangan | ✅ Batch 4 (`network/index.php` + filter ruangan) |

### D. SLA & Escalation Management
| Prioritas | Contoh gangguan | Respons awal | Target penyelesaian |
|-----------|-----------------|--------------|---------------------|
| Critical | Internet seluruh kantor mati | 15 menit | 2 jam |
| High | Aplikasi utama tidak dapat diakses | 30 menit | 4 jam |
| Medium | Internet satu ruangan bermasalah | 2 jam | 1 hari kerja |
| Low | Permintaan instalasi aplikasi | 4 jam | 3 hari kerja |

Fitur: penghitungan SLA otomatis ✅, peringatan mendekati batas ✅, notifikasi melewati SLA ✅,
eskalasi otomatis ✅, laporan pencapaian SLA/bulan ✅ (`reports/sla.php`), rata-rata respons & penyelesaian ✅.

### E. Notification Center
Notifikasi in-app ✅, email perusahaan ✅ (SMTP native / `mail()`), WhatsApp ✅ (API gateway Fonnte-compatible)
untuk: tiket baru, ditugaskan, status berubah, prioritas berubah, komentar, tiket selesai, melewati SLA, eskalasi.
Pengaturan kanal + uji kirim ada di menu **Pengaturan**; log pengiriman tersimpan di `notification_logs`.

### F. Reporting & Analytics
Total tiket ✅, tiket terbuka ✅, tiket selesai ✅, melewati SLA ✅, per kategori ✅, per ruangan ✅,
kinerja petugas ✅, rata-rata penyelesaian ✅, kepuasan pengguna ✅, tren harian ✅,
export PDF domPDF ✅ (`reports/export.php`) + export CSV untuk Excel ✅.

### G. Network Monitoring (MikroTik REST API v7)
Setting URL/username/password/list interface via halaman setting; monitoring traffic interface
(list & graph), PPPoE active (jumlah & daftar), DHCP lease (jumlah & daftar), simple queue
(list & graph). → **Batch 7**

---

### Migrasi database (bila sudah pernah import schema lama)
- Batch 4 menambah tabel `rooms` + `network_checks` dan kolom `room_id`, `technician_action`, `checked_at`.
- Jalankan berurutan: `mysql -u user -p lju_helpdesk < database/migration_batch4.sql` lalu `< database/migration_batch5.sql`
- Instalasi baru cukup import `database/schema.sql` (sudah lengkap).

---
## Changelog

### Batch 1 — Fondasi (2026-11-26)
- `config/config.example.php` + `config/config.php`: konfigurasi DB helpdesk & DB users, DEV_MODE, URL logo/avatar.
- `includes/session.php`: SSO session `LJU_APPS` (domain `.lju.co.id`), redirect ke Portal SSO.
- `includes/db.php`: koneksi PDO ke database helpdesk dan database `users` (satu mesin).
- `includes/auth.php`: refresh session dari tabel `users`, helper role IT (`admin` / `division = IT`), `avatar_url()` + fallback inisial, mapping Nama Bidang / Nama Divisi.
- `includes/functions.php`: escaping, CSRF, format tanggal, badge status/prioritas.
- `includes/header.php`, `sidebar.php`, `footer.php`: layout clean & elegan, responsif.
- `assets/css/app.css`, `assets/js/app.js`: stylesheet + JS vanilla (tanpa framework/CDN wajib).
- `database/schema.sql`: tabel `categories`, `tickets`, `ticket_comments`, `ticket_attachments`, `ticket_history`, `notifications`, `faq_articles`, `sla_policies`, `mikrotik_settings`, `mikrotik_interfaces`, `it_staff`, `system_settings`, `rooms`, `network_checks` + seed kategori, SLA, FAQ contoh.
- `index.php`, `dashboard.php`, `profile.php`, `demo-login.php`, `logout.php`: halaman Batch 1.
- `.htaccess`, `.gitignore`, `uploads/tickets/.gitkeep`, `library/dompdf/.gitkeep` (placeholder domPDF manual).

### Batch 6 — Reporting & Analytics + domPDF (2026-11-26)
- `includes/reports.php`: query agregasi laporan — `report_summary()`, `report_by_category()`, `report_by_priority()`, `report_by_officer()`, `report_by_room()`, `report_daily()`, `report_ratings()`, `report_fmt_minutes()`.
- `includes/pdf.php`: integrasi **domPDF manual** (tanpa Composer) — `dompdf_available()`, `render_pdf()`, `render_report_html()` (template A4 dengan kop logo LJU, KPI, tabel, footer).
- `reports/index.php`: dashboard analitik — 7 kartu KPI, grafik tren harian (CSS-only, tanpa library), distribusi kepuasan bintang, tabel per kategori & prioritas, gangguan jaringan per ruangan, kinerja petugas IT, filter periode (hari ini/minggu/bulan/bulan lalu/tahun/kustom).
- `reports/export.php`: export **PDF** (ringkasan, SLA, jaringan) dan **CSV** daftar tiket (UTF-8 BOM, siap Excel). Bila domPDF belum terpasang, otomatis tampil **preview siap cetak** dengan tombol Cetak browser.
- `library/dompdf/README.md`: panduan instalasi domPDF manual (unduh rilis → ekstrak → uji).
- `assets/css/app.css`: komponen grafik batang, legenda, bar preview PDF.

### Batch 5 — SLA & Escalation + Notification Center (2026-11-26)
- `includes/settings.php`: `get_settings()`, `setting()`, `setting_bool()`, `save_settings()` — pengaturan berbasis tabel `system_settings`.
- `includes/notifier.php`: Notification Center multi-kanal — `notify_user_channels()` (in-app selalu; email & WhatsApp bila diaktifkan), `notify_it_team_channels()`, `send_email_smtp()` (SMTP native STARTTLS/SSL/auth, fallback `mail()`), `send_whatsapp_api()` (Fonnte-compatible), template email HTML, `notifier_log()`.
- `includes/sla.php`: mesin SLA — `sla_targets()`, `run_sla_monitor()` (peringatan H-60 menit, penandaan pelanggaran, eskalasi otomatis naik prioritas, auto-close tiket Resolved), `sla_report_stats()`.
- `cron/sla.php`: endpoint cron (CLI atau `?token=`), dijadwalkan tiap 10 menit.
- `settings/index.php`: pengaturan SLA per prioritas (respons & penyelesaian), ambang peringatan, maksimal eskalasi otomatis, auto-close, SMTP email + tombol **uji kirim**, WhatsApp API + **uji kirim**, token cron, dan log 15 pengiriman terakhir.
- `reports/sla.php`: laporan SLA bulanan — total, selesai, melewati SLA, % kepatuhan, rata-rata respons & penyelesaian, kepatuhan per prioritas, kinerja petugas, daftar tiket terlambat.
- `tickets/view.php`: baris **Target Respons** (tepat waktu/terlambat) dan **Target Penyelesaian** + badge SLA terlewati & jumlah eskalasi otomatis.
- `database/migration_batch5.sql`: migrasi kolom SLA, kanal notifikasi, `notification_logs`, pengaturan baru, dan pengisian target respons tiket lama.
- `.htaccess` diperkuat: blokir akses langsung ke `config/`, `includes/`, `database/`, `library/`, file `.sql`, serta header keamanan dasar.

### Batch 4 — Network & Internet Complaint (2026-11-26)
- `database/schema.sql`: tabel baru `rooms` (10 ruangan contoh) + `network_checks` (checklist pemeriksaan); kolom baru pada `tickets` = `room_id`, `technician_action`, `checked_at`.
- `database/migration_batch4.sql`: migrasi untuk database yang sudah berjalan dari Batch 1–3 (termasuk pengisian `room_id` tiket lama berdasarkan nama ruangan).
- `includes/network.php`: `network_types()`, `network_check_types()`, `network_device_types()`, `get_rooms()`, `room_label()`, `network_summary_by_room()`, `network_summary_by_type()`, `network_tickets_by_room()`, `add_network_check()`, `save_technician_result()`.
- `network/index.php`: dashboard gangguan jaringan — kartu statistik, grafik bar sebaran per ruangan & per jenis gangguan, chip ruangan (klik untuk riwayat), filter periode 7/30/90/365 hari. Karyawan hanya melihat data miliknya, tim IT melihat seluruh kantor.
- `tickets/create.php`: kategori jaringan memakai **dropdown ruangan** + isian lokasi bebas + **perangkat yang diduga bermasalah**; mendukung prasetel `?network=1`.
- `tickets/view.php`: kartu **Penanganan Jaringan** — hasil pemeriksaan + tindakan perbaikan, checklist pemeriksaan (ping/speedtest/sinyal WiFi/port LAN/perangkat), form input tim IT, `checked_at` otomatis, notifikasi ke pelapor.
- `dashboard.php`: kartu ringkasan gangguan jaringan 30 hari (total, aktif, ruangan terdampak, ruangan terbanyak).

### Batch 3 — Ticket Management (2026-11-26)
- `includes/ticket_actions.php`: pusat logika manajemen tiket — `change_ticket_status()`, `change_ticket_priority()`, `assign_ticket()`, `transfer_ticket()`, `escalate_ticket()`, `reopen_ticket()`, `get_it_staff()`, `get_ticket()`. Semua aksi mencatat `ticket_history` dan mengirim notifikasi in-app.
- `admin/tickets.php`: meja kerja tim IT — kartu statistik (Open/Assigned/In Progress/Waiting User/Melewati SLA/Belum ditugaskan), filter (status, prioritas, petugas, kategori, SLA, pencarian), urutan otomatis prioritas+kritis, aksi massal (tugaskan / ubah status / ubah prioritas), pagination.
- `tickets/view.php`: panel **Aksi Tim IT** (tugaskan, ubah status, ubah prioritas, transfer + alasan, eskalasi, reopen) dan panel **Konfirmasi Penyelesaian** untuk pelapor (tutup tiket setelah Resolved / buka kembali bila masih bermasalah).
- Visual SLA: `sla_state()` + `sla_badge()` — badge "Sisa 3j 20m" / "Lewat 1j 5m" di daftar dan detail tiket.
- `notifications.php` + badge notifikasi di topbar + menu sidebar Notifikasi (tandai dibaca, hapus, buka tautan tiket).
- Timestamp otomatis: `responded_at` saat assigned/in_progress, `resolved_at`, `closed_at`, dan reset saat reopen.

### Batch 2 — Employee Portal (2026-11-26)
- `includes/ticket.php`: generator nomor tiket `LJU-YYYYMMDD-XXXX`, pencatat history, notifikasi in-app (ke pelapor & tim IT), upload lampiran (validasi ekstensi/size, simpan di `uploads/tickets/`), hitung SLA due dari `sla_policies`.
- `tickets/create.php`: form buat tiket (kategori, judul, deskripsi, prioritas, field jaringan kondisional untuk kategori network, upload lampiran), CSRF + validasi, notifikasi ke tim IT.
- `tickets.php`: daftar/tracking tiket saya (tim IT: semua tiket) dengan filter status/kategori/pencarian + pagination.
- `tickets/view.php`: detail tiket, lampiran (download + upload tambahan), komunikasi komentar (internal notes hanya untuk IT), rating layanan 1–5 setelah Resolved/Closed, timeline riwayat penanganan. Proteksi akses: hanya pemilik tiket atau tim IT.
- `faq.php`: daftar + pencarian + detail artikel Knowledge Base (counter views).
- `admin/faq.php`: pengelolaan artikel FAQ oleh tim IT (tambah/ubah/publikasi/hapus).
- `includes/url.php`: `app_base_url()` + `url()`/`asset()` — path & redirect tetap benar dari subfolder (`tickets/`, `admin/`).
- `includes/db.php` diperbaiki: `pdo_connect()` mengembalikan `null` bila gagal (tidak lagi `die()`), sehingga koneksi opsional DB users benar-benar opsional.
- Filter user aktif bersifat opsional (`users_active_condition()`): aplikasi tetap berjalan bila tabel `users` tidak punya kolom `is_active`.
- `assets/css/app.css`: komponen Batch 2 (kartu tiket, percakapan, timeline riwayat, lampiran, rating bintang, FAQ list, responsif mobile).
- `uploads/tickets/.htaccess`: blokir eksekusi script di folder lampiran.
