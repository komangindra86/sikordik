<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AttendanceService;
use App\Services\AuditLogger;
use App\Services\EducatorAssignmentService;
use App\Services\ParticipantService;
use App\Services\PlacementExtensionService;
use App\Services\PlacementService;
use App\Services\ScheduleService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SeedLocalDemo extends Command
{
    protected $signature = 'sikordik:seed-local-demo {--dry-run : Periksa lingkungan dan jumlah data tanpa menulis}';

    protected $description = 'Tambahkan satu paket latihan DUMMY fase 1–4 hanya di database lokal; tidak menimpa data';

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
            return DB::transaction(function () {
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
                    $this->warn('Paket DUMMY atau penanda sudah ada. Tidak ada data/password yang diubah.');
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
                                app(PlacementExtensionService::class)->start($admin, $p->ulid, $current->revision);
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
        } finally {
            Auth::forgetUser();
        }
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
