<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * One letter, many participants, one placement each. It only chains the existing services:
 * duplicate review, placement creation and submission keep their own rules.
 */
class BatchAdmissionService
{
    public const MAX_ROWS = 100;

    /** Header of the batch plus its cleaned participant rows. */
    public function parse(array $input): array
    {
        $head = Validator::make($input, [
            'letter_ulid' => 'nullable|string|exists:incoming_letters,ulid',
            'institution_id' => 'required_without:letter_ulid|nullable|integer|exists:institutions,id,is_active,1',
            'number' => 'required_without:letter_ulid|nullable|string|max:191',
            'letter_date' => 'required_without:letter_ulid|nullable|date_format:Y-m-d',
            'subject' => 'required_without:letter_ulid|nullable|string|max:255',
            'study_program_id' => 'required|integer|exists:study_programs,id,is_active,1',
            'participant_type_id' => 'required|integer|exists:participant_types,id,is_active,1',
            'department_id' => 'required|integer|exists:departments,id,is_active,1',
            'start_date' => 'required|date_format:Y-m-d', 'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'rows' => 'nullable|array', 'pasted' => 'nullable|string|max:20000',
        ], [], ['number' => 'nomor surat', 'letter_date' => 'tanggal surat', 'subject' => 'perihal', 'institution_id' => 'institusi'])->validate();

        $letter = ($head['letter_ulid'] ?? null) ? DB::table('incoming_letters')->where('ulid', $head['letter_ulid'])->first() : null;
        $head['institution_id'] = (int) ($letter->institution_id ?? $head['institution_id']);
        if ((int) DB::table('study_programs')->where('id', $head['study_program_id'])->value('institution_id') !== $head['institution_id']) {
            throw ValidationException::withMessages(['study_program_id' => 'Program studi harus berasal dari institusi pengirim surat.']);
        }
        $raw = array_values($head['rows'] ?? []);
        // Rows pasted from a spreadsheet: one participant per line, columns split by tab or semicolon.
        foreach (preg_split('/\r\n|\r|\n/', (string) ($head['pasted'] ?? '')) as $line) {
            $cells = array_map('trim', preg_split('/\t|;/', $line));
            if (($cells[0] ?? '') !== '') {
                $raw[] = ['name' => $cells[0], 'nim' => $cells[1] ?? null, 'birth_date' => $cells[2] ?? null, 'email' => $cells[3] ?? null];
            }
        }
        $rows = [];
        foreach ($raw as $row) {
            if (! is_array($row) || trim((string) ($row['name'] ?? '')) === '') {
                continue;
            }
            $row['birth_date'] = $this->date($row['birth_date'] ?? null);
            $rows[] = array_map(fn ($v) => is_scalar($v) ? (string) $v : null, array_intersect_key($row, array_flip(['name', 'nim', 'birth_date', 'email', 'use', 'reason'])))
                + ['nim' => null, 'birth_date' => null, 'email' => null, 'institution_id' => $head['institution_id']];
        }
        if (! $rows) {
            throw ValidationException::withMessages(['rows' => 'Isi minimal satu nama peserta.']);
        }
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['rows' => 'Paling banyak '.self::MAX_ROWS.' peserta dalam satu kali penerimaan.']);
        }
        unset($head['rows'], $head['pasted']);

        return [$head, $rows, $letter];
    }

    private function date(?string $value): ?string
    {
        $value = trim((string) $value);
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($date && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return $value === '' ? null : $value;
    }

    /** Rows with the existing participants an admin must look at before anything is saved. */
    public function preview(User $actor, array $input): array
    {
        abort_unless(app(AdmissionsAccess::class)->admin($actor), 403);
        [$head, $rows, $letter] = $this->parse($input);
        $participants = app(ParticipantService::class);
        foreach ($rows as $i => $row) {
            try {
                $rows[$i]['candidates'] = $participants->candidates($row);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(['rows' => 'Baris '.($i + 1).' ('.$row['name'].'): '.collect($e->errors())->flatten()->first()]);
            }
        }

        return compact('head', 'rows', 'letter');
    }

    /** @return array{letter: object, placements: array<int, object>} */
    public function commit(User $actor, array $input): array
    {
        abort_unless(app(AdmissionsAccess::class)->admin($actor), 403);
        [$head, $rows, $letter] = $this->parse($input);
        $submit = (bool) ($input['submit'] ?? false);

        return DB::transaction(function () use ($actor, $head, $rows, $letter, $submit) {
            $letter ??= $this->letter($actor, $head);
            $placements = [];
            $seen = [];
            foreach ($rows as $i => $row) {
                try {
                    $use = (string) ($row['use'] ?? 'new');
                    $participant = $use !== 'new' ? DB::table('participants')->where('ulid', $use)->first()
                        : app(ParticipantService::class)->create($actor, $row, $row['reason'] ?? null);
                    if (! $participant || isset($seen[$participant->id])) {
                        throw ValidationException::withMessages(['rows' => $participant ? 'peserta yang sama dipilih dua kali.' : 'peserta lama tidak ditemukan.']);
                    }
                    $seen[$participant->id] = true;
                    $p = app(PlacementService::class)->create($actor, $head + ['participant_ulid' => $participant->ulid, 'letter_ulid' => $letter->ulid]);
                    if ($submit) {
                        app(PlacementService::class)->transition($actor, $p->ulid, 'submit', 'draft', (int) $p->revision, null);
                    }
                    $placements[] = $p;
                } catch (ValidationException $e) {
                    // Nothing of the batch is kept, so the admin fixes one named row and sends the same form again.
                    throw ValidationException::withMessages(['rows' => 'Baris '.($i + 1).' ('.$row['name'].'): '.collect($e->errors())->flatten()->first()]);
                }
            }

            return ['letter' => $letter, 'placements' => $placements];
        }, 5);
    }

    private function letter(User $actor, array $head): object
    {
        $data = ['institution_id' => $head['institution_id'], 'number' => $head['number'], 'letter_date' => $head['letter_date'], 'subject' => $head['subject'],
            'normalized_number' => ParticipantService::normalize($head['number']), 'year' => (int) substr($head['letter_date'], 0, 4)];
        DB::table('institutions')->where('id', $data['institution_id'])->lockForUpdate()->firstOrFail();
        Validator::make($data, ['normalized_number' => ['required', 'max:191', Rule::unique('incoming_letters')->where('institution_id', $data['institution_id'])->where('year', $data['year'])]],
            ['normalized_number.unique' => 'Nomor surat ini sudah tercatat untuk institusi dan tahun yang sama. Pilih surat tersebut dari daftar.'])->validate();
        $id = DB::table('incoming_letters')->insertGetId($data + ['ulid' => (string) Str::ulid(), 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->log('letter.created', 'incoming_letter', $id);

        return DB::table('incoming_letters')->find($id);
    }

    /** Admissions that are waiting for a decision this user is allowed to give. */
    public function waiting(User $actor): Builder
    {
        $access = app(AdmissionsAccess::class);

        return $access->placements($actor)->whereNull('archived_at')->where(function ($q) use ($access, $actor) {
            $q->whereRaw('1 = 0');
            if ($access->role($actor, ['ketua-ksm'])) {
                $q->orWhere(fn ($q) => $q->where('status', 'menunggu_konfirmasi_ksm')->whereIn('department_id', $actor->departmentScopeIds()));
            }
            if ($access->role($actor, ['tim-kordik'])) {
                $q->orWhere('status', 'menunggu_persetujuan_kordik');
            }
        });
    }

    /**
     * Accept many waiting placements with one click. Each one goes through the normal transition,
     * so scope, separation of duties and history are exactly those of a single decision.
     *
     * @return int number of placements accepted
     */
    public function acceptMany(User $actor, array $ulids): int
    {
        return DB::transaction(function () use ($actor, $ulids) {
            $rows = $this->waiting($actor)->whereIn('ulid', array_slice($ulids, 0, 200))->orderBy('id')->get();
            foreach ($rows as $p) {
                app(PlacementService::class)->transition($actor, $p->ulid, $p->status === 'menunggu_konfirmasi_ksm' ? 'ksm_accept' : 'kordik_accept', $p->status, (int) $p->revision, null);
            }
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['ids' => 'Pilih minimal satu penempatan yang menunggu keputusan Anda.']);
            }

            return $rows->count();
        }, 5);
    }
}
