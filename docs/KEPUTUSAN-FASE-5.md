# Keputusan desain Fase 5

Tanggal: 10-09-2026. Dasar: MASTER PROMPT SIKORDIK bagian 11, 12, 18, dan cakupan Fase 5.

## Model dan versi

- Kedua jenis logbook memakai tabel `logbooks`, `logbook_versions`, dan `logbook_reviews`, dibedakan oleh `kind` (`participant`/`educator`). Identitas penempatan, penulis, penugasan penulis, dan penugasan pemeriksa tersimpan dengan foreign key; tidak memakai Eloquent untuk proses bisnis.
- Institusi dan KSM mengikuti snapshot penempatan, bukan profil peserta yang mungkin berubah. Snapshot versi mencatat nama peserta/penulis, jenis logbook/kegiatan, catatan, penugasan pemeriksa, dan hash PDF jika ada.
- Satu jenis logbook peserta pada satu penempatan memakai satu logbook dengan banyak versi. Pengunggahan ulang PDF identik pada logbook yang sama ditolak. Versi lama dan keputusan pemeriksa tidak ditimpa/dihapus.
- Setiap penyimpanan membuat versi draft baru. Logbook peserta wajib membawa PDF baru; lampiran pembimbing opsional, dengan petunjuk bahwa setiap versi menyediakan lampirannya sendiri. Lampiran sebelumnya tetap dapat diakses lewat versi lama.
- Logbook pembimbing dicatat per penempatan; penugasan kelompok resmi Fase 3 sudah memiliki kaitan penempatan, sehingga tidak diperlukan pencatatan kelompok yang menggandakan kegiatan ke semua peserta secara otomatis.
- Kegiatan pembimbing mempunyai tanggal, jenis kegiatan, lokasi, jam mulai/selesai pada hari yang sama, durasi terhitung dalam menit, materi, catatan, dan supervisor. Tanggal sudah berlangsung, berada dalam periode penempatan serta kedua penugasan. Kegiatan dengan penempatan, penulis, tanggal, jam, dan jenis yang sama ditolak sebagai duplikat.

## Akses dan pemeriksaan

- Peserta hanya mengunggah/mengajukan logbook miliknya. Pembimbing hanya menulis berdasarkan penugasan mentor resmi miliknya, dan hanya memeriksa logbook peserta yang menunjuk penugasannya. Supervisor hanya memeriksa logbook pembimbing yang menunjuk penugasan supervisornya.
- Penugasan harus approved; tautan akun pendidik harus tetap sesuai, pendidik dan akun aktif, serta role sesuai. Akhir penugasan tidak menghapus akses administratif historis, tetapi pencabutan/penggantian penugasan dan perubahan tautan akun menghapus kewenangan lama.
- Admin Kordik, Tim Kordik, dan Super Admin dapat memantau; Ketua/Sekretariat KSM hanya dalam scope. Role admin atau wildcard tidak memberi kewenangan mengesahkan bisnis. Pemeriksaan sendiri dilarang.
- Peserta tidak mendapat akses ke logbook pembimbing hanya karena logbook tersebut mengacu pada penempatannya. Semua detail, riwayat, unduhan, dan rekap memakai pembatasan baris yang sama.
- Alur: draft → diajukan → disetujui / perlu revisi / ditolak. Setelah perbaikan, versi baru kembali menjadi draft dan diajukan ulang. Catatan keputusan dan konfirmasi eksplisit wajib pada pemeriksaan.
- Penulis dapat memilih pemeriksa resmi lain ketika membuat versi perbaikan pada draft/revisi/penolakan; tidak ada pemindahan pemeriksa diam-diam saat penugasan berubah. Penugasan yang dicabut ketika logbook masih diajukan memerlukan tindak lanjut administratif; fase ini tidak menyediakan aksi pengalihan logbook yang sudah diajukan.

## Pengesahan dan penguncian

- Persetujuan sekaligus membuat pengesahan internal permanen pada baris review: identitas, nama, jabatan fungsional pada penempatan, role, penugasan, waktu, nomor dokumen `LB-<ULID>`, versi, hash snapshot, dan hash PDF. Riwayat tetap menampilkan identitas pengesahan saat keputusan diambil.
- Hash snapshot dihitung dari JSON dengan urutan kunci kanonis agar konsisten antara SQLite dan MySQL. Integritas snapshot/berkas diperiksa saat pengajuan, persetujuan, dan unduhan. Hash bukan tanda tangan digital tersertifikasi; QR/halaman verifikasi lintas modul tetap Fase 8, integrasi PSrE di luar MVP.
- Versi disetujui tidak dapat ditimpa. Status efektif menjadi **Dikunci — disetujui** apabila penempatan `selesai`; seluruh mutasi logbook juga ditolak pada penempatan selesai atau dibatalkan. Penutupan tidak disimpulkan dari tanggal akhir.
- Penguncian mengikuti status penempatan tanpa menunggu GET/command pembaruan status logbook. Pembukaan kembali penempatan tidak otomatis membuka pengeditan versi yang sudah disetujui. Checklist, keputusan penutupan, dan prosedur koreksi pascapenutupan mengikuti Fase 7.
- Transaksi mengunci peserta, penempatan, dan logbook sebelum memeriksa status/versi. Pembacaan data yang menentukan transisi memakai locking read; revisi optimistis menolak formulir usang. Unique constraint pasangan logbook/versi mencegah versi ganda.

## Berkas, privasi, dan rekap

- PDF maksimal 10 MB, ekstensi dan MIME harus cocok. Nama tersimpan acak, nama unduhan generik, penyimpanan privat/quarantine, tanpa URL publik. Pemeriksaan menggunakan `MalwareScanner`, `FileInspector`, dan command scan Fase 2; berkas tetap tertahan jika ClamAV/qpdf tidak tersedia.
- Pernyataan bebas identitas/informasi medis sensitif pasien wajib pada setiap versi, disertai petunjuk jelas di formulir. Ini merupakan deklarasi dan kewajiban pemeriksaan manusia, bukan klaim deteksi/redaksi otomatis isi PDF atau teks.
- Pengajuan dan persetujuan menolak berkas yang belum lolos pemeriksaan; unduhan memeriksa otorisasi serta hash fisik. Route unduhan administrasi lama tidak memberi akses ke resource logbook.
- Notifikasi internal dikirim kepada pemeriksa setelah pengajuan dan penulis setelah keputusan. Tidak ada pengiriman email/WhatsApp.
- Rekap privat menampilkan versi terkini dan total menit kegiatan pembimbing yang disetujui dalam akses pengguna. Draft/pengajuan/revisi/penolakan tidak dihitung. HTML responsif dapat dicetak/disimpan PDF melalui browser; ekspor khusus Excel/PDF mengikuti Fase 8.
- Rollback transaksi yang sudah menyimpan berkas dapat menyisakan orphan privat di quarantine; tidak dipublikasikan atau dihapus otomatis. Tindak lanjut mengikuti kebijakan operasional penyimpanan privat.
