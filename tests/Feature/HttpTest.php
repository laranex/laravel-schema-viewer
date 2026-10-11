<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    $this->layoutPath = sys_get_temp_dir().'/schema-viewer-test-'.uniqid().'.json';
    $this->rebootWith('local', ['layout_path' => $this->layoutPath]);
});

afterEach(function (): void {
    @unlink($this->layoutPath);
});

it('is open in the local environment', function (): void {
    $this->get('/schema-viewer')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8')
        ->assertSee('window.__SCHEMA_VIEWER__', false)
        ->assertSee('/schema-viewer/api/schema', false);
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

it('saves and reads the shared layout file', function (): void {
    $this->getJson('/schema-viewer/api/layout')->assertOk()->assertJsonPath('version', 1);

    $this->withSession(['_token' => 'csrf'])->putJson('/schema-viewer/api/layout', [
        'connection' => 'testing',
        'positions' => ['users' => ['x' => 10.4, 'y' => 20], 'posts' => ['x' => 300, 'y' => 'nope']],
    ], ['X-CSRF-TOKEN' => 'csrf'])->assertOk()->assertJsonPath('saved', 1);

    expect(json_decode((string) file_get_contents($this->layoutPath), true))
        ->toBe(['version' => 1, 'connections' => ['testing' => ['users' => ['x' => 10, 'y' => 20]]]]);

    $this->getJson('/schema-viewer/api/layout')->assertJsonPath('connections.testing.users.x', 10);

    $this->withSession(['_token' => 'csrf'])->putJson('/schema-viewer/api/layout', ['positions' => []], ['X-CSRF-TOKEN' => 'csrf'])->assertStatus(422);
});

it('saves the layout only in the local environment', function (): void {
    $this->rebootWith('staging', ['enabled' => true, 'layout_path' => $this->layoutPath]);
    Gate::define('viewSchema', fn (?User $user): bool => true);

    $this->get('/schema-viewer')->assertOk()->assertSee('"canSaveLayout":false', false);
    $this->withSession(['_token' => 'csrf'])->putJson('/schema-viewer/api/layout', ['connection' => 'testing', 'positions' => []], ['X-CSRF-TOKEN' => 'csrf'])->assertForbidden();

    expect($this->layoutPath)->not->toBeFile();
});

it('refuses a layout save without the CSRF token', function (): void {
    $this->putJson('/schema-viewer/api/layout', ['connection' => 'testing', 'positions' => []])->assertStatus(419);
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
