# Fase 4 — Presensi

Checkpoint implementasi: 09-09-2026. Aturan: [Keputusan Fase 4](KEPUTUSAN-FASE-4.md). Fase 5 belum dimulai.

## Hasil

- Menu **Presensi**: daftar penempatan sesuai akses, presensi harian, draft/pengajuan, perbaikan penolakan, dan catatan keputusan pembimbing.
- Penugasan verifikator resmi, pengganti beralasan dengan histori penugasan awal, dan akses verifikasi susulan setelah periode penugasan berakhir.
- Rekap membedakan hari belum diisi, data belum diverifikasi, serta jumlah setiap status kehadiran terverifikasi.
- Pengesahan wajib Ketua KSM dalam scope, snapshot dan versi rekap, deteksi data stale, serta penguncian presensi/kalender.
- Koreksi admin pascapengesahan mempertahankan versi lama, menandainya tidak berlaku, dan mewajibkan verifikasi serta pengesahan ulang.
- Pengingat internal harian yang idempotent, eskalasi penugasan bermasalah ke admin, laporan privat untuk cetak, dan riwayat sebelum/sesudah.

## Implementasi

- Migration aditif: `attendances`, `attendance_summaries`, `attendance_reminders`. Tidak mengubah atau menghapus data fase sebelumnya.
- `AttendanceService`, `AttendanceAccess`, controller dan Blade khusus presensi. Query aplikasi tetap memakai Query Builder.
- Integrasi guard pada perubahan jadwal/perpanjangan; jurnal/notifikasi internal menggunakan infrastruktur Fase 3.
- Command `sikordik:remind-attendance` terjadwal pukul 16.00 waktu aplikasi; tetap memerlukan runner scheduler pada host.

## Verifikasi

- Suite penuh MySQL lokal `sikordik_phase1_test`: **84 test, 908 assertion, seluruhnya lulus**. Database aplikasi tidak dipakai untuk fixture pengujian.
- Pengujian khusus presensi SQLite: **12 test, 183 assertion, seluruhnya lulus**. Uji MySQL khusus presensi termasuk concurrency: **13 test, 202 assertion, seluruhnya lulus**.
- Dua proses PHP nyata membuktikan satu dari dua pengajuan duplikat diterima, hanya satu verifikasi pada versi yang sama, dan hanya satu pengesahan/riwayat rekap yang tercatat. Temuan pembacaan snapshot lama MySQL diperbaiki dengan locking read dan diuji ulang.
- Cakupan: kepemilikan/IDOR, Ketua KSM lintas scope, wildcard Super Admin, pembimbing tanpa penugasan, tautan akun berubah/nonaktif, tanggal/lokasi salah, versi stale, draft/penolakan/pengajuan ulang, hari hilang/menunggu, penggantian pembimbing, pengingat idempotent, koreksi setelah pengesahan dan pengesahan ulang, serta larangan perubahan kalender terkunci.
- Laravel Pint, `git diff --check`, dan build Vite produksi lulus.
- Browser: halaman Blade hasil fixture test diperiksa pada desktop 1280 px dan ponsel 390 px. Formulir koreksi dan laporan tidak membuat overflow halaman; tabel laporan dapat digulir pada ponsel agar kolom tetap terbaca.
- Migration aditif Fase 4 berhasil diterapkan pada database aplikasi. `artisan schedule:list` mengonfirmasi command pengingat pukul 16.00; ini tidak berarti runner scheduler Windows sudah dipasang.

## Verifikasi manual

1. Pada placement dengan jadwal terbit, login peserta dan buka **Presensi**. Pilih tanggal yang sudah berlangsung, lokasi, pembimbing resmi, status, dan kegiatan. Simpan draft atau ajukan.
2. Login pembimbing terkait, periksa data, isi catatan lalu verifikasi atau tolak. Pastikan presensi menunggu tetap tidak dihitung sebagai tidak hadir.
3. Jalankan command pengingat dua kali pada hari yang sama. Periksa notifikasi pembimbing; hanya satu pengingat baru per presensi/hari.
4. Setelah periode berakhir, buat rekap sebagai Admin Kordik. Buka laporan draft, lengkapi hari yang belum diisi/diverifikasi, lalu sahkan sebagai Ketua KSM terkait.
5. Pastikan peserta tidak dapat mengedit rekap terkunci. Lakukan koreksi beralasan sebagai admin, lalu ulangi verifikasi pembimbing dan pengesahan Ketua KSM. Laporan versi lama harus bertanda tidak berlaku.
6. Coba akses dari peserta/KSM lain dan pembimbing tanpa penugasan. Detail/laporan maupun aksi keputusan harus ditolak.

## Batas operasional

- Jalankan `scripts/php.ps1 artisan migrate` untuk memperbarui database secara aditif. Jangan memakai fresh/wipe pada database aplikasi.
- Pengingat otomatis memerlukan runner scheduler; notifikasi pada fase ini bersifat internal, tanpa pengiriman email/WhatsApp.
- Browser memeriksa halaman hasil render fixture test terisolasi. Mutasi diverifikasi melalui test HTTP/service; pemeriksaan ini bukan UAT akun produksi.
- Sistem belum dinyatakan siap produksi. Fase 5 hanya dimulai atas instruksi pengguna berikutnya.
