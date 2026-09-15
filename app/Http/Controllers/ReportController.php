<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\LogbookAccess;
use App\Services\ReportExporter;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function __invoke(Request $request, ReportService $service, ReportExporter $exporter)
    {
        $f = $request->validate(['type' => 'sometimes|in:'.implode(',', array_keys(ReportService::TYPES)), 'format' => 'sometimes|in:html,xlsx,pdf',
            'institution' => 'nullable|integer|min:1', 'department' => 'nullable|integer|min:1', 'participant' => 'nullable|integer|min:1',
            'placement' => 'nullable|ulid', 'status' => 'nullable|string|max:60', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $type = $f['type'] ?? 'placements';
        $title = ReportService::TYPES[$type];
        $format = $f['format'] ?? 'html';
        $q = $service->query($request->user(), $type, $f);
        if ($format === 'html') {
            $page = $q->paginate(25)->withQueryString();
            $rows = $page->getCollection()->map(fn ($r) => $service->present($r))->all();
            $scope = app(LogbookAccess::class)->placements($request->user())->select('placements.id');
            $choices = DB::table('placements')->whereIn('placements.id', $scope)
                ->join('participants as p', 'p.id', '=', 'placements.participant_id')->join('institutions as i', 'i.id', '=', 'placements.institution_id')->join('departments as d', 'd.id', '=', 'placements.department_id')
                ->orderByDesc('placements.id')->limit(500)->get(['placements.ulid', 'placements.participant_id', 'placements.institution_id', 'placements.department_id', 'p.name', 'i.name as institution_name', 'd.name as department_name', 'placements.start_date']);

            return view('reports.index', compact('rows', 'page', 'f', 'type', 'title', 'choices'));
        }
        $limit = $format === 'pdf' ? 500 : 5000;
        $data = $q->limit($limit + 1)->get();
        abort_if($data->count() > $limit, 422, 'Laporan terlalu besar. Persempit filter (maksimal '.$limit.' baris).');
        $rows = $data->map(fn ($r) => $service->present($r))->all();
        $bytes = $format === 'pdf' ? $exporter->pdf($title, $rows) : $exporter->xlsx($rows ? [array_keys($rows[0]), ...array_map('array_values', $rows)] : [['Tidak ada data']]);
        app(AuditLogger::class)->log('reports.exported', 'report', $type, newValues: ['format' => $format, 'rows' => count($rows), 'filters' => $f, 'sha256' => hash('sha256', $bytes)]);

        return response($bytes, 200, ['Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="sikordik-'.$type.'.'.$format.'"']);
    }
}
