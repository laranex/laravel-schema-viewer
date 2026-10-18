<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Vite;
use Laranex\LaravelSchemaViewer\Http\Controllers\ViewerController;
use Laranex\LaravelSchemaViewer\SchemaViewer;
use Laranex\LaravelSchemaViewer\Viewer\ViewerAssets;

beforeEach(function (): void {
    $this->rebootWith('local');
});

/**
 * The URL of one of the shared viewer's files, as the page links it.
 */
function assetUrl(string $file): string
{
    return '/schema-viewer/assets/'.(new ViewerAssets)->version().'/'.$file;
}

it('is open in the local environment', function (): void {
    $this->get('/schema-viewer')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Content-Security-Policy', ViewerController::POLICY)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertSee('<script type="application/json" id="schema-viewer-data">', false);
});

it('serves the shared viewer under a strict policy, with no inline script', function (): void {
    buildSnapshot();
    $html = (string) $this->get('/schema-viewer')->getContent();

    expect(ViewerController::POLICY)->toBe("default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'")
        ->and($html)->toContain('<script type="module" src="http://localhost'.assetUrl('app.js').'"></script>')
        ->and($html)->toContain('<link rel="stylesheet" href="http://localhost'.assetUrl('app.css').'">')
        ->and($html)->toContain('<link rel="icon" href="http://localhost'.assetUrl('favicon.svg').'" type="image/svg+xml">')
        ->and($html)->toContain('<div id="schema-viewer"></div>')
        // Every script is a file of the bundle or data: nothing the policy would have to allow inline.
        ->and(preg_match_all('/<script(?![^>]*(\bsrc=|type="application\/json"))/', $html))->toBe(0);
});

it('tells the page which connection to open on, by id', function (): void {
    expect(viewerConfig($this)['defaultConnection'])->toBeNull();

    config()->set('schema-viewer.default_connection', 'testing');
    expect(viewerConfig($this)['defaultConnection'])->toBe('connection:testing');

    config()->set('schema-viewer.default_connection', '');
    expect(viewerConfig($this)['defaultConnection'])->toBeNull();
});

it('tells the page how to open files in the editor', function (): void {
    $editor = fn (): mixed => viewerConfig($this)['editor'];
    $root = rtrim(str_replace('\\', '/', base_path()), '/');

    expect($editor())->toBeNull();

    config()->set('app.editor', 'phpstorm');
    expect($editor())->toBe("phpstorm://open?file={$root}/{file}&line={line}");

    config()->set('schema-viewer.editor', ['name' => 'vscode', 'base_path' => '/var/www/html/']);
    expect($editor())->toBe('vscode://file//var/www/html/{file}:{line}');

    config()->set('schema-viewer.editor', ['href' => 'myeditor://{file}#{line}']);
    expect($editor())->toBe("myeditor://{$root}/{file}#{line}");

    config()->set('schema-viewer.editor', 'nano');
    expect($editor())->toBe("nano://open?file={$root}/{file}&line={line}");
});

it('drops editor links that would run code or embed content', function (string $href): void {
    config()->set('schema-viewer.editor', ['href' => $href]);

    expect(app(SchemaViewer::class)->editorHref())->toBeNull();
})->with([
    'javascript:alert(1)//{file}',
    ' JavaScript:alert(1)//{file}',
    "java\tscript:alert(1)//{file}",
    'data:text/html,<script>alert(1)</script>{file}',
    'vbscript:msgbox(1)//{file}',
    'no-scheme/{file}',
]);

it('drops editor names that are not schemes', function (): void {
    config()->set('schema-viewer.editor', 'javascript');
    expect(app(SchemaViewer::class)->editorHref())->toBeNull();

    config()->set('schema-viewer.editor', 'bad name:');
    expect(app(SchemaViewer::class)->editorHref())->toBeNull();
});

it('reads app.editor only in the local environment, so the app path stays private elsewhere', function (): void {
    $this->rebootWith('production', ['enabled' => true]);
    Gate::define('viewSchema', fn (?User $user): bool => true);
    $editor = fn (): mixed => viewerConfig($this)['editor'];

    config()->set('app.editor', 'phpstorm');
    expect($editor())->toBeNull();

    config()->set('schema-viewer.editor', ['name' => 'vscode', 'base_path' => '/srv/app']);
    expect($editor())->toBe('vscode://file//srv/app/{file}:{line}');
});

it('is off outside the local environment unless enabled', function (): void {
    $this->rebootWith('production');

    $this->get('/schema-viewer')->assertNotFound();
});

it('needs the viewSchema gate when enabled outside the local environment', function (): void {
    $this->rebootWith('production', ['enabled' => true]);

    $this->get('/schema-viewer')->assertForbidden();

    Gate::define('viewSchema', fn (?User $user): bool => $user?->getAuthIdentifier() === 1);

    $this->get('/schema-viewer')->assertForbidden();

    $admin = new User;
    $admin->forceFill(['id' => 1]);

    $this->actingAs($admin)->get('/schema-viewer')->assertOk();
});

it('carries the laranex-schema document in the page, without prepared downloads', function (): void {
    buildSnapshot();

    $config = viewerConfig($this);

    expect($config['document'])->toMatchArray(['format' => 'laranex-schema', 'version' => '2.0'])
        ->and($config['document']['connections'][0]['name'])->toBe('testing')
        ->and(array_keys($config))->toBe(['document', 'changes', 'editor', 'defaultConnection']);
});

it('registers the page and its assets only, no API', function (): void {
    $uris = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_starts_with($uri, 'schema-viewer'))->sort()->values()->all();

    expect($uris)->toBe(['schema-viewer', 'schema-viewer/assets/{version}/{file}']);

    $this->get('/schema-viewer/api/schema')->assertNotFound();
    $this->get('/schema-viewer/api/export?format=dbml')->assertNotFound();
});

it('compresses the page with gzip when the browser takes it', function (): void {
    buildSnapshot();

    $plain = $this->get('/schema-viewer')->assertOk()->assertHeader('Vary', 'Accept-Encoding');
    $gzip = $this->get('/schema-viewer', ['Accept-Encoding' => 'gzip, deflate, br'])
        ->assertOk()
        ->assertHeader('Content-Encoding', 'gzip')
        ->assertHeader('Vary', 'Accept-Encoding');

    expect($plain->headers->has('Content-Encoding'))->toBeFalse()
        ->and(gzdecode((string) $gzip->getContent()))->toBe($plain->getContent())
        ->and(strlen((string) $gzip->getContent()))->toBeLessThan(strlen((string) $plain->getContent()));
});

it('keeps the schema from ending the script it is carried in', function (): void {
    $attack = '</script><script>alert(1)</script><!-- & \u{2028}';
    Schema::table('posts', function (Blueprint $table) use ($attack): void {
        $table->string('note')->default($attack);
    });
    buildSnapshot();

    $html = (string) $this->get('/schema-viewer')->getContent();
    $posts = collect(viewerConfig($this)['document']['connections'][0]['tables'])->firstWhere('name', 'posts');

    expect(substr_count($html, '</script>'))->toBe(2)
        ->and($html)->not->toContain('<script>alert(1)')
        ->and($html)->not->toContain('<!--')
        ->and($html)->not->toContain("\u{2028}")
        ->and(collect($posts['columns'])->firstWhere('name', 'note')['default']['value'])->toContain('</script><script>alert(1)</script>');
});

it('serves exactly the files of the shared viewer\'s manifest, under its fingerprint', function (): void {
    $manifest = json_decode((string) file_get_contents(__DIR__.'/../../resources/viewer/manifest.json'), true);

    foreach ($manifest['files'] as $file) {
        if ($file['path'] === 'index.html') {
            continue;
        }

        $response = $this->get(assetUrl($file['path']))->assertOk()
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        expect(hash('sha256', (string) $response->streamedContent()))->toBe($file['sha256']);
    }

    $this->get(assetUrl('app.js'))->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
    $this->get(assetUrl('favicon.ico'))->assertHeader('Content-Type', 'image/x-icon');
    $font = collect($manifest['files'])->pluck('path')->first(fn (string $path): bool => str_ends_with($path, '.woff2'));
    $this->get(assetUrl($font))->assertHeader('Content-Type', 'font/woff2');

    // The viewer's own page, its manifest, files it does not list, and another fingerprint.
    $this->get(assetUrl('index.html'))->assertNotFound();
    $this->get(assetUrl('manifest.json'))->assertNotFound();
    $this->get(assetUrl('LICENSE'))->assertNotFound();
    $this->get(assetUrl('missing.js'))->assertNotFound();
    $this->get(assetUrl('..%2F..%2Fcomposer.json'))->assertNotFound();
    $this->get(assetUrl('assets/../app.js'))->assertNotFound();
    $this->get('/schema-viewer/assets/000000000000/app.js')->assertNotFound();
    $this->get('/schema-viewer/assets/app.js')->assertNotFound();
});

it('refuses a viewer file whose size is not the manifest\'s', function (): void {
    $assets = new ViewerAssets(sys_get_temp_dir().'/schema-viewer-assets-'.bin2hex(random_bytes(4)));
    $root = (new ReflectionProperty($assets, 'root'))->getValue($assets);
    mkdir($root);
    copy(__DIR__.'/../../resources/viewer/manifest.json', $root.'/manifest.json');
    file_put_contents($root.'/app.js', 'truncated');

    expect($assets->path('app.js'))->toBeNull()
        ->and($assets->path('app.css'))->toBeNull()
        ->and(fn () => $assets->contents('app.js'))->toThrow(RuntimeException::class, 'does not match its manifest');
});

it('can live on another path', function (): void {
    $this->rebootWith('local', ['path' => 'internal/schema']);

    $this->get('/internal/schema')->assertOk();
    $this->get('/schema-viewer')->assertNotFound();
});

it('gives its scripts the CSP nonce the application set for Vite', function (): void {
    expect($this->get('/schema-viewer')->getContent())->not->toContain('nonce=');

    Vite::useCspNonce('n0nce-"x');

    $html = (string) $this->get('/schema-viewer')->getContent();

    expect(substr_count($html, 'nonce="n0nce-&quot;x"'))->toBe(2)
        ->and($html)->toContain('src="http://localhost'.assetUrl('app.js').'" nonce="n0nce-&quot;x"></script>');
});
