# Data latihan lokal SIKORDIK

Paket tambahan setelah Fase 4, dibuat pada 10-09-2026. Paket latihan ini mencakup fase 1–4; belum menyediakan fixture logbook Fase 5.

## Menyiapkan paket

```powershell
.\scripts\php.ps1 artisan sikordik:seed-local-demo --dry-run
.\scripts\php.ps1 artisan sikordik:seed-local-demo
```

Command hanya menerima lingkungan local/testing dan MySQL localhost (atau SQLite memory untuk test). Paket ditambahkan dalam transaksi tanpa mengubah akun/data yang sudah ada. Pengulangan tidak mereset latihan atau password. Jangan menjalankannya pada database operasional.

Paket berisi 11 akun berpassword acak, satu institusi/program/jenjang/KSM/lokasi latihan, satu pendidik beserta kredensial simulasi, satu surat, enam peserta/penempatan, delapan jadwal, lima presensi, dan dua rekap. Semua nama data bisnis bertanda DUMMY. Tidak ada NIK, dokumen, maupun identitas pasien nyata.

Email dan password disimpan **hanya pada file privat** `storage/app/private/demo/AKUN-DAN-PANDUAN-DUMMY.md`, diabaikan Git. File tersebut juga berisi langkah berganti akun. Akun administrator sebelumnya tidak diubah. Email `.test` khusus latihan lokal, tidak membutuhkan pengiriman email untuk login akun dummy.

## Pilihan latihan

| Peserta | Kondisi awal | Akun untuk melanjutkan |
|---|---|---|
| DUMMY 01 — Penerimaan | Menunggu konfirmasi KSM | `ketua@demo.sikordik.test`, lalu `tim@demo.sikordik.test` |
| DUMMY 02 — Penugasan | Penempatan terverifikasi, penugasan pembimbing menunggu persetujuan | `ketua@demo.sikordik.test` |
| DUMMY 03 — Isi Presensi | Stase berjalan, jadwal terbit, presensi belum diisi | `presensi@demo.sikordik.test` |
| DUMMY 04 — Verifikasi Presensi | Presensi sudah diajukan | `pembimbing@demo.sikordik.test` |
| DUMMY 05 — Sahkan Rekap | Periode berakhir, presensi terverifikasi, draft rekap siap | `ketua@demo.sikordik.test` |
| DUMMY 06 — Rekap Terkunci | Rekap disahkan dan terkunci | `admin@demo.sikordik.test` untuk latihan koreksi |

Mulai dari DUMMY 03 melalui menu **Presensi**, lalu berganti akun pembimbing untuk memverifikasi. Untuk latihan pengesahan tanpa menunggu periode berakhir, gunakan DUMMY 05. Password masing-masing ada pada file privat, bukan dokumen ini.

Tanggal relatif terhadap hari pembuatan pertama, tidak digeser pada pengulangan. Jadwal berjalan tersedia pada hari pembuatan dan hari berikutnya; tambahkan jadwal terbit baru bila berlatih pada tanggal lain. Periode berjalan hingga 14 hari setelah pembuatan.

## Batas simulasi

- Dokumen DUMMY 02–06 memakai mekanisme pengecualian Tim Kordik melalui service bisnis, dengan alasan DUMMY pada histori. Pemindai tidak dimatikan dan tidak ada file palsu yang ditandai bersih. DUMMY 01 dibiarkan belum lengkap untuk latihan penerimaan/dokumen.
- Command menautkan **hanya akun peserta baru milik paket** secara langsung agar tidak bergantung pada email reset. Termasuk akun DUMMY 01 yang disiapkan sebelum penerimaan selesai; ini jalur fixture lokal, bukan perubahan aturan aktivasi peserta operasional.
- Persetujuan, penugasan, publikasi, presensi, dan pengesahan memakai service aplikasi. Kegiatan historis DUMMY 05–06 direplay pada tanggal latihan memakai jam simulasi yang segera dipulihkan, agar penugasan berlaku pada saat aksi. Status akhir placement historis tetap mengikuti fase yang tersedia dan tidak dipaksa menjadi selesai pendidikan.
- Tidak ada perintah hapus/reset otomatis. Jika penanda atau email demo ditemukan, command tidak mengadopsi akun tersebut dan tidak menimpa data.

## Verifikasi

- SQLite: 3 test, 41 assertion lulus.
- MySQL lokal terisolasi `sikordik_phase1_test`: 3 test, 41 assertion lulus.
- Cakupan: skenario terhubung, login aktual melalui route HTTP, halaman presensi sesuai kepemilikan, akun lama tidak berubah, pengulangan tidak menggandakan/mengubah data, dry-run tidak menulis, production ditolak, dan akun beremail demo yang sudah ada tidak diambil alih.
- Laravel Pint dan `git diff --check` lulus. Tidak ada perubahan aset frontend.
- Paket berhasil ditambahkan ke database lokal `sikordik` pada 10-09-2026.
