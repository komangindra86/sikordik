<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CompletionService
{
    private function rows(string $table, object $p)
    {
        return DB::table($table)->where('placement_id', $p->id)->orderBy('id')->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->get();
    }

    public function checklist(object $p): array
    {
        $checks = [];
        $evidence = ['placement' => [$p->id, $p->participant_id, $p->institution_id, $p->department_id, $p->start_date, $p->end_date, $p->revision]];
        $checks['period'] = ['label' => 'Seluruh tanggal stase telah berakhir', 'ok' => $p->end_date < now()->toDateString()];
        $docs = $this->rows('placement_documents', $p);
        $docOk = $docs->isNotEmpty();
        foreach ($docs as $doc) {
            $evidence['documents'][] = (array) $doc;
            if ($doc->status === 'exception' && $doc->reviewed_by && $doc->reason) {
                continue;
            }
            $file = DB::table('private_files')->where('id', $doc->private_file_id)->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first();
            $disk = Storage::disk('local');
            $resource = $doc->code === 'surat' ? 'letter' : 'placement';
            $docOk = $docOk && $doc->status === 'valid' && $doc->reviewed_by && (! $doc->valid_until || $doc->valid_until >= $p->end_date)
                && $file && $file->resource_type === $resource && (int) $file->resource_id === (int) ($resource === 'letter' ? $p->incoming_letter_id : $p->id)
                && $file->scan_status === 'clean' && $disk->exists($file->path) && hash_equals($file->sha256, hash_file('sha256', $disk->path($file->path)));
            $evidence['files'][] = $file ? [$file->id, $file->sha256, $file->scan_status] : null;
        }
        $checks['documents'] = ['label' => 'Dokumen wajib terverifikasi dan berlaku selama stase / pengecualian resmi', 'ok' => (bool) $docOk];
        $attendance = app(AttendanceService::class)->snapshot($p);
        $summary = $this->rows('attendance_summaries', $p)->last();
        $checks['attendance'] = ['label' => 'Semua hari pendidikan direkap dan disahkan Ketua KSM', 'ok' => $summary && $summary->status === 'sealed' && $summary->approved_by && $summary->approved_at
            && count($attendance['days']) > 0 && ! $attendance['missing'] && ! $attendance['pending']
            && ! array_diff(array_column($attendance['rows'], 'date'), $attendance['days'])
            && hash_equals($summary->fingerprint, hash('sha256', json_encode($attendance, JSON_THROW_ON_ERROR)))];
        $evidence['attendance'] = [$summary?->id, $summary?->fingerprint, app(AssessmentService::class)->fingerprint($attendance)];
        $books = $this->rows('logbooks', $p);
        $bookOk = $books->where('kind', 'participant')->isNotEmpty();
        foreach ($books as $b) {
            $v = DB::table('logbook_versions')->where('logbook_id', $b->id)->where('version', $b->current_version)->first();
            $ok = $b->status === 'approved' && $v && DB::table('logbook_reviews')->where('logbook_id', $b->id)->where('version', $b->current_version)->where('action', 'approve')->whereNotNull('approval')->exists();
            if ($ok) {
                try {
                    app(LogbookService::class)->cleanFile($v);
                } catch (HttpException) {
                    $ok = false;
                }
            }
            $bookOk = $bookOk && $ok;
            $evidence['logbooks'][] = [$b->id, $b->status, $b->revision, $b->current_version, $v?->sha256];
        }
        $checks['logbooks'] = ['label' => 'Logbook peserta disetujui dan semua logbook yang tercatat tuntas', 'ok' => $bookOk];
        $grades = $this->rows('assessments', $p);
        $gradeOk = $grades->isNotEmpty();
        foreach ($grades as $g) {
            $v = DB::table('assessment_versions')->where('assessment_id', $g->id)->where('version', $g->current_version)->first();
            $ok = $g->status === 'published' && $g->published_version == $g->current_version && $v
                && DB::table('assessment_events')->where('assessment_id', $g->id)->where('version', $g->current_version)->where('action', 'publish')->whereNotNull('approval')->exists();
            if ($ok) {
                try {
                    app(AssessmentService::class)->integrity($v);
                } catch (HttpException) {
                    $ok = false;
                }
            }
            $gradeOk = $gradeOk && $ok;
            $evidence['grades'][] = [$g->id, $g->status, $g->revision, $g->current_version, $g->published_version, $v?->sha256];
        }
        $checks['grades'] = ['label' => 'Seluruh penilaian telah dipublikasikan', 'ok' => $gradeOk];
        $appeals = DB::table('grade_appeals')->whereIn('assessment_id', $grades->pluck('id'))->orderBy('id')->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->get();
        $checks['appeals'] = ['label' => 'Tidak ada keberatan atau koreksi nilai tertunda', 'ok' => $appeals->every(fn ($r) => in_array($r->status, ['rejected', 'completed']))];
        $evidence['appeals'] = $appeals->map(fn ($r) => [$r->id, $r->status, $r->corrected_version])->all();
        $surveys = $this->rows('survey_responses', $p);
        foreach (SurveyService::KINDS as $kind => $label) {
            $r = $surveys->firstWhere('kind', $kind);
            $checks[$kind] = ['label' => $label.' terverifikasi', 'ok' => $r && $r->status === 'verified' && $r->verified_by && $r->submitted_by && $r->verified_by != $r->submitted_by];
        }
        $evidence['surveys'] = $surveys->map(fn ($r) => [$r->id, $r->status, $r->revision, $r->verified_by])->all();
        $pending = false;
        foreach (['placement_extensions' => ['pending_ksm', 'pending_kordik'], 'educator_assignments' => ['pending'], 'schedules' => ['draft', 'revision', 'submitted', 'approved'], 'overlap_exceptions' => ['pending']] as $table => $states) {
            $rows = $this->rows($table, $p);
            $pending = $pending || $rows->whereIn('status', $states)->isNotEmpty();
            $evidence[$table] = $rows->map(fn ($r) => [$r->id, $r->status, $r->revision ?? null, $r->updated_at ?? null])->all();
        }
        $checks['obligations'] = ['label' => 'Tidak ada penugasan, jadwal draft, perpanjangan, atau pengecualian tertunda', 'ok' => ! $pending];

        return ['checks' => $checks, 'ready' => collect($checks)->every(fn ($c) => (bool) $c['ok']), 'evidence' => $evidence];
    }

    public function act(User $u, string $ulid, array $input): void
    {
        $d = Validator::make($input, ['action' => 'required|in:submit,approve,reject,withdraw,reopen_request,reopen_execute,archive', 'revision' => 'required|integer|min:1', 'request_id' => 'nullable|integer', 'request_revision' => 'nullable|integer|min:1', 'reason' => 'required|string|min:10|max:2000', 'confirm' => 'required|accepted'])->validate();
        abort_unless(mb_strlen(trim($d['reason'])) >= 10, 422, 'Alasan minimal 10 karakter.');
        DB::transaction(function () use ($u, $ulid, $d) {
            $service = app(PlacementService::class);
            $p = $service->locked($ulid);
            $access = app(AdmissionsAccess::class);
            $access->placement($u, $ulid);
            abort_if(DB::table('participants')->where('id', $p->participant_id)->where('user_id', $u->id)->exists(), 403, 'Peserta tidak dapat memeriksa atau menyetujui penempatannya sendiri.');
            $decision = in_array($d['action'], ['approve', 'reject']);
            abort_unless($access->role($u, [$decision ? 'tim-kordik' : 'admin-kordik']), 403);
            abort_unless($p->revision == $d['revision'] && ! $p->archived_at, 422, 'Penempatan berubah atau sudah diarsipkan. Muat ulang.');
            $before = clone $p;
            $pending = DB::table('completion_requests')->where('placement_id', $p->id)->whereIn('status', ['pending', 'approved'])->lockForUpdate()->first();
            $id = null;
            if (in_array($d['action'], ['submit', 'reopen_request'])) {
                abort_if($pending, 422, 'Permohonan masih aktif. Putuskan atau tarik terlebih dahulu.');
                $reopen = $d['action'] === 'reopen_request';
                abort_unless($reopen ? $p->status === 'selesai' : in_array($p->status, ['sedang_stase', 'menunggu_penyelesaian']), 422, 'Status penempatan tidak sesuai.');
                if (! $reopen) {
                    $p->status = 'menunggu_penyelesaian';
                    $p->completion_status = 'submitted';
                    $p->revision++;
                    $snapshot = $this->checklist($p);
                    abort_unless($snapshot['ready'], 422, 'Checklist belum lengkap. Selesaikan semua kewajiban dahulu.');
                } else {
                    $snapshot = $this->reopenSnapshot($p);
                }
                $id = DB::table('completion_requests')->insertGetId(['ulid' => (string) Str::ulid(), 'placement_id' => $p->id, 'kind' => $reopen ? 'reopen' : 'completion', 'requested_by' => $u->id,
                    'reason' => $d['reason'], 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'sha256' => app(AssessmentService::class)->fingerprint($snapshot), 'created_at' => now(), 'updated_at' => now()]);
            } elseif ($d['action'] === 'archive') {
                abort_unless($p->status === 'selesai' && $p->completed_at && ! $pending && Carbon::parse(max($p->completed_at, $p->actual_end_date ?? $p->end_date))->addYears(3)->lte(now()), 422, 'Arsip tersedia setelah minimal tiga tahun sejak penyelesaian terakhir, tanpa permohonan aktif.');
                $p->archived_at = now()->toDateTimeString();
                $p->revision++;
            } else {
                $r = $pending;
                abort_unless($r && $r->id == ($d['request_id'] ?? null) && $r->revision == ($d['request_revision'] ?? null), 422, 'Permohonan berubah. Muat ulang.');
                abort_unless($r->kind === 'completion' ? $p->status === 'menunggu_penyelesaian' : $p->status === 'selesai', 422, 'Status penempatan tidak sesuai permohonan.');
                $snapshot = json_decode($r->snapshot, true, flags: JSON_THROW_ON_ERROR);
                abort_unless(hash_equals($r->sha256, app(AssessmentService::class)->fingerprint($snapshot)), 423, 'Integritas permohonan berubah.');
                if ($decision) {
                    abort_if((int) $r->requested_by === (int) $u->id, 403, 'Tidak boleh memutus permohonan sendiri.');
                    abort_unless($r->status === 'pending', 422);
                }
                $changes = ['revision' => $r->revision + 1, 'updated_at' => now()];
                if ($d['action'] === 'approve') {
                    if ($r->kind === 'completion') {
                        $latest = $this->checklist($p);
                        abort_unless($latest['ready'] && hash_equals($r->sha256, app(AssessmentService::class)->fingerprint($latest)), 422, 'Kewajiban berubah sejak pemeriksaan Admin. Tarik/tolak dan ajukan pemeriksaan ulang.');
                        $p->status = 'selesai';
                        $p->completion_status = 'approved';
                        $p->actual_end_date = $p->end_date;
                        $p->completed_at = now()->toDateTimeString();
                        $service->checkOverlap($p);
                        $p->revision++;
                    }
                    $changes += ['status' => $r->kind === 'completion' ? 'completed' : 'approved', 'decided_by' => $u->id, 'decision_reason' => $d['reason'],
                        'approval' => json_encode(['number' => 'SL-'.Str::ulid(), 'user_id' => $u->id, 'name' => $u->name, 'job_title' => $u->job_title, 'role' => 'tim-kordik', 'at' => now()->toIso8601String(), 'sha256' => $r->sha256], JSON_THROW_ON_ERROR)];
                } elseif ($d['action'] === 'reopen_execute') {
                    abort_unless($r->kind === 'reopen' && $r->status === 'approved' && $r->decided_by && $r->decided_by != $u->id, 403, 'Eksekusi Admin memerlukan persetujuan terpisah Tim Kordik.');
                    abort_unless(hash_equals($r->sha256, app(AssessmentService::class)->fingerprint($this->reopenSnapshot($p))), 422, 'Penempatan berubah sejak pengajuan.');
                    $p->status = 'menunggu_penyelesaian';
                    $p->completion_status = 'pending';
                    $p->actual_end_date = null;
                    $p->completed_at = null;
                    $p->revision++;
                    $service->checkOverlap($p);
                    $changes += ['status' => 'executed', 'executed_by' => $u->id];
                } else {
                    abort_unless(in_array($d['action'], ['reject', 'withdraw']), 422);
                    $changes += ['status' => $d['action'] === 'reject' ? 'rejected' : 'withdrawn', 'decision_reason' => $d['reason'], 'decided_by' => $u->id];
                    if ($r->kind === 'completion') {
                        $p->completion_status = 'pending';
                        $p->status = 'sedang_stase';
                        $p->revision++;
                    }
                }
                DB::table('completion_requests')->where('id', $r->id)->update($changes);
                $id = $r->id;
                app(SchedulingJournal::class)->record($u, 'completion_requests', $id, 'completion_'.$d['action'], $r, $d['reason']);
            }
            DB::table('placements')->where('id', $p->id)->update(['status' => $p->status, 'completion_status' => $p->completion_status, 'revision' => $p->revision, 'actual_end_date' => $p->actual_end_date,
                'completed_at' => $p->completed_at, 'archived_at' => $p->archived_at, 'updated_at' => now()]);
            $service->history($u, DB::table('placements')->find($p->id), $before->status, 'completion_'.$d['action'], $d['reason']);
            if ($id && in_array($d['action'], ['submit', 'reopen_request'])) {
                app(SchedulingJournal::class)->record($u, 'completion_requests', $id, 'completion_'.$d['action'], null, $d['reason']);
            }
            $j = app(SchedulingJournal::class);
            $j->notify(array_merge($j->roleUsers($decision ? 'admin-kordik' : 'tim-kordik'), [DB::table('participants')->where('id', $p->participant_id)->value('user_id')]), $p, 'Status penyelesaian diperbarui. Periksa menu Survei & penyelesaian.');
        }, 5);
    }

    private function reopenSnapshot(object $p): array
    {
        $data = (array) $p;
        unset($data['updated_at']);

        return ['placement' => $data];
    }
}
