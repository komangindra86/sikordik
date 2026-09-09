<?php

namespace App\Http\Controllers;

use App\Services\AdmissionsAccess;
use App\Services\AuditLogger;
use App\Services\ParticipantImportService;
use App\Services\ParticipantService;
use App\Services\PlacementService;
use App\Services\PrivateFileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdmissionsController extends Controller
{
    public function index(Request $request, AdmissionsAccess $access)
    {
        $q = $access->placements($request->user());
        if ($request->filled('status')) {
            $q->where('status', $request->string('status')->toString());
        }
        if ($request->filled('q')) {
            $search = mb_substr($request->string('q')->toString(), 0, 100);
            $q->whereIn('participant_id', DB::table('participants')->where('name', 'like', '%'.$search.'%')->orWhere('number', 'like', '%'.$search.'%')->select('id'));
        }
        $placements = $q->orderByDesc('id')->paginate(20)->withQueryString();
        $participants = DB::table('participants')->whereIn('id', $placements->pluck('participant_id'))->get()->keyBy('id');

        return view('admissions.index', compact('placements', 'participants', 'access'));
    }

    private function admin(Request $request): void
    {
        abort_unless(app(AdmissionsAccess::class)->admin($request->user()), 403);
    }

    private function masters(): array
    {
        $data = [];
        foreach (['institutions', 'study_programs', 'participant_types', 'departments'] as $table) {
            $data[$table] = DB::table($table)->where('is_active', true)->orderBy('name')->get();
        }

        return $data;
    }

    public function participants(Request $request)
    {
        $this->admin($request);
        $q = DB::table('participants');
        if ($request->filled('q')) {
            $s = mb_substr($request->string('q')->toString(), 0, 100);
            $q->where(fn ($q) => $q->where('name', 'like', '%'.$s.'%')->orWhere('number', 'like', '%'.$s.'%')->orWhere('nim', $s));
        }

        return view('admissions.participants', $this->masters() + ['participants' => $q->orderByDesc('id')->paginate(20)->withQueryString()]);
    }

    public function previewParticipant(Request $request, ParticipantService $service)
    {
        $this->admin($request);
        $data = $service->validate($request->all());

        return view('admissions.participant-preview', ['data' => $data, 'candidates' => $service->candidates($data)]);
    }

    public function storeParticipant(Request $request, ParticipantService $service)
    {
        $request->validate(['review_confirmed' => 'accepted', 'duplicate_reason' => 'nullable|string|max:2000']);
        $p = $service->create($request->user(), $request->all(), $request->input('duplicate_reason'));

        return redirect()->route('admissions.participants')->with('status', 'Peserta tersimpan: '.$p->number);
    }

    public function activate(Request $request, string $ulid, ParticipantService $service)
    {
        $service->activate($request->user(), $ulid, $request->all());

        return back()->with('status', 'Akun peserta aktif. Peserta dapat menggunakan Lupa kata sandi untuk menetapkan password melalui email terverifikasi petugas.');
    }

    public function editParticipant(Request $request, string $ulid)
    {
        $this->admin($request);

        return view('admissions.participant-edit', $this->masters() + ['p' => DB::table('participants')->where('ulid', $ulid)->firstOrFail()]);
    }

    public function updateParticipant(Request $request, string $ulid, ParticipantService $service)
    {
        $service->update($request->user(), $ulid, $request->all());

        return back()->with('status', 'Data induk diperbarui. Nomor peserta, akun, dan snapshot penempatan dipertahankan.');
    }

    public function letters(Request $request)
    {
        $this->admin($request);

        return view('admissions.letters', $this->masters() + ['letters' => DB::table('incoming_letters')->orderByDesc('id')->paginate(20)]);
    }

    public function storeLetter(Request $request)
    {
        $this->admin($request);
        $data = $request->validate(['institution_id' => 'required|exists:institutions,id,is_active,1', 'number' => 'required|string|max:191', 'letter_date' => 'required|date_format:Y-m-d', 'subject' => 'required|string|max:255']);
        $data['normalized_number'] = ParticipantService::normalize($data['number']);
        $data['year'] = (int) substr($data['letter_date'], 0, 4);
        DB::transaction(function () use ($request, $data) {
            DB::table('institutions')->where('id', $data['institution_id'])->lockForUpdate()->firstOrFail();
            validator($data, ['normalized_number' => ['required', 'max:191', Rule::unique('incoming_letters')->where('institution_id', $data['institution_id'])->where('year', $data['year'])]])->validate();
            $id = DB::table('incoming_letters')->insertGetId($data + ['ulid' => (string) Str::ulid(), 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            app(AuditLogger::class)->log('letter.created', 'incoming_letter', $id);
        });

        return back()->with('status', 'Surat tercatat. Unggah PDF dan tambahkan peserta melalui Penempatan.');
    }

    public function create(Request $request)
    {
        $this->admin($request);
        $participants = DB::table('participants')->orderByDesc('id')->limit(100)->get();
        if ($request->filled('participant')) {
            $participants = DB::table('participants')->where('ulid', $request->input('participant'))->get();
        }

        $letters = DB::table('incoming_letters')->when($request->filled('letter'), fn ($q) => $q->where('ulid', $request->input('letter')))->orderByDesc('id')->limit(100)->get();

        return view('admissions.create', $this->masters() + ['participants' => $participants, 'letters' => $letters]);
    }

    public function store(Request $request, PlacementService $service)
    {
        $p = $service->create($request->user(), $request->all());

        return redirect()->route('admissions.show', $p->ulid)->with('status', 'Draft dibuat; periksa checklist dan konflik sebelum mengajukan.');
    }

    public function show(Request $request, string $ulid, AdmissionsAccess $access, PlacementService $service)
    {
        $p = $access->placement($request->user(), $ulid);
        $files = DB::table('private_files')->where(fn ($q) => $q->where(fn ($s) => $s->where('resource_type', 'placement')->where('resource_id', $p->id))->orWhere(fn ($s) => $s->where('resource_type', 'letter')->where('resource_id', $p->incoming_letter_id)))->orderByDesc('id')->get()->filter(fn ($f) => $access->file($request->user(), $f));
        $staff = $access->role($request->user(), ['admin-kordik', 'super-admin', 'tim-kordik', 'ketua-ksm', 'sekretariat-ksm']);

        return view('admissions.show', [
            'p' => $p, 'participant' => DB::table('participants')->select('name', 'number', 'ulid')->find($p->participant_id),
            'files' => $files, 'access' => $access, 'staff' => $staff,
            'documents' => DB::table('placement_documents')->where('placement_id', $p->id)->get(),
            'histories' => $staff ? DB::table('placement_histories')->where('placement_id', $p->id)->orderByDesc('id')->get() : collect(),
            'conflicts' => $access->role($request->user(), ['admin-kordik', 'super-admin', 'tim-kordik']) ? $service->conflicts($p) : collect(),
            'exceptions' => $staff ? DB::table('overlap_exceptions')->where('placement_id', $p->id)->get() : collect(),
            'departments' => $access->admin($request->user()) ? DB::table('departments')->where('is_active', true)->get() : collect(),
        ]);
    }

    public function transition(Request $request, string $ulid, PlacementService $service)
    {
        $data = $request->validate(['action' => 'required|string', 'expected_status' => 'required|string', 'revision' => 'required|integer', 'reason' => 'nullable|string|max:2000']);
        $service->transition($request->user(), $ulid, $data['action'], $data['expected_status'], $data['revision'], $data['reason'] ?? null);

        return back()->with('status', 'Status penempatan diperbarui dan dicatat dalam histori.');
    }

    public function period(Request $request, string $ulid, PlacementService $service)
    {
        $service->revisePeriod($request->user(), $ulid, $request->all());

        return back()->with('status', 'Periode direvisi. Persetujuan diulang dari draft.');
    }

    public function requestOverlap(Request $request, string $ulid, PlacementService $service)
    {
        $service->requestException($request->user(), $ulid, $request->all());

        return back()->with('status', 'Permohonan pengecualian dicatat.');
    }

    public function decideException(Request $request, string $ulid, PlacementService $service)
    {
        $data = $request->validate(['approved' => 'required|boolean', 'reason' => 'required|string|min:10|max:2000']);
        $service->decideException($request->user(), $ulid, (bool) $data['approved'], $data['reason']);

        return back()->with('status', 'Keputusan pengecualian disimpan.');
    }

    public function review(Request $request, string $ulid, PlacementService $service)
    {
        $service->reviewDocument($request->user(), $ulid, $request->all());

        return back()->with('status', 'Review dokumen disimpan.');
    }

    public function upload(Request $request, string $resource, string $ulid, PrivateFileService $service)
    {
        $request->validate(['file' => 'required|file|max:10240', 'category' => 'required|string', 'deidentified' => 'accepted']);
        $file = $service->upload($request->user(), $resource, $ulid, $request->input('category'), $request->file('file'), $request->boolean('deidentified'));

        return back()->with('status', 'Versi '.$file->version.' disimpan privat. Hasil scan: '.$file->scan_status.'.');
    }

    public function download(Request $request, string $ulid, AdmissionsAccess $access)
    {
        $file = DB::table('private_files')->where('ulid', $ulid)->firstOrFail();
        abort_unless($access->file($request->user(), $file), 404);
        abort_unless($file->scan_status === 'clean', 423, 'Berkas masih tertahan atau ditolak.');
        $disk = Storage::disk('local');
        abort_unless($disk->exists($file->path) && hash_equals($file->sha256, hash_file('sha256', $disk->path($file->path))), 423);
        app(AuditLogger::class)->log('file.downloaded', 'private_file', $file->ulid);

        return $disk->download($file->path, $file->original_name, ['Content-Type' => $file->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function imports(Request $request)
    {
        $this->admin($request);

        return view('admissions.imports', $this->masters() + ['imports' => DB::table('participant_imports')->where('created_by', $request->user()->id)->orderByDesc('id')->paginate(20)]);
    }

    public function previewImport(Request $request, ParticipantImportService $service)
    {
        $request->validate(['file' => 'required|file|max:5120', 'institution_id' => 'required|integer']);
        $import = $service->preview($request->user(), $request->file('file'), $request->integer('institution_id'));

        return redirect()->route('admissions.import', $import->ulid);
    }

    public function showImport(Request $request, string $ulid)
    {
        $this->admin($request);
        $import = DB::table('participant_imports')->where('ulid', $ulid)->where('created_by', $request->user()->id)->firstOrFail();

        return view('admissions.import', ['import' => $import, 'source' => DB::table('private_files')->where('resource_type', 'import')->where('resource_id', $import->id)->first(), 'rows' => DB::table('participant_import_rows')->where('participant_import_id', $import->id)->orderBy('row_number')->get()]);
    }

    public function commitImport(Request $request, string $ulid, ParticipantImportService $service)
    {
        $request->validate(['confirmed' => 'accepted', 'choices' => 'nullable|array', 'choices.*.existing_ulid' => 'nullable|string|exists:participants,ulid', 'choices.*.reason' => 'nullable|string|max:2000']);
        $service->commit($request->user(), $ulid, $request->input('choices', []));

        return back()->with('status', 'Impor diproses. Baris berhasil tidak akan diulang; periksa alasan pada baris gagal.');
    }

    public function templates(Request $request)
    {
        $this->admin($request);

        return view('admissions.templates', $this->masters() + ['templates' => DB::table('document_templates')->orderByDesc('id')->get()]);
    }

    public function storeTemplate(Request $request)
    {
        $this->admin($request);
        $data = $request->validate(['participant_type_id' => 'required|exists:participant_types,id', 'institution_id' => 'nullable|exists:institutions,id', 'study_program_id' => 'nullable|exists:study_programs,id', 'department_id' => 'nullable|exists:departments,id', 'code' => 'required|regex:/^[a-z][a-z0-9_]{0,59}$/', 'label' => 'required|string|max:255', 'reason' => 'required|string|min:10|max:2000']);
        DB::transaction(function () use ($data) {
            $reason = $data['reason'];
            unset($data['reason']);
            $id = DB::table('document_templates')->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
            app(AuditLogger::class)->log('document_template.created', 'document_template', $id, reason: $reason);
        });

        return back()->with('status', 'Persyaratan tambahan berlaku untuk placement baru sesuai cakupan.');
    }

    public function disableTemplate(Request $request, int $id)
    {
        $this->admin($request);
        $data = $request->validate(['reason' => 'required|string|min:10|max:2000']);
        DB::transaction(function () use ($id, $data) {
            DB::table('document_templates')->where('id', $id)->firstOrFail();
            DB::table('document_templates')->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]);
            app(AuditLogger::class)->log('document_template.disabled', 'document_template', $id, reason: $data['reason']);
        });

        return back()->with('status', 'Template dinonaktifkan. Snapshot penempatan lama dipertahankan.');
    }
}
