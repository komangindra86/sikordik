<?php

namespace Tests\Feature;

use App\Services\EducatorAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

/**
 * The daily routine in as few clicks as the rules allow: one form for the whole period,
 * one approval for all days, one tap per day of attendance, one verification for many days.
 */
class QuickFlowTest extends TestCase
{
    use RefreshDatabase, SchedulingFixtures;

    private array $f;

    private object $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = $this->schedulingFixture();
        $p = $this->f['p'];
        $this->a = app(EducatorAssignmentService::class)->request($this->f['admin'], $p->ulid, ['educator_id' => $this->f['educator'], 'role' => 'mentor', 'start_date' => $p->start_date, 'end_date' => $p->end_date]);
        app(EducatorAssignmentService::class)->decide($this->f['chief'], $this->a->ulid, ['action' => 'approve', 'revision' => 1]);
    }

    private function range(array $extra = []): array
    {
        return $extra + ['date_from' => '2026-10-01', 'date_to' => '2026-10-10', 'weekdays' => [1, 2, 3, 4, 5], 'activity' => 'Kegiatan stase',
            'clinical_location_id' => $this->f['location'], 'mentor_assignment_id' => $this->a->id, 'submit' => 1];
    }

    public function test_whole_period_is_scheduled_approved_and_attended_in_a_handful_of_clicks(): void
    {
        $f = $this->f;
        $ulid = $f['p']->ulid;
        $base = '/penjadwalan/penempatan/'.$ulid;

        $this->actingAs($f['owner'])->get($base.'/jadwal/rentang')->assertOk()->assertSee('Susun jadwal stase');
        $this->post($base.'/jadwal/rentang', $this->range())->assertRedirect()->assertSessionHasNoErrors();
        // 1–10 October 2026 holds seven weekdays; the weekend is left out.
        $this->assertSame(7, DB::table('schedules')->where('status', 'submitted')->count());
        $this->assertDatabaseMissing('schedules', ['date' => '2026-10-03']);
        $this->post($base.'/jadwal/rentang', $this->range())->assertRedirect()->assertSessionHas('status');
        $this->assertSame(7, DB::table('schedules')->count());

        $one = DB::table('schedules')->orderBy('date')->first();
        $this->post('/penjadwalan/jadwal/'.$one->ulid.'/setujui', ['revision' => $one->revision])->assertForbidden();
        $this->post($base.'/jadwal-massal', ['action' => 'release'])->assertStatus(422);

        $this->actingAs($f['mentor'])->get($base)->assertOk()->assertSee('Setujui semua');
        $this->post($base.'/jadwal-massal', ['action' => 'release'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(7, DB::table('schedules')->where('status', 'published')->where('approved_by', $f['mentor']->id)->count());
        $this->assertSame(7, DB::table('scheduling_histories')->where('event', 'schedule_publish')->where('actor_id', $f['mentor']->id)->count());
        // Published before the first day: scheduled, not yet running.
        $this->assertDatabaseHas('placements', ['id' => $f['p']->id, 'status' => 'dijadwalkan']);

        $this->travelTo(now()->setDate(2026, 10, 2)->setTime(9, 0));
        $this->actingAs($f['owner'])->get('/presensi/penempatan/'.$ulid)->assertOk()->assertSee('Hari ini')->assertSee('Hadir');
        $this->post('/presensi/penempatan/'.$ulid.'/cepat', ['date' => '2026-10-05'])->assertStatus(422);
        foreach (['2026-10-01', '2026-10-02'] as $date) {
            $this->post('/presensi/penempatan/'.$ulid.'/cepat', ['date' => $date])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertSame(2, DB::table('attendances')->where('status', 'waiting')->where('attendance_status', 'hadir')->where('mentor_assignment_id', $this->a->id)->where('activity', 'Kegiatan stase')->count());
        $this->post('/presensi/penempatan/'.$ulid.'/cepat', ['date' => '2026-10-02'])->assertStatus(422);
        // The first attendance on a scheduled day starts the placement; nobody has to press "Mulai stase".
        $this->assertDatabaseHas('placements', ['id' => $f['p']->id, 'status' => 'sedang_stase']);
        $this->assertDatabaseHas('placement_histories', ['placement_id' => $f['p']->id, 'action' => 'activity_started', 'actor_id' => $f['owner']->id]);

        $ids = DB::table('attendances')->pluck('ulid')->all();
        $this->post('/presensi/penempatan/'.$ulid.'/verifikasi-massal', ['ids' => $ids])->assertStatus(422);
        $this->actingAs($this->createUserWithRole('pembimbing'))->post('/presensi/penempatan/'.$ulid.'/verifikasi-massal', ['ids' => $ids])->assertNotFound();
        $this->actingAs($f['mentor'])->get('/presensi/penempatan/'.$ulid)->assertOk()->assertSee('Menunggu verifikasi Anda (2)');
        $this->post('/presensi/penempatan/'.$ulid.'/verifikasi-massal', ['ids' => $ids])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('attendances')->where('status', 'verified')->where('verified_by', $f['mentor']->id)->whereNotNull('approval')->count());
    }

    public function test_staff_must_explain_scheduling_for_a_participant_and_drafts_are_sent_in_one_step(): void
    {
        $f = $this->f;
        $base = '/penjadwalan/penempatan/'.$f['p']->ulid;

        $this->actingAs($f['admin'])->post($base.'/jadwal/rentang', $this->range())->assertStatus(422);
        $this->actingAs($f['owner'])->post($base.'/jadwal/rentang', $this->range(['submit' => 0, 'weekdays' => [4]]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('schedules')->where('status', 'draft')->count());
        $this->get($base)->assertOk()->assertSee('Ajukan semua ke pembimbing');
        $this->post($base.'/jadwal-massal', ['action' => 'submit'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('schedules')->where('status', 'submitted')->where('submitted_by', $f['owner']->id)->count());
        // A day outside the placement period rejects the whole request; nothing is half-created.
        $this->post($base.'/jadwal/rentang', $this->range(['date_to' => '2026-10-12']))->assertStatus(422);
        $this->assertSame(2, DB::table('schedules')->count());
    }

    public function test_placements_whose_first_day_arrives_start_from_the_daily_command(): void
    {
        $f = $this->f;
        $base = '/penjadwalan/penempatan/'.$f['p']->ulid;
        $this->actingAs($f['owner'])->post($base.'/jadwal/rentang', $this->range())->assertRedirect();
        $this->actingAs($f['mentor'])->post($base.'/jadwal-massal', ['action' => 'release'])->assertRedirect();

        $this->artisan('sikordik:start-placements')->expectsOutput('0 penempatan dimulai otomatis.');
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(0, 10));
        $this->artisan('sikordik:start-placements')->expectsOutput('1 penempatan dimulai otomatis.');
        $this->assertDatabaseHas('placements', ['id' => $f['p']->id, 'status' => 'sedang_stase']);
        $this->assertStringContainsString('otomatis', DB::table('placement_histories')->where('action', 'activity_started')->value('reason'));
    }
}
