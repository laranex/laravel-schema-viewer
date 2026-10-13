---
name: laravel-schema-viewer
description: >
  Install, configure and share laranex/laravel-schema-viewer in a Laravel app: who may open the viewer, which connections and models it reads, editor links, caching, accepting hints with hints.ignore, failing CI with schema-viewer:check, and exporting a standalone HTML viewer. To read the schema itself before changing the database, use the laravel-schema-viewer-schema skill.
license: MIT
metadata:
  author: Nay Thu Khant
---

# Laravel Schema Viewer

## When to use

Use this skill to add `laranex/laravel-schema-viewer` to a Laravel app, change its settings, open it to a team or a server, accept or enforce its hints, or publish the viewer as one HTML file. To learn the app's tables and relations before writing a migration, a model or a query, use the `laravel-schema-viewer-schema` skill instead.

## Install

```bash
composer require laranex/laravel-schema-viewer --dev
```

Requires PHP 8.1+ and Laravel 10.36 to 13. The service provider registers itself; open `/schema-viewer` in the local environment. Install it as a dev dependency unless the team wants the viewer on a server.

## Configure

Every setting has a default, so most apps configure nothing. Publish `config/schema-viewer.php` with `php artisan vendor:publish --tag="laravel-schema-viewer-config"` only to change:

- `enabled` (`SCHEMA_VIEWER_ENABLED`): unset means on in `local`, off elsewhere.
- `path` (`SCHEMA_VIEWER_PATH`, default `schema-viewer`), `domain`, `middleware` (default `['web']`).
- `connections`: null reads the default connection plus every connection a model uses; a list reads exactly those.
- `default_connection` (`SCHEMA_VIEWER_DEFAULT_CONNECTION`): what the viewer opens on, `all` (every connection on one diagram) or one connection's name.
- `editor` (`SCHEMA_VIEWER_EDITOR`): `phpstorm`, `vscode`, `cursor`, `zed` or another name Laravel's `app.editor` takes, or `['href' => '…{file}…{line}']`, plus `base_path` when the editor sees the project elsewhere (Docker, WSL); null uses `app.editor` in `local`. Links with a `javascript:`, `data:` or similar scheme are dropped. Models and relations in the viewer then open at their line.
- `cache.enabled` (`SCHEMA_VIEWER_CACHE`, default `true`), `cache.store` (`SCHEMA_VIEWER_CACHE_STORE`, null for the default store), `cache.ttl` (seconds, default 3600): the viewer keeps the schema it read until a model file, this config, a connection's migrations or an SQLite file changes. After altering a table by hand, use the viewer's refresh button (`Shift R`) or `/schema-viewer/api/schema?refresh=1`. The artisan commands always read fresh.
- `models`: folders scanned for models; null scans `app/Models`.
- `exclude_tables` (never shown) and `framework_tables` (shown, tagged, no hints); wildcards allowed. `package_tables` (default `true`) tags the tables of installed Passport, spatie/laravel-permission and Filament Breezy with their package; they still get hints.
- `hints.ignore`: a list of rules; a hint is left out when it matches every field a rule names (`connection` and `table` take wildcards, plus `kind`, `columns`, `relation`), e.g. `['kind' => 'morph_without_map']`, `['connection' => 'mysql', 'table' => 'audit_logs']`, `['table' => 'oauth_*']`, `['connection' => 'mysql', 'table' => 'comments', 'kind' => 'relation_without_foreign_key', 'columns' => 'user_id']`. Each hint in the JSON export carries its rules in `ignore` (`hint`, `kindOnTable`, `table`, `kind`), and `schema-viewer:check` prints the narrowest one; copy those rather than writing rules by hand. Unknown fields or kinds are rejected.
- `hints.fail_on`: kinds that fail `schema-viewer:check`.

Outside `local`, the viewer needs a gate:

```php
Gate::define('viewSchema', fn ($user) => $user->isAdmin());
```

In `local` there is no gate: anyone who can reach the app opens the viewer. Set `SCHEMA_VIEWER_ENABLED=false` before sharing a local app with Herd Share, Expose or ngrok.

## Use

### Enforce the hints in CI

```bash
php artisan schema-viewer:check
php artisan schema-viewer:check --fail-on=foreign_key_without_index --fail-on=unresolved_relation
```

The command lists every hint and exits non-zero when one of a `hints.fail_on` kind remains (or of the kinds passed with `--fail-on`). Under each hint it prints the narrowest `hints.ignore` rule that leaves it out; add that rule to the config when the team accepts the hint, rather than lowering `fail_on`.

### Share the viewer without the app

```bash
php artisan schema-viewer:export --format=html --output=build/schema-viewer
```

Writes `build/schema-viewer/index.html` (or the `.html` file you name): the whole viewer with the schema and the downloads inlined. It opens from disk or any static host without the Laravel app, its database or its API, so it suits a pull request artifact, CI pages or a teammate without a database. It shows the schema as it was when exported; export again after migrations. Host it only where the schema may be seen.

### Open it on a server

Set `SCHEMA_VIEWER_ENABLED=true` and define the `viewSchema` gate (above); without the gate the viewer answers 403 outside `local`. Use `path` or `domain` to move it, and `middleware` to add your own (the gate is always added).

## Test your app

The package reads schema only and never queries rows. In a feature test, assert on the document:

```php
$document = app(\Laranex\LaravelSchemaViewer\SchemaViewer::class)->document();

expect(collect($document->hints)->pluck('kind'))->not->toContain(\Laranex\LaravelSchemaViewer\Schema\HintKind::ModelWithoutTable);
```

## Avoid

- Enabling the viewer in production without the `viewSchema` gate; schema details help attackers.
- Sharing a local app (Herd Share, Expose, ngrok) with the viewer on: in `local`, anyone who reaches the app can open it.
- Writing `hints.ignore` rules by hand: copy the rule `schema-viewer:check` prints or the JSON export's `ignore` field.
- Ignoring `relation_without_foreign_key` on a regular `belongsTo`: add the foreign key unless the relation crosses connections.
- Publishing an HTML export where the schema should not be seen.
