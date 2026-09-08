<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureDepartmentScope;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\PreventSensitiveCaching;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [PreventSensitiveCaching::class]);
        $middleware->alias([
            'active' => EnsureActiveUser::class,
            'permission' => EnsurePermission::class,
            'department.scope' => EnsureDepartmentScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
