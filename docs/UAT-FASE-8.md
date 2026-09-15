# UAT dan keputusan go-live SIKORDIK

## Keputusan saat checkpoint

**Belum go-live.** Implementasi dapat diuji lokal dengan data DUMMY. Fase 8 adalah fase terakhir pengembangan MVP, tetapi penerimaan pengguna dan persiapan produksi berikut tetap wajib diselesaikan.

Pemeriksaan lokal 15 September 2026 mendapati konfigurasi development, debug aktif, URL belum HTTPS produksi, cookie/sesi belum memenuhi konfigurasi produksi, database runtime masih root, SMTP belum siap, secret backup belum diset permanen, qpdf dan ClamAV belum tersedia. Queue dikonfigurasi database dan build frontend tersedia; keberadaan worker/scheduler berjalan belum dibuktikan.

Jalankan ulang tanpa menampilkan secret:

```powershell
.\scripts\php.ps1 artisan sikordik:readiness
```

Exit 1 berarti ada pemeriksaan belum terpenuhi. Exit 0 hanya berarti pemeriksaan otomatis konfigurasi lulus; tidak menggantikan bukti di bawah.

## Matriks penerimaan petugas — belum ditandatangani

Gunakan staging HTTPS terisolasi dan data DUMMY, peramban desktop serta ponsel. Catat nama penguji, tanggal, hasil, bukti dan nomor masalah. Jangan menuliskan password/identitas pasien dalam bukti.

| Skenario | Penguji | Kriteria lulus | Status |
|---|---|---|---|
| Peserta lama kembali | Admin | Identitas lama dipakai; penempatan baru memiliki riwayat terpisah | Menunggu UAT |
| Penerimaan dan penolakan | Admin, KSM, Tim Kordik | Pemisahan pemohon/pemberi persetujuan, kuota/periode/dokumen diperiksa | Menunggu UAT |
| Jadwal dan penugasan | Peserta, pembimbing, KSM | Hanya penugasan sah dapat menyetujui dan jadwal terbit terlihat | Menunggu UAT |
| Presensi dan rekap | Peserta, pembimbing, Ketua KSM | Presensi unik per tanggal, koreksi beralasan, rekap sah terkunci | Menunggu UAT |
| Logbook peserta/pendidik | Peserta, pembimbing, supervisor | Revisi dan pengesahan terpisah; berkas tertahan tidak dapat diunduh | Menunggu UAT |
| Nilai dan keberatan | Pembimbing, peserta | Hanya versi publik terlihat peserta; keberatan/koreksi tercatat | Menunggu UAT |
| Google Form nyata | Admin, peserta | Kedua form siap, privasi pasien terjaga, respons dicocokkan manual | Menunggu UAT |
| Penyelesaian dan reopen | Admin, Tim Kordik | Checklist lengkap, keputusan terpisah, penguncian dan histori QR lama benar | Menunggu UAT |
| Dashboard dan laporan | Semua peran | Hitungan sesuai fixture, filter benar, Excel/PDF terbaca, tidak ada akses lintas scope | Menunggu UAT |
| QR perangkat nyata | Petugas, peserta | Kamera ponsel membuka domain resmi, login/akses diterapkan, versi berlaku/riwayat terbaca | Menunggu UAT |
| PWA dan logout | Peserta, IT | Installability HTTPS, offline/logout tidak membuka dokumen privat dari cache | Menunggu UAT |
| Beban kerja | IT | Pagination dan ekspor pada volume institusi memenuhi target waktu/memori yang disetujui | Menunggu staging |

## Persiapan produksi

1. Domain, sertifikat HTTPS, document root `public`, proxy tepercaya spesifik, blok `.env/.git/backup`, konfigurasi cookie, debug false dan APP_KEY tetap. Uji akses HTTP serta header dan CSRF nyata di browser.
2. Runtime PHP dan MySQL yang didukung, Composer 2 terbaru pada deployment; audit dependensi ulang. Jalankan migrate aditif, build, cache konfigurasi/view/route dan restart worker terkontrol.
3. Akun database runtime non-root; buktikan SELECT/INSERT audit diizinkan dan UPDATE/DELETE audit ditolak dengan akun runtime. Migration/restore memakai akun deployment terpisah.
4. ClamAV dan qpdf terpasang, uji file bersih dan sampel uji antivirus resmi di staging; konfigurasi upload dan karantina. Jangan memaksa scan_status melalui SQL.
5. SMTP/TLS nyata: reset sampai kotak masuk, link benar, expired/single-use dan pencabutan sesi. Worker dan scheduler disupervisi serta kegagalan dimonitor.
6. Secret backup terpisah, salinan offsite terenkripsi, retensi disahkan, RPO/RTO diisi dan diuji. Restore seluruh database dan file pada host terisolasi, buktikan login/relasi/unduhan/pengesahan serta durasi. Tes fixture tidak menggantikan pemulihan produksi.
7. Akun produksi resmi, role/scope ditinjau; akun latihan tidak dipindahkan ke produksi. Google Form institusi dikonfigurasi dan isi privasi diperiksa Admin.
8. Tutup semua masalah kritis UAT. Tim Kordik dan IT mencatat persetujuan, jadwal rilis, kontak insiden dan rencana rollback sebelum data nyata digunakan.

## Latihan backup/restore

Di sumber, isi `SIKORDIK_BACKUP_PASSWORD` melalui secret environment (minimal 32 karakter; jangan argumen command/Git). Stop worker/scheduler, tunggu request selesai, lalu:

```powershell
.\scripts\php.ps1 artisan down
.\scripts\php.ps1 artisan sikordik:backup
.\scripts\php.ps1 artisan up
```

Pulihkan worker/scheduler dan salin arsip terenkripsi ke penyimpanan offsite. Simpan APP_KEY dan password backup terpisah. Jangan membiarkan aplikasi down jika backup gagal; tangani sesuai runbook insiden.

Pada checkout/host latihan terisolasi dengan commit yang sama, arahkan konfigurasi ke database baru kosong misalnya `sikordik_drill_restore`, environment local, SMTP nonaktif, worker/scheduler tidak berjalan. Database harus dibuat operator terlebih dahulu. Kemudian:

```powershell
.\scripts\php.ps1 artisan migrate
.\scripts\php.ps1 artisan sikordik:backup --verify="PATH_ARSIP.zip"
.\scripts\php.ps1 artisan sikordik:restore-drill "PATH_ARSIP.zip"
```

Jangan seed sebelum restore. Command menolak target operasional/berisi data. File pulih berada di direktori privat baru yang dicetak; untuk uji aplikasi, salin ke disk privat **checkout latihan** dengan izin yang tepat, gunakan APP_KEY sumber melalui secret, lalu uji alur. Jangan arahkan aplikasi produksi ke database latihan. Simpan bukti count/relasi/hash/durasi tanpa data pribadi; jangan commit backup atau hasil restore.

## Persetujuan

- Penanggung jawab UAT: belum ditetapkan/disahkan.
- Penanggung jawab infrastruktur dan backup: belum ditetapkan/disahkan.
- Target RPO/RTO dan retensi: belum ditetapkan/disahkan.
- Keputusan Tim Kordik/IT: **menunggu**, bukan otomatis disetujui oleh hasil tes kode.
