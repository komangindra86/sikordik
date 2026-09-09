<?php

use App\Http\Controllers\AdmissionsController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
    Route::get('/lupa-kata-sandi', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/lupa-kata-sandi', [PasswordResetLinkController::class, 'store'])->middleware('throttle:3,1')->name('password.email');
    Route::get('/reset-kata-sandi/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-kata-sandi', [NewPasswordController::class, 'store'])->name('password.update');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::prefix('penerimaan')->name('admissions.')->controller(AdmissionsController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/peserta', 'participants')->name('participants');
        Route::post('/peserta/pratinjau', 'previewParticipant')->name('participant-preview');
        Route::post('/peserta', 'storeParticipant')->name('participant-store');
        Route::post('/peserta/{ulid}/aktivasi', 'activate')->name('activate');
        Route::get('/peserta/{ulid}/ubah', 'editParticipant')->name('participant-edit');
        Route::post('/peserta/{ulid}/ubah', 'updateParticipant')->name('participant-update');
        Route::get('/surat', 'letters')->name('letters');
        Route::post('/surat', 'storeLetter')->name('letter-store');
        Route::get('/impor', 'imports')->name('imports');
        Route::post('/impor', 'previewImport')->name('import-preview');
        Route::get('/impor/{ulid}', 'showImport')->name('import');
        Route::post('/impor/{ulid}', 'commitImport')->name('import-commit');
        Route::get('/persyaratan', 'templates')->name('templates');
        Route::post('/persyaratan', 'storeTemplate')->name('template-store');
        Route::post('/persyaratan/{id}/nonaktif', 'disableTemplate')->whereNumber('id')->name('template-disable');
        Route::get('/penempatan/tambah', 'create')->name('create');
        Route::post('/penempatan', 'store')->name('store');
        Route::get('/penempatan/{ulid}', 'show')->name('show');
        Route::post('/penempatan/{ulid}/status', 'transition')->name('transition');
        Route::post('/penempatan/{ulid}/periode', 'period')->name('period');
        Route::post('/penempatan/{ulid}/pengecualian', 'requestOverlap')->name('exception');
        Route::post('/pengecualian/{ulid}/keputusan', 'decideException')->name('exception-decide');
        Route::post('/penempatan/{ulid}/dokumen', 'review')->name('review');
        Route::post('/berkas/{resource}/{ulid}', 'upload')->middleware('throttle:20,1')->name('upload');
        Route::get('/berkas/{ulid}', 'download')->name('download');
    });
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->middleware('permission:dashboard.view')->name('dashboard');

    Route::get('/pengguna', [UserController::class, 'index'])->middleware('permission:users.view')->name('users.index');
    Route::get('/pengguna/tambah', [UserController::class, 'create'])->middleware('permission:users.create')->name('users.create');
    Route::post('/pengguna', [UserController::class, 'store'])->middleware(['permission:users.create', 'permission:roles.assign'])->name('users.store');
    Route::get('/pengguna/{user}/ubah', [UserController::class, 'edit'])->whereNumber('user')->middleware('permission:users.update')->name('users.edit');
    Route::put('/pengguna/{user}', [UserController::class, 'update'])->whereNumber('user')->middleware(['permission:users.update', 'permission:roles.assign'])->name('users.update');
    Route::patch('/pengguna/{user}/status', [UserController::class, 'status'])->whereNumber('user')->middleware('permission:users.status')->name('users.status');

    Route::get('/role', [RoleController::class, 'index'])->middleware('permission:roles.manage')->name('roles.index');
    Route::get('/role/tambah', [RoleController::class, 'create'])->middleware('permission:roles.manage')->name('roles.create');
    Route::post('/role', [RoleController::class, 'store'])->middleware('permission:roles.manage')->name('roles.store');
    Route::get('/role/{role}/ubah', [RoleController::class, 'edit'])->whereNumber('role')->middleware('permission:roles.manage')->name('roles.edit');
    Route::put('/role/{role}', [RoleController::class, 'update'])->whereNumber('role')->middleware('permission:roles.manage')->name('roles.update');

    Route::get('/master/{master}', [MasterDataController::class, 'index'])->middleware('permission:masters.view')->name('masters.index');
    Route::get('/master/{master}/tambah', [MasterDataController::class, 'create'])->middleware('permission:masters.create')->name('masters.create');
    Route::post('/master/{master}', [MasterDataController::class, 'store'])->middleware('permission:masters.create')->name('masters.store');
    Route::get('/master/{master}/{id}/ubah', [MasterDataController::class, 'edit'])->whereNumber('id')->middleware('permission:masters.update')->name('masters.edit');
    Route::put('/master/{master}/{id}', [MasterDataController::class, 'update'])->whereNumber('id')->middleware('permission:masters.update')->name('masters.update');
    Route::patch('/master/{master}/{id}/status', [MasterDataController::class, 'status'])->whereNumber('id')->middleware('permission:masters.status')->name('masters.status');

    Route::get('/audit-log', AuditLogController::class)->middleware('permission:audit-logs.view')->name('audit-logs.index');
});
