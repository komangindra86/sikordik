<?php

namespace Tests\Feature;

use App\Services\EducatorAssignmentService;
use App\Services\ScheduleService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

class TaskListTest extends TestCase
{
    use RefreshDatabase, SchedulingFixtures;

    private function titles($user): array
    {
        return array_column(app(TaskService::class)->for($user), 'title');
    }

    public function test_each_role_sees_the_next_step_that_is_waiting_for_them(): void
    {
        $f = $this->schedulingFixture();
        $p = $f['p'];

        $this->assertContains('Ajukan pembimbing', $this->titles($f['admin']));
        $this->assertSame([], $this->titles($f['owner']));
        $this->assertSame([], $this->titles($f['mentor']));

        $a = app(EducatorAssignmentService::class)->request($f['admin'], $p->ulid, ['educator_id' => $f['educator'], 'role' => 'mentor', 'start_date' => $p->start_date, 'end_date' => $p->end_date, 'reason' => 'Penugasan pembimbing resmi']);
        $this->assertNotContains('Ajukan pembimbing', $this->titles($f['admin']));
        $this->assertContains('Setujui penugasan pendidik', $this->titles($f['chief']));

        app(EducatorAssignmentService::class)->decide($f['chief'], $a->ulid, ['action' => 'approve', 'revision' => 1, 'reason' => 'Persetujuan penugasan resmi']);
        $this->assertSame([], $this->titles($f['chief']));
        $this->assertSame(['Susun jadwal stase'], $this->titles($f['owner']));

        $s = app(ScheduleService::class)->save($f['owner'], $p->ulid, ['date' => '2026-10-02', 'activity' => 'Kegiatan pendidikan', 'clinical_location_id' => $f['location'], 'mentor_assignment_id' => $a->id, 'revision' => 0, 'change_kind' => 'schedule']);
        $this->assertSame(['Ajukan jadwal ke pembimbing'], $this->titles($f['owner']));

        app(ScheduleService::class)->transition($f['owner'], $s->ulid, ['action' => 'submit', 'revision' => 1]);
        $this->assertSame([], $this->titles($f['owner']));
        $this->assertSame(['Setujui jadwal peserta'], $this->titles($f['mentor']));

        $this->actingAs($f['mentor'])->get('/dashboard')->assertOk()->assertSee('Tugas saya')->assertSee('Setujui jadwal peserta')->assertSee('Peserta Pengujian');
    }

    public function test_tasks_never_leak_other_departments_or_other_participants(): void
    {
        $f = $this->schedulingFixture();
        $otherChief = $this->createUserWithRole('ketua-ksm');
        $otherParticipant = $this->createUserWithRole('peserta');
        $otherMentor = $this->createUserWithRole('pembimbing');
        app(EducatorAssignmentService::class)->request($f['admin'], $f['p']->ulid, ['educator_id' => $f['educator'], 'role' => 'mentor', 'start_date' => $f['p']->start_date, 'end_date' => $f['p']->end_date, 'reason' => 'Penugasan pembimbing resmi']);

        foreach ([$otherChief, $otherParticipant, $otherMentor] as $user) {
            $this->assertSame([], $this->titles($user));
            $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Tidak ada yang menunggu Anda saat ini.')->assertDontSee('Peserta Pengujian');
        }
    }

    public function test_menu_follows_the_role(): void
    {
        $f = $this->schedulingFixture();

        $this->actingAs($f['owner'])->get('/dashboard')->assertOk()
            ->assertSee('Stase saya')
            ->assertDontSee('Pengguna')->assertDontSee('Laporan Excel / PDF')->assertDontSee('Audit log')->assertDontSee('Ringkasan angka')->assertDontSee('Penerimaan peserta');
        $this->actingAs($f['mentor'])->get('/dashboard')->assertOk()
            ->assertSee('Peserta bimbingan')->assertSee('Laporan Excel / PDF')->assertDontSee('Penerimaan peserta')->assertDontSee('Pengguna');
        $this->actingAs($f['admin'])->get('/dashboard')->assertOk()
            ->assertSee('Penerimaan peserta')->assertSee('Pengguna')->assertSee('Ringkasan angka');
    }
}
