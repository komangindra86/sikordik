# Fase 2 — Penerimaan dan penempatan

Checkpoint implementasi: 09-09-2026. Acuan: KEPUTUSAN-FASE-2.md. Fase 3 belum dimulai; ini bukan pernyataan siap produksi.

## Implementasi

- Data induk peserta memakai BIGINT internal, ULID pada URL, dan nomor tetap `PDK-YYYY-000001`. Sequence tahunan dikunci dalam transaksi. NIK opsional unik, NIM berupa teks, email/nama dinormalisasi untuk pencarian. Pratinjau menampilkan alasan kandidat; tidak ada auto-merge. Kandidat berbeda orang memerlukan alasan yang diaudit. Edit identitas memakai pemeriksaan stale hash dan tidak mengubah nomor, akun, atau snapshot penempatan.
- Surat masuk unik menurut institusi, tahun tanggal surat, dan nomor ternormalisasi. Satu surat digunakan banyak peserta melalui placement. Surat lama tetap bisa dipilih melalui tautan pada daftar surat. PDF surat memiliki versi privat; versi sebelumnya tetap ada.
- Placement berulang menyimpan snapshot institusi/program/jenis/KSM dan checklist. Draft belum memesan periode. Pemeriksaan inklusif lintas seluruh KSM memakai lock peserta dan locking read placement. Riwayat selesai memakai tanggal aktual jika tersedia. Tolak/batal tidak memesan periode. Konflik dalam KSM yang sama ditolak; lintas KSM memerlukan pengecualian Tim Kordik dari draft, dokumen pendukung bersih, dan approver berbeda dari pemohon.
- Pengecualian terikat pasangan placement, KSM, periode, tanggal aktual, dan revisi. Revisi/buka kembali membatalkan pemakaian persetujuan pengecualian sebelumnya. Tidak ada bypass keputusan Tim Kordik/Ketua KSM hanya karena Super Admin.
- Alur sampai `terverifikasi`: pengajuan → KSM → Tim Kordik → dokumen → verifikasi. Penerimaan KSM dan penerusan ke Kordik dicatat sebagai dua event dalam satu transaksi. Status KSM/Kordik/dokumen/kegiatan/penyelesaian disimpan terpisah. Status/revisi stale, transisi ilegal, dan alasan kosong untuk tolak/batal/revisi ditolak.
- Revisi periode sebelum penjadwalan kembali ke draft, mengulang approval dan review dokumen. Persyaratan tambahan cakupan baru ditambahkan tanpa membuang kewajiban historis. Pembatalan setelah kegiatan mulai dan pembukaan kembali riwayat selesai dibatasi Tim Kordik. Tidak ada endpoint untuk melompati verifikasi menuju jadwal/kegiatan/penyelesaian; modul itu mengikuti fase 3–7.
- Checklist dasar Koas/Residen/Nonkedokteran disalin per placement. Template tambahan dapat dibatasi jenis/institusi/program/KSM dan dinonaktifkan beralasan. Admin Kordik mereview versi file sesuai resource/kategori dan masa berlaku; Tim Kordik dapat menyetujui pengecualian persyaratan. Template yang berubah tidak mengubah checklist lama tanpa revisi.
- Akun peserta hanya diaktifkan setelah placement terverifikasi dan kepemilikan diperiksa beralasan. Akun baru mendapat password acak yang tidak disampaikan dalam log/UI; peserta menggunakan alur lupa kata sandi. Akun lama yang sudah tertaut dipakai kembali. Email yang cocok dengan akun lain tidak pernah menautkan akun secara otomatis.
- Dashboard menampilkan antrean penerimaan sesuai scope. Halaman peserta, surat, import, template, detail placement, keputusan, checklist, foto, unduhan, dan histori tersedia lewat Blade.

## File dan impor

- Semua berkas disimpan di `storage/app/private/quarantine` dengan nama acak, hash SHA-256, MIME dari isi, ukuran, pengunggah, kategori, resource, versi, dan status scan. Sumber impor juga masuk karantina; tidak ada public disk/symlink untuk unggahan.
- ClamAV memakai protokol TCP INSTREAM, timeout terbatas, hasil `clean` hanya untuk respons sukses eksplisit. Scanner tidak tersedia/error tetap `pending`; malware menjadi `infected`. Struktur yang tidak lolos menjadi `held`.
- PDF harus lolos qpdf check dan inspeksi struktur yang telah didekompresi; PDF terenkripsi atau berisi aksi/objek aktif ditahan. Gambar dicek MIME, dimensi, batas piksel, dan decoding. Pemeriksa qpdf yang hilang tidak dianggap bersih.
- File hanya diunduh sebagai attachment melalui controller dengan pemeriksaan entitlement setiap request, scan bersih, dan kesesuaian hash. Header `private, no-store` dan `nosniff`; unduhan diaudit. Super Admin tidak otomatis memperoleh akses dokumen. Admin/Tim Kordik hanya kategori administratif fase 2; KSM hanya kategori kebutuhan penempatan dalam scope; peserta hanya dokumen placement miliknya yang diizinkan. Surat batch, file peserta lain, serta kategori nilai/logbook yang belum diimplementasikan ditolak. Pendidik belum memiliki entitlement file sebelum penugasan fase 3. Tidak ada jalur akses darurat/wildcard file.
- Impor memakai pembaca XLSX terbatas berbasis ZipArchive/SimpleXML: satu worksheet, 500 peserta, file 5 MB, 100 entri ZIP, total ekstraksi 20 MB, maksimal 8 MB per bagian. Macro, external relationship, entity/doctype XML, bagian terenkripsi, path traversal, dan arsip berlebihan ditolak. Formula tidak dieksekusi dan barisnya ditandai tidak valid. NIK/NIM harus sel teks.
- Header A–E: `name`, `birth_date`, `nik`, `nim`, `email`. Tanggal berupa teks `YYYY-MM-DD`. Pratinjau memeriksa kandidat database dan dalam sumber yang sama. Konfirmasi eksplisit memproses per baris. Baris sukses tidak diulang; peserta lama dapat dipilih eksplisit dengan alasan. Sumber identik untuk operator/institusi sama memakai import yang sudah ada. Baris data rusak diperbaiki melalui unggah sumber baru; tidak ada perubahan identitas diam-diam.
- Tidak ada job hapus otomatis. Retensi/arsip, legal hold, backup, dan otorisasi pemusnahan mengikuti dokumen keputusan dan persetujuan rumah sakit sebelum produksi.

## Verifikasi checkpoint

- PHPUnit SQLite: 51 test, 349 assertion; 1 test concurrency dilewati karena membutuhkan MySQL.
- PHPUnit MySQL lokal `sikordik_phase1_test`: 51 test, 364 assertion, semuanya lulus. Database aplikasi tidak dipakai sebagai database test.
- Test MySQL menjalankan dua proses PHP nyata: hanya satu pengajuan overlap diterima, hanya satu identitas NIK sama dibuat; histori mengikuti transaksi yang berhasil.
- Cakupan HTTP/service: peserta kembali, surat multi-peserta, nomor/normalisasi/duplikat, snapshot, batas inklusif, lintas KSM, pengecualian dan self-approval, stale/ilegal/revisi, checklist/expiry/file asing, aktivasi tanpa takeover email, IDOR lintas peserta/KSM, penolakan Super Admin untuk file, nilai belum publik, MIME/ukuran/scanner, versi lama, impor ulang/macro/ZIP berlebihan.
- Laravel Pint dan build Vite produksi lulus. Build memerlukan izin menjalankan esbuild di luar sandbox Windows; tidak mengubah toolchain global.
- Browser lokal: login CSRF nyata berhasil, navigasi penerimaan dan formulir peserta diperiksa; desktop 1280 px dan ponsel 390 px, tidak ada overflow halaman pada formulir peserta. Data resmi kosong dipertahankan; tidak ada fixture bisnis ditambahkan ke database aplikasi.
- Migration aditif fase 2 diterapkan pada database aplikasi; tidak ada fresh/wipe. Lima migration aplikasi total; fase 2 menambah 11 tabel.

## Batas operasional dan fase berikutnya

ClamAV/qpdf belum dikonfigurasi sebagai layanan operasional lokal/produksi pada checkpoint ini. Integrasi dan perilaku tertahan diuji otomatis; tidak ada klaim pemindaian malware produksi nyata. Skenario file bersih pada test memakai mock hanya di database/storage test. Aktifkan pemindai nyata sebelum menerima dokumen atau impor operasional.

SMTP nyata, konfigurasi deployment, append-only audit pada grant DB, scheduler, dan backup/restore drill tetap mengikuti OPERASIONAL.md. Daftar resmi institusi/program/KSM diisi petugas; aplikasi tidak membuat daftar rumah sakit fiktif. Alur password akun peserta membutuhkan layanan email yang berfungsi.

Fase 3 harus memakai lock peserta/PlacementService yang sama untuk publikasi jadwal, perubahan periode/perpanjangan saat kegiatan berjalan, dan pemeriksaan ulang dokumen; pengecualian periode tidak mengizinkan benturan jam. Fase 7 menambahkan pengesahan/penyesuaian tanggal aktual dan penyelesaian dengan pemeriksaan ulang konflik, bukan UPDATE langsung. CRUD lisensi dan penugasan pendidik tetap fase 3.

Operasi fase 2 memakai role proses eksplisit melalui `AdmissionsAccess`; role kustom fondasi tidak otomatis menjadi pengambil keputusan klinis atau pembaca dokumen. Pengaturan role/scopes tidak ditimpa oleh seeder fase 2.
