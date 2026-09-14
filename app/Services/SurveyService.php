<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SurveyService
{
    public const KINDS = ['participant' => 'Kepuasan peserta', 'patient' => 'Satu respons survei pasien'];

    public function configure(User $u, array $input): void
    {
        abort_unless(app(AdmissionsAccess::class)->admin($u), 403);
        $d = Validator::make($input, ['kind' => 'required|in:participant,patient', 'name' => 'required|string|max:150', 'url' => 'required|url:https|max:500', 'confirm' => 'required|accepted'])->validate();
        // Only responder links; no arbitrary redirects, scripts, credentials, queries or editor URLs.
        abort_unless(preg_match('~^https://(?:forms\.gle/[A-Za-z0-9_-]+|docs\.google\.com/forms/d/e/[A-Za-z0-9_-]+/viewform)$~D', $d['url']), 422, 'Gunakan tautan respons Google Form (forms.gle atau docs.google.com/forms/d/e/.../viewform) tanpa parameter.');
        DB::transaction(function () use ($u, $d) {
            $id = DB::table('survey_forms')->insertGetId(['kind' => $d['kind'], 'name' => $d['name'], 'url' => $d['url'], 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
            app(SchedulingJournal::class)->record($u, 'survey_forms', $id, 'survey_form_created', null);
        });
    }

    public function disable(User $u, int $id): void
    {
        abort_unless(app(AdmissionsAccess::class)->admin($u), 403);
        DB::transaction(function () use ($u, $id) {
            $f = DB::table('survey_forms')->where('id', $id)->lockForUpdate()->firstOrFail();
            DB::table('survey_forms')->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]);
            app(SchedulingJournal::class)->record($u, 'survey_forms', $id, 'survey_form_disabled', $f);
        });
    }

    public function act(User $u, string $ulid, array $input): void
    {
        $d = Validator::make($input, ['kind' => 'required|in:participant,patient', 'action' => 'required|in:start,submit,verify,reject', 'revision' => 'required|integer|min:0', 'confirm' => 'required|accepted'])->validate();
        DB::transaction(function () use ($u, $ulid, $d) {
            $p = app(PlacementService::class)->locked($ulid);
            $a = app(AdmissionsAccess::class);
            $a->placement($u, $ulid);
            $owner = app(SchedulingAccess::class)->owner($u, $p);
            $review = in_array($d['action'], ['verify', 'reject']);
            abort_unless($review ? ($a->role($u, ['admin-kordik']) && ! $owner) : $owner, 403);
            abort_unless(in_array($p->status, ['sedang_stase', 'menunggu_penyelesaian']) && ! $p->archived_at, 422, 'Survei hanya dapat diubah pada penempatan berjalan.');
            $r = DB::table('survey_responses')->where('placement_id', $p->id)->where('kind', $d['kind'])->lockForUpdate()->first();
            abort_unless(($r->revision ?? 0) == $d['revision'], 422, 'Status survei berubah. Muat ulang.');
            if ($d['action'] === 'start') {
                abort_if($r, 422, 'Token survei sudah diterbitkan.');
                $form = DB::table('survey_forms')->where('kind', $d['kind'])->where('is_active', true)->orderByDesc('id')->lockForUpdate()->first();
                abort_unless($form, 422, 'Admin belum menyiapkan tautan survei aktif.');
                $id = DB::table('survey_responses')->insertGetId(['placement_id' => $p->id, 'survey_form_id' => $form->id, 'kind' => $d['kind'], 'token' => 'SV-'.Str::ulid(), 'created_at' => now(), 'updated_at' => now()]);
            } else {
                abort_unless($r && ($review ? $r->status === 'submitted' : in_array($r->status, ['issued', 'rejected'])), 422, 'Tindakan tidak tersedia pada status survei ini.');
                abort_if($review && (int) $r->submitted_by === (int) $u->id, 403);
                $changes = ['status' => match ($d['action']) {
                    'submit' => 'submitted', 'verify' => 'verified', 'reject' => 'rejected'
                }, 'revision' => $r->revision + 1, 'updated_at' => now()];
                $changes += $review ? ['verified_by' => $u->id, 'verified_at' => now()] : ['submitted_by' => $u->id, 'submitted_at' => now(), 'verified_by' => null, 'verified_at' => null];
                DB::table('survey_responses')->where('id', $r->id)->update($changes);
                $id = $r->id;
            }
            app(SchedulingJournal::class)->record($u, 'survey_responses', $id, 'survey_'.$d['action'], $r);
            $recipients = $review ? [DB::table('participants')->where('id', $p->participant_id)->value('user_id')] : app(SchedulingJournal::class)->roleUsers('admin-kordik');
            app(SchedulingJournal::class)->notify($recipients, $p, 'Status kewajiban survei diperbarui. Periksa menu Survei & penyelesaian.');
        }, 5);
    }
}
