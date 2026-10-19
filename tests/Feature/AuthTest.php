<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laranex\LaravelSchemaViewer\Http\Middleware\Authorize;
use Laranex\LaravelSchemaViewer\SchemaViewer;
use Laranex\LaravelSchemaViewer\Viewer\ViewerAssets;

it('is open in the local environment to requests from this machine to this machine', function (string $address, string $host): void {
    $this->rebootWith('local');

    // The Host header is the URL's host, as a browser sends it.
    $this->withServerVariables(['REMOTE_ADDR' => $address])->get("http://{$host}/schema-viewer")->assertOk();
})->with([
    'localhost' => ['127.0.0.1', 'localhost'],
    'localhost with a port' => ['127.0.0.1', 'localhost:8000'],
    'a .localhost name' => ['127.0.0.1', 'shop.localhost'],
    '127.0.0.1' => ['127.0.0.1', '127.0.0.1:8000'],
    'another loopback address' => ['127.0.1.1', '127.0.1.1'],
    'IPv6' => ['::1', '[::1]:8000'],
    'IPv4 as IPv6' => ['::ffff:127.0.0.1', 'localhost'],
]);

it('closes the local environment to every other request unless the gate allows it', function (string $address, string $host): void {
    $this->rebootWith('local');

    $this->withServerVariables(['REMOTE_ADDR' => $address])->get("http://{$host}/schema-viewer")
        ->assertForbidden()
        ->assertHeader('Cache-Control', 'no-store, private');

    Gate::define('viewSchema', fn (?User $user): bool => true);

    $this->withServerVariables(['REMOTE_ADDR' => $address])->get("http://{$host}/schema-viewer")->assertOk();
})->with([
    'a remote address' => ['203.0.113.7', 'localhost'],
    'a spoofed loopback Host from a remote address' => ['203.0.113.7', '127.0.0.1'],
    'a LAN address' => ['192.168.1.20', '192.168.1.10:8000'],
    'a tunnel on this machine' => ['127.0.0.1', 'shop.ngrok.app'],
    'a public name on this machine' => ['127.0.0.1', 'schema.example.com'],
    'a name that only starts with localhost' => ['127.0.0.1', 'localhost.example.com'],
    'IPv6, remote' => ['2001:db8::1', '[::1]'],
]);

it('ignores proxy headers when deciding what is local', function (): void {
    $this->rebootWith('local');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1', 'HTTP_X_FORWARDED_HOST' => 'localhost'])
        ->get('/schema-viewer')
        ->assertForbidden();
});

it('denies a viewer enabled in production while the application defines no gate', function (): void {
    $this->rebootWith('production', ['enabled' => true]);
    buildSnapshot();

    $this->get('/schema-viewer')->assertForbidden();
    $this->actingAs((new User)->forceFill(['id' => 1]))->get('/schema-viewer')->assertForbidden();
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
    'viewer' => fn (): string => '/schema-viewer',
    'asset' => fn (): string => '/schema-viewer/assets/'.(new ViewerAssets)->version().'/app.js',
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
