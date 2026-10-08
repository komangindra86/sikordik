<?php

namespace Tests\Feature;

use App\Services\PlacementHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CompletionFixtures;
use Tests\TestCase;

class PlacementHubTest extends TestCase
{
    use CompletionFixtures, RefreshDatabase;

    public function test_one_page_shows_progress_and_only_the_sections_each_role_may_open(): void
    {
        $f = $this->completionFixture();
        $url = '/stase/'.$f['p']->ulid;
        $hub = app(PlacementHub::class);
        $p = DB::table('placements')->find($f['p']->id);

        $this->assertSame(['overview', 'documents', 'schedule', 'attendance', 'logbook', 'grades', 'completion'], array_keys($hub->tabs($f['owner'], $p)));
        // The mentor's assignment has ended, so the scheduling module no longer lists this placement for them.
        $this->assertSame(['overview', 'attendance', 'logbook', 'grades'], array_keys($hub->tabs($f['mentor'], $p)));

        $this->actingAs($f['owner'])->get($url)->assertOk()
            ->assertSee('Peserta Pengujian')->assertSee('Sedang stase')->assertSee('Langkah berikutnya')
            ->assertSee('Kelengkapan untuk menutup stase')->assertSee('1 / 1')->assertSee('2 / 2');
        $this->actingAs($f['mentor'])->get($url)->assertOk()
            ->assertSee('Presensi')->assertDontSee('Dokumen persyaratan')->assertDontSee('Kelengkapan untuk menutup stase');
        $this->actingAs($f['admin'])->get($url)->assertOk()->assertSee('Dokumen persyaratan');
    }

    public function test_placements_outside_the_users_access_stay_hidden(): void
    {
        $f = $this->completionFixture();
        $stranger = $this->createUserWithRole('peserta');
        $otherChief = $this->createUserWithRole('ketua-ksm');
        $otherMentor = $this->createUserWithRole('pembimbing');

        foreach ([$stranger, $otherChief, $otherMentor] as $user) {
            $this->actingAs($user)->get('/stase/'.$f['p']->ulid)->assertNotFound();
            $this->actingAs($user)->get('/stase')->assertOk()->assertDontSee('Peserta Pengujian');
        }
        $this->actingAs($f['owner'])->get('/stase')->assertOk()->assertSee('Peserta Pengujian')->assertSee('Berikutnya:');
        $this->actingAs($f['chief'])->get('/stase?q=Pengujian')->assertOk()->assertSee('Peserta Pengujian');
        $this->actingAs($f['chief'])->get('/stase?tampil=selesai')->assertOk()->assertDontSee('Peserta Pengujian');
    }

    public function test_module_pages_share_the_same_header_and_tabs(): void
    {
        $f = $this->completionFixture();
        $ulid = $f['p']->ulid;

        foreach (['/penerimaan/penempatan/', '/penjadwalan/penempatan/', '/presensi/penempatan/', '/logbook/penempatan/', '/penilaian/penempatan/', '/penyelesaian/'] as $path) {
            $this->actingAs($f['owner'])->get($path.$ulid)->assertOk()->assertSee('Langkah berikutnya')->assertSee('Semua penempatan')->assertSee('Ringkasan');
        }
    }
}
