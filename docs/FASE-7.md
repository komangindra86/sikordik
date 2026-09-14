# Fase 7 — Survei dan penyelesaian

Checkpoint implementasi: 14-09-2026. Aturan bisnis: [Keputusan Fase 7](KEPUTUSAN-FASE-7.md).

## Hasil

- Menu **Survei & penyelesaian**: dua kewajiban survei per penempatan, konfigurasi tautan Google Form, kode pencocokan tanpa identitas pasien, pengajuan peserta, dan verifikasi Admin.
- Checklist dokumen, periode, presensi/rekap sah, logbook, publikasi nilai, keberatan, survei, dan kewajiban tertunda. Pemeriksaan Admin dan persetujuan terpisah Tim Kordik dengan pemeriksaan ulang snapshot.
- Penguncian penempatan, pengesahan internal, riwayat/notifikasi, pembukaan kembali melalui persetujuan lalu eksekusi Admin, dan arsip setelah retensi minimal tiga tahun tanpa penghapusan.
- Migration aditif `2026_09_14_000002_create_phase_seven_tables.php`: `survey_forms`, `survey_responses`, `completion_requests`; kolom `completed_at` dan `archived_at` pada `placements`.
- Backend: `SurveyService`, `CompletionService`, `CompletionController`; tiga halaman Blade `resources/views/completion`, route dan navigasi. Integrasi penguncian pada `PlacementService`, `AttendanceService`, `PrivateFileService` dan halaman terkait.
- Pengujian: `CompletionTest`, `CompletionConcurrencyTest`, fixture `CompletionFixtures`, worker subprocess MySQL, dan pembaruan ekspektasi jalur reopen lama di `AdmissionsTest`.

## Verifikasi

- Suite penuh MySQL lokal terisolasi `sikordik_phase1_test`: **128 test, 1.661 assertion, seluruhnya lulus**. Database aplikasi tidak digunakan sebagai fixture. Suite dijalankan berurutan karena fake storage dipakai bersama oleh pengujian modul.
- Fase 7 pada MySQL: **12 test fitur, 170 assertion** dan **1 test konkurensi, 65 assertion**. Uji dua proses PHP meliputi pengajuan/verifikasi survei, pengajuan/persetujuan penyelesaian, pengajuan/persetujuan/eksekusi pembukaan kembali, penutupan ulang, dan arsip; tiap pasangan menerima satu permintaan dan menolak satu duplikat.
- Cakupan: checklist setiap modul, tanggal akhir inklusif, peran/scope/IDOR, penolakan wildcard dan persetujuan sendiri, revisi usang, keberatan setelah pengajuan, pemeriksaan ulang setelah keberatan selesai, penguncian jalur lama, riwayat pengesahan, unduhan setelah selesai/arsip, retensi tiga tahun, konflik periode saat pembukaan kembali, masa berlaku dan integritas berkas, validasi URL Google Form, serta siklus survei ditolak/diajukan ulang/diverifikasi.
- Laravel Pint, kompilasi Blade, `git diff --check`, dan build Vite produksi lulus. Build memerlukan izin subprocess esbuild setelah sandbox mengembalikan `spawn EPERM`.
- Migration aditif berhasil diterapkan pada database aplikasi sebagai batch 7. Tidak ada akun, respons Google Form, atau penempatan aplikasi yang dibuat/diselesaikan oleh fixture pengujian.
- Browser memeriksa HTML hasil render fixture pada ponsel 390 px dan desktop 1280 px: checklist, formulir keputusan, pengaturan survei, dan daftar penempatan. Tidak ada overflow horizontal halaman. Ini pemeriksaan layout; mutasi diverifikasi melalui HTTP/service dan konkurensi, bukan UAT Google Form nyata.

## Uji manual

1. Login Admin, buka **Survei & penyelesaian → Kelola tautan survei**. Tambahkan kedua jenis formulir institusi. Pastikan formulir memiliki kolom kode respons dan survei pasien tidak mengumpulkan email/identitas/data medis.
2. Login peserta, pilih penempatan berjalan, terbitkan kode untuk masing-masing survei. Salin kode ke Google Form dan kirim respons (survei pasien minimal satu wawancara). Setelah itu ajukan verifikasi di aplikasi.
3. Login Admin, cocokkan kode dengan respons lengkap pada Google Form. Pilih hasil pemeriksaan. Coba tolak respons yang belum ditemukan; peserta dapat mengajukan ulang.
4. Lengkapi dokumen, presensi seluruh hari pendidikan dan rekap sah Ketua KSM, logbook sah, serta nilai terpublikasi. Selesaikan keberatan/perpanjangan/jadwal tertunda. Pastikan seluruh kewajiban institusi sudah tercatat.
5. Setelah tanggal akhir stase lewat, Admin memeriksa checklist, mengisi alasan, mengonfirmasi dan mengajukan penyelesaian. Login Tim Kordik yang berbeda, periksa dan setujui. Pastikan status selesai, nomor pengesahan/hash, dan riwayat tampil.
6. Peserta tetap dapat membaca nilai dan mengunduh logbook yang diizinkan. Coba mutasi presensi/dokumen/nilai/survei; penempatan selesai harus terkunci.
7. Untuk koreksi, Admin mengajukan pembukaan kembali, Tim Kordik menyetujui, lalu Admin melaksanakan. Periksa riwayat lama tetap ada. Setelah koreksi dan pengesahan ulang modul terkait, ajukan penyelesaian baru.
8. Arsip baru tersedia setelah tiga tahun sejak penyelesaian terakhir, tanpa permohonan aktif. Gunakan fixture pengujian untuk simulasi waktu; jangan mengganti tanggal database aplikasi. Filter **Arsip** tetap menyediakan detail dan unduhan.

## Batas dan kelanjutan

- Google Form nyata belum dikonfigurasi otomatis; isi dan respons eksternal memerlukan pemeriksaan petugas. Tidak ada sinkronisasi Google API.
- Pemendekan stase berjalan dan pemulihan arsip untuk koreksi belum disediakan. Kuota ujian/logbook khusus institusi masih diperiksa Admin. Lihat keputusan desain untuk cakupan rinci.
- Fase 8 — dashboard, laporan, QR, hardening dan UAT — belum dimulai. Sistem belum dinyatakan siap produksi.
