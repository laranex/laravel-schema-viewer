<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;

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

it('downloads the database schema as Mermaid, DBML or Markdown', function (): void {
    expect($this->get('/schema-viewer/api/export?format=mermaid&connection=testing')
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="schema-testing.mmd"')
        ->getContent())->toStartWith('erDiagram');

    $this->get('/schema-viewer/api/export?format=dbml')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="schema-testing.dbml"');
    $this->get('/schema-viewer/api/export?format=markdown')->assertOk()->assertHeader('Content-Type', 'text/markdown; charset=utf-8');
    $this->get('/schema-viewer/api/export?format=json')->assertStatus(422);
    $this->get('/schema-viewer/api/export?format=dbml&connection=nope')->assertNotFound();
});
