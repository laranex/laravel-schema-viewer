<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laranex\LaravelSchemaViewer\Http\Middleware\Authorize;
use Laranex\LaravelSchemaViewer\SchemaViewer;

it('is open to everyone in the local environment by default', function (): void {
    $this->rebootWith('local');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->getJson('/schema-viewer/api/schema')->assertOk();
});

it('needs the viewSchema gate elsewhere by default', function (): void {
    $this->rebootWith('production', ['enabled' => true]);

    $this->getJson('/schema-viewer/api/schema')->assertForbidden();

    Gate::define('viewSchema', fn (?User $user): bool => true);

    $this->getJson('/schema-viewer/api/schema')->assertOk();
});

it('lets the auth callback close the viewer in the local environment', function (): void {
    $this->rebootWith('local');
    SchemaViewer::auth(fn (Request $request): bool => false);

    $this->getJson('/schema-viewer/api/schema')->assertForbidden();
});

it('lets the auth callback open the viewer without the gate elsewhere', function (): void {
    $this->rebootWith('production', ['enabled' => true]);
    SchemaViewer::auth(fn (Request $request): bool => $request->header('X-Team') === 'data');

    $this->getJson('/schema-viewer/api/schema')->assertForbidden();
    $this->withHeaders(['X-Team' => 'data'])->getJson('/schema-viewer/api/schema')->assertOk();
});

it('asks the auth callback on every route with the request', function (string $uri): void {
    $this->rebootWith('local');
    $seen = [];
    SchemaViewer::auth(function (Request $request) use (&$seen): bool {
        $seen[] = $request->path();

        return false;
    });

    $this->get($uri)->assertForbidden();

    expect($seen)->toBe([ltrim((string) parse_url($uri, PHP_URL_PATH), '/')]);
})->with([
    'viewer' => '/schema-viewer',
    'schema' => '/schema-viewer/api/schema',
    'export' => '/schema-viewer/api/export?format=dbml',
    'asset' => '/schema-viewer/assets/app.js',
]);

it('lets an exception in the auth callback through instead of opening the viewer', function (): void {
    $this->rebootWith('local');
    SchemaViewer::auth(fn (Request $request): bool => throw new RuntimeException('auth failed'));

    $this->withoutExceptionHandling();

    expect(fn () => $this->get('/schema-viewer'))->toThrow(RuntimeException::class, 'auth failed');
});

it('keeps Authorize::allows() answering as SchemaViewer::check() does', function (): void {
    $this->rebootWith('local');
    $request = Request::create('/schema-viewer');

    expect(Authorize::allows(app(), $request))->toBeTrue()
        ->and(SchemaViewer::check($request))->toBeTrue();

    SchemaViewer::auth(fn (Request $request): bool => false);

    expect(Authorize::allows(app(), $request))->toBeFalse()
        ->and(SchemaViewer::check($request))->toBeFalse();
});
