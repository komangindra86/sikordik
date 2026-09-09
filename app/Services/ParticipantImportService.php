<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class ParticipantImportService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }

    public function preview(User $actor, UploadedFile $file, int $institution): object
    {
        abort_unless(app(AdmissionsAccess::class)->admin($actor), 403);
        Validator::make(['institution_id' => $institution], ['institution_id' => 'exists:institutions,id,is_active,1'])->validate();
        if (! $file->isValid() || strtolower($file->getClientOriginalExtension()) !== 'xlsx' || $file->getSize() > 5 * 1024 * 1024 || ! in_array((new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()), ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])) {
            $this->fail('Gunakan XLSX tanpa macro, maksimum 5 MB.');
        }
        $hash = hash_file('sha256', $file->getRealPath());
        $import = DB::transaction(function () use ($actor, $institution, $hash, $file) {
            DB::table('users')->where('id', $actor->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('participant_imports')->where('created_by', $actor->id)->where('institution_id', $institution)->where('sha256', $hash)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $id = DB::table('participant_imports')->insertGetId(['ulid' => (string) Str::ulid(), 'created_by' => $actor->id, 'institution_id' => $institution, 'sha256' => $hash, 'created_at' => now(), 'updated_at' => now()]);
            $ulid = (string) Str::ulid();
            $path = $file->storeAs('quarantine', $ulid.'.xlsx', 'local');
            DB::table('private_files')->insert(['ulid' => $ulid, 'resource_type' => 'import', 'resource_id' => $id, 'category' => 'import', 'version' => 1, 'path' => $path, 'original_name' => 'sumber-impor.xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'size' => $file->getSize(), 'sha256' => $hash, 'uploaded_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            app(AuditLogger::class)->log('import.uploaded', 'participant_import', $id);

            return DB::table('participant_imports')->find($id);
        }, 5);
        $source = DB::table('private_files')->where('resource_type', 'import')->where('resource_id', $import->id)->firstOrFail();
        $path = Storage::disk('local')->path($source->path);
        $status = is_file($path) && hash_equals($source->sha256, hash_file('sha256', $path)) ? app(MalwareScanner::class)->scan($path) : 'invalid';
        DB::table('private_files')->where('id', $source->id)->update(['scan_status' => $status, 'scanned_at' => now(), 'updated_at' => now()]);
        if ($status !== 'clean') {
            $this->fail('Sumber tersimpan di karantina. Pemindaian malware belum bersih/tersedia; unggah ulang setelah scanner siap.');
        }
        try {
            $rows = $this->readXlsx($path);
        } catch (ValidationException $e) {
            DB::table('private_files')->where('id', $source->id)->update(['scan_status' => 'held']);
            throw $e;
        }

        return DB::transaction(function () use ($import, $institution, $rows) {
            DB::table('participant_imports')->where('id', $import->id)->lockForUpdate()->first();
            $id = $import->id;
            if (DB::table('participant_import_rows')->where('participant_import_id', $id)->exists()) {
                return $import;
            }
            $batchIdentities = [];
            foreach ($rows as $n => $row) {
                $row['institution_id'] = $institution;
                $issues = [];
                try {
                    $issues = app(ParticipantService::class)->candidates($row);
                    $normalized = app(ParticipantService::class)->validate($row);
                    $keys = array_filter([
                        $normalized['nik'] ? 'nik:'.$normalized['nik'] : null,
                        $normalized['email'] ? 'email:'.$normalized['email'] : null,
                        $normalized['nim'] ? 'nim:'.$institution.':'.$normalized['nim'] : null,
                        $normalized['birth_date'] ? 'name:'.$normalized['normalized_name'].':'.$normalized['birth_date'] : null,
                    ]);
                    foreach ($keys as $key) {
                        if (isset($batchIdentities[$key])) {
                            $issues[] = ['Kandidat dalam sumber yang sama pada baris '.$batchIdentities[$key].'; pilih peserta lama setelah baris tersebut berhasil.'];
                        } else {
                            $batchIdentities[$key] = $n;
                        }
                    }
                } catch (ValidationException $e) {
                    $issues = $e->errors();
                }
                DB::table('participant_import_rows')->insert(['participant_import_id' => $id, 'row_number' => $n, 'payload' => json_encode($row), 'issues' => json_encode($issues), 'created_at' => now(), 'updated_at' => now()]);
            }
            app(AuditLogger::class)->log('import.previewed', 'participant_import', $id, newValues: ['rows' => count($rows)]);

            return DB::table('participant_imports')->find($id);
        }, 5);
    }

    private function xml(string $value): \SimpleXMLElement
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $value)) {
            $this->fail('XML tidak aman.');
        }
        $old = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($value, \SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
        if ($xml === false) {
            $this->fail('Struktur XML rusak.');
        }

        return $xml;
    }

    public function readXlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->fail('Arsip XLSX rusak.');
        }
        try {
            if ($zip->numFiles > 100) {
                $this->fail('Terlalu banyak bagian XLSX.');
            }
            $total = 0;
            $seen = [];
            $sheets = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                $total += $stat['size'];
                if ($total > 20 * 1024 * 1024 || $stat['size'] > 8 * 1024 * 1024 || ($stat['encryption_method'] ?? 0) !== 0 || isset($seen[$name]) || str_contains($name, '..') || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('/vba|externalLinks|embeddings|activeX|\.bin$/i', $name)) {
                    $this->fail('Isi XLSX tidak aman atau melewati batas ekstraksi.');
                }
                $seen[$name] = true;
                if (preg_match('~^xl/worksheets/sheet\d+\.xml$~', $name)) {
                    $sheets++;
                }
                if (str_ends_with($name, '.rels') || str_ends_with($name, '.xml')) {
                    $xml = $this->xml($zip->getFromIndex($i));
                    if (str_ends_with($name, '.rels') && $xml->xpath('//*[local-name()="Relationship" and @TargetMode="External"]')) {
                        $this->fail('Tautan eksternal tidak diizinkan.');
                    }
                    if ($name === '[Content_Types].xml' && preg_match('/macroEnabled|vbaProject/i', $zip->getFromIndex($i))) {
                        $this->fail('Macro tidak diizinkan.');
                    }
                }
            }
            if ($sheets !== 1 || ! isset($seen['xl/worksheets/sheet1.xml'], $seen['[Content_Types].xml'], $seen['xl/workbook.xml'])) {
                $this->fail('Gunakan satu worksheet, dengan kolom name, birth_date, nik, nim, email.');
            }
            $shared = [];
            if (isset($seen['xl/sharedStrings.xml'])) {
                foreach ($this->xml($zip->getFromName('xl/sharedStrings.xml'))->xpath('//*[local-name()="si"]') as $si) {
                    $shared[] = implode('', array_map('strval', $si->xpath('.//*[local-name()="t"]')));
                }
            }
            $rows = [];
            $header = null;
            foreach ($this->xml($zip->getFromName('xl/worksheets/sheet1.xml'))->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
                if (count($rows) >= 500) {
                    $this->fail('Maksimum 500 baris peserta per impor.');
                }
                $values = array_fill(0, 5, '');
                $formula = false;
                $numericIdentity = false;
                foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
                    if (! preg_match('/^([A-E])[1-9][0-9]*$/', (string) $cell['r'], $m)) {
                        $this->fail('Hanya kolom A sampai E diizinkan.');
                    }
                    $v = $cell->xpath('./*[local-name()="v"]');
                    $value = isset($v[0]) ? (string) $v[0] : '';
                    if ((string) $cell['t'] === 's') {
                        $value = $shared[(int) $value] ?? '';
                    }
                    if ((string) $cell['t'] === 'inlineStr') {
                        $value = implode('', array_map('strval', $cell->xpath('.//*[local-name()="t"]')));
                    }
                    if (in_array($m[1], ['C', 'D']) && $value !== '' && ! in_array((string) $cell['t'], ['s', 'inlineStr'])) {
                        $numericIdentity = true;
                    }
                    if ($cell->xpath('./*[local-name()="f"]') || preg_match('/^[=+@]/', $value)) {
                        $formula = true;
                    }
                    if (mb_strlen($value) > 255) {
                        $this->fail('Nilai sel terlalu panjang.');
                    }
                    $values[ord($m[1]) - 65] = $value;
                }
                if (! $header) {
                    if ($values !== ['name', 'birth_date', 'nik', 'nim', 'email'] || $formula) {
                        $this->fail('Header wajib: name, birth_date, nik, nim, email. Tanggal berupa teks YYYY-MM-DD; NIK/NIM format teks.');
                    }
                    $header = $values;

                    continue;
                }
                if (implode('', $values) === '') {
                    continue;
                }
                $data = array_combine($header, $values);
                if ($formula) {
                    $data['name'] = '';
                    $data['_error'] = 'Formula tidak diizinkan; ubah menjadi nilai teks.';
                }
                if ($numericIdentity) {
                    $data['name'] = '';
                    $data['_error'] = 'NIK/NIM harus berupa sel teks agar nol di depan tidak hilang.';
                }
                $rows[count($rows) + 2] = $data;
            }
            if (! $header || ! $rows) {
                $this->fail('Tidak ada baris peserta.');
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    public function commit(User $actor, string $ulid, array $choices): void
    {
        abort_unless(app(AdmissionsAccess::class)->admin($actor), 403);
        $import = DB::table('participant_imports')->where('ulid', $ulid)->where('created_by', $actor->id)->firstOrFail();
        abort_unless(DB::table('private_files')->where('resource_type', 'import')->where('resource_id', $import->id)->where('scan_status', 'clean')->exists(), 423, 'Sumber impor belum bersih.');
        foreach (DB::table('participant_import_rows')->where('participant_import_id', $import->id)->pluck('id') as $id) {
            DB::transaction(function () use ($actor, $id, $choices) {
                $row = DB::table('participant_import_rows')->where('id', $id)->lockForUpdate()->first();
                if ($row->status === 'committed') {
                    return;
                }
                $choice = $choices[$row->row_number] ?? [];
                $payload = json_decode($row->payload, true);
                try {
                    $data = app(ParticipantService::class)->validate($payload);
                    if (! empty($choice['existing_ulid'])) {
                        Validator::make($choice, ['reason' => 'required|string|min:10|max:2000'])->validate();
                        $p = DB::table('participants')->where('ulid', $choice['existing_ulid'])->firstOrFail();
                        if ($data['nik'] && $p->nik !== $data['nik']) {
                            $this->fail('NIK berbeda; koreksi sumber impor.');
                        }
                        app(AuditLogger::class)->log('import.existing_selected', 'participant', $p->id, reason: $choice['reason']);
                    } else {
                        $p = app(ParticipantService::class)->create($actor, $data, $choice['reason'] ?? null);
                    }
                    DB::table('participant_import_rows')->where('id', $id)->update(['participant_id' => $p->id, 'status' => 'committed', 'issues' => '[]', 'updated_at' => now()]);
                } catch (ValidationException $e) {
                    DB::table('participant_import_rows')->where('id', $id)->update(['status' => 'failed', 'issues' => json_encode($e->errors()), 'updated_at' => now()]);
                }
            }, 5);
        }
        app(AuditLogger::class)->log('import.committed', 'participant_import', $import->id);
    }
}
