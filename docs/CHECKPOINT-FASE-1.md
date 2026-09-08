# Checkpoint penutupan Fase 0/1

Tanggal: 08-09-2026.

**Keputusan: siap memulai pengembangan Fase 2.** Gate wajib audit Fase 0 telah ditutup. Modul bisnis/migration Fase 2 belum dibuat; checkpoint ini tidak menyatakan sistem siap produksi.

## Gate wajib

| Gate | Bukti penutupan |
|---|---|
| Repository mandiri dan baseline | Root Git sekarang `C:\laragon\www\Sikordik`; baseline `a3eaee4`. Source dan koreksi dapat ditinjau lewat Git. `.env`, credential handoff, file upload, log, cache dan SQLite tidak masuk version control. |
| Administrator awal | Satu akun Super Admin lokal aktif dibuat dengan password acak 24 karakter melalui CLI, diaudit; login/logout HTTP nyata berhasil. Handoff berada di `storage/app/private/bootstrap/01M1ZSFTHXTCY29DZQG74R3B0H.txt`, diabaikan Git. |
| Keputusan penerimaan Kordik | Status `menunggu_persetujuan_kordik` dan `ditolak_kordik`, keputusan terpisah dari konfirmasi KSM, pelaku dan transisi ditetapkan di KEPUTUSAN-FASE-2.md. |
| Overlap dan identitas | Periode inklusif lintas KSM per peserta, pengecualian disetujui Tim Kordik, transaksi dengan locking; BIGINT + ULID + nomor tetap `PDK-YYYY-000001`. |
| Kebijakan file privat | Format/ukuran, karantina, hak akses, versioning, audit dan retensi ditetapkan. Disk lokal sudah privat dan route serve otomatis dimatikan. Modul upload/scanning/authorized download dibuat di Fase 2. |

## Koreksi fondasi yang selesai

- Scope KSM diterapkan pada daftar/pencarian, opsi form, edit/update/status master KSM/lokasi/pendidik. Scope kosong berarti tidak ada resource KSM yang bisa diakses. POST tidak boleh memindahkan resource ke KSM di luar scope atau menautkan akun yang tidak tersedia dalam cakupan form.
- Dashboard non-global tidak menampilkan total pengguna/pendidik seluruh rumah sakit. Referensi umum institusi/program/jenis peserta tetap dapat dibaca oleh role dengan `masters.view`.
- Admin biasa tidak dapat mengubah/reset/menonaktifkan Super Admin, mengelola akun berpermission lebih tinggi, atau memberikan permission melebihi kewenangannya lewat role kustom. Super Admin tidak bisa mencabut role administrator atau menonaktifkan akun sendiri. Pengelola role delegasi juga dibatasi pada permission yang dimiliki.
- Penggantian password administratif maupun reset token mencabut sesi database dan merotasi remember token; penonaktifan akun juga mencabut sesi. Login menormalisasi email sebelum pemeriksaan dan rate limit.
- Response halaman dinamis memakai `private, no-store`, `nosniff` dan kebijakan referrer. Service worker hanya membersihkan cache dengan prefix milik SIKORDIK, tidak menghapus cache aplikasi lain pada origin yang sama.
- Seeder tidak mengembalikan role yang sudah disunting ke permission default dan tidak mengaktifkan ulang jenis peserta yang dinonaktifkan.
- CLI bootstrap lokal menolak production dan akun yang sudah ada; password tidak ditaruh di source atau output terminal. Petugas perlu mengganti password dan menghapus handoff sesudah disimpan aman.
- Wrapper PowerShell mengisolasi runtime PHP proyek tanpa mengubah PATH global. Template environment produksi dan prosedur operasional telah ditambahkan.

## Bukti verifikasi

| Pemeriksaan | Hasil |
|---|---|
| PHPUnit SQLite in-memory | **32 test, 164 assertion, lulus** |
| PHPUnit MySQL `sikordik_phase1_test` | **32 test, 164 assertion, lulus**; database khusus, bukan `sikordik` |
| Migration database aplikasi | `Nothing to migrate`; tetap 4 migration dan 23 tabel, tidak ada fresh/wipe pada database aplikasi |
| Data aplikasi | 1 pengguna/1 Super Admin aktif; 8 role, 12 permission, 3 jenis peserta |
| Laravel Pint | Lulus |
| Build Vite produksi | Lulus; 58 modul |
| Smoke test HTTP lokal | Login dengan CSRF nyata ke dashboard 200, no-store, URL privat 404, logout berhasil; server uji dihentikan |
| Git | Diff whitespace bersih; ignore secret/private file diverifikasi |

Tambahan cakupan regression test: throttle login/reset, normalisasi email, notifikasi reset melalui fake, token reset kedaluwarsa/single-use, pencabutan sesi, logout, account privilege escalation, self-demotion, scope melalui HTTP, master uniqueness, audit tanpa route mutasi, seed ulang, bootstrap lokal dan larangan bootstrap production.

## Penundaan eksplisit dan batas kesiapan

- CRUD lisensi pendidik: **Fase 3**, sebelum aturan kelayakan penugasan. Tidak menghalangi surat/peserta/placement Fase 2.
- PATH PHP global dan Composer global tidak diubah; wrapper membuat perintah proyek dapat dipakai sekarang. Upgrade toolchain sebelum CI/produksi.
- SMTP delivery nyata, scanner malware, deployment HTTPS/secure cookie, grant DB append-only audit, queue/scheduler dan backup/restore drill belum dijalankan. Persyaratannya ada di OPERASIONAL.md dan wajib sebelum penggunaan produksi terkait.
- Tidak ada klaim pengujian ulang installability lintas browser, audit dependency terbaru, penetration test, atau concurrency placement. Pengujian fase berikutnya harus menambahkan cakupan tersebut saat fitur tersedia.
- Master resmi rumah sakit belum diisi. Tidak ada institusi/KSM/peserta fiktif ditambahkan ke database aplikasi; fixture berada di database test.
- Git lokal bukan backup offsite; belum ada remote atau push.

## Titik mulai Fase 2

Ikuti KEPUTUSAN-FASE-2.md. Mulai dari migration dan service peserta/nomor identitas, surat, placement/histori/approval, serta metadata file privat. Setelah itu checklist dokumen, impor terkontrol, aktivasi akun, UI dan test penerimaan. Pertahankan Query Builder untuk proses bisnis utama.
