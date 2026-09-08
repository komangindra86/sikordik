# Catatan Implementasi Fase 1 — Fondasi

Status 08-09-2026: koreksi fondasi dan gate Fase 2 selesai. Bukti dan batasan ada pada [checkpoint Fase 1](CHECKPOINT-FASE-1.md). Keputusan bisnis berikutnya mengikuti [keputusan Fase 2](KEPUTUSAN-FASE-2.md).

## Ruang lingkup selesai

- Laravel 12 dan konfigurasi Bahasa Indonesia/zona waktu Asia/Makassar.
- Login session, logout, lupa/reset kata sandi, rate limit login, dan invalidasi session akun nonaktif.
- Multi-role RBAC, katalog permission, role sistem, role kustom, serta assignment role.
- Scope akses KSM melalui `user_scopes` dan middleware/service reusable untuk modul berikutnya.
- Manajemen pengguna termasuk perubahan status, alasan perubahan, dan pencegahan eskalasi Super Admin.
- Audit log hanya-baca dengan data sebelum/sesudah, alasan, pelaku, IP, user-agent, dan penyaringan field sensitif.
- Master institusi, jenjang, program studi, KSM, lokasi klinis, jenis peserta, serta pembimbing/penguji/supervisor.
- Tabel lisensi tenaga pendidik sebagai fondasi pengembangan detail kredensial.
- Layout dashboard mobile-first dengan navigasi sesuai permission.
- Manifest, ikon 192/512, service worker aset statis, dan halaman offline. Navigasi dinamis/sensitif tidak pernah dimasukkan cache.

## Tabel Fase 1

`users`, `password_reset_tokens`, `sessions`, `roles`, `permissions`, `role_permissions`, `user_roles`, `user_scopes`, `institutions`, `education_levels`, `study_programs`, `departments`, `clinical_locations`, `participant_types`, `educators`, `educator_licenses`, dan `audit_logs`.

Tabel cache/jobs bawaan Laravel juga dipertahankan untuk kebutuhan framework dan fase notifikasi berikutnya.

## Keputusan arsitektur

- Model `User` dipakai hanya untuk kontrak autentikasi Laravel. CRUD dan proses aplikasi menggunakan Query Builder.
- Role sistem berasal dari `config/rbac.php`. Seeder hanya memasang role/assignment default yang belum ada, tidak menimpa pengaturan role yang sudah diubah petugas. Permission administratif Super Admin bersifat implisit; file klinis Fase 2 memerlukan policy tersendiri yang tidak mengikuti wildcard ini.
- Role berbasis KSM (`sekretariat-ksm`, `ketua-ksm`) wajib memiliki minimal satu scope KSM.
- Master data tidak dihapus melalui UI. Status aktif/nonaktif mempertahankan referensi historis.
- Setiap update administratif dan perubahan status wajib memuat alasan. Pembuatan data tidak memerlukan alasan.
- Audit log tidak memiliki route update/hapus. Password, token, cookie, authorization, dan isi file disaring sebelum disimpan.
- Service worker menggunakan network-only untuk navigasi dengan fallback offline; hanya aset versi build dan ikon yang dicache.

## Asumsi dan batas Fase 1

- Scope institusi/penempatan/peserta disiapkan oleh bentuk generik `scope_type` dan akan ditambahkan saat tabel bisnis terkait tersedia.
- CRUD lisensi pendidik secara eksplisit ditunda ke Fase 3, sebelum validasi kelayakan penugasan pendidik. Tabel dan constraint sudah tersedia; ini bukan blocker modul surat/peserta Fase 2.
- Dashboard Fase 1 hanya menampilkan statistik fondasi. Widget proses pendidikan dibuat pada fase bisnis masing-masing.
- Email reset menggunakan mail driver Laravel. Lingkungan produksi wajib memasang SMTP/provider yang sesuai.
- HTTPS, backup, dan konfigurasi cookie produksi merupakan pekerjaan deployment/hardening, bukan nilai yang aman untuk dipaksakan pada lingkungan lokal.

## Verifikasi manual

1. Lokal: jalankan `scripts/php.ps1 artisan migrate --seed`, lalu `scripts/php.ps1 artisan sikordik:bootstrap-local-admin`. Ikuti petunjuk kredensial privat. Produksi memakai secret deployment sesuai [operasional](OPERASIONAL.md).
2. Masuk dan pastikan menu sesuai role.
3. Buat KSM, pengguna dengan dua role, dan scope KSM; masuk sebagai pengguna tersebut untuk memeriksa pembatasan.
4. Ubah master/pengguna dengan alasan, lalu periksa data sebelum/sesudah pada Audit Log.
5. Uji lupa kata sandi dengan mail driver yang dikonfigurasi.
6. Jalankan build produksi, buka DevTools Application, dan pastikan manifest/installability serta fallback offline bekerja.

## Rencana fase berikutnya

Fase 2 dapat menambahkan surat masuk, data induk peserta, deteksi kandidat duplikat, impor Excel, penempatan berulang, persetujuan KSM/Tim Kordik, dokumen persyaratan, aktivasi akun peserta, dan riwayat status. Jangan mulai sebelum Fase 1 diterima.
