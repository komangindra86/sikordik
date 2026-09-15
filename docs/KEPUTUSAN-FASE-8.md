# Keputusan Fase 8 — Dashboard, laporan, verifikasi, dan operasional

Tanggal: 15 September 2026. Dasar: MASTER PROMPT bagian 18–21 dan Fase 8.

## Batas MVP dan kesiapan

Fase 8 adalah fase pengembangan terakhir MVP dalam master prompt. Portal institusi, SIMRS, SSO, WhatsApp/email lanjutan, survei pasien internal, PSrE dan analitik mutu merupakan pengembangan lanjutan. Penyelesaian implementasi tidak menggantikan UAT petugas atau persetujuan go-live.

Status checkpoint ini: implementasi tersedia untuk uji coba lokal menggunakan data DUMMY. **Belum disetujui untuk produksi/data pribadi nyata.** Pemeriksaan lingkungan, kriteria UAT dan penanggung jawab dicatat di [UAT](UAT-FASE-8.md).

## Dashboard dan laporan

- Dashboard menggunakan hitungan database dan cakupan peran/KSM/penugasan/kepemilikan. Jadwal hari ini dibatasi 20, pengelompokan institusi/KSM 50; laporan menyediakan penelusuran lebih lanjut. Angka peserta aktif mewakili baris penempatan berstatus `sedang_stase`, bukan hitungan orang unik lintas KSM.
- Tersedia laporan penempatan/riwayat peserta/institusi/KSM, surat, dokumen, presensi, rekap sah, jadwal/kegiatan pembimbing, logbook, nilai, survei, penyelesaian, dan metadata audit.
- Filter periode penempatan menggunakan irisan tanggal (akhir >= dari, mulai <= sampai). Presensi dan jadwal juga menyaring tanggal kegiatan. Surat menyaring tanggal surat, audit menyaring waktu kejadian. Filter institusi pada surat tidak memberi akses KSM/peserta ke surat.
- Nilai/logbook memerlukan satu penempatan. Ini batas MVP yang disengaja agar ekspor memakai persis pemeriksaan baris modul asal. Tidak ada ekspor massal nilai lintas penempatan. Peserta hanya memperoleh `published_version`, termasuk ketika revisi baru masih draft. Laporan nilai dokumen menunjukkan metadata; berkas sumber diunduh melalui modul asal dengan pemindaian dan otorisasi yang sudah ada.
- Daftar pilihan filter memuat maksimal 500 penempatan terbaru dalam cakupan. Untuk penempatan lama, URL filter `placement` dari laporan tetap dapat dipakai. Filter kosong berarti seluruh data yang berhak diakses. Arsip tetap dapat dilaporkan. Surat dan audit adalah laporan global khusus peran/permission; parameter penempatan/KSM tidak berlaku pada dua jenis tersebut.
- Survei menampilkan kedua kewajiban, termasuk `belum_dimulai`; tidak menampilkan kode respons atau isi Google Form. Laporan dokumen menampilkan status pemeriksaan yang tercatat, bukan klaim pemeriksaan ulang file saat ekspor. Checklist penyelesaian tetap menjadi pemeriksaan kelengkapan final.
- Excel berupa berkas OOXML `.xlsx` asli. Sel masukan pengguna selalu berupa teks, tanpa formula/hyperlink aktif. PDF A4 landscape diproduksi Dompdf dengan akses remote, PHP dan JavaScript nonaktif. HTML diekspresikan melalui escaping Blade.
- HTML dipaginasi 25 baris. Ekspor dibatasi 5.000 baris Excel dan 500 baris PDF; kelebihan ditolak dengan permintaan mempersempit filter, tidak dipotong diam-diam. Ekspor adalah salinan laporan, bukan pengesahan dokumen. Audit ekspor mencatat pembuat, jenis, filter, jumlah baris dan SHA-256 hasil; file tidak disimpan permanen di public.

## QR dan pengesahan

- QR dibuat lokal tanpa layanan pihak ketiga. URL memakai APP_URL yang harus merupakan domain HTTPS resmi saat deployment. Pemindaian mengarah ke login lalu pemeriksaan hak akses; nomor/QR bukan kredensial akses publik.
- Pengesahan meliputi presensi, rekap, review logbook, pengesahan/publikasi/perubahan nilai dan penyelesaian. Hash snapshot dicek ulang terhadap pengesahan sebelum hasil ditampilkan. QR tidak mengklaim memeriksa berkas PDF eksternal yang dibawa pemindai atau identitas pasien dalam berkas.
- Identitas, peran, jabatan, waktu, nomor dan hash presensi/rekap baru disimpan sebagai snapshot pengesahan. Presensi memakai peristiwa histori sebagai referensi QR agar koreksi/reverifikasi tidak mengganti identitas pengesahan lama. Metadata sebelum fase 8 tidak diisi mundur dengan identitas petugas saat ini; riwayat lama tetap dibaca pada modul asal.
- Versi lama atau penyelesaian yang dibuka kembali ditandai sebagai riwayat. Peserta tidak dapat melihat pengesahan draft nilai melalui QR. Pengesahan internal tidak setara tanda tangan tersertifikasi PSrE.

## Hardening dan performa

- Header no-store/nosniff tetap berlaku, ditambah larangan iframe, object, base/form lintas origin serta izin kamera/mikrofon/geolokasi. Kebijakan script/style ketat, HSTS dan trusted proxy spesifik memerlukan pengujian deployment HTTPS; tidak diklaim telah diuji pada localhost.
- Backend menolak akses lintas peserta/KSM, scope kosong, pengguna nonaktif, serta ekspor audit tanpa permission. Ekspor dan QR dibatasi throttle. Penyaringan secret pada audit mencakup APP_KEY, secret backup dan token akses/refresh.
- Migration aditif menambah `approval` nullable pada `attendances`/`attendance_summaries`, serta indeks komposit pada placements, attendances, schedules, audit_logs. Rollback memulihkan indeks pendukung foreign key bila InnoDB menggantinya dengan indeks komposit.
- Query dashboard tidak membaca isi file dan tidak melakukan query per baris penempatan. Laporan memakai subquery scope, paginasi dan batas ekspor. Pengujian beban dengan volume produksi masih menjadi pekerjaan staging.

## Backup dan pemulihan

- `sikordik:backup` membuat ZIP AES-256 berpassword terpisah minimal 32 karakter, berisi baris database JSONL, file privat dan manifest SHA-256. Maintenance wajib; operator harus menghentikan worker/scheduler dan menunggu request aktif selesai. File sumber tidak ditimpa/dihapus.
- `.env`, APP_KEY, bootstrap handoff, artefak latihan direktori demo, backup sebelumnya dan restore-drills tidak dimasukkan. Simpan APP_KEY dan secret backup secara terpisah. Temporary dump plaintext berada hanya di folder privat selama pembuatan; `finally` membersihkannya. Setelah crash, operator meninjau temporary di folder backup privat dengan prosedur insiden.
- Setiap arsip langsung diuji dekripsi/hash; `--verify` mengulangi pemeriksaan. Backup lokal bukan offsite dan bukan bukti RPO/RTO terpenuhi.
- `sikordik:restore-drill` hanya menerima environment local/testing dan database MySQL localhost berakhiran `_restore`, kosong setelah migrate tanpa seed (SQLite memory khusus test). Tabel harus cocok. Database operasional dan target berisi data ditolak. File dipulihkan ke folder privat baru, tidak menimpa file aplikasi. Gunakan commit/migrasi yang sama dengan backup dan validasi relasi/alur setelah restore.
- Tidak ada restore produksi otomatis, penghapusan arsip, pemendekan retensi, atau perubahan APP_KEY. Jadwal offsite, enkripsi volume kerja, retensi, monitoring, RPO/RTO dan persetujuan pemulihan produksi ditetapkan operator.
