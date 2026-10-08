<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AssessmentService;
use App\Services\AssessmentTemplateService;
use App\Services\AttendanceService;
use App\Services\AuditLogger;
use App\Services\CompletionService;
use App\Services\EducatorAssignmentService;
use App\Services\LogbookService;
use App\Services\ParticipantService;
use App\Services\PlacementExtensionService;
use App\Services\PlacementService;
use App\Services\ScheduleService;
use App\Services\SurveyService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SeedLocalDemo extends Command
{
    protected $signature = 'sikordik:seed-local-demo {--dry-run : Periksa lingkungan dan jumlah data tanpa menulis}';

    protected $description = 'Tambahkan paket latihan DUMMY (dasar dan lanjutan sampai penyelesaian) hanya di database lokal; tidak menimpa data';

    private const HANDOFF = 'demo/AKUN-DAN-PANDUAN-DUMMY.md';

    private const REASON = 'DUMMY latihan lokal, bukan data atau keputusan pendidikan nyata.';

    private array $accounts = [];

    public function handle(): int
    {
        $connection = config('database.default');
        $host = config("database.connections.{$connection}.host");
        $database = config("database.connections.{$connection}.database");
        $safe = ($connection === 'mysql' && in_array($host, ['127.0.0.1', 'localhost'], true))
            || (app()->environment('testing') && $connection === 'sqlite' && $database === ':memory:');
        if (! app()->environment(['local', 'testing']) || ! $safe || config("database.connections.{$connection}.url")) {
            $this->error('Data DUMMY hanya boleh dibuat pada lingkungan local/testing dan database lokal yang eksplisit.');

            return self::FAILURE;
        }
        $this->info('Lingkungan: '.app()->environment().' | Database: '.$database);
        $this->table(['Data', 'Jumlah sebelum perubahan'], collect(['users', 'participants', 'placements', 'attendances'])->map(fn ($table) => [$table, DB::table($table)->count()])->all());
        if ($this->option('dry-run')) {
            $this->info('Pemeriksaan selesai; tidak ada data yang ditulis.');

            return self::SUCCESS;
        }
        $this->accounts = [];
        try {
            $status = DB::transaction(function () {
                // Serializes concurrent runs even before any demo accounts exist.
                $roles = DB::table('roles')->orderBy('id')->lockForUpdate()->get()->keyBy('code');
                foreach (['admin-kordik', 'tim-kordik', 'ketua-ksm', 'sekretariat-ksm', 'pembimbing', 'peserta'] as $code) {
                    if (! isset($roles[$code])) {
                        throw new \RuntimeException('Role dasar belum lengkap; jalankan seeder fondasi terlebih dahulu.');
                    }
                }
                if (DB::table('users')->where('email', 'like', '%@demo.sikordik.test')->lockForUpdate()->exists()
                    || DB::table('departments')->where('code', 'DEMO-KSM')->lockForUpdate()->exists()
                    || Storage::disk('local')->exists(self::HANDOFF)) {
                    $this->warn('Paket dasar DUMMY atau penandanya sudah ada; data dan password paket dasar tidak diubah.');
                    if (Storage::disk('local')->exists(self::HANDOFF)) {
                        $this->info('Panduan privat: '.Storage::disk('local')->path(self::HANDOFF));
                    }

                    return self::SUCCESS;
                }
                $department = $this->master('departments', ['code' => 'DEMO-KSM', 'name' => 'DUMMY — KSM Pendidikan Latihan']);
                $institution = $this->master('institutions', ['code' => 'DEMO-INSTITUSI', 'name' => 'DUMMY — Institut Pendidikan Simulasi']);
                $level = $this->master('education_levels', ['code' => 'DEMO-PROFESI', 'name' => 'DUMMY — Profesi']);
                $program = $this->master('study_programs', ['code' => 'DEMO-PROGRAM', 'name' => 'DUMMY — Program Pendidikan Klinis', 'institution_id' => $institution, 'education_level_id' => $level]);
                $location = $this->master('clinical_locations', ['code' => 'DEMO-UNIT', 'name' => 'DUMMY — Unit Praktik', 'department_id' => $department]);
                $admin = $this->account('admin', 'DUMMY — Admin Kordik', $roles['admin-kordik']->id);
                $kordik = $this->account('tim', 'DUMMY — Tim Kordik', $roles['tim-kordik']->id);
                $chief = $this->account('ketua', 'DUMMY — Ketua KSM', $roles['ketua-ksm']->id, $department);
                $this->account('sekretariat', 'DUMMY — Sekretariat KSM', $roles['sekretariat-ksm']->id, $department);
                $mentor = $this->account('pembimbing', 'DUMMY — Pembimbing Klinik', $roles['pembimbing']->id);
                $educator = $this->master('educators', ['user_id' => $mentor->id, 'department_id' => $department, 'name' => 'DUMMY — Pembimbing Klinik', 'can_mentor' => true, 'can_examine' => true]);
                Auth::setUser($admin);
                app(EducatorAssignmentService::class)->license($admin, $educator, ['license_type' => 'Otorisasi latihan DUMMY', 'license_number' => 'DEMO-LISENSI-001',
                    'issued_at' => today()->subYear()->toDateString(), 'expires_at' => today()->addYear()->toDateString(), 'is_active' => true, 'revision' => 0, 'reason' => self::REASON]);
                $letter = $this->master('incoming_letters', ['ulid' => (string) Str::ulid(), 'institution_id' => $institution, 'number' => 'DUMMY/001/'.today()->year,
                    'normalized_number' => 'DUMMY/001/'.today()->year, 'letter_date' => today()->subDays(21)->toDateString(), 'year' => today()->subDays(21)->year,
                    'subject' => 'DUMMY — Surat latihan, bukan surat resmi', 'created_by' => $admin->id]);
                $type = DB::table('participant_types')->where('code', 'KOAS')->value('id');
                if (! $type) {
                    throw new \RuntimeException('Jenis peserta KOAS belum tersedia.');
                }
                $scenarios = ['penerimaan' => '01 — Penerimaan', 'penugasan' => '02 — Penugasan', 'presensi' => '03 — Isi Presensi',
                    'verifikasi' => '04 — Verifikasi Presensi', 'rekap' => '05 — Sahkan Rekap', 'terkunci' => '06 — Rekap Terkunci'];
                $guide = [];
                foreach ($scenarios as $key => $label) {
                    $ended = in_array($key, ['rekap', 'terkunci']);
                    $owner = $this->account($key, 'DUMMY '.$label, $roles['peserta']->id);
                    Auth::setUser($admin);
                    $participant = app(ParticipantService::class)->create($admin, ['name' => 'DUMMY '.$label, 'email' => $owner->email,
                        'nim' => 'DEMO-'.strtoupper($key), 'birth_date' => '2000-01-01', 'institution_id' => $institution]);
                    $p = app(PlacementService::class)->create($admin, ['participant_ulid' => $participant->ulid, 'letter_ulid' => DB::table('incoming_letters')->where('id', $letter)->value('ulid'),
                        'study_program_id' => $program, 'participant_type_id' => $type, 'department_id' => $department,
                        'start_date' => today()->subDays($ended ? 14 : 3)->toDateString(), 'end_date' => today()->addDays($ended ? -1 : 14)->toDateString()]);
                    $this->transition($admin, $p, 'submit');
                    if ($key !== 'penerimaan') {
                        $this->transition($chief, $p, 'ksm_accept');
                        $this->transition($kordik, $p, 'kordik_accept');
                        Auth::setUser($kordik);
                        foreach (DB::table('placement_documents')->where('placement_id', $p->id)->get() as $doc) {
                            app(PlacementService::class)->reviewDocument($kordik, $p->ulid, ['code' => $doc->code, 'status' => 'exception',
                                'reason' => self::REASON.' Pengecualian dokumen khusus data latihan tanpa unggahan.']);
                        }
                        $this->transition($admin, $p, 'verify');
                    }
                    // Only the newly created dummy identities are linked; existing accounts are never adopted.
                    DB::table('participants')->where('id', $participant->id)->update(['user_id' => $owner->id, 'updated_at' => now()]);
                    app(AuditLogger::class)->log('demo.participant_linked', 'participant', $participant->id, reason: self::REASON, newValues: ['user_id' => $owner->id]);
                    if ($key !== 'penerimaan') {
                        Auth::setUser($admin);
                        $a = app(EducatorAssignmentService::class)->request($admin, $p->ulid, ['educator_id' => $educator, 'role' => 'mentor',
                            'start_date' => $p->start_date, 'end_date' => $p->end_date, 'reason' => self::REASON]);
                        if ($key !== 'penugasan') {
                            Auth::setUser($chief);
                            app(EducatorAssignmentService::class)->decide($chief, $a->ulid, ['action' => 'approve', 'revision' => 1, 'reason' => self::REASON]);
                            $dates = $ended ? [today()->subDays(3)->toDateString(), today()->subDays(2)->toDateString()] : [today()->toDateString(), today()->addDay()->toDateString()];
                            foreach ($dates as $index => $date) {
                                $recordAttendance = $key !== 'presensi' && $date <= today()->toDateString();
                                // Replay past dummy activities within their assignment dates; restore the clock afterwards.
                                Carbon::withTestNow($ended ? $date.' 12:00:00' : now(), function () use ($owner, $p, $date, $location, $a, $mentor, $recordAttendance, $index, $ended) {
                                    Auth::setUser($owner);
                                    $s = app(ScheduleService::class)->save($owner, $p->ulid, ['date' => $date, 'start_time' => '08:00', 'end_time' => '10:00',
                                        'activity' => 'DUMMY — Diskusi dan praktik pendidikan', 'clinical_location_id' => $location, 'mentor_assignment_id' => $a->id, 'revision' => 0, 'change_kind' => 'schedule']);
                                    app(ScheduleService::class)->transition($owner, $s->ulid, ['action' => 'submit', 'revision' => 1]);
                                    Auth::setUser($mentor);
                                    app(ScheduleService::class)->transition($mentor, $s->ulid, ['action' => 'approve', 'revision' => 2]);
                                    Auth::setUser($owner);
                                    app(ScheduleService::class)->transition($owner, $s->ulid, ['action' => 'publish', 'revision' => 3]);
                                    if ($recordAttendance) {
                                        app(AttendanceService::class)->save($owner, $p->ulid, ['date' => $date, 'clinical_location_id' => $location, 'mentor_assignment_id' => $a->id,
                                            'attendance_status' => $index === 0 ? 'hadir' : 'izin', 'activity' => 'DUMMY — Latihan pencatatan kegiatan tanpa identitas pasien.', 'revision' => 0, 'action' => 'submit']);
                                        if ($ended) {
                                            Auth::setUser($mentor);
                                            $r = DB::table('attendances')->where('placement_id', $p->id)->where('date', $date)->first();
                                            app(AttendanceService::class)->decide($mentor, $r->ulid, ['action' => 'verify', 'revision' => 1, 'reason' => self::REASON]);
                                        }
                                    }
                                });
                            }
                            Auth::setUser($admin);
                            if ($ended) {
                                app(AttendanceService::class)->summary($admin, $p->ulid, ['action' => 'generate', 'version' => 0]);
                                if ($key === 'terkunci') {
                                    Auth::setUser($chief);
                                    app(AttendanceService::class)->summary($chief, $p->ulid, ['action' => 'approve', 'version' => 1]);
                                }
                            } else {
                                $current = DB::table('placements')->find($p->id);
                                // The first attendance already starts a due placement; only the untouched one still needs the Admin.
                                if ($current->status === 'dijadwalkan') {
                                    app(PlacementExtensionService::class)->start($admin, $p->ulid, $current->revision);
                                }
                            }
                        }
                    }
                    $guide[] = '| DUMMY '.$label.' | '.$owner->email.' | '.$p->start_date.' s.d. '.$p->end_date.' |';
                }
                Auth::setUser($admin);
                app(AuditLogger::class)->log('demo.created', 'demo', null, reason: self::REASON, newValues: ['participants' => 6, 'accounts' => count($this->accounts)]);
                $text = "# SIKORDIK — Akun dan latihan DUMMY (PRIVAT)\n\nDibuat ".now()." WITA. Hanya untuk komputer lokal. Jangan unggah file ini ke Git atau membagikannya.\n\n";
                $text .= "Buka alamat aplikasi yang biasa dipakai. Jika memakai artisan serve: http://127.0.0.1:8000/login\n\n| Akun | Email | Kata sandi |\n|---|---|---|\n".implode("\n", $this->accounts);
                $text .= "\n\n## Skenario\n\n| Peserta | Akun peserta | Periode |\n|---|---|---|\n".implode("\n", $guide);
                $text .= "\n\n## Mulai mencoba\n\n1. Login presensi@demo.sikordik.test → Presensi → DUMMY 03 → Isi presensi harian → pilih tanggal yang tersedia → Ajukan ke pembimbing.\n2. Keluar, login pembimbing@demo.sikordik.test → Presensi → DUMMY 03 atau DUMMY 04 → isi catatan minimal 10 karakter → Verifikasi.\n3. Keluar, login ketua@demo.sikordik.test → Presensi → DUMMY 05 → buka laporan → Sahkan sebagai Ketua KSM & kunci.\n4. Login admin@demo.sikordik.test → Presensi → DUMMY 06 → Koreksi administratif → isi alasan → minta verifikasi ulang.\n5. Untuk penerimaan: login ketua → Penerimaan & penempatan → DUMMY 01 → terima KSM. Lanjut login tim untuk persetujuan Kordik, lalu admin untuk dokumen.\n6. Untuk penugasan: login ketua → Penugasan & jadwal → DUMMY 02 → setujui Penugasan pendidik. Lanjut login penugasan@demo.sikordik.test untuk membuat jadwal.\n\nAkun peserta latihan sudah ditautkan oleh command lokal sehingga tidak perlu email reset. Akun peserta DUMMY 01 disiapkan untuk latihan saja walaupun penerimaannya belum selesai; alur aktivasi operasional tetap mensyaratkan verifikasi.\n\nDokumen DUMMY 02–06 memakai pengecualian Tim Kordik yang tercatat. Tidak ada unggahan yang dipalsukan menjadi bersih. DUMMY 01 sengaja belum diberi pengecualian untuk mencoba alur dokumen.\n\nTanggal mengikuti hari paket pertama kali dibuat, tidak bergeser saat command diulang. Data latihan yang telah kamu ubah tidak direset.\n";
                if (! Storage::disk('local')->put(self::HANDOFF, $text)) {
                    throw new \RuntimeException('Gagal menyimpan panduan kredensial; pembuatan database dibatalkan.');
                }
                $this->info('Selesai: 11 akun, 6 peserta/penempatan, 8 jadwal, 5 presensi, dan 2 rekap DUMMY.');
                $this->info('Akun dan panduan privat: '.Storage::disk('local')->path(self::HANDOFF));

                return self::SUCCESS;
            });

            return $status === self::SUCCESS ? $this->advanced() : $status;
        } finally {
            Auth::forgetUser();
        }
    }

    /**
     * Second package on top of the basic one: three placements dated around today so the
     * simplified flow can be practised end to end (schedule a period, close a stase, read a finished one).
     * It runs in its own transaction; if it cannot be built the basic package stays as it is.
     */
    private function advanced(): int
    {
        $this->accounts = [];
        try {
            return DB::transaction(function () {
                $roles = DB::table('roles')->orderBy('id')->lockForUpdate()->pluck('id', 'code');
                if (DB::table('users')->where('email', 'jadwal@demo.sikordik.test')->lockForUpdate()->exists()) {
                    return self::SUCCESS;
                }
                $staff = [];
                foreach (['admin' => 'admin-kordik', 'tim' => 'tim-kordik', 'ketua' => 'ketua-ksm', 'pembimbing' => 'pembimbing'] as $key => $role) {
                    $staff[$key] = User::where('email', $key.'@demo.sikordik.test')->where('is_active', true)->first();
                    if (! $staff[$key] || ! in_array($role, $staff[$key]->roleCodes(), true)) {
                        $this->warn('Paket dasar DUMMY tidak lengkap; paket lanjutan dilewati.');

                        return self::SUCCESS;
                    }
                }
                $base = ['department' => DB::table('departments')->where('code', 'DEMO-KSM')->value('id'), 'institution' => DB::table('institutions')->where('code', 'DEMO-INSTITUSI')->value('id'),
                    'program' => DB::table('study_programs')->where('code', 'DEMO-PROGRAM')->value('id'), 'location' => DB::table('clinical_locations')->where('code', 'DEMO-UNIT')->value('id'),
                    'type' => DB::table('participant_types')->where('code', 'KOAS')->value('id')];
                $base['educator'] = DB::table('educators')->where('user_id', $staff['pembimbing']->id)->where('department_id', $base['department'])->value('id');
                $base['letter'] = DB::table('incoming_letters')->where('institution_id', $base['institution'])->where('number', 'like', 'DUMMY/%')->orderBy('id')->value('ulid');
                if (in_array(null, $base, true)) {
                    $this->warn('Data induk paket dasar DUMMY tidak ditemukan; paket lanjutan dilewati.');

                    return self::SUCCESS;
                }
                $guide = [];
                foreach (['jadwal' => '07 — Susun Jadwal', 'tutup' => '08 — Siap Ditutup', 'selesai' => '09 — Selesai'] as $key => $label) {
                    $ended = $key !== 'jadwal';
                    $start = today()->subDays($ended ? 14 : 2)->toDateString();
                    $end = today()->addDays($ended ? -1 : 14)->toDateString();
                    $owner = $this->account($key, 'DUMMY '.$label, $roles['peserta']);
                    $p = $this->admit($staff, $base, $owner, $key, $label, $start, $end);
                    // Activities are replayed on the last day of the period, when the assignment is still in force.
                    Carbon::withTestNow($ended ? $end.' 12:00:00' : now(), function () use ($staff, $base, $owner, $p, $ended, $start) {
                        Auth::setUser($staff['admin']);
                        $a = app(EducatorAssignmentService::class)->request($staff['admin'], $p->ulid, ['educator_id' => $base['educator'], 'role' => 'mentor', 'start_date' => $p->start_date, 'end_date' => $p->end_date]);
                        Auth::setUser($staff['ketua']);
                        app(EducatorAssignmentService::class)->decide($staff['ketua'], $a->ulid, ['action' => 'approve', 'revision' => 1]);
                        if ($ended) {
                            $this->stase($staff, $base, $owner, $p, $a, [$start, Carbon::parse($start)->addDay()->toDateString()]);
                        }
                    });
                    if ($ended) {
                        Auth::setUser($staff['admin']);
                        app(AttendanceService::class)->summary($staff['admin'], $p->ulid, ['action' => 'generate', 'version' => 0]);
                        Auth::setUser($staff['ketua']);
                        app(AttendanceService::class)->summary($staff['ketua'], $p->ulid, ['action' => 'approve', 'version' => 1]);
                    }
                    if ($key === 'selesai') {
                        $current = DB::table('placements')->find($p->id);
                        Auth::setUser($staff['admin']);
                        app(CompletionService::class)->act($staff['admin'], $p->ulid, ['action' => 'submit', 'revision' => $current->revision, 'confirm' => 1]);
                        $request = DB::table('completion_requests')->where('placement_id', $p->id)->first();
                        Auth::setUser($staff['tim']);
                        app(CompletionService::class)->act($staff['tim'], $p->ulid, ['action' => 'approve', 'revision' => DB::table('placements')->where('id', $p->id)->value('revision'),
                            'request_id' => $request->id, 'request_revision' => $request->revision, 'confirm' => 1]);
                    }
                    $guide[] = '| DUMMY '.$label.' | '.$owner->email.' | '.$start.' s.d. '.$end.' |';
                }
                Auth::setUser($staff['admin']);
                app(AuditLogger::class)->log('demo.advanced_created', 'demo', null, reason: self::REASON, newValues: ['participants' => 3, 'accounts' => count($this->accounts)]);
                $text = "\n\n## Paket lanjutan (dibuat ".now()." WITA)\n\n| Akun | Email | Kata sandi |\n|---|---|---|\n".implode("\n", $this->accounts);
                $text .= "\n\n| Peserta | Akun peserta | Periode |\n|---|---|---|\n".implode("\n", $guide);
                $text .= "\n\n1. DUMMY 07: login jadwal@demo.sikordik.test → Stase saya → Pembimbing & jadwal → Susun jadwal satu periode → Simpan & ajukan. Login pembimbing → Beranda → Setujui semua. Login jadwal lagi → Presensi → Hadir.";
                $text .= "\n2. DUMMY 08: login admin@demo.sikordik.test → Beranda → Periksa kelengkapan & ajukan penyelesaian. Login tim@demo.sikordik.test → Setujui penyelesaian.";
                $text .= "\n3. DUMMY 09: sudah selesai dan terkunci; login selesai@demo.sikordik.test untuk melihat nilai, logbook, dan pengesahan.";
                $text .= "\n\nTautan survei pada paket ini adalah contoh, bukan Google Form nyata. Ganti melalui Pengaturan → Tautan survei sebelum berlatih mengisi survei.\n";
                if (! Storage::disk('local')->put(self::HANDOFF, (Storage::disk('local')->get(self::HANDOFF) ?? '').$text)) {
                    throw new \RuntimeException('Gagal menyimpan panduan kredensial paket lanjutan.');
                }
                $this->info('Paket lanjutan: 3 akun peserta, 3 penempatan (susun jadwal, siap ditutup, selesai).');

                return self::SUCCESS;
            });
        } catch (HttpException|ValidationException|\RuntimeException $e) {
            $this->warn('Paket lanjutan tidak dibuat: '.$e->getMessage());
            $this->warn('Biasanya karena pemeriksaan berkas belum tersedia. Pasang ClamAV/qpdf atau isi SIKORDIK_SCAN_BYPASS=true pada .env lokal, lalu jalankan ulang perintah ini.');

            return self::SUCCESS;
        }
    }

    /** Accepted, document-complete placement with a linked participant account. */
    private function admit(array $staff, array $base, User $owner, string $key, string $label, string $start, string $end): object
    {
        Auth::setUser($staff['admin']);
        $participant = app(ParticipantService::class)->create($staff['admin'], ['name' => 'DUMMY '.$label, 'email' => $owner->email,
            'nim' => 'DEMO-'.strtoupper($key), 'birth_date' => '2000-01-01', 'institution_id' => $base['institution']]);
        $p = app(PlacementService::class)->create($staff['admin'], ['participant_ulid' => $participant->ulid, 'letter_ulid' => $base['letter'], 'study_program_id' => $base['program'],
            'participant_type_id' => $base['type'], 'department_id' => $base['department'], 'start_date' => $start, 'end_date' => $end]);
        $this->transition($staff['admin'], $p, 'submit');
        $this->transition($staff['ketua'], $p, 'ksm_accept');
        $this->transition($staff['tim'], $p, 'kordik_accept');
        Auth::setUser($staff['tim']);
        foreach (DB::table('placement_documents')->where('placement_id', $p->id)->get() as $doc) {
            app(PlacementService::class)->reviewDocument($staff['tim'], $p->ulid, ['code' => $doc->code, 'status' => 'exception', 'reason' => self::REASON.' Pengecualian dokumen khusus data latihan tanpa unggahan.']);
        }
        $this->transition($staff['admin'], $p, 'verify');
        DB::table('participants')->where('id', $participant->id)->update(['user_id' => $owner->id, 'updated_at' => now()]);
        app(AuditLogger::class)->log('demo.participant_linked', 'participant', $participant->id, reason: self::REASON, newValues: ['user_id' => $owner->id]);

        return DB::table('placements')->find($p->id);
    }

    /** Everything a participant and mentor do during a stase, through the same services the screens use. */
    private function stase(array $staff, array $base, User $owner, object $p, object $a, array $dates): void
    {
        $mentor = $staff['pembimbing'];
        Auth::setUser($owner);
        foreach ($dates as $date) {
            $s = app(ScheduleService::class)->save($owner, $p->ulid, ['date' => $date, 'start_time' => '08:00', 'end_time' => '10:00', 'activity' => 'DUMMY — Diskusi dan praktik pendidikan',
                'clinical_location_id' => $base['location'], 'mentor_assignment_id' => $a->id, 'revision' => 0, 'change_kind' => 'schedule']);
            app(ScheduleService::class)->transition($owner, $s->ulid, ['action' => 'submit', 'revision' => 1]);
        }
        Auth::setUser($mentor);
        app(ScheduleService::class)->bulk($mentor, $p->ulid, 'release');
        Auth::setUser($owner);
        foreach ($dates as $date) {
            app(AttendanceService::class)->quick($owner, $p->ulid, ['date' => $date]);
        }
        Auth::setUser($mentor);
        app(AttendanceService::class)->verifyMany($mentor, $p->ulid, ['ids' => DB::table('attendances')->where('placement_id', $p->id)->pluck('ulid')->all()]);

        Auth::setUser($owner);
        $path = tempnam(sys_get_temp_dir(), 'sikordik-dummy');
        file_put_contents($path, $this->pdf('DUMMY logbook latihan '.$p->ulid));
        try {
            $book = app(LogbookService::class)->save($owner, $p->ulid, ['kind' => 'participant', 'type' => 'DUMMY — Logbook institusi', 'revision' => 0, 'deidentified' => 1,
                'reviewer_assignment_id' => $a->id, 'notes' => 'Berkas contoh tanpa isi pendidikan nyata.'], new UploadedFile($path, 'logbook-dummy.pdf', 'application/pdf', null, true));
        } finally {
            @unlink($path);
        }
        app(LogbookService::class)->transition($owner, $book->ulid, ['action' => 'submit', 'revision' => 1, 'confirm' => 1]);
        Auth::setUser($mentor);
        app(LogbookService::class)->transition($mentor, $book->ulid, ['action' => 'approve', 'revision' => 2, 'confirm' => 1]);

        $template = DB::table('assessment_templates')->where('name', 'DUMMY — Penilaian akhir')->where('is_active', true)->value('id');
        if (! $template) {
            Auth::setUser($staff['admin']);
            $template = app(AssessmentTemplateService::class)->create($staff['admin'], ['name' => 'DUMMY — Penilaian akhir', 'exam_type' => 'Responsi', 'calculation' => 'none',
                'components' => [['name' => 'Skor akhir', 'input_type' => 'number', 'minimum' => 0, 'maximum' => 100, 'required' => 1]]]);
        }
        Auth::setUser($mentor);
        $grade = app(AssessmentService::class)->save($mentor, $p->ulid, ['template_id' => $template, 'title' => 'DUMMY — Responsi akhir', 'date' => $dates[0], 'mode' => 'dynamic', 'revision' => 0,
            'deidentified' => 1, 'author_assignment_id' => $a->id, 'mentor_assignment_id' => $a->id, 'scores' => [DB::table('assessment_components')->where('assessment_template_id', $template)->value('id') => 85]]);
        app(AssessmentService::class)->transition($mentor, $grade->ulid, ['action' => 'approve', 'revision' => 1, 'confirm' => 1]);
        app(AssessmentService::class)->transition($mentor, $grade->ulid, ['action' => 'publish', 'revision' => 2, 'confirm' => 1]);

        foreach (SurveyService::KINDS as $kind => $name) {
            if (! DB::table('survey_forms')->where('kind', $kind)->where('is_active', true)->exists()) {
                Auth::setUser($staff['admin']);
                app(SurveyService::class)->configure($staff['admin'], ['kind' => $kind, 'name' => 'DUMMY — '.$name.' (tautan contoh)', 'url' => 'https://forms.gle/ContohLatihanSikordik', 'confirm' => 1]);
            }
            foreach (['start' => $owner, 'submit' => $owner, 'verify' => $staff['admin']] as $action => $actor) {
                Auth::setUser($actor);
                app(SurveyService::class)->act($actor, $p->ulid, ['action' => $action, 'kind' => $kind, 'confirm' => 1,
                    'revision' => DB::table('survey_responses')->where('placement_id', $p->id)->where('kind', $kind)->value('revision') ?? 0]);
            }
        }
    }

    /** A small but structurally complete one-page PDF, so real inspectors accept it too. */
    private function pdf(string $text): string
    {
        $stream = 'BT /F1 14 Tf 72 720 Td ('.preg_replace('/[^A-Za-z0-9 ]/', '', $text).') Tj ET';
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    private function master(string $table, array $data): int
    {
        return DB::table($table)->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
    }

    private function account(string $key, string $name, int $role, ?int $department = null): User
    {
        $email = $key.'@demo.sikordik.test';
        $password = Str::password(24, symbols: false);
        $id = $this->master('users', ['name' => $name, 'email' => $email, 'password' => Hash::make($password), 'is_active' => true]);
        DB::table('user_roles')->insert(['user_id' => $id, 'role_id' => $role, 'assigned_at' => now()]);
        if ($department) {
            DB::table('user_scopes')->insert(['user_id' => $id, 'scope_type' => 'department', 'scope_id' => $department]);
        }
        $this->accounts[] = '| '.$name.' | '.$email.' | '.$password.' |';

        return User::findOrFail($id);
    }

    private function transition(User $actor, object $p, string $action): void
    {
        Auth::setUser($actor);
        $current = DB::table('placements')->find($p->id);
        app(PlacementService::class)->transition($actor, $p->ulid, $action, $current->status, $current->revision, self::REASON);
    }
}
