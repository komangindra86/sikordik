# SIKORDIK RSBM

Sistem Informasi Manajemen Pendidikan Klinis RSBM, dibangun dengan Laravel 12, Blade, Tailwind CSS 4, Vite, dan Query Builder untuk proses aplikasi.

## Panduan pengguna dan proses bisnis

- [Buku panduan pengguna PDF](docs/panduan/Buku-Panduan-SIKORDIK.pdf): langkah per peran, penerimaan sampai penyelesaian, kendala dan latihan.
- [Alur proses bisnis PDF](docs/panduan/Alur-Proses-Bisnis-SIKORDIK.pdf): ringkasan untuk orientasi dan pembagian tugas.
- [Sumber panduan](docs/PANDUAN-PENGGUNA.md) dan [diagram proses bisnis](docs/PROSES-BISNIS.md) untuk pemeliharaan dokumentasi.

## Kebutuhan lokal

- PHP 8.2+
- MySQL 8 / MariaDB yang kompatibel
- Composer 2
- Node.js 20+

## Instalasi

```bash
composer install
npm install
copy .env.example .env
php artisan key:generate
```

Atur koneksi MySQL/MariaDB pada `.env`. Buat database kosong `sikordik` jika belum ada. Untuk lingkungan lokal Laragon yang dipakai proyek ini:

```dotenv
DB_DATABASE=sikordik
DB_USERNAME=root
DB_PASSWORD=
```

Lalu jalankan:

```powershell
.\scripts\php.ps1 artisan migrate --seed
.\scripts\php.ps1 artisan sikordik:bootstrap-local-admin
npm run build
.\scripts\php.ps1 artisan serve
```

Bootstrap menghasilkan password acak di file privat yang diabaikan Git. Gunakan handoff yang ditunjukkan perintah, ganti password setelah login, kemudian hapus file handoff. Perintah tidak menimpa akun yang sudah ada. Produksi memakai secret deployment; lihat [operasional](docs/OPERASIONAL.md).

## Pengujian di Laragon saat ini

PHP 8.2 lokal memiliki DLL SQLite tetapi belum mengaktifkannya di `php.ini`. Test dapat dijalankan tanpa mengubah konfigurasi global:

```powershell
.\scripts\php.ps1 vendor\bin\phpunit
.\scripts\php.ps1 vendor\bin\pint --test
npm run build
```

## Fase aktif

Untuk mencoba alur tanpa input manual, tersedia [paket latihan DUMMY lokal](docs/LATIHAN-DUMMY.md). Akun/password dan panduan latihan tersimpan privat setelah menjalankan `scripts/php.ps1 artisan sikordik:seed-local-demo`.

Fase 8: dashboard sesuai peran, laporan Excel/PDF, QR verifikasi pengesahan, hardening, backup terenkripsi dan latihan restore. Menu **Laporan Excel / PDF** dan **Verifikasi pengesahan** tersedia. Lihat [Fase 8](docs/FASE-8.md), [keputusan desain](docs/KEPUTUSAN-FASE-8.md), dan [UAT / syarat go-live](docs/UAT-FASE-8.md).

**Fase 8 adalah fase pengembangan terakhir MVP. Aplikasi dapat diuji lokal dengan data DUMMY, tetapi belum siap digunakan di produksi dengan data pribadi nyata.** UAT petugas, konfigurasi HTTPS/SMTP/scanner, worker/scheduler dan bukti pemulihan produksi masih diperlukan. Jalankan `scripts/php.ps1 artisan sikordik:readiness` untuk pemeriksaan konfigurasi tanpa menampilkan secret.

Jalankan `composer install`, `scripts/php.ps1 artisan migrate` dan `npm run build` setelah memperbarui kode. Migration fase 8 bersifat aditif (kolom pengesahan dan indeks). QR menggunakan APP_URL; atur domain resmi sebelum produksi.

Fase 7 tetap tersedia melalui **Survei & penyelesaian**: [implementasi](docs/FASE-7.md), [aturan bisnis](docs/KEPUTUSAN-FASE-7.md). Admin menyiapkan dua tautan Google Form melalui **Kelola tautan survei**. Respons dicocokkan manual memakai kode; membuka tautan tidak dianggap selesai.

Penilaian tetap tersedia: [Fase 6](docs/FASE-6.md), [keputusan Fase 6](docs/KEPUTUSAN-FASE-6.md). Konfigurasikan ClamAV dan qpdf sebelum memakai berkas/impor; file tetap tertahan jika pemeriksa tidak tersedia. Pemeriksaan ulang: `scripts/php.ps1 artisan sikordik:scan-private-files`.

Logbook tetap tersedia: [Fase 5](docs/FASE-5.md), [keputusan Fase 5](docs/KEPUTUSAN-FASE-5.md).

Presensi tetap tersedia: [Fase 4](docs/FASE-4.md), [keputusan Fase 4](docs/KEPUTUSAN-FASE-4.md). Pengingat pukul 16.00 memerlukan Laravel scheduler (`artisan schedule:run` setiap menit); command manual: `scripts/php.ps1 artisan sikordik:remind-attendance`.

Penugasan dan jadwal tetap tersedia: [Fase 3](docs/FASE-3.md), [keputusan Fase 3](docs/KEPUTUSAN-FASE-3.md).

Penerimaan tetap tersedia melalui menu **Penerimaan & penempatan**: [Fase 2](docs/FASE-2.md), [keputusan Fase 2](docs/KEPUTUSAN-FASE-2.md).

Riwayat fondasi: [checkpoint Fase 1](docs/CHECKPOINT-FASE-1.md), [audit Fase 0](docs/FASE-0.md), [Fase 1](docs/FASE-1.md).
