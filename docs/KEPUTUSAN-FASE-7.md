# Keputusan Fase 7 — Survei dan penyelesaian

Tanggal: 14-09-2026. Dasar: MASTER PROMPT SIKORDIK bagian 15–18, 21, dan cakupan Fase 7.

## Survei Google Form

- Kedua survei memakai integrasi tautan Google Form MVP: kepuasan peserta dan minimal satu respons wawancara pasien per peserta per penempatan. Tidak ada pengambilan respons Google secara otomatis atau klaim bahwa membuka tautan berarti selesai.
- Admin/Super Admin menambahkan nama, jenis, dan tautan respons. Tautan harus HTTPS `forms.gle/...` atau `docs.google.com/forms/d/e/.../viewform` tanpa parameter. Formulir aktif terbaru per jenis dipakai untuk token baru. Definisi tidak diedit; buat versi baru. Penonaktifan hanya menghentikan token baru, sehingga token lama tetap dapat diverifikasi terhadap formulir asal.
- Admin menyiapkan kolom kode respons pada Google Form. Survei peserta dapat menilai pelayanan Kordik, pembimbing, fasilitas, jadwal, lingkungan belajar, proses pembelajaran, dan saran. Survei pasien tidak boleh meminta nama, NIK, nomor rekam medis, diagnosis, atau data medis sensitif; matikan pengumpulan email otomatis. Konfirmasi konfigurasi adalah pemeriksaan manusia, bukan validasi otomatis isi formulir eksternal.
- Peserta menerbitkan kode acak `SV-<ULID>`, mengisinya ke formulir, lalu mengonfirmasi respons sudah dikirim. Satu baris kewajiban per jenis dan penempatan dilindungi unique constraint. Token merupakan kode pencocokan, bukan kredensial atau tautan akses ke jawaban.
- Alur: `issued → submitted → verified/rejected`. Hanya Admin Kordik memverifikasi kode dengan respons lengkap di Google Form; peserta tidak bisa memverifikasi sendiri. Respons ditolak dapat diajukan ulang dengan kode yang sama. Sistem menyimpan jenis/formulir, kode, status, pencatat, pemeriksa, waktu, dan revisi; tidak menyimpan jawaban atau identitas pasien.
- Tim Kordik melihat status/kode tanpa memerlukan identitas pasien. Ketua/Sekretariat KSM terbatas scope dan hanya melihat status, tanpa token atau tautan. Peserta hanya mengakses penempatannya. Super Admin dapat memantau dan mengelola konfigurasi, tetapi wildcard tidak memberi hak pemeriksaan atau persetujuan operasional.

## Checklist dan pemeriksaan Admin

- Pengajuan hanya dari `sedang_stase` atau `menunggu_penyelesaian`, setelah seluruh tanggal stase berakhir (hari setelah tanggal akhir). Tidak ada penyelesaian otomatis berdasarkan tanggal.
- Fase ini memakai tanggal akhir resmi yang tersimpan, termasuk perpanjangan yang telah disetujui. Saat disetujui, `actual_end_date = end_date`. Pemendekan stase yang sedang berjalan belum disediakan; jangan mengganti tanggal melalui SQL untuk melewati rekap/persetujuan.
- Dokumen wajib harus valid, memiliki pemeriksa, berkas bersih dan utuh, serta berlaku sampai akhir stase; pengecualian resmi tetap dihormati. Dokumen yang kedaluwarsa setelah stase berakhir tidak membatalkan kelengkapan historis.
- Rekap terbaru harus disahkan Ketua KSM, memuat seluruh hari dari jadwal terbit/selesai, tanpa presensi hilang/tertunda/tanggal asing, dan fingerprint harus sama dengan data presensi saat ini.
- Minimal satu logbook peserta dan satu penilaian diperlukan. Semua logbook yang tercatat (termasuk pendidik) harus disetujui, versi/berkas utuh. Semua penilaian yang tercatat harus terpublikasi pada versi terkini, utuh, dan memiliki pengesahan publikasi. Kuota jenis ujian/logbook khusus institusi belum dikonfigurasi otomatis; Admin wajib memastikan seluruh kewajiban institusi sudah tercatat sebelum konfirmasi.
- Semua keberatan harus ditolak atau selesai. Survei peserta dan satu survei pasien harus terverifikasi. Tidak boleh ada penugasan, jadwal draft/revisi/diajukan/disetujui-belum-terbit, perpanjangan, atau pengecualian periode tertunda.
- Admin Kordik memeriksa checklist, mengisi alasan/hasil pemeriksaan dan konfirmasi, lalu mengajukan. Sistem menyimpan snapshot checklist beserta bukti versi, fingerprint, dan pemohon. Penempatan beralih menjadi `menunggu_penyelesaian`.
- Tim Kordik yang berbeda dari pemohon menyetujui atau menolak dengan alasan. Peserta yang memiliki role tambahan tetap tidak boleh memeriksa/menyetujui penempatannya sendiri. Admin bukan pemberi persetujuan; Super Admin tidak melewati pemisahan peran.
- Persetujuan menghitung ulang checklist dan membandingkan hash snapshot. Perubahan sejak pemeriksaan Admin, walaupun akhirnya lengkap lagi, membutuhkan penarikan/penolakan dan pengajuan baru. Keberatan baru dan koreksi presensi tetap boleh selama menunggu; keduanya dapat menahan persetujuan.
- Penolakan/penarikan penyelesaian mengembalikan `sedang_stase` dengan status penyelesaian pending. Histori permohonan tetap tersimpan. Pembatalan penempatan dari modul penerimaan ditahan selama ada permohonan penyelesaian/pembukaan kembali aktif.

## Penguncian, pengesahan, dan pembukaan kembali

- Persetujuan Tim Kordik secara atomik menyimpan status `selesai`, tanggal aktual, waktu selesai, revisi, nomor `SL-<ULID>`, identitas/nama/jabatan, role, waktu pengesahan, serta hash snapshot. Konflik periode diperiksa ulang. Hash memakai kanonisasi rekursif agar konsisten pada JSON MySQL.
- Presensi (termasuk koreksi Admin, penggantian verifikator, dan rekap), logbook, penilaian/keberatan, survei, review dokumen, dan unggah berkas penempatan terkunci setelah selesai. Pembacaan/unduhan data yang diizinkan tetap tersedia. Dokumen surat dan profil peserta adalah sumber bersama; berkas versi yang sudah dipilih tidak ditimpa oleh unggahan baru.
- Jalur `reopen` langsung pada penerimaan dihapus. Pengganti: Admin mengajukan pembukaan kembali dengan alasan → Tim Kordik menyetujui/menolak → Admin selain pemberi persetujuan melaksanakan pembukaan kembali. Snapshot penempatan, pemohon, pemberi persetujuan, pelaksana, alasan, waktu, dan perubahan dicatat.
- Eksekusi memeriksa ulang snapshot dan konflik periode sebelum mengembalikan `menunggu_penyelesaian`. Tanggal aktual/waktu selesai dikosongkan, revisi naik, pengesahan lama tetap disimpan. Pembukaan kembali tidak otomatis membuka versi logbook/nilai yang telah disahkan; aturan koreksi tiap modul tetap berlaku.
- Data yang telah diperbaiki harus melewati pengajuan dan persetujuan penyelesaian baru. Rekap yang dikoreksi memerlukan verifikasi dan pengesahan Ketua KSM kembali. Review dokumen juga tersedia ketika menunggu penyelesaian.

## Arsip dan integritas

- Arsip merupakan penanda `archived_at`, bukan perubahan status menjadi status baru atau penghapusan. Admin Kordik dapat mengarsipkan penempatan selesai minimal tiga tahun setelah penyelesaian terakhir/tanggal aktual (yang lebih akhir), tanpa permohonan aktif, dengan alasan dan konfirmasi.
- Data diarsipkan keluar dari daftar aktif modul penyelesaian dan tersedia pada filter Arsip. URL, hak akses, riwayat, dan berkas tetap tersedia. Tidak ada job penghapusan otomatis atau pengurangan retensi. Pemulihan arsip untuk koreksi belum disediakan; jangan mengubah penanda arsip secara langsung.
- Mutasi mengunci peserta lalu penempatan, mengikuti urutan kunci modul sebelumnya. Locking read pada data checklist, pemeriksaan revisi, unique constraint survei, dan transaksi menolak permintaan rangkap. Keputusan menolak snapshot usang.
- Semua tindakan memiliki histori/audit dan notifikasi internal; tidak ada email/WhatsApp atau pengiriman respons survei oleh aplikasi. Halaman sensitif memakai `private, no-store`.
- Pemeriksaan file dapat tetap tertahan bila ClamAV/qpdf belum siap. Pengujian menggunakan mock scanner dan tidak menyatakan pemindaian produksi sudah siap. QR, laporan gabungan, hardening dan UAT mengikuti Fase 8. Pengesahan internal bukan tanda tangan tersertifikasi PSrE.
