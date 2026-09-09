# Keputusan desain Fase 3

Tanggal: 09-09-2026. Cakupan: penugasan, kelompok, jadwal, perubahan, perpanjangan, dan notifikasi internal. Dasar: MASTER PROMPT SIKORDIK dan keputusan Fase 2.

## Kewenangan

- Admin Kordik dan Sekretariat KSM dalam scope menyiapkan penugasan dan kelompok. Ketua/Koordinator KSM terkait memutuskan penugasan. Pemohon tidak boleh menyetujui permohonannya sendiri, termasuk akun multi-role. Super Admin tidak mendapat kewenangan keputusan bisnis hanya dari wildcard.
- Peserta menyusun dan mengajukan jadwal sendiri; admin/sekretariat dalam scope dapat membantu dengan alasan. Pembimbing yang tercantum pada penugasan jadwal menyetujui atau meminta revisi. Penguji, supervisor, dan pembimbing lain tidak otomatis berwenang menyetujui jadwal tersebut.
- Setelah disetujui, peserta atau petugas administrasi yang berwenang menerbitkan jadwal. Penerbitan memeriksa ulang penugasan, akun pemberi persetujuan, kelayakan pendidik, periode, checklist, dan benturan.
- Perpanjangan dimohonkan Admin Kordik, disetujui KSM lalu Tim Kordik. Admin dapat menarik permohonan stale/keliru dan mengajukan ulang. Tanggal berubah hanya pada keputusan final Tim Kordik.
- Aturan kelompok dan kewenangan perpanjangan di atas merupakan aturan awal yang dilaporkan pada awal pengerjaan; tidak ada perubahan kebijakan rumah sakit yang diasumsikan sudah disahkan.

## Pendidik dan lisensi

- Peran `mentor`, `examiner`, dan `supervisor` melekat pada penugasan per placement dengan periode inklusif. Satu placement dapat memiliki beberapa pembimbing; pendidik yang sama dapat memiliki penugasan pembimbing sekaligus penguji.
- Pendidik harus aktif, berasal dari KSM penempatan, memiliki kemampuan peran terkait, serta akun aktif dengan role pembimbing/supervisor yang sesuai. Penugasan menyimpan akun pendidik saat diajukan. Mengganti tautan akun pada master tidak memindahkan kewenangan lama.
- Admin Kordik mencatat lisensi/otorisasi pendidikan sesuai kredensial resmi. Minimal satu kredensial aktif; semua kredensial yang ditandai aktif harus berlaku sepanjang penugasan dan tidak kedaluwarsa saat keputusan. Jenis wajib per profesi harus ditentukan petugas dari kebijakan rumah sakit, bukan ditebak sebagai aturan hukum oleh aplikasi. Tanggal akhir kosong berarti tanpa tanggal akhir yang dicatat. Pembaruan kredensial disimpan dengan versi dan histori; kredensial lama dapat dinonaktifkan beralasan, tanpa dihapus.
- Pergantian menautkan penugasan baru ke penugasan lama. Alasan, pemohon, approver, waktu, serta sebelum/sesudah tersimpan. Penugasan lama menjadi `replaced`, bukan dihapus. Jadwal aktif yang masih memakai pendidik lama harus direvisi/dibatalkan melalui persetujuan terlebih dahulu; tidak dipindah otomatis.
- Penugasan dapat menunjuk kelompok tetapi tetap dicatat per placement anggota. Tidak ada propagasi otomatis ke anggota yang masuk kemudian. Ini mempertahankan persetujuan dan periode setiap peserta.

## Kelompok

- Kelompok berada pada satu KSM. Keanggotaan memiliki tanggal mulai dan selesai, harus berada dalam periode placement, dan tidak boleh bertumpang tindih untuk placement yang sama.
- Peserta dapat berpindah dengan mengakhiri keanggotaan lama beralasan lalu menambah periode baru. Tanggal lama dan baru tersimpan dalam histori. Keanggotaan tidak dipotong jika masih ada jadwal/penugasan kelompok yang melampaui tanggal akhir baru.
- Jadwal kelompok dicatat per peserta anggota. Keanggotaan dicek pada tanggal kegiatan; mengubah anggota kelompok tidak mengubah jadwal historis atau membuat jadwal peserta lain otomatis.

## Jadwal dan revisi

- Alur: `draft → submitted → approved → published → completed`. Pembimbing dapat mengembalikan `submitted → revision`, kemudian peserta menyimpan draft baru dan mengajukan ulang. Pengajuan belum terbit dapat ditarik beralasan.
- Jam menggunakan WITA. Dua jam harus diisi bersama, jam akhir lebih besar dari jam mulai; kegiatan lintas tengah malam dibuat menjadi dua kegiatan pada tanggal masing-masing. Tanpa jam, satu kegiatan memesan hari penuh.
- Benturan diperiksa untuk peserta yang sama lintas semua placement/KSM. Interval jam setengah terbuka: 08:00–10:00 dan 10:00–12:00 boleh berdampingan. Jadwal diajukan, disetujui, terbit, dan selesai memesan waktu; draft belum memesan. Pengecualian periode paralel tidak membolehkan benturan jam.
- Pemeriksaan ini adalah kalender peserta. Pendidik dapat membimbing beberapa peserta pada sesi yang sama; sistem tidak menganggap jadwal peserta berbeda otomatis menjadi benturan kapasitas pendidik/ruangan.
- Jadwal terbit tidak dapat diubah langsung. Permohonan pengganti/pembatalan memiliki alasan dan persetujuan pembimbing, menautkan versi asal, serta menyimpan versi asal sampai keputusan pengganti diterbitkan. Hanya satu permohonan perubahan aktif per jadwal asal. Pembatalan dapat memakai pembimbing lain yang sudah ditugaskan resmi; bukan pemberian kewenangan admin untuk menyetujui sendiri.
- `completed` hanya menandai satu kegiatan yang harinya telah berakhir. Tidak menyelesaikan placement atau menggantikan verifikasi presensi pada Fase 4. Admin dapat memulai stase dalam periode yang sah setelah jadwal terbit dan syarat diperiksa kembali.

## Perpanjangan dan dokumen

- Perpanjangan tidak memperpanjang penugasan/keanggotaan secara otomatis. Jadwal pada tanggal tambahan memerlukan penugasan yang mencakup tanggal tersebut.
- Checklist diperiksa hingga tanggal akhir baru. Review/pembaruan dokumen diperbolehkan pada placement terverifikasi, dijadwalkan, dan sedang stase. Mengganti dokumen menjadi belum valid menghalangi publikasi baru sampai syarat lengkap; jadwal historis tidak dihapus.
- Konflik periode memakai lock peserta dan PlacementService yang sama dengan Fase 2. Perpanjangan dalam KSM yang sama tetap tidak boleh bentrok.
- Perpanjangan paralel lintas KSM memerlukan berkas pendukung bersih milik placement, daftar konflik yang di-snapshot, dan persetujuan eksplisit KSM serta Tim Kordik. Perubahan daftar/periode konflik membuat permohonan stale. Persetujuan final membuat pengecualian baru yang terikat fingerprint periode/revisi baru dalam transaksi yang sama, lalu memeriksa ulang overlap. Persetujuan lama tetap disimpan.

## Akses dan notifikasi

- Daftar/detail kegiatan dibatasi scope KSM, kepemilikan peserta, atau penugasan aktif yang belum berakhir. Pendidik tidak memperoleh akses dokumen administratif peserta hanya karena dapat membaca jadwal. Entitlement file Fase 2 tetap berlaku; file pendukung hanya diunduh pihak yang berhak.
- Notifikasi internal tersimpan dalam transaksi bersama perubahan bisnis dan ditujukan kepada pemohon, peserta, pembimbing atau approver tahap berikutnya. Notifikasi hanya dibaca/ditandai oleh penerimanya. Pesan ringkas tidak membawa isi dokumen atau identitas pasien.
- Email/WhatsApp, presensi, logbook, nilai, dan penyelesaian placement tetap mengikuti fase masing-masing.
