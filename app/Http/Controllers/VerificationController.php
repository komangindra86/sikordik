<?php

namespace App\Http\Controllers;

use App\Services\VerificationService;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\Request;

class VerificationController extends Controller
{
    public function index(Request $request, VerificationService $service)
    {
        $f = $request->validate(['placement' => 'nullable|ulid']);
        $entries = empty($f['placement']) ? [] : $service->entries($request->user(), $f['placement']);

        return view('verification.index', compact('entries', 'f'));
    }

    public function show(Request $request, VerificationService $service, string $type, int $id)
    {
        $document = $service->verify($request->user(), $type, $id);
        // Configured canonical URL avoids encoding an untrusted Host header.
        $url = rtrim(config('app.url'), '/').route('verification.show', compact('type', 'id'), false);
        $qr = (new QRCode(new QROptions(['outputBase64' => true, 'outputType' => QRCode::OUTPUT_IMAGE_PNG])))->render($url);

        return view('verification.show', compact('document', 'qr', 'url'));
    }
}
