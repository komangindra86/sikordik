<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ParticipantService
{
    public static function normalize(string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', ' ', trim($value)));
    }

    public function validate(array $data): array
    {
        $data = array_intersect_key($data, array_flip(['name', 'birth_date', 'nik', 'nim', 'email', 'institution_id']));
        foreach (['nik', 'nim', 'email', 'birth_date'] as $key) {
            $data[$key] = trim((string) ($data[$key] ?? '')) ?: null;
        }
        $data['name'] = trim((string) ($data['name'] ?? ''));
        $data['email'] = $data['email'] ? mb_strtolower($data['email']) : null;
        $data['nim'] = $data['nim'] ? self::normalize($data['nim']) : null;
        $data = Validator::make($data, [
            'name' => 'required|string|max:255', 'nik' => ['nullable', 'regex:/^[0-9]{16}$/'],
            'nim' => 'nullable|string|max:100', 'email' => 'nullable|email|max:255',
            'birth_date' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'institution_id' => 'required|integer|exists:institutions,id,is_active,1',
        ])->validate();
        $data['normalized_name'] = self::normalize($data['name']);

        return $data;
    }

    public function candidates(array $data, ?string $excludeUlid = null): array
    {
        $data = $this->validate($data);
        $rows = DB::table('participants')->where(function ($q) use ($data) {
            $q->where('normalized_name', $data['normalized_name']);
            if ($data['nik']) {
                $q->orWhere('nik', $data['nik']);
            }
            if ($data['email']) {
                $q->orWhere('email', $data['email']);
            }
            if ($data['nim']) {
                $q->orWhere(fn ($s) => $s->where('nim', $data['nim'])->where('institution_id', $data['institution_id']));
            }
        })->when($excludeUlid, fn ($q) => $q->where('ulid', '!=', $excludeUlid))->get();

        return $rows->map(function ($p) use ($data) {
            $reasons = [];
            if ($data['nik'] && $data['nik'] === $p->nik) {
                $reasons[] = 'NIK sama';
            }
            if ($data['email'] && $data['email'] === $p->email) {
                $reasons[] = 'Email sama';
            }
            if ($data['nim'] && $data['nim'] === $p->nim && (int) $data['institution_id'] === (int) $p->institution_id) {
                $reasons[] = 'NIM dan institusi sama';
            }
            if ($data['normalized_name'] === $p->normalized_name && $data['birth_date'] && $data['birth_date'] === $p->birth_date) {
                $reasons[] = 'Nama dan tanggal lahir sama';
            }

            return ['ulid' => $p->ulid, 'number' => $p->number, 'name' => $p->name, 'reasons' => $reasons ?: ['Nama sama; periksa identitas'], 'hard' => in_array('NIK sama', $reasons, true)];
        })->all();
    }

    public function create(User $actor, array $input, ?string $duplicateReason = null): object
    {
        abort_unless(app(AdmissionsAccess::class)->admin($actor), 403);
        $data = $this->validate($input);

        return DB::transaction(function () use ($actor, $data, $duplicateReason) {
            $year = now('Asia/Makassar')->year;
            DB::table('participant_sequences')->insertOrIgnore(['year' => $year, 'value' => 0]);
            $sequence = DB::table('participant_sequences')->where('year', $year)->lockForUpdate()->first();
            $candidates = $this->candidates($data);
            if (collect($candidates)->contains('hard', true) || ($candidates && mb_strlen(trim((string) $duplicateReason)) < 10)) {
                throw ValidationException::withMessages(['duplicate_reason' => 'Tinjau kandidat. NIK sama wajib memakai peserta lama; kandidat lainnya memerlukan alasan minimal 10 karakter.']);
            }
            DB::table('participant_sequences')->where('year', $year)->increment('value');
            $id = DB::table('participants')->insertGetId($data + ['ulid' => (string) Str::ulid(), 'number' => sprintf('PDK-%d-%06d', $year, $sequence->value + 1), 'created_at' => now(), 'updated_at' => now()]);
            app(AuditLogger::class)->log('participant.created', 'participant', $id, reason: $duplicateReason, newValues: ['actor_id' => $actor->id, 'reviewed_candidates' => array_column($candidates, 'ulid')]);

            return DB::table('participants')->find($id);
        }, 5);
    }

    public function update(User $actor, string $ulid, array $input): void
    {
        abort_unless(app(AdmissionsAccess::class)->admin($actor), 403);
        Validator::make($input, ['reason' => 'required|string|min:10|max:2000', 'expected_hash' => 'required|string|size:64', 'duplicate_reason' => 'nullable|string|min:10|max:2000'])->validate();
        $data = $this->validate($input);
        DB::transaction(function () use ($actor, $ulid, $input, $data) {
            $year = now('Asia/Makassar')->year;
            DB::table('participant_sequences')->insertOrIgnore(['year' => $year, 'value' => 0]);
            DB::table('participant_sequences')->where('year', $year)->lockForUpdate()->first();
            $p = DB::table('participants')->where('ulid', $ulid)->lockForUpdate()->firstOrFail();
            if (! hash_equals(self::identityHash($p), $input['expected_hash'])) {
                throw ValidationException::withMessages(['name' => 'Identitas telah berubah. Muat ulang formulir.']);
            }
            $candidates = $this->candidates($data, $ulid);
            if (collect($candidates)->contains('hard', true) || ($candidates && empty($input['duplicate_reason']))) {
                throw ValidationException::withMessages(['duplicate_reason' => 'Tinjau kandidat: '.implode('; ', array_map(fn ($c) => $c['number'].' ('.implode(', ', $c['reasons']).')', $candidates)).'. NIK sama tidak boleh disimpan; kandidat lain memerlukan alasan berbeda orang.']);
            }
            DB::table('participants')->where('id', $p->id)->update($data + ['updated_at' => now()]);
            app(AuditLogger::class)->log('participant.updated', 'participant', $p->id, reason: $input['reason'], newValues: ['actor_id' => $actor->id, 'changed_fields' => array_keys(array_diff_assoc($data, (array) $p)), 'duplicate_reason' => $input['duplicate_reason'] ?? null, 'reviewed_candidates' => array_column($candidates, 'ulid')]);
        }, 5);
    }

    public static function identityHash(object $p): string
    {
        return hash('sha256', json_encode([$p->name, $p->birth_date, $p->nik, $p->nim, $p->email, $p->institution_id], JSON_THROW_ON_ERROR));
    }

    public function activate(User $actor, string $ulid, array $input): void
    {
        abort_unless(app(AdmissionsAccess::class)->admin($actor), 403);
        $data = Validator::make($input, ['email' => 'required|email|max:255', 'ownership_reason' => 'required|string|min:10|max:2000', 'ownership_confirmed' => 'accepted'])->validate();
        DB::transaction(function () use ($actor, $ulid, $data) {
            $p = DB::table('participants')->where('ulid', $ulid)->lockForUpdate()->firstOrFail();
            if (! DB::table('placements')->where('participant_id', $p->id)->where('status', 'terverifikasi')->exists()) {
                throw ValidationException::withMessages(['email' => 'Aktivasi memerlukan placement terverifikasi.']);
            }
            $email = mb_strtolower(trim($data['email']));
            $account = DB::table('users')->where('email', $email)->lockForUpdate()->first();
            if (($account && (int) $p->user_id !== (int) $account->id) || ($p->user_id && (! $account || (int) $p->user_id !== (int) $account->id))) {
                throw ValidationException::withMessages(['email' => 'Email sudah terpakai atau berbeda dari akun tertaut. Tidak ada penautan otomatis.']);
            }
            $userId = $p->user_id;
            if (! $userId) {
                $userId = DB::table('users')->insertGetId(['name' => $p->name, 'email' => $email, 'password' => Hash::make(Str::random(64)), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('user_roles')->insert(['user_id' => $userId, 'role_id' => DB::table('roles')->where('code', 'peserta')->value('id'), 'assigned_by' => $actor->id, 'assigned_at' => now()]);
                DB::table('participants')->where('id', $p->id)->update(['user_id' => $userId, 'updated_at' => now()]);
            } else {
                abort_if($account->deleted_at !== null, 422, 'Akun telah diarsipkan.');
                DB::table('users')->where('id', $userId)->update(['is_active' => true, 'updated_at' => now()]);
            }
            app(AuditLogger::class)->log('participant.account_activated', 'participant', $p->id, reason: $data['ownership_reason'], newValues: ['user_id' => $userId, 'actor_id' => $actor->id]);
        }, 5);
    }
}
