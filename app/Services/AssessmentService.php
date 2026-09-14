<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AssessmentService
{
    public const LABELS = ['draft' => 'Draft', 'approved' => 'Disahkan', 'published' => 'Dipublikasikan', 'submitted' => 'Diajukan', 'reviewing' => 'Sedang ditinjau', 'accepted' => 'Diterima', 'rejected' => 'Ditolak', 'completed' => 'Selesai'];

    public function fingerprint(array $data): string
    {
        $canonical = function (array $value) use (&$canonical): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$v) {
                if (is_array($v)) {
                    $v = $canonical($v);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($canonical($data), JSON_THROW_ON_ERROR));
    }

    public function snapshot(object $v): array
    {
        $s = json_decode($v->snapshot, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(hash_equals($v->sha256, $this->fingerprint($s)), 423, 'Integritas nilai berubah.');

        return $s;
    }

    public function integrity(object $v): array
    {
        $s = $this->snapshot($v);
        app(AssessmentFileService::class)->clean($v->private_file_id, $s['file_hash']);

        return $s;
    }

    private function writable(object $p): void
    {
        abort_unless(in_array($p->status, ['dijadwalkan', 'sedang_stase', 'menunggu_penyelesaian']), 422, 'Penempatan belum berjalan atau telah dikunci.');
    }

    private function assignment(int $id, object $p, string $date, bool $mentor = false): object
    {
        $a = DB::table('educator_assignments')->where('id', $id)->where('placement_id', $p->id)->lockForUpdate()->firstOrFail();
        $e = DB::table('educators')->where('id', $a->educator_id)->lockForUpdate()->firstOrFail();
        $u = DB::table('users')->where('id', $a->educator_user_id)->whereNull('deleted_at')->lockForUpdate()->first();
        $role = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->where('ur.user_id', $a->educator_user_id)->where('r.code', 'pembimbing')->lockForUpdate()->first();
        abort_unless($u && $u->is_active && $role && $e->is_active && (int) $e->user_id === (int) $u->id && $a->status === 'approved' && in_array($a->role, ['mentor', 'examiner']) && (! $mentor || $a->role === 'mentor') &&
            $date >= $a->start_date && $date <= $a->end_date, 422, 'Penugasan tidak berlaku pada tanggal penilaian atau akun pendidik berubah.');

        return $a;
    }

    private function event(User $u, object $r, string $action, ?string $note = null, ?array $approval = null): void
    {
        DB::table('assessment_events')->insert(['assessment_id' => $r->id, 'version' => $r->current_version, 'action' => $action, 'note' => $note,
            'approval' => $approval ? json_encode($approval, JSON_THROW_ON_ERROR) : null, 'actor_id' => $u->id, 'created_at' => now()]);
        app(SchedulingJournal::class)->record($u, 'assessments', $r->id, 'assessment_'.$action, $r, $note);
    }

    public function save(User $u, string $placement, array $input, ?UploadedFile $upload = null, ?string $ulid = null): object
    {
        $d = Validator::make($input, ['template_id' => 'required|integer', 'title' => 'required|string|max:150', 'date' => 'required|date_format:Y-m-d',
            'mode' => 'required|in:dynamic,document', 'author_assignment_id' => 'required|integer', 'mentor_assignment_id' => 'required|integer',
            'revision' => 'required|integer|min:0', 'scores' => 'nullable|array|max:30', 'notes' => 'nullable|string|max:2000', 'deidentified' => 'required|accepted'])->validate();
        $file = null;
        $r = DB::transaction(function () use ($u, $placement, $d, $upload, $ulid, &$file) {
            $p = app(PlacementService::class)->locked($placement);
            $this->writable($p);
            $access = app(AssessmentAccess::class);
            abort_if(DB::table('participants')->where('id', $p->participant_id)->where('user_id', $u->id)->exists(), 403, 'Peserta tidak boleh menilai dirinya sendiri.');
            $old = $ulid ? DB::table('assessments')->where('ulid', $ulid)->where('placement_id', $p->id)->lockForUpdate()->firstOrFail() : null;
            $author = $this->assignment($d['author_assignment_id'], $p, $d['date']);
            $mentor = $this->assignment($d['mentor_assignment_id'], $p, $d['date'], true);
            abort_unless((int) $author->educator_user_id === (int) $u->id || ($old && $access->mentor($u, $old)), 403);
            abort_if($d['date'] < $p->start_date || $d['date'] > $p->end_date || $d['date'] > now()->toDateString(), 422, 'Tanggal harus sudah berlangsung dan berada dalam penempatan.');
            if ($old) {
                abort_unless(($access->author($u, $old) || $access->mentor($u, $old)) && (int) $old->revision === (int) $d['revision'], 422, 'Versi berubah atau penulis tidak berwenang.');
                foreach (['assessment_template_id' => 'template_id', 'title' => 'title', 'date' => 'date', 'mode' => 'mode', 'author_assignment_id' => 'author_assignment_id', 'mentor_assignment_id' => 'mentor_assignment_id'] as $column => $key) {
                    abort_unless((string) $old->$column === (string) $d[$key], 422, 'Identitas penilaian tidak dapat diubah.');
                }
                $appeal = DB::table('grade_appeals')->where('assessment_id', $old->id)->where('version', $old->published_version)->lockForUpdate()->first();
                abort_unless($old->status === 'draft' || ($old->status === 'published' && $appeal?->status === 'accepted'), 422, 'Nilai disahkan/dipublikasikan hanya dikoreksi melalui keberatan diterima.');
                if ($old->published_version) {
                    abort_unless($access->mentor($u, $old) && $appeal?->status === 'accepted', 403);
                    $this->snapshot(DB::table('assessment_versions')->where('assessment_id', $old->id)->where('version', $old->published_version)->lockForUpdate()->firstOrFail());
                }
                // Templates are immutable; inactive templates remain usable for existing corrections.
                $template = DB::table('assessment_templates')->where('id', $old->assessment_template_id)->lockForUpdate()->firstOrFail();
            } else {
                abort_unless((int) $d['revision'] === 0, 422);
                $template = app(AssessmentTemplateService::class)->available($p, $d['date'])->where('id', $d['template_id'])->lockForUpdate()->firstOrFail();
                abort_if(DB::table('assessments')->where('placement_id', $p->id)->where('assessment_template_id', $template->id)->where('title', $d['title'])->where('date', $d['date'])->lockForUpdate()->exists(), 422, 'Penilaian yang sama sudah ada.');
            }
            $components = DB::table('assessment_components')->where('assessment_template_id', $template->id)->orderBy('position')->get()->map(fn ($c) => (array) $c)->all();
            $result = $d['mode'] === 'dynamic' ? app(AssessmentTemplateService::class)->scores($template, $components, $d['scores'] ?? []) : ['scores' => [], 'total' => null, 'passed' => null];
            abort_if($d['mode'] === 'document' && ! $upload, 422, 'Unggah formulir institusi yang sudah diisi dan ditandatangani untuk setiap versi.');
            $version = ($old->current_version ?? 0) + 1;
            $values = ['status' => 'draft', 'current_version' => $version, 'revision' => ($old->revision ?? 0) + 1, 'updated_at' => now()];
            $id = $old?->id;
            if ($old) {
                DB::table('assessments')->where('id', $id)->update($values);
            } else {
                $id = DB::table('assessments')->insertGetId($values + ['ulid' => (string) Str::ulid(), 'placement_id' => $p->id, 'assessment_template_id' => $template->id,
                    'title' => $d['title'], 'date' => $d['date'], 'mode' => $d['mode'], 'author_assignment_id' => $author->id, 'mentor_assignment_id' => $d['mentor_assignment_id'], 'created_at' => now()]);
            }
            $file = $upload ? app(AssessmentFileService::class)->upload($u, $id, 'assessment', $version, $upload) : null;
            $snapshot = $result + ['template' => (array) $template, 'components' => $components, 'placement' => json_decode($p->snapshot, true),
                'participant' => DB::table('participants')->where('id', $p->participant_id)->value('name'), 'title' => $d['title'], 'date' => $d['date'],
                'author' => $u->name, 'notes' => $d['notes'] ?? null, 'file_hash' => $file?->sha256];
            DB::table('assessment_versions')->insert(['assessment_id' => $id, 'version' => $version, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'sha256' => $this->fingerprint($snapshot), 'private_file_id' => $file?->id, 'created_by' => $u->id, 'created_at' => now()]);
            $row = DB::table('assessments')->find($id);
            $this->event($u, $row, 'saved');
            if ((int) $mentor->educator_user_id !== (int) $u->id) {
                app(SchedulingJournal::class)->notify([$mentor->educator_user_id], $p, 'Penilaian '.$row->title.' menunggu pemeriksaan pembimbing.');
            }

            return $row;
        }, 5);
        if ($file) {
            app(PrivateFileService::class)->scan($file);
        }

        return $r;
    }

    public function transition(User $u, string $ulid, array $input, ?UploadedFile $upload = null): void
    {
        $d = Validator::make($input, ['action' => 'required|in:approve,publish,appeal,review,accept,reject', 'revision' => 'required|integer|min:1',
            'note' => 'required|string|min:5|max:2000', 'confirm' => 'required|accepted', 'deidentified' => 'exclude_unless:action,appeal|required|accepted'])->validate();
        $file = null;
        DB::transaction(function () use ($u, $ulid, $d, $upload, &$file) {
            $ref = DB::table('assessments')->where('ulid', $ulid)->firstOrFail();
            $p = app(PlacementService::class)->locked(DB::table('placements')->where('id', $ref->placement_id)->value('ulid'));
            $r = DB::table('assessments')->where('id', $ref->id)->lockForUpdate()->firstOrFail();
            $this->writable($p);
            $access = app(AssessmentAccess::class);
            $isAppeal = $d['action'] === 'appeal';
            abort_unless($isAppeal ? $access->owner($u, $p) : (! $access->owner($u, $p) && $access->mentor($u, $r)), 403);
            abort_unless((int) $r->revision === (int) $d['revision'], 422, 'Status atau versi berubah. Muat ulang.');
            $mentor = $this->assignment($r->mentor_assignment_id, $p, $r->date, true);
            $v = DB::table('assessment_versions')->where('assessment_id', $r->id)->where('version', $r->current_version)->lockForUpdate()->firstOrFail();
            $approval = null;
            $update = ['revision' => $r->revision + 1, 'updated_at' => now()];
            if (in_array($d['action'], ['approve', 'publish'])) {
                abort_unless($r->status === ($d['action'] === 'approve' ? 'draft' : 'approved'), 422, 'Urutan pengesahan dan publikasi tidak sesuai.');
                $s = $this->integrity($v);
                $this->assignment($r->author_assignment_id, $p, $r->date);
                $approval = ['document_number' => 'NL-'.Str::ulid(), 'user_id' => $u->id, 'name' => $u->name, 'role' => 'pembimbing', 'position' => 'Pembimbing penempatan',
                    'assignment_id' => $mentor->id, 'at' => now()->toIso8601String(), 'version' => $r->current_version, 'sha256' => $v->sha256, 'file_hash' => $s['file_hash']];
                $update['status'] = $d['action'] === 'approve' ? 'approved' : 'published';
                if ($d['action'] === 'publish') {
                    $approved = DB::table('assessment_events')->where('assessment_id', $r->id)->where('version', $r->current_version)->where('action', 'approve')->lockForUpdate()->firstOrFail();
                    abort_unless(hash_equals(json_decode($approved->approval)->sha256, $v->sha256), 423, 'Pengesahan tidak sesuai versi.');
                    $update['published_version'] = $r->current_version;
                    if ($r->published_version) {
                        $appeal = DB::table('grade_appeals')->where('assessment_id', $r->id)->where('version', $r->published_version)->lockForUpdate()->firstOrFail();
                        abort_unless($appeal->status === 'accepted', 422);
                        DB::table('grade_appeals')->where('id', $appeal->id)->update(['status' => 'completed', 'corrected_version' => $r->current_version, 'updated_at' => now()]);
                    }
                }
            } elseif ($isAppeal) {
                abort_unless($r->status === 'published' && $r->published_version, 422, 'Keberatan hanya untuk nilai yang dipublikasikan.');
                abort_if(DB::table('grade_appeals')->where('assessment_id', $r->id)->where('version', $r->published_version)->lockForUpdate()->exists(), 422, 'Keberatan versi ini sudah diajukan.');
                $id = DB::table('grade_appeals')->insertGetId(['ulid' => (string) Str::ulid(), 'assessment_id' => $r->id, 'version' => $r->published_version, 'actor_id' => $u->id,
                    'reason' => $d['note'], 'status' => 'submitted', 'created_at' => now(), 'updated_at' => now()]);
                if ($upload) {
                    $file = app(AssessmentFileService::class)->upload($u, $id, 'grade_appeal', 1, $upload);
                    DB::table('grade_appeals')->where('id', $id)->update(['private_file_id' => $file->id, 'file_hash' => $file->sha256]);
                }
            } else {
                $appeal = DB::table('grade_appeals')->where('assessment_id', $r->id)->where('version', $r->published_version)->lockForUpdate()->firstOrFail();
                abort_unless($r->status === 'published' && $appeal->status === ($d['action'] === 'review' ? 'submitted' : 'reviewing'), 422, 'Status keberatan tidak sesuai.');
                app(AssessmentFileService::class)->clean($appeal->private_file_id, $appeal->file_hash);
                $status = ['review' => 'reviewing', 'accept' => 'accepted', 'reject' => 'rejected'][$d['action']];
                DB::table('grade_appeals')->where('id', $appeal->id)->update(['status' => $status, 'response' => $d['note'], 'updated_at' => now()]);
            }
            DB::table('assessments')->where('id', $r->id)->update($update);
            $this->event($u, $r, $d['action'], $d['note'], $approval);
            $recipient = $isAppeal ? $mentor->educator_user_id : DB::table('participants')->where('id', $p->participant_id)->value('user_id');
            if ($d['action'] !== 'approve') {
                app(SchedulingJournal::class)->notify([$recipient], $p, 'Penilaian '.$r->title.': '.(['publish' => 'Dipublikasikan', 'appeal' => 'Keberatan diajukan', 'review' => 'Keberatan ditinjau', 'accept' => 'Keberatan diterima', 'reject' => 'Keberatan ditolak'][$d['action']]).'.');
            }
        }, 5);
        if ($file) {
            app(PrivateFileService::class)->scan($file);
        }
    }
}
