<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ParticipantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BatchAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $chief;

    private User $kordik;

    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 9));
        $this->admin = $this->createUserWithRole('admin-kordik');
        $this->chief = $this->createUserWithRole('ketua-ksm');
        $this->kordik = $this->createUserWithRole('tim-kordik');
        $department = DB::table('departments')->insertGetId(['code' => 'B', 'name' => 'KSM Bedah']);
        $institution = DB::table('institutions')->insertGetId(['code' => 'U', 'name' => 'Universitas Uji']);
        $level = DB::table('education_levels')->insertGetId(['code' => 'S1', 'name' => 'S1']);
        $program = DB::table('study_programs')->insertGetId(['institution_id' => $institution, 'education_level_id' => $level, 'code' => 'PD', 'name' => 'Profesi Dokter']);
        DB::table('user_scopes')->insert(['user_id' => $this->chief->id, 'scope_type' => 'department', 'scope_id' => $department]);
        $this->ids = compact('department', 'institution', 'program');
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['institution_id' => $this->ids['institution'], 'number' => '012/UU/IX/2026', 'letter_date' => '2026-09-01', 'subject' => 'Permohonan stase',
            'study_program_id' => $this->ids['program'], 'participant_type_id' => DB::table('participant_types')->where('code', 'KOAS')->value('id'), 'department_id' => $this->ids['department'],
            'start_date' => '2026-10-01', 'end_date' => '2026-10-28'];
    }

    public function test_one_letter_admits_new_and_returning_participants_together(): void
    {
        $old = app(ParticipantService::class)->create($this->admin, ['name' => 'Budi Lama', 'nim' => '2001', 'institution_id' => $this->ids['institution']]);
        $rows = [['name' => 'Ayu Baru', 'nim' => '2101'], ['name' => 'Budi Lama', 'nim' => '2001'], ['name' => '', 'nim' => '']];

        $this->actingAs($this->chief)->get('/penerimaan/rombongan')->assertForbidden();
        $this->actingAs($this->admin)->get('/penerimaan/rombongan')->assertOk()->assertSee('Terima peserta baru');
        $this->post('/penerimaan/rombongan/periksa', $this->payload(['rows' => $rows, 'pasted' => "Citra Tempel\t2102\t14-03-2001\tcitra@uji.test"]))->assertOk()
            ->assertSee('Mungkin peserta lama')->assertSee($old->number)->assertSee('Citra Tempel')->assertSee('14-03-2001')->assertSee('Simpan & ajukan 3 penempatan ke KSM', false);
        $this->assertDatabaseCount('incoming_letters', 0);
        $this->assertDatabaseCount('placements', 0);

        $commit = $this->payload(['submit' => 1, 'rows' => [['name' => 'Ayu Baru', 'nim' => '2101', 'use' => 'new'], ['name' => 'Budi Lama', 'nim' => '2001', 'use' => $old->ulid],
            ['name' => 'Citra Tempel', 'nim' => '2102', 'birth_date' => '2001-03-14', 'email' => 'citra@uji.test', 'use' => 'new']]]);
        $this->post('/penerimaan/rombongan', $commit)->assertRedirect('/stase')->assertSessionHasNoErrors();

        $this->assertDatabaseCount('incoming_letters', 1);
        $this->assertDatabaseCount('participants', 3);
        $this->assertSame(3, DB::table('placements')->where('status', 'menunggu_konfirmasi_ksm')->where('incoming_letter_id', DB::table('incoming_letters')->value('id'))->count());
        $this->assertDatabaseHas('placements', ['participant_id' => $old->id]);
        $this->assertDatabaseHas('participants', ['name' => 'Citra Tempel', 'birth_date' => '2001-03-14']);
        // Every placement got its own document checklist and history, exactly as when created one by one.
        $this->assertSame(9, DB::table('placement_documents')->count());
        $this->assertSame(3, DB::table('placement_histories')->where('action', 'submit')->where('actor_id', $this->admin->id)->count());

        // The same letter number cannot be entered as new twice; the existing letter is reused instead.
        $this->post('/penerimaan/rombongan', $this->payload(['rows' => [['name' => 'Dedi', 'use' => 'new']]]))->assertSessionHasErrors('normalized_number');
        $letter = DB::table('incoming_letters')->value('ulid');
        $this->post('/penerimaan/rombongan', ['letter_ulid' => $letter, 'rows' => [['name' => 'Dedi', 'use' => 'new']]] + $this->payload(['submit' => 0]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('incoming_letters', 1);
        $this->assertSame(1, DB::table('placements')->where('status', 'draft')->count());
    }

    public function test_a_problem_in_one_row_saves_nothing(): void
    {
        app(ParticipantService::class)->create($this->admin, ['name' => 'Budi Lama', 'nim' => '2001', 'institution_id' => $this->ids['institution']]);
        $rows = [['name' => 'Ayu Baru', 'use' => 'new'], ['name' => 'Budi Lama', 'nim' => '2001', 'use' => 'new']];

        $this->actingAs($this->admin)->post('/penerimaan/rombongan', $this->payload(['submit' => 1, 'rows' => $rows]))->assertSessionHasErrors('rows');
        $this->assertStringContainsString('Baris 2 (Budi Lama)', session('errors')->first('rows'));
        $this->assertDatabaseCount('incoming_letters', 0);
        $this->assertDatabaseCount('placements', 0);
        $this->assertDatabaseCount('participants', 1);

        $rows[1]['reason'] = 'Orang berbeda, NIM kebetulan sama di arsip lama';
        $this->post('/penerimaan/rombongan', $this->payload(['submit' => 1, 'rows' => $rows]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('placements', 2);
        $this->post('/penerimaan/rombongan/periksa', $this->payload(['number' => 'X', 'rows' => []]))->assertSessionHasErrors('rows');
    }

    public function test_ksm_and_kordik_accept_a_whole_group_at_once_within_their_own_authority(): void
    {
        $rows = [['name' => 'Ayu', 'use' => 'new'], ['name' => 'Bima', 'use' => 'new'], ['name' => 'Cici', 'use' => 'new']];
        $this->actingAs($this->admin)->post('/penerimaan/rombongan', $this->payload(['submit' => 1, 'rows' => $rows]))->assertRedirect();
        $ids = DB::table('placements')->orderBy('id')->pluck('ulid')->all();
        $otherChief = $this->createUserWithRole('ketua-ksm');

        $this->actingAs($otherChief)->get('/penerimaan/keputusan')->assertOk()->assertDontSee('Bima');
        $this->post('/penerimaan/keputusan', ['ids' => $ids])->assertSessionHasErrors('ids');
        $this->actingAs($this->kordik)->post('/penerimaan/keputusan', ['ids' => $ids])->assertSessionHasErrors('ids');
        $this->assertSame(3, DB::table('placements')->where('status', 'menunggu_konfirmasi_ksm')->count());

        $this->actingAs($this->chief)->get('/dashboard')->assertOk()->assertSee('Putuskan sekaligus');
        $this->get('/penerimaan/keputusan')->assertOk()->assertSee('Ayu')->assertSee('Cici')->assertSee('012/UU/IX/2026');
        $this->post('/penerimaan/keputusan', ['ids' => array_slice($ids, 0, 2)])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('placements')->where('status', 'menunggu_persetujuan_kordik')->where('ksm_status', 'accepted')->count());
        $this->assertSame(2, DB::table('placement_histories')->where('action', 'ksm_accept')->where('actor_id', $this->chief->id)->count());

        $this->actingAs($this->kordik)->post('/penerimaan/keputusan', ['ids' => $ids])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('placements')->where('status', 'menunggu_dokumen')->count());
        $this->assertSame(1, DB::table('placements')->where('status', 'menunggu_konfirmasi_ksm')->count());
    }
}
