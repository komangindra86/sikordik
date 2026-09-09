# Keputusan desain Fase 4

Tanggal: 09-09-2026. Dasar: MASTER PROMPT SIKORDIK bagian 10 dan cakupan Fase 4.

## Hari pendidikan dan presensi

- Hari pendidikan adalah tanggal unik jadwal `published` atau `completed` per placement. Beberapa sesi dalam satu tanggal tetap memiliki satu presensi. Akhir pekan tidak otomatis dikecualikan apabila ada jadwal pendidikan terbit. Kalender hari libur nasional tidak diasumsikan sebagai kebijakan rumah sakit.
- Keunikan mengikuti kriteria penerimaan master prompt: satu presensi per hari **per penempatan**. Placement paralel resmi dapat mempunyai presensi masing-masing; benturan jam tetap ditangani Fase 3.
- Peserta mengisi presensi miliknya pada tanggal pendidikan yang sudah berlangsung, di dalam periode placement. Pengisian susulan diperbolehkan agar keterlambatan administrasi tidak berubah otomatis menjadi ketidakhadiran. Tidak ada GPS, foto, atau penentuan terlambat otomatis yang belum diminta.
- Status kehadiran: hadir, terlambat, izin, sakit, tidak hadir. Jumlah pada rekap hanya menghitung data **terverifikasi**. Hari belum diisi dan presensi belum diverifikasi ditampilkan terpisah.
- Penyimpanan draft tidak mengajukan data. Aksi ajukan dicatat pada histori dan langsung menempatkan data pada `waiting` (Menunggu verifikasi); tidak diperlukan state sementara terpisah untuk Diajukan. Pembimbing dapat memverifikasi atau menolak dengan catatan. Peserta dapat memperbaiki draft/penolakan sebelum pengesahan.
- Lokasi harus aktif dan berasal dari KSM placement. Ringkasan/catatan dibatasi panjangnya dan UI mengingatkan agar identitas pasien tidak dimasukkan.

## Kewenangan

- Pembimbing yang dipilih harus memiliki penugasan mentor resmi berstatus approved pada placement dan tanggal tersebut, akun aktif, role pembimbing, serta tautan pendidik aktif yang masih sesuai. Pemilik presensi tidak dapat memverifikasi sendiri walaupun mempunyai beberapa role.
- Pembimbing tetap dapat membaca dan memverifikasi setelah tanggal akhir penugasannya untuk menyelesaikan administrasi hari yang tercakup penugasan. Pencabutan/penggantian penugasan atau perubahan tautan akun tidak memindahkan akses historis ke akun baru.
- Admin Kordik dapat mengganti verifikator beralasan melalui penugasan resmi yang sudah disetujui KSM. Penugasan awal disimpan permanen, setiap pergantian dicatat, dan pembimbing lama tidak dapat memutuskan data yang sudah dialihkan.
- Admin Kordik/sekretariat dalam scope atau Ketua KSM dapat menghasilkan draft rekap. Hanya role Ketua KSM dalam scope yang dapat mengesahkan; wildcard Super Admin tidak memberikan kewenangan pengesahan bisnis. Peserta tidak dapat mengesahkan rekap miliknya.
- Daftar, detail, laporan, dan riwayat memakai pembatasan placement/KSM/kepemilikan/penugasan. Laporan tidak memiliki URL publik dan memakai respons `private, no-store`.

## Rekap, penguncian, dan koreksi

- Rekap akhir dibuat setelah hari terakhir stase berakhir dalam waktu aplikasi (default WITA). Tombol **Buat rekap terbaru** menghasilkan snapshot berversi untuk pemeriksaan Ketua KSM, termasuk tanggal pendidikan, data harian, nama peserta/KSM, lokasi, dan verifikator.
- Pengesahan mensyaratkan minimal satu hari pendidikan, tidak ada tanggal hilang, tidak ada data belum terverifikasi, tidak ada tanggal presensi di luar kalender, dan fingerprint data masih sesuai. Rekap stale harus dibuat ulang.
- Setelah pengesahan, presensi dan perubahan kalender/perpanjangan terkunci. Hari yang sudah mempunyai presensi tidak boleh dibatalkan/diganti melalui alur perubahan jadwal agar data harian tidak kehilangan dasar kalendernya.
- Perubahan pascapengesahan hanya melalui koreksi Admin Kordik dengan alasan. Snapshot/pengesahan lama tetap tersimpan tetapi statusnya menjadi **tidak berlaku/digantikan**. Presensi berstatus Dikoreksi dan wajib diverifikasi ulang pembimbing, dibuatkan rekap baru, kemudian disahkan kembali Ketua KSM.
- Data yang pernah dikoreksi setelah pengesahan tetap hanya dapat diedit admin, termasuk jika pembimbing menolak koreksi. Tidak ada pembukaan akses edit peserta melalui penolakan tersebut. Koreksi tidak otomatis mengubah status penyelesaian placement; integrasi gate penyelesaian mengikuti fase berikutnya.
- Pengajuan, keputusan, koreksi, dan pengesahan memakai transaksi, penguncian peserta/placement, versi optimistis, serta constraint unik. Pembacaan status pengesahan memakai locking read agar tidak memakai snapshot transaksi MySQL yang stale setelah menunggu proses lain.

## Pengingat dan laporan

- Pengingat internal pukul 16.00 waktu aplikasi melalui Laravel scheduler. Maksimal satu pengingat per presensi/penerima/hari, termasuk jika command dijalankan ulang. Pengingat berhenti setelah verifikasi. Jika penugasan/akun tidak lagi berlaku, pengingat menuju Admin Kordik untuk tindak lanjut.
- Command manual `scripts/php.ps1 artisan sikordik:remind-attendance` tersedia. Scheduler host perlu menjalankan `artisan schedule:run` setiap menit atau `artisan schedule:work` untuk pengembangan. Tidak ada perubahan Windows Task Scheduler yang dibuat otomatis.
- Laporan HTML privat dapat dicetak/disimpan sebagai PDF melalui browser, menggunakan snapshot tersimpan dan penanda draft/disahkan/tidak berlaku. Ekspor PDF/Excel khusus dan QR verifikasi tetap mengikuti Fase 8.
- File cetak yang sudah berada di luar sistem tidak dapat ditarik kembali; laporan mencantumkan referensi versi dan arahan memeriksa status terkini di SIKORDIK.
