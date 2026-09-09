# Fase 3 — Penugasan dan jadwal

Checkpoint implementasi: 09-09-2026. Aturan: [Keputusan Fase 3](KEPUTUSAN-FASE-3.md). Fase 4 belum dimulai. Checkpoint ini bukan pernyataan siap produksi.

## Hasil

- Menu **Penugasan & jadwal**: daftar placement sesuai akses, kegiatan peserta, kelompok, penugasan, perpanjangan, dan timeline dengan perbandingan data sebelum/sesudah.
- Pengelolaan lisensi pendidik, periode validitas, status aktif, pemeriksaan versi stale, serta histori perubahan tanpa penghapusan.
- Beberapa pembimbing/penguji/supervisor per peserta, akun penugasan yang terikat, pengesahan Ketua/Koordinator KSM, dan pergantian pendidik beralasan yang menjaga penugasan lama.
- Kelompok dalam KSM, keanggotaan berperiode, perpindahan dengan histori, dan penugasan/jadwal anggota per placement.
- Draft, pengajuan, revisi, persetujuan pembimbing terkait, publikasi, pembatalan melalui permohonan, dan penyelesaian kegiatan. Perubahan jadwal terbit mempertahankan versi sebelumnya.
- Validasi periode, lokasi, penugasan, kredensial, checklist, kepemilikan, serta benturan jam lintas KSM. Seluruh mutasi jadwal mengunci peserta melalui PlacementService; keputusan stale ditolak.
- Perpanjangan oleh Admin Kordik melalui keputusan KSM dan Tim Kordik, termasuk pembaruan pengecualian paralel berbukti dengan fingerprint baru. Penugasan pendidik tidak diperpanjang otomatis.
- Notifikasi internal yang bersifat privat bagi penerima. Mulai stase memeriksa tanggal serta jadwal terbit tanpa membuka jalan pintas ke penyelesaian placement.

## Berkas dan migration

- Migration `2026_09_09_000001_create_phase_three_tables`: tujuh tabel baru (`clinical_groups`, `group_memberships`, `educator_assignments`, `schedules`, `placement_extensions`, `scheduling_histories`, `scheduling_notifications`) dan kolom status/revisi pada `educator_licenses`.
- Service baru: `SchedulingAccess`, `SchedulingJournal`, `EducatorAssignmentService`, `ClinicalGroupService`, `ScheduleService`, dan `PlacementExtensionService`.
- `SchedulingController`, route `/penjadwalan`, navigasi, serta enam tampilan Blade penjadwalan. PlacementService menyediakan pemeriksaan dokumen/riwayat bersama dan review dokumen selama kegiatan; halaman penerimaan menyediakan formulir review pada status tersebut.
- Test fase 3: `SchedulingTest`, `SchedulingConcurrencyTest`, fixture terisolasi; worker concurrency Fase 2 diperluas untuk mutasi jadwal.

## Verifikasi

- PHPUnit MySQL lokal `sikordik_phase1_test`: **71 test, 706 assertion, semuanya lulus**. Database aplikasi tidak digunakan untuk test.
- PHPUnit SQLite: suite 70 test/672 assertion lulus dengan dua test MySQL concurrency dilewati pada pemeriksaan sebelum kasus pembaruan dokumen ditambahkan. Setelah perubahan terakhir, seluruh 19 test HTTP/service penjadwalan diulang: **327 assertion, semuanya lulus**. Suite MySQL di atas mencakup versi akhir seluruh test.
- Dua proses PHP nyata pada MySQL membuktikan hanya satu dari dua jadwal bentrok yang diterima, dan hanya satu publikasi pada versi yang sama menghasilkan perubahan status/riwayat. Test concurrency Fase 2 juga tetap lulus.
- Cakupan: beberapa pembimbing dan peran terpisah, kelayakan/lisensi, pergantian beralasan, akun pendidik dipindah, scope KSM, IDOR peserta/notifikasi, tanggal/lokasi salah, revisi dan versi stale, jadwal terbit immutable, pembatalan, batas jam berdampingan, kegiatan sepanjang hari, recheck dokumen, perpindahan kelompok, perpanjangan dua tahap, pengecualian paralel baru, dan awal kegiatan tanpa menutup placement.
- Kasus MySQL JSON yang mengurutkan ulang kunci sudah diperbaiki: snapshot konflik dibandingkan berdasarkan nilai; test perpanjangan paralel lulus pada MySQL maupun SQLite.
- Laravel Pint, `git diff --check`, serta build Vite produksi lulus. Build memakai izin proses esbuild di luar sandbox Windows ketika sandbox menolak proses anak.
- Browser: halaman Blade aktual dirender dari fixture test terisolasi untuk inspeksi visual. Desktop 1280 px dan mobile 390 px; formulir jadwal, detail kegiatan, perpanjangan, dan lisensi tidak memiliki overflow horizontal. Alur mutasi diverifikasi lewat test HTTP/service; pemeriksaan visual ini bukan klaim UAT akun produksi atau uji pengiriman email.
- Migration aditif fase 3 diterapkan pada database aplikasi, batch 3. Tidak ada fresh/wipe atau fixture bisnis yang ditambahkan ke database aplikasi.

## Verifikasi manual

1. Buka **Penugasan & jadwal → Lisensi pendidik** sebagai Admin Kordik. Catat kredensial pendidik resmi; pastikan akun dan kemampuan perannya benar di master.
2. Pilih placement terverifikasi. Bila diperlukan buat kelompok di halaman daftar lalu catat keanggotaannya. Ajukan penugasan pembimbing; login Ketua/Koordinator KSM terkait untuk menyetujuinya.
3. Sebagai peserta, buat draft dalam periode penempatan dan ajukan. Sebagai pembimbing yang tercantum, minta revisi atau setujui. Peserta/admin menerbitkan jadwal yang telah disetujui.
4. Pada jadwal terbit, pilih **Ajukan perubahan / pembatalan**. Pastikan jadwal asal tetap terbit sampai permohonan pengganti disetujui dan diterbitkan; buka riwayat untuk membandingkan keduanya.
5. Ajukan perpanjangan sebagai Admin Kordik, periksa dokumen hingga akhir baru, lalu lakukan keputusan KSM dan Tim Kordik. Untuk periode paralel, unggah bukti melalui penerimaan, tunggu scan bersih, pilih bukti dan setujui overlap secara eksplisit.
6. Coba akses sebagai peserta lain/KSM lain: detail dan tindakan harus ditolak. Periksa notifikasi pada akun penerima masing-masing.

## Operasional dan batas

- Jalankan migration aditif dengan `scripts/php.ps1 artisan migrate`; jangan memakai fresh/wipe pada database aplikasi.
- ClamAV/qpdf, SMTP, deployment, backup/restore, dan kebijakan kredensial resmi mengikuti kesiapan operasional sebelumnya. Bukti pendukung operasional harus benar-benar lolos scan; fixture bersih hanya digunakan di pengujian.
- UI kelompok bekerja per placement; belum ada penyebaran jadwal massal. Kalender kapasitas pendidik/ruangan dan integrasi hari libur tidak dibuat sebagai aturan bisnis baru. Fase 4 menambahkan presensi dan aturan hari pendidikan.
- Fase berikutnya hanya dimulai setelah instruksi pengguna.
