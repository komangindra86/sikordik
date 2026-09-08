# Operasional dan batas kesiapan

## Runtime lokal yang teruji

Gunakan wrapper PowerShell dari root proyek agar tidak memakai PHP 7.4 bawaan PATH:

```powershell
.\scripts\php.ps1 artisan migrate --seed
.\scripts\php.ps1 artisan sikordik:bootstrap-local-admin
.\scripts\php.ps1 artisan serve
.\scripts\php.ps1 vendor\bin\phpunit
.\scripts\php.ps1 vendor\bin\pint --test
npm run build
```

Wrapper memilih PHP 8.2.27 Laragon dan memuat SQLite bila belum aktif. Lokasi PHP lain dapat diberikan lewat `SIKORDIK_PHP`. Wrapper tidak mengubah PATH/konfigurasi global atau proyek Laragon lain. `artisan test` dan `composer dev` dapat membuat subprocess memakai PATH lama: untuk setup ini jalankan PHPUnit langsung dan server lewat wrapper.

Bootstrap lokal membuat password acak dan menyimpan handoff di `storage/app/private/bootstrap/*.txt`, tidak mencetak password ke terminal dan tidak menimpa akun yang sudah ada. File tidak disajikan aplikasi dan diabaikan Git, tetapi tetap bisa dibaca akun OS yang punya akses folder: pindahkan kredensial ke password manager, ganti password lewat halaman akun, lalu hapus file handoff. Perintah menolak lingkungan production. Email `.test` hanya lokal, bukan email delivery nyata.

Seeder ulang menambahkan data dasar yang belum ada tanpa mengembalikan role/permission assignment atau master nonaktif ke default. Perubahan katalog permission di fase berikutnya memerlukan migration/rekonsiliasi eksplisit dan review akses; jangan mengandalkan reseed untuk memberi semua izin baru otomatis.

## Database test

SQLite `:memory:` adalah default. Pengujian MySQL menggunakan database lokal terpisah berakhiran `_test`; runner menolak database aplikasi dan DB_URL agar test destruktif tidak salah sasaran. **RefreshDatabase menghapus/membuat ulang tabel database test.** Jangan isi database tersebut dengan data operasional.

Database `sikordik_phase1_test` sudah disiapkan khusus test. Untuk menjalankan ulang dalam terminal PowerShell tersendiri:

```powershell
$env:DB_CONNECTION='mysql'
$env:DB_DATABASE='sikordik_phase1_test'
$env:DB_HOST='127.0.0.1'
$env:DB_USERNAME='root'
$env:DB_PASSWORD=''
$env:DB_URL=''
.\scripts\php.ps1 vendor\bin\phpunit
```

Tutup terminal test setelah selesai agar override tidak terbawa ke perintah aplikasi. Jangan pernah menjalankan `migrate:fresh`/`db:wipe` terhadap `sikordik`. MySQL test Fase 1 membuktikan migrasi, constraint dan workflow HTTP pada MySQL; test concurrency placement baru relevan setelah service Fase 2 tersedia.

## Checklist sebelum produksi — belum dijalankan

1. Gunakan `.env.production.example` sebagai template, isi domain/secret nyata. Simpan APP_KEY dengan aman dan jangan regenerasi saat deployment rutin. Pin dan perbarui runtime PHP/MySQL/Composer melalui staging; Composer global lokal 2.2.3 belum diperbarui dalam checkpoint ini.
2. Document root wajib direktori `public`, bukan root repository. Larang akses web ke `.env`, `.git`, `storage` privat dan backup; gunakan HTTPS, redirect HTTP, secure cookie, trusted proxy yang spesifik, serta header keamanan di web server.
3. Gunakan user DB runtime non-root dengan grant per tabel: audit hanya SELECT/INSERT, tanpa UPDATE/DELETE/TRUNCATE/DROP/ALTER. Tabel aplikasi lain mendapat izin minimum yang dibutuhkan (session, jobs, token dan pivot membutuhkan DELETE). Jangan memberi grant global/schema yang membatalkan pembatasan tabel audit. Migrasi memakai identitas deployment terpisah, bukan kredensial runtime. Uji bahwa mutasi audit langsung ditolak. Ini membuat audit append-only untuk aplikasi, bukan kebal terhadap DBA; ekspor audit ke penyimpanan terpisah yang dibatasi perubahan untuk bukti insiden.
4. Seed administrator pertama melalui secret CLI `INITIAL_ADMIN_EMAIL`/`INITIAL_ADMIN_PASSWORD` (minimal 12 karakter acak) sebelum config cache dibuat, lalu hapus kedua secret. Jangan deploy akun/password handoff lokal ke produksi. Periksa email administrator dan cadangan akses resmi.
5. Konfigurasi SMTP/TLS dengan pengirim resmi. Uji email reset sampai kotak masuk, link HTTPS, kedaluwarsa, single-use, serta pencabutan sesi. Hindari mail driver log di produksi karena token reset dapat tercatat. Automated test saat ini memakai notification fake, bukan provider SMTP.
6. Jalankan queue worker database dan scheduler dengan service manager, monitoring kegagalan, retry terkontrol, serta restart worker saat deploy. Sesuaikan cache/config route/view setelah pengaturan final. Migrasi deploy dijalankan dengan backup dan rencana rollback, bukan fresh migration.
7. Sebelum upload bisnis diaktifkan, pasang scanner malware Fase 2; scan harus berhasil sebelum file dapat diunduh. Terapkan batas upload web server/PHP dan aplikasi secara konsisten. Scanner, file authorization dan versioning belum diimplementasikan di Fase 1.
8. Enkripsi backup database + file privat, simpan salinan terpisah/offsite dan kunci terpisah, tetapkan RPO/RTO serta jadwal retensi yang disahkan, lakukan restore drill dengan pemeriksaan hash. Jangan menaruh backup atau kunci di `public` atau Git.
9. Uji browser/mobile, installability PWA, CSRF nyata, logout/offline dan larangan cache dokumen pada lingkungan HTTPS produksi. Pengujian HTTP PHPUnit tidak menggantikan uji lintas browser atau uji penetrasi.

Kesiapan memulai pengembangan Fase 2 tidak sama dengan izin meluncurkan sistem ke produksi atau menggunakan data pribadi nyata.
