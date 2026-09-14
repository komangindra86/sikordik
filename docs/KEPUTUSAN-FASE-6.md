# Keputusan desain Fase 6

Tanggal: 14-09-2026. Dasar: MASTER PROMPT SIKORDIK bagian 13, 14, 18, dan cakupan Fase 6.

## Template dan komponen

- Admin Kordik/Super Admin mengonfigurasi template. Template disimpan tetap: perubahan dilakukan dengan membuat template pengganti dan menonaktifkan template sebelumnya. Template nonaktif tidak menerima penilaian baru, tetapi riwayat dan koreksi penilaian lama tetap tersedia.
- Cakupan opsional: institusi, program studi, jenis peserta, KSM, jenis ujian, dan rentang tanggal. Kecocokan memakai identitas penempatan dan tanggal penilaian, bukan profil peserta terkini. Beberapa template yang cocok ditampilkan agar pengisi memilih format institusi yang benar; tidak ada prioritas template otomatis.
- Setiap template memiliki 1–30 komponen terurut, nama, deskripsi, input angka atau teks, rentang skor, bobot opsional, batas lulus komponen, catatan, dan status wajib. Angka memakai maksimal dua desimal agar validasi sesuai presisi penyimpanan. Kolom tidak dikenal ditolak.
- Default **tanpa agregasi** menampilkan setiap komponen tanpa memaksakan rumus total/lulus. Opsi **berbobot** harus dipilih secara eksplisit: jumlah `(nilai − minimum) / (maksimum − minimum) × bobot`, dengan bobot total 100, komponen angka wajib, dan total dibulatkan dua desimal pada skala 0–100. Kelulusan total hanya dihitung jika batas lulus total dikonfigurasi. Batas lulus komponen tetap ditampilkan terpisah.
- Rumus lain, kategori kompleks, atau format institusi yang tidak cocok dengan angka/teks dapat memakai metode unggah PDF yang sudah diisi, dihitung, dan ditandatangani institusi/pembimbing. Sistem tidak melakukan OCR atau menafsirkan tanda tangan di PDF.

## Pengisian dan publikasi

- Satu penilaian ditandai penempatan, template, judul/identitas ujian, dan tanggal; kombinasi yang sama ditolak sebagai duplikat. Ujian berbeda atau pengulangan dapat memiliki judul berbeda. Identitas, metode, dan penugasan pada penilaian tidak berubah setelah dibuat.
- Pembimbing maupun penguji dengan penugasan resmi dapat mengisi. Keduanya memakai role akun `pembimbing`, dibedakan lewat penugasan `mentor`/`examiner`. Penguji memilih pembimbing resmi sebagai pengesah/penerbit. Role admin, wildcard Super Admin, Ketua KSM, atau Tim Kordik tidak memberi hak mengesahkan nilai.
- Hanya pembimbing yang ditunjuk pada penilaian dapat mengesahkan, memublikasikan, dan memutuskan keberatan. Penguji tidak dapat mengambil alih pengesahan melalui penugasan penguji saja. Pengisian oleh penguji mengirim notifikasi internal kepada pembimbing.
- Penugasan harus approved, akun dan pendidik aktif, tautan akun sesuai, role sesuai, serta tanggal penilaian berada dalam periode penugasan dan penempatan serta tidak di masa depan. Akhir periode penugasan tidak menghapus akses historis; pencabutan, perubahan tautan akun, atau penonaktifan menghapus kewenangan. Tidak ada pemberian penugasan otomatis.
- Alur `draft → approved → published`: penyimpanan draft membuat versi baru; pengesahan membekukan versi; publikasi oleh pembimbing langsung membuat versi tersebut terlihat oleh peserta tanpa verifikasi Tim Kordik. Versi disahkan belum dapat dilihat peserta sebelum publikasi dan tidak diedit langsung.
- Peserta hanya melihat versi yang memiliki peristiwa publikasi, termasuk PDF dan pengesahannya. Saat koreksi masih draft/disahkan, peserta tetap melihat nilai publikasi sebelumnya. Jalur unduhan menjalankan pembatasan yang sama; URL berkas modul penerimaan tidak dapat membaca berkas penilaian.
- Admin/Tim Kordik/Super Admin dapat memantau; Ketua/Sekretariat KSM terbatas scope KSM. Pembimbing/penguji terbatas penilaian yang menunjuk penugasannya. Peserta yang memiliki role tambahan tetap tidak boleh menilai dirinya sendiri.

## Keberatan dan koreksi

- Peserta mengajukan alasan yang menyebut komponen/hasil yang dipermasalahkan, konfirmasi, deklarasi bebas data pasien, dan PDF opsional setelah publikasi. Satu keberatan per versi mencegah pengajuan rangkap; versi koreksi yang dipublikasikan dapat diberi keberatan baru. Fase ini tidak menetapkan tenggat hari atau banding ulang terhadap versi yang keberatannya ditolak.
- Alur: **Diajukan → Sedang ditinjau → Diterima atau Ditolak**. Tanggapan wajib pada setiap tindakan pembimbing dan riwayat tanggapan tetap tersimpan. Lampiran yang belum lolos pemeriksaan menahan tinjauan/keputusan.
- Keberatan diterima memberi pembimbing akses membuat versi koreksi. Nilai lama, PDF, hash, pencatat, waktu, dan pengesahan tidak ditimpa/dihapus. Penguji tidak dapat mengubah nilai terpublikasi melalui alur ini.
- Pembimbing mengesahkan dan memublikasikan koreksi. Publikasi secara atomik mengalihkan `published_version`, menautkan `corrected_version`, dan mengubah keberatan menjadi **Selesai**. Keberatan ditolak tetap **Ditolak**, tanpa membuka pengeditan. Tidak ada koreksi langsung oleh admin.
- Semua mutasi ditolak setelah penempatan selesai/dibatalkan atau pada status sebelum berjalan. Data selesai tetap dibaca sesuai hak akses. Pembukaan kembali dan checklist penyelesaian mengikuti Fase 7.

## Integritas, transaksi, dan berkas

- Tabel: `assessment_templates`, `assessment_components`, `assessments`, `assessment_versions`, `assessment_events`, `grade_appeals`. Nilai komponen disimpan dalam snapshot versi beserta definisi komponen, aturan perhitungan, identitas penempatan, hasil, dan hash PDF. Berkas memakai `private_files`; notifikasi/audit memakai layanan yang sudah ada.
- Pengesahan dan publikasi menyimpan nomor `NL-<ULID>`, identitas/nama/jabatan, role, penugasan, waktu, versi, hash snapshot, dan hash PDF. Pengesahan koreksi memakai mekanisme yang sama. Hash memakai JSON kanonis rekursif agar urutan kunci MySQL tidak memengaruhi hasil.
- Hash snapshot diperiksa saat membaca nilai, pengesahan, publikasi, dan unduhan. Integritas PDF fisik serta status pemindaian diperiksa sebelum pengesahan/publikasi/unduhan. Hash dan pengesahan internal bukan tanda tangan tersertifikasi; QR dan halaman verifikasi mengikuti Fase 8, PSrE di luar MVP.
- Transaksi mengunci peserta/penempatan sebelum penilaian; data penentu transisi dibaca dengan locking read, termasuk penugasan, akun, pendidik, dan role saat validasi pengesahan. Revisi optimistis menolak formulir usang. Unique constraints melindungi identitas penilaian, versi, dan keberatan.
- PDF maksimal 10 MB, MIME dan ekstensi PDF cocok, nama acak, storage privat/quarantine, tanpa URL publik. ClamAV/qpdf yang gagal atau tidak tersedia menahan berkas. Deklarasi bebas informasi sensitif pasien tetap memerlukan pemeriksaan manusia; tidak ada klaim redaksi/deteksi identitas otomatis.
- Rollback setelah penulisan berkas dapat menyisakan orphan privat di quarantine. Ikuti kebijakan operasional penyimpanan; tidak ada penghapusan otomatis atau bypass scanner.
- Notifikasi hanya internal setelah publikasi dan tindakan keberatan. Halaman dan unduhan memakai `private, no-store`; tidak ada email/WhatsApp atau ekspor khusus dalam fase ini.

## Batas alur

- Belum ada pengalihan penilaian ketika penugasan pemeriksa/pengisi dicabut atau akun ditautkan ulang. Sistem menolak kewenangan lama, dan tidak memindahkan nilai secara diam-diam; penyelesaian administratif membutuhkan prosedur lanjutan yang menjaga riwayat.
- Versi yang sudah disahkan dibekukan sampai publikasi. Pembatalan pengesahan sebelum publikasi dan koreksi administratif tanpa keberatan belum disediakan.
- Tidak ada template atau nilai institusi nyata yang diisikan otomatis. Admin menyiapkan template sesuai formulir yang disetujui institusi sebelum penggunaan operasional.
