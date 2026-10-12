<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Laranex\LaravelSchemaViewer\SchemaViewer;

beforeEach(function (): void {
    $this->rebootWith('local');
});

it('is open in the local environment', function (): void {
    $this->get('/schema-viewer')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8')
        ->assertSee('window.__SCHEMA_VIEWER__', false)
        ->assertSee('/schema-viewer/api/schema', false);
});

it('tells the page which connection to open on', function (): void {
    $config = function (): array {
        preg_match('/window\.__SCHEMA_VIEWER__ = (.+?);<\/script>/', (string) $this->get('/schema-viewer')->getContent(), $match);

        return json_decode($match[1], true);
    };

    expect($config()['defaultConnection'])->toBe('all');

    config()->set('schema-viewer.default_connection', 'testing');
    expect($config()['defaultConnection'])->toBe('testing');

    config()->set('schema-viewer.default_connection', '');
    expect($config()['defaultConnection'])->toBe('all');
});

it('tells the page how to open files in the editor', function (): void {
    $editor = function (): mixed {
        preg_match('/window\.__SCHEMA_VIEWER__ = (.+?);<\/script>/', (string) $this->get('/schema-viewer')->getContent(), $match);

        return json_decode($match[1], true)['editor'];
    };
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
    $editor = function (): mixed {
        preg_match('/window\.__SCHEMA_VIEWER__ = (.+?);<\/script>/', (string) $this->get('/schema-viewer')->assertOk()->getContent(), $match);

        return json_decode($match[1], true)['editor'];
    };

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

it('serves the schema document', function (): void {
    $this->getJson('/schema-viewer/api/schema')
        ->assertOk()
        ->assertJsonPath('version', 1)
        ->assertJsonPath('connections.0.name', 'testing')
        ->assertJsonStructure(['connections', 'models', 'relations', 'links', 'derivedLinks', 'hints']);
});

it('serves the built assets and nothing else', function (): void {
    $this->get('/schema-viewer/assets/app.js')->assertOk()->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
    $this->get('/schema-viewer/assets/app.css')->assertOk();

    $font = basename((string) glob(__DIR__.'/../../dist/assets/*.woff2')[0]);
    $this->get('/schema-viewer/assets/assets/'.$font)->assertOk()->assertHeader('Content-Type', 'font/woff2');
    $this->get('/schema-viewer/assets/index.html')->assertNotFound();
    $this->get('/schema-viewer/assets/missing.js')->assertNotFound();
    $this->get('/schema-viewer/assets/..%2Fcomposer.json')->assertNotFound();
});

it('can live on another path', function (): void {
    $this->rebootWith('local', ['path' => 'internal/schema']);

    $this->get('/internal/schema')->assertOk();
    $this->get('/schema-viewer')->assertNotFound();
});

it('downloads the database schema as Mermaid, DBML, Markdown or JSON', function (): void {
    expect($this->get('/schema-viewer/api/export?format=mermaid&connection=testing')
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="schema-testing.mmd"')
        ->getContent())->toStartWith('erDiagram');

    $this->get('/schema-viewer/api/export?format=dbml')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="schema-testing.dbml"');
    $this->get('/schema-viewer/api/export?format=markdown')->assertOk()->assertHeader('Content-Type', 'text/markdown; charset=utf-8');
    $this->get('/schema-viewer/api/export?format=json')
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="schema-testing.json"')
        ->assertJsonPath('name', 'testing')
        ->assertJsonMissingPath('models');
    $this->get('/schema-viewer/api/export?format=png')->assertStatus(422);
    $this->get('/schema-viewer/api/export?format=dbml&connection=nope')->assertNotFound();
});

it('rejects a format or connection given as a list', function (): void {
    $this->getJson('/schema-viewer/api/export?format[]=dbml')->assertStatus(422)->assertJsonPath('message', 'Use format mermaid, dbml, markdown or json.');
    $this->getJson('/schema-viewer/api/export?format=dbml&connection[]=testing')->assertStatus(422)->assertJsonPath('message', 'Name one connection.');
});

it('gives its scripts the CSP nonce the application set for Vite', function (): void {
    expect($this->get('/schema-viewer')->getContent())->not->toContain('nonce=');

    Vite::useCspNonce('n0nce-"x');

    $html = (string) $this->get('/schema-viewer')->getContent();

    expect(substr_count($html, 'nonce="n0nce-&quot;x"'))->toBe(3)
        ->and($html)->toContain('<script nonce="n0nce-&quot;x">window.__SCHEMA_VIEWER__');
});
