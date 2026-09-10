# Fase 5 — Logbook

Checkpoint implementasi: 10-09-2026. Aturan: [Keputusan Fase 5](KEPUTUSAN-FASE-5.md). Fase 6 belum dimulai.

## Hasil dan file

- Menu **Logbook**: unggah PDF institusi peserta, versi draft/perbaikan, pengajuan dan pemeriksaan pembimbing, riwayat PDF/keputusan, serta pengesahan internal.
- Form kegiatan pembimbing: penugasan resmi, tanggal, lokasi, jam, durasi, materi, catatan, lampiran opsional, dan pemeriksaan supervisor.
- Rekap kegiatan privat, notifikasi internal, pembatasan peserta/KSM/penugasan, deteksi duplikasi, revisi optimistis, dan penguncian mengikuti penempatan selesai.
- Migration aditif `database/migrations/2026_09_10_000001_create_phase_five_tables.php` menambahkan `logbooks`, `logbook_versions`, `logbook_reviews`; berkas memakai `private_files` yang sudah ada.
- Backend: `app/Services/LogbookAccess.php`, `app/Services/LogbookService.php`, `app/Http/Controllers/LogbookController.php`; route pada `routes/web.php`, lima halaman Blade pada `resources/views/logbooks` dan tautan navigasi bersama.
- Pengujian: `tests/Feature/LogbookTest.php`, `tests/Feature/LogbookConcurrencyTest.php`, serta worker subprocess `tests/Support/concurrent-admissions.php`.

## Verifikasi

- Suite penuh MySQL lokal terisolasi `sikordik_phase1_test`: **99 test, 1.149 assertion, seluruhnya lulus**. Database aplikasi tidak digunakan sebagai fixture pengujian.
- Uji khusus logbook SQLite: **11 test, 177 assertion, seluruhnya lulus**. Uji konkurensi MySQL menambahkan **1 test, 23 assertion**.
- Uji dua proses PHP nyata: pembuatan kegiatan duplikat, pengajuan versi yang sama, dan persetujuan versi yang sama masing-masing menghasilkan tepat satu penerimaan serta satu penolakan, dengan satu versi/pengesahan yang berlaku.
- Cakupan: versi lama utuh, perbaikan/penolakan/pengajuan ulang, logbook pembimbing dan lampiran, hash snapshot/PDF, pemeriksa tidak tersedia, ukuran/MIME/privasi, akses ilegal lintas peserta/KSM, role tanpa penugasan, wildcard admin, penugasan dicabut, akun ditautkan ulang, pendidik nonaktif, data usang, tanggal/lokasi/jam tidak valid, rekap hanya menghitung persetujuan, dan data selesai terkunci.
- Laravel Pint, `git diff --check`, serta build Vite produksi lulus. Migration aditif berhasil diterapkan pada database aplikasi.
- Browser memeriksa HTML hasil render fixture terisolasi pada desktop 1280 px dan ponsel 390 px: formulir pembimbing, pemeriksaan, dan rekap tidak membuat overflow halaman. Tabel rekap dapat digulir pada ponsel. Mutasi diverifikasi melalui test HTTP/service; pemeriksaan browser bukan UAT akun produksi.

## Verifikasi manual

1. Pastikan penempatan memiliki status dijadwalkan/sedang stase/menunggu penyelesaian, akun peserta tertaut, dan penugasan mentor/supervisor telah disetujui Ketua KSM.
2. Login peserta, buka **Logbook**, pilih penempatan, unggah PDF institusi tanpa informasi sensitif pasien, lalu simpan draft. Periksa status PDF; command `scripts/php.ps1 artisan sikordik:scan-private-files` tersedia untuk scan ulang setelah pemeriksa siap.
3. Ajukan draft. Login pembimbing yang ditunjuk, unduh PDF, lalu minta perbaikan dengan catatan. Peserta menambahkan PDF versi baru dan mengajukan ulang; PDF lama tetap ada.
4. Setujui sebagai pembimbing. Periksa nomor pengesahan, nama/role pemeriksa, waktu, versi, dan hash pada riwayat. Versi disetujui tidak dapat diedit.
5. Login pembimbing, catat kegiatan dan pilih penugasan supervisor. Simpan draft dan ajukan. Login supervisor tersebut, beri keputusan dan pengesahan. Rekap hanya menambahkan durasi setelah disetujui.
6. Login peserta/KSM lain atau pembimbing/supervisor tanpa penugasan. Detail, berkas, keputusan, dan rekap di luar kewenangan harus ditolak. Pemilik peserta tidak otomatis melihat logbook pembimbing.
7. Integrasi status selesai telah diuji dengan fixture: data tetap dapat dibaca sesuai akses, tetapi semua mutasi ditolak. Gunakan alur penyelesaian resmi setelah Fase 7 tersedia, jangan mengubah database aplikasi secara manual.

## Batas dan pekerjaan selanjutnya

- ClamAV dan qpdf perlu tersedia agar PDF lolos pemeriksaan. Tidak ada bypass scanner pada aplikasi; mock scanner hanya digunakan di pengujian.
- Pengalihan logbook yang masih diajukan ketika pemeriksa dicabut belum tersedia; sistem menolak pemeriksaan dengan penugasan lama. Tidak ada koreksi langsung terhadap versi disetujui.
- Pengesahan internal dan cetak HTML tersedia. QR/ekspor khusus mengikuti Fase 8; checklist penyelesaian mengikuti Fase 7. Sistem belum dinyatakan siap produksi.
- Rekomendasi berikutnya: Fase 6, penilaian dinamis, publikasi nilai pembimbing, dan keberatan nilai. Implementasi menunggu instruksi pengguna berikutnya.
