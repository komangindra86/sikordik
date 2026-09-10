# SIKORDIK RSBM

Sistem Informasi Manajemen Pendidikan Klinis RSBM, dibangun dengan Laravel 12, Blade, Tailwind CSS 4, Vite, dan Query Builder untuk proses aplikasi.

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

Fase 5: unggah dan versi PDF logbook peserta, verifikasi pembimbing, logbook kegiatan pembimbing, pemeriksaan supervisor, pengesahan internal, dan rekap kegiatan privat. Akses melalui menu **Logbook**. Implementasi dan verifikasi: [Fase 5](docs/FASE-5.md). Aturan bisnis: [keputusan Fase 5](docs/KEPUTUSAN-FASE-5.md).

Jalankan `scripts/php.ps1 artisan migrate` untuk menambah tabel fase 5. Konfigurasikan ClamAV dan qpdf sebelum memakai berkas/impor; file tetap tertahan jika pemeriksa tidak tersedia. Pemeriksaan ulang: `scripts/php.ps1 artisan sikordik:scan-private-files`. Sistem belum dinyatakan siap produksi. Fase 6 belum dimulai.

Presensi tetap tersedia: [Fase 4](docs/FASE-4.md), [keputusan Fase 4](docs/KEPUTUSAN-FASE-4.md). Pengingat pukul 16.00 memerlukan Laravel scheduler (`artisan schedule:run` setiap menit); command manual: `scripts/php.ps1 artisan sikordik:remind-attendance`.

Penugasan dan jadwal tetap tersedia: [Fase 3](docs/FASE-3.md), [keputusan Fase 3](docs/KEPUTUSAN-FASE-3.md).

Penerimaan tetap tersedia melalui menu **Penerimaan & penempatan**: [Fase 2](docs/FASE-2.md), [keputusan Fase 2](docs/KEPUTUSAN-FASE-2.md).

Riwayat fondasi: [checkpoint Fase 1](docs/CHECKPOINT-FASE-1.md), [audit Fase 0](docs/FASE-0.md), [Fase 1](docs/FASE-1.md).
