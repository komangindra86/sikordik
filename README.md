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

Atur koneksi MySQL/MariaDB dan kredensial administrator awal pada `.env`:

```dotenv
DB_DATABASE=sikordik
DB_USERNAME=root
DB_PASSWORD=
INITIAL_ADMIN_NAME="Super Admin"
INITIAL_ADMIN_EMAIL=admin@example.test
INITIAL_ADMIN_PASSWORD="gunakan-kata-sandi-kuat"
```

Lalu jalankan:

```bash
php artisan migrate --seed
npm run build
php artisan serve
```

Kosongkan `INITIAL_ADMIN_PASSWORD` setelah akun pertama berhasil dibuat. Seeder tidak menyimpan kata sandi bawaan di source code dan aman dijalankan ulang.

## Pengujian di Laragon saat ini

PHP 8.2 lokal memiliki DLL SQLite tetapi belum mengaktifkannya di `php.ini`. Test dapat dijalankan tanpa mengubah konfigurasi global:

```powershell
& 'C:\laragon\bin\php\php-8.2.27-Win32-vs16-x64\php.exe' -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit
& 'C:\laragon\bin\php\php-8.2.27-Win32-vs16-x64\php.exe' vendor\bin\pint --test
npm run build
```

## Fase aktif

Fase 0 telah disusun sebagai audit retrospektif terhadap fondasi yang ada. Baca `docs/FASE-0.md` untuk arsitektur, ERD, matriks akses, alur status, risiko, dan gate sebelum Fase 2. Implementasi Fase 1 dijelaskan di `docs/FASE-1.md`.
