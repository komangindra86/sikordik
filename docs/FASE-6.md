# Fase 6 — Penilaian

Checkpoint implementasi: 14-09-2026. Aturan rinci: [Keputusan Fase 6](KEPUTUSAN-FASE-6.md). Fase 7 belum dimulai.

## Hasil dan file

- Menu **Penilaian**: template dinamis dengan komponen dan cakupan institusi/KSM/periode, pengisian angka/teks atau unggah PDF formulir institusi, pengesahan dan publikasi langsung oleh pembimbing, serta tampilan nilai peserta.
- Keberatan dengan lampiran, tinjauan/keputusan pembimbing, versi koreksi, publikasi ulang, riwayat nilai dan pengesahan, notifikasi internal, serta penguncian mengikuti status penempatan.
- Migration aditif `database/migrations/2026_09_14_000001_create_phase_six_tables.php` menambah enam tabel tanpa mengubah data fase sebelumnya. Nilai komponen dan definisinya disimpan bersama dalam snapshot versi, sehingga riwayat tidak bergantung pada template yang sedang aktif.
- Backend: `AssessmentAccess`, `AssessmentTemplateService`, `AssessmentFileService`, `AssessmentService`, dan `AssessmentController`. UI: lima halaman Blade di `resources/views/assessments`, route di `routes/web.php`, dan menu bersama.
- Pengujian: `tests/Feature/AssessmentTest.php`, `tests/Feature/AssessmentConcurrencyTest.php`, dan worker subprocess `tests/Support/concurrent-admissions.php`.

## Verifikasi

- Suite penuh MySQL lokal terisolasi `sikordik_phase1_test`: **115 test, 1.425 assertion, seluruhnya lulus**. Database aplikasi tidak digunakan sebagai fixture.
- Uji penilaian SQLite `:memory:`: **15 test, 213 assertion, seluruhnya lulus**. Uji konkurensi MySQL menambahkan **1 test, 63 assertion**.
- Uji dua proses PHP nyata mencakup pembuatan duplikat, pengesahan, publikasi, pengajuan/tinjauan/penerimaan keberatan, pembuatan versi koreksi, dan pengesahan/publikasi koreksi. Setiap pasangan permintaan menghasilkan satu penerimaan dan satu penolakan; dua versi tetap tersimpan dan keberatan selesai tepat satu kali.
- Cakupan HTTP/service: nilai berbobot dan tanpa agregasi, input angka/teks, penguji versus pembimbing, publikasi tanpa Tim Kordik, privasi draft dan koreksi/PDF, keberatan diterima/ditolak, template nonaktif dan cakupan/periode, nilai di luar rentang, presisi, input template berbahaya, duplikasi, revisi usang, role tanpa penugasan, wildcard admin, lintas KSM/peserta, penugasan dicabut, akun ditautkan ulang, pendidik nonaktif, menilai diri sendiri, penguncian selesai, MIME/ukuran PDF, scanner tertahan, dan integritas snapshot/berkas.
- Laravel Pint, kompilasi Blade, `git diff --check`, serta build Vite produksi lulus. Build akhir dijalankan dengan izin subprocess setelah sandbox menolak esbuild (`spawn EPERM`). Migration aditif berhasil diterapkan pada database aplikasi.
- Browser memeriksa HTML hasil render fixture terisolasi: formulir pengisian, konfigurasi template, dan tampilan nilai/keberatan pada viewport ponsel 390 px; pemeriksaan pembimbing pada desktop 1280 px. Tidak ada overflow horizontal halaman. Nilai ditampilkan sebelum formulir keberatan. Ini pemeriksaan layout; mutasi diverifikasi melalui HTTP/service test, bukan UAT akun produksi.

## Verifikasi manual

1. Login Admin Kordik, buka **Penilaian → Kelola template penilaian**. Buat nama/versi formulir institusi, jenis ujian, cakupan, periode, dan minimal satu komponen. Pilih tanpa agregasi bila rumus institusi tidak sesuai opsi berbobot. Periksa rincian template setelah tersimpan.
2. Pastikan peserta memiliki penempatan berjalan dan pembimbing/penguji telah ditugaskan serta disetujui Ketua KSM. Login pengisi, buka penempatan pada **Penilaian**, pilih tanggal dan template yang berlaku, lalu isi penilaian.
3. Untuk metode unggah, gunakan PDF institusi yang sudah diisi dan ditandatangani, maksimal 10 MB dan bebas data pasien. Pastikan pemeriksaan file lolos. Bila scanner belum siap, konfigurasi ClamAV/qpdf dan jalankan `scripts/php.ps1 artisan sikordik:scan-private-files`; jangan mengubah status scan lewat SQL.
4. Login pembimbing yang ditunjuk, baca nilai/formulir dan sahkan dengan catatan serta konfirmasi. Periksa nomor/hash pengesahan. Login peserta: nilai belum terlihat. Kembali sebagai pembimbing dan publikasikan; peserta langsung melihat nilai tanpa keputusan Tim Kordik.
5. Sebagai peserta ajukan keberatan dengan alasan dan lampiran opsional. Pembimbing mulai tinjauan lalu menerima/menolak dengan tanggapan. Penolakan tidak membuka pengeditan.
6. Untuk keberatan diterima, pembimbing menyimpan versi koreksi. Periksa sebagai peserta bahwa nilai lama tetap terlihat dan draft/PDF koreksi tidak tersedia. Sahkan dan publikasikan koreksi; keberatan menjadi selesai, nilai versi baru muncul, dan nilai lama tetap ada di riwayat.
7. Coba akun peserta/KSM lain dan pembimbing tanpa penugasan. Detail, berkas, pengisian, dan keputusan di luar kewenangan harus ditolak. Tim Kordik dapat memantau, tetapi tidak mengesahkan atau memublikasikan.
8. Integrasi status selesai diuji dengan fixture terisolasi: mutasi ditolak, nilai terpublikasi tetap terbaca. Gunakan alur penutupan resmi setelah Fase 7 tersedia; jangan mengubah status database aplikasi secara manual.

## Batas dan kelanjutan

- Scanner PDF masih membutuhkan ClamAV dan qpdf yang tersedia. Pengujian otomatis memakai mock scanner; ini tidak membuktikan kesiapan pemindaian produksi.
- Pengalihan penilaian akibat pergantian penugasan, pembatalan pengesahan, dan koreksi administratif tanpa keberatan belum tersedia. Detail alur konservatif terdapat pada dokumen keputusan.
- Rekomendasi berikutnya: **Fase 7 — survei dan penyelesaian**, termasuk checklist nilai dipublikasikan dan keberatan/kewajiban belum selesai. Implementasi menunggu instruksi pengguna.
- QR, ekspor khusus, dashboard gabungan, hardening, dan UAT mengikuti Fase 8. Sistem belum dinyatakan siap produksi.
