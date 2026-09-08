# Keputusan acuan Fase 2

Tanggal: 08-09-2026. Dasar: master prompt, audit Fase 0, dan persetujuan pengguna untuk memilih solusi terbaik. Dokumen ini menggantikan pertanyaan terbuka sebelum Fase 2 pada FASE-0.md. **Ini spesifikasi yang disepakati, bukan klaim bahwa fitur Fase 2 sudah dibuat.**

## 1. Identitas peserta

- Satu orang memiliki satu data induk seumur hidup dalam aplikasi; kunjungan kembali membuat placement baru, bukan peserta baru.
- Primary key internal: BIGINT. Referensi publik/URL: ULID unik. Nomor yang dibaca petugas: `PDK-YYYY-000001`, misalnya `PDK-2026-000001`.
- Tahun adalah tahun pendaftaran pertama, zona waktu Asia/Makassar. Nomor tetap saat peserta kembali, pindah institusi, atau berganti tahun. Minimal enam digit urutan; angka di atas 999999 boleh bertambah digit.
- Nomor dibuat server melalui sequence per tahun yang dikunci dalam transaksi, dilindungi unique index; jangan gunakan `MAX(id)+1`. Celah urutan akibat transaksi/kegagalan dapat diterima, nomor tidak didaur ulang.
- NIK/NIM bukan primary key atau bagian URL. ULID tidak menggantikan pemeriksaan otorisasi.

## 2. Deteksi duplikat dan surat masuk

- NIK opsional; bila diisi berupa string 16 digit, bukan integer. NIM disimpan sebagai string untuk mempertahankan nol di depan.
- Normalisasi pencarian: trim; email lowercase; NIM uppercase dan institusi yang sama; nama case-insensitive dengan spasi berulang dirapikan, dibandingkan bersama tanggal lahir. Simpan nama asli untuk tampilan. Nilai kosong tidak pernah cocok dengan nilai kosong.
- Kandidat pasti secara pencocokan: NIK sama; NIM + institusi sama; email sama; atau nama ternormalisasi + tanggal lahir sama. Tampilkan alasan kecocokan, bukan persentase identitas yang menyesatkan. Kemiripan nama saja hanya peringatan tambahan.
- **Tidak ada auto-merge.** Admin meninjau kandidat. Untuk NIK sama, gunakan data lama atau koreksi NIK yang salah; unique NIK non-null mencegah dua record memakai NIK yang sama. Kandidat lain dapat ditandai bukan orang yang sama dengan alasan dan audit.
- Email akun login tetap unik. Email kontak peserta tidak otomatis membuktikan identitas atau menghubungkan akun. Aktivasi akun harus diperiksa admin; tidak pernah menautkan akun hanya karena email cocok.
- Nomor surat unik pada kombinasi institusi pengirim + tahun tanggal surat + nomor ternormalisasi. Normalisasi nomor: trim, uppercase, rapikan spasi; pertahankan slash/tanda baca. Nomor yang sama dari institusi/tahun berbeda diperbolehkan.
- Surat memiliki banyak peserta dan placement. Perbaikan berkas surat menjadi versi baru, tidak membuat surat duplikat. Snapshot institusi/program/jenis peserta disimpan pada placement agar perubahan data induk tidak menulis ulang sejarah.
- Impor menyediakan pratinjau, validasi tiap baris, deteksi duplikat, dan konfirmasi sebelum commit. Tidak auto-merge; kegagalan ditampilkan per baris dan percobaan ulang tidak menggandakan data yang sudah berhasil.
- Master resmi diisi petugas berwenang; jangan mengarang daftar KSM/institusi rumah sakit. Fixture pengujian hanya di database test. Ketiadaan daftar resmi tidak menghambat pengembangan.

## 3. Aturan overlap placement

Overlap berarti **satu peserta memiliki dua periode penempatan yang bertumpang tindih**, termasuk lintas KSM atau institusi. Ini bukan benturan dua peserta yang berbeda.

Tanggal mulai/selesai bersifat inklusif. Rumus:

```text
mulai_baru <= selesai_lama DAN selesai_baru >= mulai_lama
```

Contoh: 1–10 September dan 10–20 September bentrok; 1–10 September dan 11–20 September tidak bentrok.

- Tanggal selesai tidak boleh lebih awal dari mulai. Pemeriksaan meliputi semua placement yang sudah diajukan/disetujui, sedang berjalan, dan riwayat selesai, bukan hanya status aktif hari ini.
- `draft` tidak memesan periode, tetapi menampilkan peringatan konflik. `ditolak_ksm`, `ditolak_kordik`, dan `dibatalkan` tidak memesan periode; histori tetap disimpan.
- Overlap dalam KSM yang sama ditolak sebagai penempatan rangkap. Perubahan/perpanjangan harus melalui placement yang sudah ada.
- Lintas KSM: default ditolak saat pengajuan. Jika benar program paralel yang sah, admin dapat meminta pengecualian kepada Tim Kordik dari draft, dengan alasan, daftar placement yang berbenturan, dan dokumen pendukung. Persetujuan pengecualian harus ada **sebelum** pengajuan dilanjutkan.
- Pengecualian tidak mengizinkan jadwal pada jam yang sama (validasi jadwal pada Fase 3). Tim Kordik tidak menyetujui permohonannya sendiri; harus approver lain yang berwenang. Tidak ada bypass hanya karena role Super Admin.
- Persetujuan pengecualian terikat pada pasangan placement, KSM dan periode yang diperiksa. Mengubah periode/KSM memerlukan pemeriksaan ulang; persetujuan lama tidak dapat dipakai untuk konflik baru.
- Pada pengajuan, persetujuan, perubahan periode, perpanjangan dan pembukaan kembali: kunci baris peserta dengan `lockForUpdate()` di transaksi, baca ulang placement dengan locking read, lalu validasi konflik dan izin. Semua jalur mutasi harus memakai service yang sama. Riwayat dan audit ikut transaksi.
- Untuk penempatan yang belum selesai gunakan periode rencana; untuk riwayat selesai gunakan tanggal selesai aktual yang telah disahkan. Perubahan tanggal aktual yang menimbulkan konflik juga harus ditinjau, tidak memotong histori secara diam-diam.

## 4. Persetujuan dan status

Status konfirmasi KSM, keputusan Kordik, kelengkapan dokumen, kegiatan, dan penyelesaian disimpan terpisah; `status` placement merangkum posisi workflow. `diterima_ksm` bukan persetujuan final rumah sakit.

| Dari | Aksi dan pelaku | Ke |
|---|---|---|
| `draft` | Admin mengajukan setelah validasi dan overlap sah | `menunggu_konfirmasi_ksm` |
| `menunggu_konfirmasi_ksm` | Ketua/Koordinator KSM pada scope terkait menyatakan tersedia | `diterima_ksm` |
| `menunggu_konfirmasi_ksm` | Ketua/Koordinator KSM menolak dengan alasan | `ditolak_ksm` |
| `diterima_ksm` | Sistem meneruskan antrean dalam transaksi yang sama; kedua event tercatat | `menunggu_persetujuan_kordik` |
| `menunggu_persetujuan_kordik` | Tim Kordik menyetujui | `menunggu_dokumen` |
| `menunggu_persetujuan_kordik` | Tim Kordik menolak dengan alasan | `ditolak_kordik` |
| `menunggu_dokumen` | Admin Kordik memverifikasi checklist lengkap, valid, atau pengecualian disetujui | `terverifikasi` |
| `terverifikasi` | Jadwal disahkan/dipublikasikan (Fase 3) | `dijadwalkan` |
| `dijadwalkan` | Kegiatan dimulai sesuai periode dan syarat | `sedang_stase` |
| `sedang_stase` | Pengajuan akhir kegiatan | `menunggu_penyelesaian` |
| `menunggu_penyelesaian` | Checklist selesai dan Tim Kordik menyetujui (Fase 7) | `selesai` |

- Sekretariat KSM menyiapkan/menindaklanjuti respons; persetujuan final KSM adalah Ketua/Koordinator. Admin Kordik mengelola proses administratif, Tim Kordik memutuskan penerimaan. Multi-role tidak menghapus pemeriksaan scope.
- Tolak/batal/revisi memerlukan alasan. Pembatalan sebelum stase oleh Admin Kordik; setelah mulai memerlukan persetujuan Tim Kordik. Penempatan selesai tidak dibatalkan langsung: perlu pembukaan kembali beralasan dan berizin.
- Pengajuan ditolak tidak ditimpa diam-diam. Revisi eksplisit kembali ke draft mempertahankan histori, membatalkan approval lama, dan mengulang validasi. Aksi pada stale status ditolak.
- Akun peserta baru diaktifkan setelah verifikasi; akun peserta lama dipakai kembali setelah pemeriksaan kepemilikan. Akses peserta hanya ke data miliknya dan file yang secara khusus diizinkan.
- Checklist awal mengikuti master prompt: Koas (surat, ijazah, BHD), Residen (surat, ijazah, BHD, SIP, STR, sertifikat kompetensi), Nonkedokteran (surat, ijazah). Dapat dikonfigurasi berdasarkan jenis/program/institusi/KSM, di-snapshot per placement agar perubahan template tidak mengubah kewajiban historis tanpa revisi beralasan.

## 5. Kebijakan file privat

Semua unggahan pengguna privat sejak awal. Penyimpanan `storage/app/private`, tidak melalui public disk/symlink. Hanya aset UI statis boleh publik. Download harus melalui controller yang memeriksa permission, scope, kepemilikan/penugasan, jenis dokumen, dan status publikasi pada setiap request. Signed URL saja tidak cukup untuk dokumen sensitif.

| Kategori | Format yang diizinkan | Maksimum per file |
|---|---|---:|
| Foto peserta | JPEG, PNG, WebP | 3 MB |
| Surat pengantar | PDF | 10 MB |
| Dokumen administrasi/kompetensi | PDF, JPEG, PNG | 10 MB |
| Logbook | PDF | 25 MB |
| Penilaian/keberatan | PDF, JPEG, PNG | 15 MB |
| Impor peserta | XLSX tanpa macro | 5 MB |

- Validasi ekstensi dan MIME dari isi, ukuran, jumlah file, serta struktur file. Tolak executable, HTML, SVG, arsip bebas, macro, konten aktif berbahaya dan file terenkripsi yang tidak bisa diperiksa. XLSX diperiksa dengan batas jumlah baris/ukuran hasil ekstraksi untuk mencegah ZIP bomb; formula tidak dieksekusi saat impor.
- Upload masuk karantina. Produksi hanya dapat membuka file dengan hasil scan malware bersih; scanner gagal/tidak tersedia berarti tetap tertahan, bukan dianggap bersih. Scanner palsu hanya untuk automated test. Integrasi scanning merupakan pekerjaan Fase 2, belum tersedia sekarang.
- Path acak menggunakan ULID; nama asli hanya metadata yang disanitasi. Simpan SHA-256, MIME terdeteksi, ukuran, pengunggah, kategori, resource terkait, status scan, versi, dan waktu. File tidak ditimpa; versi lama tetap tersimpan dengan akses terkontrol.
- Gunakan download attachment sebagai default; preview hanya tipe aman. Header `Cache-Control: private, no-store` dan `X-Content-Type-Options: nosniff`. Catat unduhan/akses sensitif di audit tanpa isi file/token.
- Admin/Tim Kordik: dokumen administratif sesuai kewenangan proses. KSM: hanya kebutuhan penempatan dalam scope-nya. Pendidik: hanya penugasan terkait dan jenis dokumen yang diperlukan, bukan seluruh identitas administratif peserta.
- Peserta: dokumen sendiri yang boleh diakses dan nilai yang sudah dipublikasikan; **bukan surat batch lengkap berisi peserta lain**, file peserta lain, atau nilai belum publik. Bila diperlukan, sediakan ekstrak surat khusus peserta yang terotorisasi.
- Super Admin mengelola sistem, bukan otomatis pembaca semua file klinis. Policy file Fase 2 wajib memeriksa entitlement dokumen tersendiri tanpa mengandalkan wildcard `hasPermission()` Fase 1. Akses darurat harus eksplisit, beralasan, berbatas waktu, dan diaudit.
- Dilarang menyimpan nama/NIK/nomor rekam medis, wajah pasien atau identitas pasien lain dalam logbook/survei/file. Pengunggah harus menyatakan sudah menghilangkan identitas; reviewer menolak file yang melanggar. Scan malware tidak membuktikan file bebas data pasien.

## 6. Retensi dan arsip

- Simpan dokumen bisnis/riwayat/audit minimal 3 tahun setelah placement selesai, lalu arsipkan, **bukan otomatis hapus**. Dokumen bersama mengikuti placement terkait yang paling akhir selesai. Dokumen identitas aktif mengikuti kebutuhan peserta dan kebijakan arsipnya.
- Kebijakan operasional awal: file sementara/gagal/karantina tidak terpakai ditinjau untuk dibersihkan setelah 7 hari; laporan dan sumber impor setelah 90 hari. Dokumen yang sudah menjadi bukti bisnis tidak termasuk file sementara.
- Penghapusan harus mempertimbangkan legal hold, insiden, kewajiban rumah sakit dan backup; legal hold mengalahkan jadwal pembersihan. Catat tombstone, pelaku, alasan dan waktu penghapusan tanpa menyimpan ulang isi sensitif.
- Angka di atas adalah keputusan desain awal, bukan kesimpulan kepatuhan hukum. Kebijakan retensi final dan otorisasi pemusnahan perlu disahkan rumah sakit sebelum produksi. Belum ada job auto-delete yang diaktifkan.

## 7. Batas pengerjaan berikutnya

Fase 2 mencakup migration/service/UI/test surat, peserta, deteksi duplikat, impor, placement, persetujuan, file privat, checklist dokumen, aktivasi akun, dan histori. Tidak memasukkan jadwal/presensi/logbook/nilai sebagai modul penuh sebelum fase masing-masing.

Uji penerimaan wajib: peserta lama kembali tanpa data induk baru; surat multi-peserta; duplikat tanpa auto-merge; batas tanggal inklusif; konflik lintas KSM; persetujuan pengecualian; concurrency MySQL; transisi ilegal/stale; IDOR file lintas peserta/KSM; file salah MIME/ukuran/scan; versi lama tidak hilang; nilai belum publik tidak terbaca; impor diulang tidak menggandakan data.
