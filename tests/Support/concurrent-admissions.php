<?php

use App\Models\User;
use App\Services\AttendanceService;
use App\Services\ParticipantService;
use App\Services\PlacementService;
use App\Services\ScheduleService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Dedicated subprocess for real MySQL locking tests. Never reads fixture credentials from argv.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (($input['connection']['driver'] ?? '') !== 'mysql' || ! str_ends_with($input['connection']['database'] ?? '', '_test') || ! in_array($input['connection']['host'] ?? '', ['127.0.0.1', 'localhost'], true) || ! empty($input['connection']['url'])) {
    exit(2);
}
putenv('APP_ENV=testing');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'mysql', 'database.connections.mysql' => $input['connection']]);
DB::purge('mysql');
$actor = User::findOrFail($input['actor']);
Auth::login($actor);
echo "READY\n";
flush();
try {
    if ($input['action'] === 'submit') {
        app(PlacementService::class)->transition($actor, $input['ulid'], 'submit', 'draft', 1, null);
    } elseif ($input['action'] === 'schedule') {
        Carbon::setTestNow('2026-09-09 10:00:00');
        app(ScheduleService::class)->transition($actor, $input['ulid'], $input['payload']);
    } elseif (in_array($input['action'], ['attendance-save', 'attendance-decide', 'attendance-summary'])) {
        Carbon::setTestNow('2026-10-11 10:00:00');
        $method = substr($input['action'], strlen('attendance-'));
        app(AttendanceService::class)->$method($actor, $input['ulid'], $input['payload']);
    } else {
        app(ParticipantService::class)->create($actor, $input['payload']);
    }
    echo "ACCEPTED\n";
} catch (ValidationException) {
    echo "REJECTED\n";
} catch (HttpException $e) {
    if ($e->getStatusCode() !== 422) {
        throw $e;
    }
    echo "REJECTED\n";
}
