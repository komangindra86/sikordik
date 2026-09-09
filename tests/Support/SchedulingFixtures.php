<?php

namespace Tests\Support;

use App\Services\ParticipantService;
use App\Services\PlacementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait SchedulingFixtures
{
    protected function schedulingFixture(): array
    {
        $this->travelTo(now()->setDate(2026, 9, 9));
        $admin = $this->createUserWithRole('admin-kordik');
        $chief = $this->createUserWithRole('ketua-ksm');
        $mentor = $this->createUserWithRole('pembimbing');
        $owner = $this->createUserWithRole('peserta');
        $kordik = $this->createUserWithRole('tim-kordik');
        $department = DB::table('departments')->insertGetId(['code' => 'S', 'name' => 'KSM Pengujian']);
        $institution = DB::table('institutions')->insertGetId(['code' => 'S', 'name' => 'Institusi Pengujian']);
        $level = DB::table('education_levels')->insertGetId(['code' => 'S', 'name' => 'S1']);
        $program = DB::table('study_programs')->insertGetId(['institution_id' => $institution, 'education_level_id' => $level, 'code' => 'S', 'name' => 'Program Pengujian']);
        DB::table('user_scopes')->insert(['user_id' => $chief->id, 'scope_type' => 'department', 'scope_id' => $department]);
        $educator = DB::table('educators')->insertGetId(['user_id' => $mentor->id, 'department_id' => $department, 'name' => 'Pendidik Pengujian', 'can_mentor' => true, 'can_examine' => true, 'can_supervise' => false]);
        DB::table('educator_licenses')->insert(['educator_id' => $educator, 'license_type' => 'Otorisasi pendidikan', 'license_number' => 'TEST-001', 'issued_at' => '2026-01-01', 'expires_at' => '2027-12-31']);
        $location = DB::table('clinical_locations')->insertGetId(['department_id' => $department, 'code' => 'S', 'name' => 'Lokasi Pengujian']);
        $letter = (string) Str::ulid();
        DB::table('incoming_letters')->insert(['ulid' => $letter, 'institution_id' => $institution, 'number' => 'S', 'normalized_number' => 'S', 'letter_date' => '2026-09-01', 'year' => 2026, 'subject' => 'Pengujian', 'created_by' => $admin->id]);
        $participant = app(ParticipantService::class)->create($admin, ['name' => 'Peserta Pengujian', 'institution_id' => $institution]);
        DB::table('participants')->where('id', $participant->id)->update(['user_id' => $owner->id]);
        $p = app(PlacementService::class)->create($admin, ['participant_ulid' => $participant->ulid, 'letter_ulid' => $letter, 'study_program_id' => $program,
            'participant_type_id' => DB::table('participant_types')->value('id'), 'department_id' => $department, 'start_date' => '2026-10-01', 'end_date' => '2026-10-10']);
        DB::table('placements')->where('id', $p->id)->update(['status' => 'terverifikasi', 'document_status' => 'verified', 'ksm_status' => 'accepted', 'kordik_status' => 'accepted']);
        DB::table('placement_documents')->where('placement_id', $p->id)->update(['status' => 'exception', 'reason' => 'Pengecualian fixture pengujian', 'reviewed_by' => $kordik->id]);
        $p = DB::table('placements')->find($p->id);

        return compact('admin', 'chief', 'mentor', 'owner', 'kordik', 'department', 'institution', 'educator', 'location', 'participant', 'p');
    }
}
