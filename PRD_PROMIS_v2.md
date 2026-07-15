# PRD — PROMIS v2 (POI Monitoring Management System)

Rebuild total dari sistem lama. Dokumen ini jadi acuan sebelum mulai build (vibe coding). Bagian yang ditandai **[ASUMSI — konfirmasi]** adalah keputusan yang gua ambil karena belum dibahas eksplisit; koreksi kalau salah.

## 1. Latar Belakang & Tujuan

PROMIS adalah sistem internal untuk memantau POI (Point of Interest / calon & existing mitra bisnis) dan kunjungan sales BNI. Versi v1 (live sekarang) dibangun bertahap tanpa desain sistem yang rapi: styling inline tidak konsisten, celah keamanan (SQL injection, broken access control, tanpa CSRF), dan tidak ada strategi performa untuk data besar.

v2 adalah rebuild total, bukan tempelan di atas kode lama:
- Database mulai dari kosong (bukan migrasi dari dump lama).
- Desain sistem yang disiapkan untuk tumbuh sampai ±200.000 baris data tanpa lag.
- Tampilan dimodernisasi (lihat preview yang sudah disetujui: sidebar + topbar, stat card, chart, tabel modern) tapi **logic dan isi card dashboard tidak berubah** — hanya tampilannya.
- Deploy ke Hostinger.

## 2. Role & Hak Akses

Disederhanakan dari 5 role jadi 3:

| Role | Lingkup | Bisa apa |
|---|---|---|
| **admin** | Semua kantor | Full access. Satu-satunya role yang bisa **membuat/mengelola user baru** dan **import POI massal via Excel**. Bisa lihat & kelola semua kantor, semua laporan, semua modul. |
| **admin_final** | Kantor yang ditugaskan (bisa lebih dari satu) | Kelola POI & kunjungan untuk kantor miliknya saja, lihat dashboard/rekap kantor miliknya, tambah POI manual satu-satu, **bisa import POI massal via Excel untuk kantornya**. Tidak bisa buat user baru — itu khusus `admin`. Tidak bisa lihat/ubah data kantor lain. |
| **sales** | Kantor yang ditugaskan | Input kunjungan, lihat POI kantor miliknya, lihat riwayat kunjungan pribadi. Tidak bisa edit/hapus POI, tidak bisa lihat rekap kantor lain. |

**Dikonfirmasi:** satu user bisa ditugaskan ke lebih dari satu kantor (seperti sistem lama). Kalau lebih dari satu, user pilih "kantor aktif" saat login — validasi server-side memastikan kantor yang dipilih memang miliknya (celah ini yang bocor di versi lama, harus ditutup).

## 3. Modul & Fitur

**Scope dipersempit: fokus POI. Modul Leads (leads sales, upload Excel leads, ranking follow-up/closing) dihapus dari scope v2** — bukan bagian dari rebuild ini.

1. **Auth** — login, ganti password wajib di login pertama, logout, rate limit percobaan login gagal.
2. **Dashboard** — ringkasan POI per area/sektor, hasil kunjungan per periode, chart. **Logic & card sama persis dengan versi lama, cuma tampilan yang di-upgrade.**
3. **Data POI** — list dengan filter (kantor/status/area/sektor) + search + pagination server-side, CRUD manual satu-satu, **import massal via Excel (admin & admin_final, terbatas ke kantor masing-masing untuk admin_final)**, hapus/reopen dengan audit trail (siapa, kapan, kenapa — ini yang dulu tidak pernah dicatat).
4. **Kunjungan** — sales input hasil kunjungan per POI (produk ditawarkan, hasil, nominal, catatan), riwayat personal & kantor.
5. **Geocoding** — proses alamat POI jadi koordinat lat/long otomatis (batch, dijalankan via cron terjadwal, bukan auto-reload di browser seperti sistem lama yang boros resource).
6. **Export & Laporan** — export rekap kunjungan (closing/non-closing) ke Excel asli (pakai library PhpSpreadsheet, bukan HTML-nyamar-jadi-xls seperti sistem lama), aman dari formula injection.
7. **Manajemen User** — khusus admin: buat/nonaktifkan user, assign kantor (bisa lebih dari satu), reset password paksa. Ada ±500 user yang perlu di-setup awal — lihat pembahasan "bulk user setup" di bagian 3b, masih didiskusikan.

### 3a. Template import POI (Excel) — sudah dicek dari file real (`Data POI W02 Send`, 184.345 baris)

**Ini bukan lagi skala hipotetis "kalau nanti 200rb baris" — data real yang bakal diimport pertama kali aja udah 184.345 baris.** Jadi semua strategi performa di bagian 5 wajib ada dari hari pertama, bukan nanti-nanti.

Kolom: `Nama, Alamat, Kategori, Sub Kategori, Area, Outlet, Bank, PIC`. Mapping ke skema: `Nama`→nama_poi, `Alamat`→alamat, `Kategori`→sektor, `Sub Kategori`→sub_sektor, `Area`→area, `Outlet`→kantor, `PIC`→pic.

**Kolom `Bank` sudah jelas** — ternyata bukan binary BNI/NON BNI, tapi 3 nilai pasti:
- `Bukan Nasabah BNI` (177.236 baris / 96%)
- `Nasabah Non Merchant BNI` (5.140 baris)
- `Nasabah Merchant BNI` (1.969 baris)

`status_mitra` didesain sebagai ENUM 3 nilai ini persis (bukan 2 seperti asumsi awal gua).

**Keputusan final soal kualitas data:**

1. **`Area`** — cuma keisi di 0,4% baris (759 dari 184.345). Dibuat opsional, bukan wajib.
2. **`Outlet` (kantor)`** — **baris tanpa kantor DITOLAK saat import** (bukan diimport sebagai "belum ada kantor"). Data akan dibersihkan dulu di sisi lu sebelum upload, jadi sistem cukup tegas reject + kasih laporan baris mana aja yang gagal karena ini, tanpa perlu logic khusus "kantor kosong".
3. **Nama kantor** — tabel master `kantor` (nama resmi + kode) tetap dibikin sebagai referensi tunggal yang dipakai POI & user, tapi **penyamaan nama (Payakumbuh vs PAYAKUMBUH BRANCH OFFICE, dll) dikerjain di sisi lu sebelum data di-upload** — sistem gak perlu fitur fuzzy-matching otomatis, cukup validasi ketat: nama kantor di file harus persis sama dengan salah satu di master `kantor`, kalau tidak ada → baris ditolak dengan pesan jelas. Admin bisa tambah/edit kantor master manual dari halaman pengaturan.

**POI bisa diedit oleh `admin` maupun `admin_final`** (admin_final terbatas ke kantor miliknya) — dikonfirmasi.

Admin/admin_final download template kosong dari halaman import, isi, upload — sistem validasi baris per baris (tolak baris invalid dengan pesan jelas per baris, bukan gagal semua baris).

### 3b. Bulk user setup (±500 user) — sudah dicek dari file real (`LIST USER POI W02`, 596 baris)

File aslinya kolomnya: `NO, ROLE, KANTOR/CABANG, NAMA, NPP` — tapi `ROLE` di file ini isinya **jabatan/unit** (mis. "BRANCH MANAGER", "BUSINESS & TRANSACTION RELATIONSHIP MANAGER"), **bukan** role sistem (admin/admin_final/sales). Ada 21 jabatan unik, dan NPP unik 100% (596/596, cocok jadi basis username). Jadi template final ditambah kolom `Unit` sesuai request lu, terpisah dari `Role` sistem:

**Kolom template (diperbaiki: NPP = Username, tidak ada kolom Username terpisah):**

| Kolom | Wajib? | Keterangan |
|---|---|---|
| NPP | Ya, unik | Nomor pegawai — **langsung jadi Username login**, bukan cuma basis/opsional lagi |
| Nama Lengkap | Ya | |
| Unit / Jabatan | Ya | Teks bebas dari data HR (mis. "BRANCH MANAGER") — cuma buat ditampilkan, **tidak mempengaruhi hak akses** |
| Role sistem | Ya | Harus persis salah satu: `admin`, `admin_final`, `sales` |
| Kantor | Ya | Satu atau lebih, dipisah koma dalam satu sel |

**Keputusan final:**

1. **Role sistem tidak di-auto-generate dari jabatan HR** — kolom `Role sistem` di template wajib diisi eksplisit per baris, nilainya ditentukan langsung oleh lu saat siapin file (bukan sistem yang nebak dari "BRANCH MANAGER" atau jabatan lain). Sistem cuma validasi nilainya harus salah satu dari `admin`/`admin_final`/`sales`.
2. **Nama kantor** — sama seperti POI (bagian 3a): harus persis sama dengan tabel master `kantor`, penyamaan format (BRANCH OFFICE, sub-branch, dll) dikerjain di sisi lu sebelum upload. Baris dengan nama kantor yang tidak dikenal sistem akan ditolak dengan pesan jelas.

**Password (update sesuai arahan lu):** password awal = **NPP itu sendiri** (sama dengan Username, bukan random lagi). Jadi login pertama: Username = NPP, Password = NPP. `force_password` tetap wajib aktif — user dipaksa ganti password begitu berhasil login pertama kali, jadi password default yang gampang ditebak ini cuma "berlaku" satu kali pakai. Ini pola umum dipakai (temp password = employee ID) dan cukup aman **selama** force-change-nya beneran gak bisa di-skip — nanti itu yang jadi salah satu poin wajib di testing keamanan (bagian 7).

## 4. Skema Database (bersih, mulai dari 0)

Tabel inti dipertahankan dari sistem lama tapi dirapikan:
- `users` (password pakai bcrypt via `password_hash()`, bukan sha256)
- `user_kantor` (relasi user ↔ kantor, many-to-many)
- `kantor` (tabel master baru — di sistem lama nama kantor cuma string bebas, sekarang jadi tabel referensi supaya konsisten & bisa divalidasi)
- `poi` (dengan foreign key ke `kantor`, index di kolom `kantor_id`, `area`, `status_mitra`, `sektor`)
- `kunjungan` (foreign key ke `poi.id` dan `users.id` — bukan simpan username sebagai teks bebas seperti dulu; `nominal` jadi tipe `decimal`, bukan `varchar`)
- `poi_reopen_log` (audit trail — benar-benar dipakai kali ini)
- `geocode_failed`
- `dashboard_summary` — **tabel baru**, ringkasan agregat (total POI per status/kantor/area, total kunjungan & closing per hari) yang di-update tiap ada insert/update transaksi, supaya dashboard tidak perlu hitung ulang dari tabel jutaan baris tiap kali dibuka.

Semua kolom yang dipakai untuk filter (`kantor_id`, `area`, `status_mitra`, `tanggal`, `sales_id`) diberi index dari awal.

## 5. Strategi Performa (target ±200.000 baris)

200 ribu baris itu kecil untuk MySQL kalau fondasinya benar — bukan soal jumlah data, tapi soal cara query-nya:

- **Index di semua kolom filter** (bukan ditambah belakangan setelah lag, tapi didesain dari awal skema).
- **Pagination & search server-side** — tabel POI/kunjungan tidak pernah load semua baris ke browser sekaligus (DataTables server-side processing, bukan client-side seperti dulu).
- **Dashboard baca dari tabel `dashboard_summary`**, bukan `COUNT`/`SUM` langsung dari tabel transaksi tiap kali halaman dibuka. Update summary dilakukan saat ada insert/update (trigger atau langsung di kode aplikasi), bukan recalculate penuh.
- **Prepared statement + connection reuse** — hindari query berulang dalam loop (N+1 query), terutama di halaman rekap/laporan.
- **Geocoding jadi cron batch** (jalan tiap beberapa menit lewat cron job Hostinger), bukan auto-reload browser tiap 3 detik yang lama-lama numpuk proses di server.
- **Export Excel** diproses per-chunk (query pakai `LIMIT`/`OFFSET` bertahap) supaya tidak menghabiskan memory PHP saat rekap jadi besar.

## 6. Pertimbangan Hosting (Hostinger) — dikonfirmasi

Paket: **Cloud Startup** (dicek dari hPanel → Resource Usage). Spesifikasi: 4GB RAM dedicated, 100 PHP workers, 40 entry processes, timeout MySQL 30 menit, 100GB NVMe storage, 2 juta inode. Ini longgar untuk skala ±200.000 baris — cron job & batch geocoding aman dijalankan kapan saja, tidak perlu terlalu ketat jadwalnya.

Catatan: Hostinger shared/cloud hosting tidak menyediakan Redis/Memcached, jadi caching dashboard tetap pakai tabel `dashboard_summary` di MySQL sendiri (bukan cache layer terpisah) — ini didesain dari awal, bukan optimisasi belakangan.

## 7. Keamanan (baseline wajib, bukan opsional)

Diambil dari audit sistem lama — semua ini harus ada dari awal, bukan ditambal belakangan:
- Prepared statement (PDO) di **semua** query, tanpa kecuali (celah SQL injection di sistem lama karena ada halaman yang lupa escape).
- CSRF token di semua form & aksi state-changing (hapus, reopen, dll — jangan lewat link GET polos).
- Validasi otorisasi di **setiap** halaman/endpoint: role-check dan kantor-check dilakukan di server, bukan cuma disembunyikan di UI (celah `pilih_kantor.php` & IDOR `edit_poi.php` di sistem lama).
- Password bcrypt (`password_hash`/`password_verify`), rate limit login (5x gagal → lock 15 menit).
- Session hardening: `session_regenerate_id()` setelah login, cookie `HttpOnly` + `Secure` + `SameSite=Lax`.
- `display_errors` mati di production, error di-log ke file bukan ditampilkan ke user.
- Escape semua output (`htmlspecialchars`) dan sanitasi export Excel (anti formula injection).
- File konfigurasi (kredensial DB) tetap di luar akses langsung browser (lanjutkan pola `.htaccess deny` yang sudah benar di sistem lama).

## 8. Tampilan (sudah disetujui arahnya)

Berdasarkan preview yang sudah di-review: layout sidebar (coklat tua) + topbar, brand warna coklat-krem dipertahankan, font Inter, icon Bootstrap Icons, chart pakai Chart.js, peta pakai Leaflet, tabel modern dengan search & badge status berwarna. Dashboard: **tampilan baru, logic & card tetap sama seperti sistem lama** (tidak menambah/mengurangi metrik yang ditampilkan).

Responsive — sales sering akses dari HP di lapangan, jadi mobile-first bukan tambahan belakangan.

## 9. Pendekatan Teknis

**[ASUMSI — bisa didiskusikan]** Tetap PHP native (bukan pindah ke framework berat seperti Laravel), tapi disusun rapi:
- PDO + prepared statement (ganti total dari `mysqli` gaya lama).
- Struktur folder: `config/` (di luar webroot kalau memungkinkan di Hostinger, atau minimal di-deny total via `.htaccess`), `includes/` (partial header/sidebar/footer/auth-guard dipakai bareng semua halaman — beda dari sistem lama yang tiap file berdiri sendiri), `public/` (entry point).
- Library pihak ketiga lewat Composer kalau Hostinger mendukung (PhpSpreadsheet untuk export Excel asli, bukan HTML-nyamar seperti dulu).

Alasan tetap PHP native dan bukan framework besar: cocok untuk shared hosting, tanpa build step, gampang diiterasi cepat gaya vibe coding, dan tim yang pegang nanti tidak perlu belajar framework baru.

## 10. Rencana Build (bertahap)

1. Fondasi: skema DB + auth + RBAC (3 role) + partial layout baru (header/sidebar/topbar).
2. Manajemen user + bulk setup ±500 user awal (mekanisme final — lihat bagian 3b).
3. Modul POI: CRUD manual + import Excel + list dengan filter/pagination server-side.
4. Modul Kunjungan: form input + riwayat.
5. Dashboard: pasang ulang tampilan baru di atas logic lama + tabel `dashboard_summary`.
6. Geocoding batch (cron) + Export Excel asli.
7. Hardening keamanan penuh (checklist bagian 7) + testing.
8. Deploy ke Hostinger + setup cron + SSL + konfigurasi awal (kantor master, user admin pertama).

## 11. Status: PRD final untuk mulai build

Semua keputusan utama sudah dikonfirmasi (role, modul, skema data, template import POI & user, keamanan, hosting). Yang jadi tanggung jawab lu sebelum kedua file diupload ke sistem nanti:

- Bersihin `Data POI W02` — pastikan tiap baris punya `Outlet` (kantor) terisi, dan nama kantornya persis sama dengan yang bakal jadi master `kantor` di sistem.
- Olah `LIST USER POI W02` jadi format template final (bagian 3b) — isi kolom `Role sistem` per orang, samain nama kantor ke master yang sama, kolom `Unit` diisi dari jabatan HR yang sudah ada.

Kalau dua file itu sudah siap di format template, gua lanjut proses import beneran. Untuk sekarang, build bisa mulai dari fondasi (skema DB, auth, RBAC, layout) tanpa nunggu file selesai — modul import baru butuh file real pas testing.
