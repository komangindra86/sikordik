# Penyederhanaan alur SIKORDIK

Dikerjakan 5–8 Oktober 2026 atas permintaan pemilik produk: alur harus simpel, mudah digunakan, dan tidak membingungkan pengguna. Aturan bisnis Fase 1–8 dipertahankan; yang berubah adalah jumlah langkah, bahasa layar, dan beberapa aturan yang dicatat tegas di bawah.

## Tahap dan checkpoint

| Tahap | Isi | Commit |
|---|---|---|
| 1 | Beranda *Tugas saya* dan menu sesuai peran | `37c2b73` |
| 2 | Halaman penempatan terpadu: garis tahap, langkah berikutnya, tab | `1702066` |
| 3 | Bahasa layar, tombol langsung, alasan hanya saat menolak | `8e928f5` |
| 4 | Jadwal satu periode, presensi satu ketuk, verifikasi massal, mulai otomatis | `2df4850` |
| 5 | Penerimaan rombongan dan persetujuan sekaligus | `c25b007` |
| 6 | Akun saya, tautan aktivasi, unggah dokumen oleh peserta, mode latihan lokal | checkpoint ini |

## Aturan yang berubah

Perubahan berikut disetujui pemilik produk ("kerjakan semua") terhadap usulan tanggal 5 Oktober 2026. Semuanya tetap meninggalkan jejak pelaku, waktu, dan audit log.

1. **Alasan hanya wajib untuk keputusan yang menghentikan.** Menolak, meminta revisi, menarik, membatalkan, mengoreksi, membuka kembali, dan mengecualikan tetap wajib beralasan (minimal 10 karakter; logbook dan keberatan nilai 5). Menyetujui penugasan, presensi, perpanjangan, logbook, nilai, dokumen valid, pengajuan dan persetujuan penyelesaian tidak lagi meminta alasan. Penugasan pertama tidak meminta alasan; pergantian pendidik tetap wajib.
2. **Jadwal yang disetujui pembimbing langsung terbit.** Persetujuan dan penerbitan tetap dicatat sebagai dua kejadian atas nama pembimbing. Status *disetujui, belum terbit* masih dikenali untuk data lama.
3. **Stase dimulai otomatis** ketika jadwal terbit pada periode yang sudah berjalan, ketika presensi pertama dikirim, atau oleh perintah harian `sikordik:start-placements`. Syarat mulai (jadwal terbit, dokumen lengkap, tanpa benturan) tidak berubah; bila belum terpenuhi, tombol *Mulai stase sekarang* milik Admin tetap ada.
4. **Nilai disahkan dan dipublikasikan dengan satu tombol.** Dua pengesahan (sahkan, publikasikan) tetap tercatat. Keberatan diputus dengan satu tombol; langkah *ditinjau* tetap tercatat sebelum keputusan.
5. **Akun peserta dapat diaktifkan sejak penerimaan disetujui Tim Kordik** (status menunggu dokumen), bukan baru setelah dokumen terverifikasi, agar peserta dapat mengunggah dokumennya sendiri. Akun baru menerima tautan sekali pakai untuk membuat kata sandi; tautan ditampilkan kepada Admin sehingga aktivasi tidak bergantung pada email.
6. **Peserta boleh mengunggah dokumen persyaratan miliknya** (ijazah, BHD, SIP, STR, kompetensi, administrasi). Surat pengantar, berkas pendukung, dan pernyataan valid tetap wewenang Admin Kordik; pengecualian tetap wewenang Tim Kordik.

Tidak berubah: konfirmasi KSM lalu persetujuan Tim Kordik, pemisahan pemohon dan pemberi keputusan, verifikasi presensi oleh pembimbing, pengesahan rekap oleh Ketua KSM, pemeriksaan logbook, kewajiban dua survei, checklist dan persetujuan penyelesaian, penguncian, serta larangan data pasien.

## Yang baru di aplikasi

- `/dashboard` — Tugas saya (`TaskService`), ringkasan angka hanya untuk petugas.
- `/stase`, `/stase/{ulid}` — daftar dan ringkasan penempatan (`PlacementHub`). Tab hanya tampil bila modulnya memang boleh dibuka pengguna itu.
- `/penerimaan/rombongan` — penerimaan satu surat untuk banyak peserta (`BatchAdmissionService`). Satu baris bermasalah membatalkan seluruh simpan.
- `/penerimaan/keputusan` — persetujuan banyak penerimaan sekaligus dalam kewenangan masing-masing.
- `/penjadwalan/penempatan/{ulid}/jadwal/rentang`, `/jadwal-massal`, `/jadwal/{ulid}/setujui`.
- `/presensi/penempatan/{ulid}/cepat`, `/verifikasi-massal`.
- `/akun` — data akun dan ganti kata sandi; sesi di perangkat lain dikeluarkan.
- `App\Support\Ui` — satu tempat untuk label status, nama kejadian, dan format tanggal.

## Mode latihan lokal

`SIKORDIK_SCAN_BYPASS=true` meloloskan berkas saat ClamAV/qpdf tidak terpasang. Pengaturan ini **hanya** berlaku bila `APP_ENV=local`; di lingkungan lain diabaikan, dan `sikordik:readiness` menandainya sebagai belum siap. Layar menampilkan peringatan selama mode ini aktif. Berkas lama yang masih tertahan dapat diperiksa ulang dengan `sikordik:scan-private-files`.

## Operasional

Tambahkan ke scheduler (sudah terdaftar di `routes/console.php`): `sikordik:start-placements` setiap hari pukul 00.10 WITA. Tanpa scheduler, stase tetap mulai pada presensi pertama.

## Belum dikerjakan

- Paket latihan DUMMY masih mencakup Fase 1–4; skenario logbook, nilai, survei, dan penyelesaian dijalankan manual memakai akun latihan.
- PDF buku panduan (`docs/panduan/*.pdf`) belum dibuat ulang; isinya masih memakai nama menu lama. Gunakan [panduan ringkas](PANDUAN-RINGKAS.md).
- Halaman daftar per modul (`/presensi`, `/logbook`, `/penilaian`) masih ada tetapi tidak lagi ditautkan dari menu.
- UAT petugas dan syarat produksi pada [UAT-FASE-8.md](UAT-FASE-8.md) tetap berlaku dan belum dipenuhi.
