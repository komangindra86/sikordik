# Checkpoint dokumentasi pengguna

Tanggal: 15 September 2026. Cakupan: buku panduan dan proses bisnis fitur sampai Fase 8. Tidak memulai fase implementasi baru.

## Hasil

- `panduan/Buku-Panduan-SIKORDIK.pdf`: 21 halaman, petunjuk awal per peran, status, menu, prosedur seluruh modul, koreksi, kendala dan latihan.
- `panduan/Alur-Proses-Bisnis-SIKORDIK.pdf`: 3 halaman untuk orientasi, pembagian tanggung jawab dan checklist penyelesaian.
- `PANDUAN-PENGGUNA.md`: sumber naskah yang dapat diperbarui.
- `PROSES-BISNIS.md`: ringkasan yang dihasilkan dari naskah, termasuk Mermaid dengan cabang revisi dan penolakan. PDF memakai diagram urutan utama yang lebih ringkas dan penjelasan tindak lanjut.
- `scripts/build-user-guide.py`: pembuat PDF menggunakan ReportLab, dengan bookmark bab. Jalankan dari runtime Python yang memiliki ReportLab; proses hanya membaca naskah dan menulis artefak dokumentasi.

## Dasar pemeriksaan isi

Nama menu dan tindakan diperiksa terhadap `routes/web.php` serta Blade modul penerimaan, jadwal, presensi, logbook, penilaian, penyelesaian, laporan dan verifikasi. Aturan dibandingkan dengan service terkait dan keputusan Fase 2-8. Jika spesifikasi awal berbeda dengan implementasi terkini, panduan mengikuti implementasi; contoh batas PDF logbook dan penilaian adalah 10 MB.

Panduan tidak memuat akun, kata sandi, token, data peserta nyata, berkas privat atau tangkapan layar data operasional. Pembuatan panduan tidak memodifikasi data aplikasi. Alamat instalasi dan kontak petugas mengikuti informasi pengelola, tanpa alamat produksi yang diasumsikan.

## Validasi

- Pembuatan ulang kedua PDF berhasil.
- Jumlah halaman dan teks setiap bab diperiksa; bookmark tersedia untuk navigasi.
- Seluruh halaman dirender dan diperiksa secara visual, termasuk diagram, tabel, batas halaman dan footer.
- `git diff --check` lulus.
- Tidak ada perubahan logika aplikasi; pengujian aplikasi tidak dijalankan ulang untuk perubahan dokumentasi ini. Pemeriksaan isi berdasarkan kode bukan pengganti uji praktik pengguna.

Kesiapan produksi tetap mengikuti `UAT-FASE-8.md` dan `OPERASIONAL.md`. Buku panduan dapat dibagikan untuk orientasi; checkpoint dokumentasi tidak menyatakan lingkungan produksi atau UAT pengguna telah disahkan.
