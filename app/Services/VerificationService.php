<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class VerificationService
{
    public const TYPES = ['attendance' => 'Presensi', 'summary' => 'Rekap presensi', 'logbook' => 'Logbook', 'grade' => 'Penilaian / perubahan nilai', 'completion' => 'Penyelesaian'];

    public function attendanceApproval(User $u, object $row, bool $summary = false): array
    {
        return ['number' => ($summary ? 'RP-' : 'PR-').$row->ulid.'-'.($row->version ?? $row->revision), 'name' => $u->name, 'user_id' => $u->id,
            'job_title' => $u->job_title, 'role' => $summary ? 'ketua-ksm' : 'pembimbing', 'at' => now()->toIso8601String(),
            'sha256' => $summary ? app(AssessmentService::class)->fingerprint(json_decode($row->snapshot, true)) : $this->attendanceHash($row)];
    }

    public function attendanceHash(object $r): string
    {
        return app(AssessmentService::class)->fingerprint(array_intersect_key((array) $r, array_flip(['ulid', 'placement_id', 'date', 'clinical_location_id', 'mentor_assignment_id', 'attendance_status', 'activity', 'notes', 'revision', 'verified_by', 'verified_at'])));
    }

    public function verify(User $u, string $type, int $id): array
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        $table = match ($type) {
            'attendance' => 'scheduling_histories', 'summary' => 'attendance_summaries', 'logbook' => 'logbook_reviews', 'grade' => 'assessment_events', 'completion' => 'completion_requests'
        };
        if ($type === 'attendance') {
            $history = DB::table($table)->where('id', $id)->where('resource_type', 'attendances')->where('event', 'attendance_verify')->firstOrFail();
            $event = (object) json_decode($history->after, true, flags: JSON_THROW_ON_ERROR);
            abort_unless(! empty($event->approval), 404);
        } else {
            $event = DB::table($table)->where('id', $id)->whereNotNull('approval')->firstOrFail();
        }
        $r = match ($type) {
            'logbook' => DB::table('logbooks')->find($event->logbook_id), 'grade' => DB::table('assessments')->find($event->assessment_id), default => $event
        };
        $access = app(match ($type) {
            'logbook' => LogbookAccess::class, 'grade' => AssessmentAccess::class, 'attendance', 'summary' => AttendanceAccess::class, default => AdmissionsAccess::class
        });
        $p = $access->placements($u)->where('id', $r->placement_id)->firstOrFail();
        if (in_array($type, ['logbook', 'grade'])) {
            $access->rows($u, $p)->where('id', $r->id)->firstOrFail();
            if ($type === 'grade' && $access->owner($u, $p)) {
                abort_unless($event->action === 'publish' && $event->version === $r->published_version, 404);
            }
        }
        $approval = json_decode($event->approval, true, flags: JSON_THROW_ON_ERROR);
        $hash = '';
        $current = false;
        if ($type === 'attendance') {
            $hash = $this->attendanceHash($r);
            $live = DB::table('attendances')->find($r->id);
            $current = $live && $live->status === 'verified' && $live->revision === $r->revision && hash_equals($hash, $this->attendanceHash($live));
        } elseif ($type === 'summary') {
            $hash = app(AssessmentService::class)->fingerprint(json_decode($r->snapshot, true));
            $current = $r->status === 'sealed';
        } elseif ($type === 'completion') {
            $hash = app(AssessmentService::class)->fingerprint(json_decode($r->snapshot, true));
            $latest = DB::table('completion_requests')->where('placement_id', $p->id)->where('kind', 'completion')->where('status', 'completed')->max('id');
            $current = $p->status === 'selesai' && $r->kind === 'completion' && $r->status === 'completed' && (int) $latest === (int) $r->id;
            abort_unless(hash_equals($r->sha256, $hash), 423, 'Integritas penyelesaian berubah.');
        } else {
            $v = DB::table($type === 'grade' ? 'assessment_versions' : 'logbook_versions')->where($type === 'grade' ? 'assessment_id' : 'logbook_id', $r->id)->where('version', $event->version)->firstOrFail();
            $s = json_decode($v->snapshot, true);
            $hash = $type === 'grade' ? app(AssessmentService::class)->fingerprint($s) : app(LogbookService::class)->fingerprint($s);
            abort_unless(hash_equals($v->sha256, $hash), 423, 'Integritas versi berubah.');
            $current = $type === 'grade' ? $r->published_version === $event->version && $r->current_version === $event->version && $r->status === 'published' : $r->current_version === $event->version && $r->status === 'approved';
        }
        abort_unless(isset($approval['sha256']) && hash_equals($approval['sha256'], $hash), 423, 'Hash pengesahan tidak sesuai.');

        return ['type' => self::TYPES[$type], 'number' => $approval['number'] ?? $approval['document_number'], 'name' => $approval['name'],
            'role' => $approval['role'], 'position' => $approval['job_title'] ?? $approval['position'] ?? $approval['role'],
            'at' => $approval['at'] ?? $approval['approved_at'], 'hash' => $hash, 'current' => $current];
    }

    public function entries(User $u, string $placement): array
    {
        $out = [];
        foreach (self::TYPES as $type => $label) {
            $access = app(match ($type) {
                'logbook' => LogbookAccess::class, 'grade' => AssessmentAccess::class, 'attendance', 'summary' => AttendanceAccess::class, default => AdmissionsAccess::class
            });
            $p = $access->placements($u)->where('ulid', $placement)->first();
            if (! $p) {
                continue;
            }
            $q = match ($type) {
                'logbook' => DB::table('logbook_reviews')->whereIn('logbook_id', $access->rows($u, $p)->select('id')),
                'grade' => DB::table('assessment_events')->whereIn('assessment_id', $access->rows($u, $p)->select('id')),
                'attendance' => DB::table('scheduling_histories')->where('resource_type', 'attendances')->where('event', 'attendance_verify')->whereIn('resource_id', DB::table('attendances')->where('placement_id', $p->id)->select('id')),
                default => DB::table(match ($type) {
                    'attendance' => 'attendances', 'summary' => 'attendance_summaries', default => 'completion_requests'
                })->where('placement_id', $p->id),
            };
            if ($type === 'grade' && $access->owner($u, $p)) {
                $q->where('action', 'publish')->whereExists(fn ($s) => $s->selectRaw('1')->from('assessments')->whereColumn('assessments.id', 'assessment_events.assessment_id')->whereColumn('assessments.published_version', 'assessment_events.version'));
            }
            $q->whereNotNull($type === 'attendance' ? 'after->approval' : 'approval');
            foreach ($q->orderByDesc('id')->limit(100)->get(['id']) as $r) {
                $out[] = ['type' => $type, 'label' => $label, 'id' => $r->id];
            }
        }

        return $out;
    }
}
