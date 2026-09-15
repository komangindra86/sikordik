# Fase 8 — Dashboard, laporan dan hardening

Checkpoint implementasi: 15 September 2026. [Keputusan desain](KEPUTUSAN-FASE-8.md) · [UAT dan go-live](UAT-FASE-8.md).

## Hasil

- Dashboard mengikuti peran, KSM, penugasan dan peserta; ringkasan penerimaan, presensi, rekap, logbook, nilai, survei, penyelesaian, jadwal hari ini dan pengelompokan institusi/KSM.
- Menu **Laporan Excel / PDF**: filter institusi, peserta, KSM, penempatan, status dan periode; 11 jenis laporan, paginasi dan ekspor `.xlsx`/PDF asli. Laporan logbook mencakup peserta dan pendidik. Nilai/logbook dipilih per penempatan dan mengikuti izin baris modul asal.
- Menu **Verifikasi pengesahan**: QR lokal, halaman terautentikasi, pemeriksaan hash, identitas pengesah dan pembedaan versi berlaku/riwayat. Presensi memakai peristiwa histori agar QR pengesahan lama tetap merujuk peristiwa yang sama.
- Header keamanan, audit ekspor/hash, penyaringan secret audit, batas ukuran/throttle ekspor dan indeks database.
- Perintah `sikordik:readiness`, `sikordik:backup` dan `sikordik:restore-drill`, dokumentasi backup/restore dan matriks UAT yang belum ditandatangani.

## File dan migration

Layanan baru: `DashboardService`, `ReportService`, `ReportExporter`, `VerificationService`, `BackupService`. Controller dashboard diperbarui; `ReportController` dan `VerificationController` ditambahkan. Tiga command operasional, `config/operations.php`, halaman reports/verification, route, navigasi, metadata pengesahan presensi, middleware header, audit logger dan template environment diperbarui.

Migration `2026_09_15_000001_add_phase_eight_indexes_and_attestations.php` menambah kolom `approval` nullable pada `attendances` dan `attendance_summaries`, serta indeks komposit placements/attendances/schedules/audit_logs. Migration aditif berhasil diterapkan pada database aplikasi. Rollback diuji melalui suite MySQL, termasuk pemulihan indeks foreign key InnoDB.

Dependensi terkunci: `dompdf/dompdf 3.1.6`, `chillerlan/php-qrcode 5.0.5` beserta dependensi transitif. Tidak ada layanan QR eksternal. OOXML dibuat dengan ZipArchive dan sel teks eksplisit.

## Verifikasi

- **Suite penuh MySQL: 141 test, 1.802 assertion, semuanya lulus**, database terisolasi `sikordik_phase1_test`. Termasuk pengujian konkurensi fase sebelumnya; fixture tidak memakai database aplikasi.
- `ReportingTest`: 13 skenario meliputi seluruh jenis laporan, scope/peran/IDOR, versi nilai publik vs draft, dashboard semua peran, QR lima jenis pengesahan, QR dapat didekode kembali ke URL yang tepat, hash yang berubah, histori reopen/presensi, formula injection Excel, batas PDF, survei belum dimulai, audit secret, guest/nonaktif/no-store, backup terenkripsi serta restore database/file ke target terisolasi.
- Latihan pemulihan fixture: baris peserta/nilai cocok, foreign key SQLite diperiksa, hash file pulih cocok, target berisi data dan password salah ditolak. Ini uji mekanisme pemulihan, bukan bukti RPO/RTO host produksi.
- Laravel Pint, kompilasi Blade, route cache/clear dan `git diff --check` lulus. Build Vite produksi lulus; esbuild membutuhkan eksekusi di luar sandbox setelah `spawn EPERM` pada percobaan ulang.
- `npm audit` dan Composer audit locked: **0 advisori**, tidak ada paket abandoned saat pemeriksaan. Audit Composer memakai phar resmi yang checksum-nya diverifikasi; Composer global tidak diganti.
- Chromium headless dengan profil sementara memeriksa HTML fixture dashboard/laporan/verifikasi pada 390 px dan 1280 px. Tidak ada overflow horizontal halaman. Gambar diperiksa secara visual. Browser bawaan sesi tidak menyediakan tool eksekusi; pemeriksaan memakai Chromium lokal.
- PDF contoh 60 baris: empat halaman A4 landscape, tabel dan pengulangan header diperiksa dengan Poppler; tidak ada JavaScript. File XLSX berhasil dibuka dengan openpyxl (10 kolom). Sel PDF di atas 500 karakter ditolak dengan arahan menggunakan Excel agar isi tidak terpotong; batas Excel 32.767 karakter per sel.
- `sikordik:readiness` berhasil mengidentifikasi konfigurasi yang belum siap dan keluar dengan status gagal sesuai desain. Tidak ada secret dicetak.

## Uji manual singkat

1. Jalankan aplikasi lokal dan gunakan akun DUMMY dari panduan latihan privat yang sudah tersedia. Buka Dashboard pada tiap peran.
2. Buka **Laporan Excel / PDF**, pilih penempatan dan jenis laporan; bandingkan angka/data dengan modul asal, unduh Excel dan PDF. Coba akun peserta/KSM berbeda dan pastikan data tidak bocor.
3. Salin kode penempatan dari laporan ke **Verifikasi pengesahan**, buka dokumen dan pindai QR. URL menggunakan APP_URL; ponsel memerlukan domain yang dapat diakses. Login akun yang tidak berhak harus ditolak.
4. Untuk presensi/rekap yang disahkan sebelum fase 8, lihat riwayat modul asal. Pengesahan baru menyimpan snapshot QR; metadata lama tidak difabrikasi.
5. Ikuti [UAT](UAT-FASE-8.md) untuk form nyata, scanner, SMTP, HTTPS/PWA, backup offsite, restore, beban kerja dan persetujuan Tim Kordik/IT.

## Apakah ini fase terakhir dan siap dipakai?

**Ya, ini fase pengembangan terakhir MVP yang direncanakan.** Kode sampai fase 8 telah diuji dan tersedia untuk latihan lokal dengan data DUMMY.

**Belum siap produksi/data pribadi nyata.** UAT pengguna belum ditandatangani dan lingkungan lokal belum memenuhi konfigurasi produksi (HTTPS/cookie, SMTP, akun DB non-root, ClamAV, qpdf dan secret backup). Operasional worker/scheduler, grant audit, backup offsite dan restore pada staging juga memerlukan bukti. Checklist lengkap ada di dokumen UAT. Tidak ada klaim go-live otomatis atau pengesahan PSrE.

Langkah berikutnya adalah persiapan staging dan UAT/go-live sesuai checklist, bukan otomatis memulai fitur lanjutan.
