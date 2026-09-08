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

Fase 0/1 ditutup pada 08-09-2026: **siap mulai pengembangan Fase 2**, belum siap produksi. Bukti verifikasi: [checkpoint Fase 1](docs/CHECKPOINT-FASE-1.md). Aturan yang dipakai fase berikutnya: [keputusan Fase 2](docs/KEPUTUSAN-FASE-2.md). Audit historis: [Fase 0](docs/FASE-0.md); implementasi fondasi: [Fase 1](docs/FASE-1.md).
