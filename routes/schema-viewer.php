<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Laranex\LaravelSchemaViewer\Http\Controllers\AssetController;
use Laranex\LaravelSchemaViewer\Http\Controllers\ViewerController;
use Laranex\LaravelSchemaViewer\Http\Middleware\Authorize;

$middleware = Config::get('schema-viewer.middleware', ['web']);

Route::group([
    'domain' => Config::get('schema-viewer.domain'),
    'prefix' => Config::get('schema-viewer.path', 'schema-viewer'),
    'middleware' => [...(is_array($middleware) ? $middleware : [$middleware]), Authorize::class],
    'as' => 'schema-viewer.',
], function (): void {
    Route::get('/', ViewerController::class)->name('index');
    Route::get('assets/{version}/{file}', AssetController::class)->where(['version' => '[0-9a-f]{12}', 'file' => '[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)*'])->name('asset');
});
