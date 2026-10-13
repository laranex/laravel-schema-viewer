<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laranex\LaravelSchemaViewer\Http\Middleware\Authorize;
use Laranex\LaravelSchemaViewer\SchemaViewer;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

it('is open to everyone in the local environment by default', function (): void {
    $this->rebootWith('local');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->get('/schema-viewer')->assertOk();
});

it('needs the viewSchema gate elsewhere by default', function (): void {
    $this->rebootWith('production', ['enabled' => true]);

    $this->get('/schema-viewer')->assertForbidden();

    Gate::define('viewSchema', fn (?User $user): bool => true);

    $this->get('/schema-viewer')->assertOk();
});

it('lets the auth callback close the viewer in the local environment', function (): void {
    $this->rebootWith('local');
    SchemaViewer::auth(fn (Request $request): bool => false);

    $this->get('/schema-viewer')->assertForbidden();
});

it('lets the auth callback open the viewer without the gate elsewhere', function (): void {
    $this->rebootWith('production', ['enabled' => true]);
    SchemaViewer::auth(fn (Request $request): bool => $request->header('X-Team') === 'data');

    $this->get('/schema-viewer')->assertForbidden();
    $this->withHeaders(['X-Team' => 'data'])->get('/schema-viewer')->assertOk();
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

it('opens in the local environment on APP_URL\'s host and on this machine\'s names only', function (string $url, int $status): void {
    $this->rebootWith('local');
    config()->set('app.url', 'http://shop.test');

    $this->get($url)->assertStatus($status);
})->with([
    'APP_URL' => ['http://shop.test/schema-viewer', 200],
    'APP_URL in capitals' => ['http://SHOP.test/schema-viewer', 200],
    'localhost' => ['http://localhost:8000/schema-viewer', 200],
    '127.0.0.1' => ['http://127.0.0.1:8000/schema-viewer', 200],
    '::1' => ['http://[::1]:8000/schema-viewer', 200],
    'a page rebinding its own name to this machine' => ['http://attacker.example/schema-viewer', 403],
    'a tunnel' => ['https://abc123.sharedwithexpose.com/schema-viewer', 403],
    'ngrok' => ['https://1a2b-203-0-113-7.ngrok-free.app/schema-viewer', 403],
    'another name of the app' => ['http://shop.test.attacker.example/schema-viewer', 403],
]);

it('takes APP_URL\'s host whatever its port and path', function (): void {
    $this->rebootWith('local');
    config()->set('app.url', 'https://shop.test:8443/store');

    $this->get('https://shop.test:8443/schema-viewer')->assertOk();
    $this->get('http://shop.test/schema-viewer')->assertOk();
    $this->get('http://other.test/schema-viewer')->assertForbidden();
});

it('says how to open the viewer when a local host is refused', function (): void {
    $this->rebootWith('local');

    $this->withoutExceptionHandling();

    expect(fn () => $this->get('http://attacker.example/schema-viewer'))
        ->toThrow(AccessDeniedHttpException::class, 'Open the schema viewer through APP_URL, localhost or 127.0.0.1, or decide who may open it with SchemaViewer::auth().');
});

it('lets the auth callback replace the host rule', function (): void {
    $this->rebootWith('local');
    SchemaViewer::auth(fn (Request $request): bool => true);

    $this->get('http://abc123.sharedwithexpose.com/schema-viewer')->assertOk();
});

it('checks no host outside the local environment, only the gate', function (): void {
    $this->rebootWith('production', ['enabled' => true]);
    Gate::define('viewSchema', fn (?User $user): bool => true);

    $this->get('http://anything.example/schema-viewer')->assertOk();
});
