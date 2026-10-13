---
name: laravel-schema-viewer
description: >
  Install, configure and share laranex/laravel-schema-viewer in a Laravel app: building the schema snapshot with schema-viewer:build, who may open the viewer, which connections and models it reads, editor links, accepting hints with hints.ignore, failing CI with schema-viewer:check, and exporting a standalone HTML viewer. To read the schema itself before changing the database, use the laravel-schema-viewer-schema skill.
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

Requires PHP 8.1+ and Laravel 10.36 to 13. The service provider registers itself. Build the snapshot, then open `/schema-viewer` in the local environment:

```bash
php artisan schema-viewer:build
```

Install it as a dev dependency unless the team wants the viewer on a server.

`schema-viewer:build` reads the database and the models and writes `schema-viewer.json` in the project root, always there (readable JSON: `format`, the schema `document`, each connection's `downloads`). The viewer has no API: its page carries the schema and the downloads from that file, and no model code runs in a web request. Without the file, the page says to run the build. Run it again after migrating or changing a model; on a server, run it in the deploy script after `migrate`. Commit the file only when the repository is private, since it lists every table and column, the models' fillable, hidden, casts and scopes, and source file paths; then every clone and server has the viewer and schema changes show as a diff. Otherwise add `/schema-viewer.json` to `.gitignore` and build it on each machine.

Building constructs every model and calls its relation methods with every connection pretending, model events off, and jobs, mail, notifications, HTTP calls, events and the cache faked; everything is put back afterwards.

## Configure

Every setting has a default, so most apps configure nothing. Publish `config/schema-viewer.php` with `php artisan vendor:publish --tag="laravel-schema-viewer-config"` only to change:

- `enabled` (`SCHEMA_VIEWER_ENABLED`): unset means on in `local`, off elsewhere.
- `path` (`SCHEMA_VIEWER_PATH`, default `schema-viewer`), `domain`, `middleware` (default `['web']`).
- `connections`: null reads the default connection plus every connection a model uses; a list reads exactly those.
- `default_connection` (`SCHEMA_VIEWER_DEFAULT_CONNECTION`): what the viewer opens on, `all` (every connection on one diagram) or one connection's name.
- `editor` (`SCHEMA_VIEWER_EDITOR`): `phpstorm`, `vscode`, `cursor`, `zed` or another name Laravel's `app.editor` takes, or `['href' => '…{file}…{line}']`, plus `base_path` when the editor sees the project elsewhere (Docker, WSL); null uses `app.editor` in `local`. Links with a `javascript:`, `data:` or similar scheme are dropped. Models and relations in the viewer then open at their line.
- `models`: folders scanned for models; null scans `app/Models`.
- `exclude_tables` (never shown) and `framework_tables` (shown, tagged, no hints); wildcards allowed. `package_tables` maps Composer package names to table patterns (default: Laravel's own, Passport `oauth_*`, Cashier, Cashier Paddle, Pennant `features`); while a package is installed its tables are tagged with its name and still get hints. Add third-party packages there, or set `false` to tag nothing.
- `hints.ignore`: a list of rules; a hint is left out when it matches every field a rule names (`connection` and `table` take wildcards, plus `kind`, `columns`, `relation`, and `certainty`: `certain` or `likely`), e.g. `['kind' => 'morph_without_map']`, `['connection' => 'mysql', 'table' => 'audit_logs']`, `['table' => 'oauth_*']`, `['certainty' => 'likely']`, `['connection' => 'mysql', 'table' => 'comments', 'kind' => 'relation_without_foreign_key', 'columns' => 'user_id']`. Each hint in the JSON export carries its rules in `ignore` (`hint`, `kindOnTable`, `table`, `kind`), and `schema-viewer:check` prints the narrowest one; copy those rather than writing rules by hand. Unknown fields, kinds or certainties are rejected.
- `hints.fail_on`: kinds that fail `schema-viewer:check`.

Outside `local`, the viewer needs a gate:

```php
Gate::define('viewSchema', fn ($user) => $user->isAdmin());
```

To decide in every environment, set a callback in a service provider's `boot()`; it replaces both the local default and the gate:

```php
use Illuminate\Http\Request;
use Laranex\LaravelSchemaViewer\SchemaViewer;

SchemaViewer::auth(fn (Request $request): bool => $request->user()?->isAdmin() ?? false);
```

Without a callback, `local` is open to everyone, as with Telescope; elsewhere only users the `viewSchema` gate allows.

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

Writes `build/schema-viewer/index.html` (or the `.html` file you name): the whole viewer with the schema and the downloads inlined. It opens from disk or any static host without the Laravel app or its database, so it suits a pull request artifact, CI pages or a teammate without a database. It shows the schema as it was when exported; export again after migrations. Host it only where the schema may be seen.

### Open it on a server

Set `SCHEMA_VIEWER_ENABLED=true`, define the `viewSchema` gate or `SchemaViewer::auth()` (above), and run `php artisan schema-viewer:build` in the deploy script after `migrate` (or commit `schema-viewer.json`); without the gate the viewer answers 403 outside `local`. Use `path` or `domain` to move it, and `middleware` to add your own (the access check is always added).

## Test your app

The package reads schema only and never queries rows. Migrations in the test environment do not build the snapshot. In a feature test, assert on the document:

```php
$document = app(\Laranex\LaravelSchemaViewer\SchemaViewer::class)->document();

expect(collect($document->hints)->pluck('kind'))->not->toContain(\Laranex\LaravelSchemaViewer\Schema\HintKind::ModelWithoutTable);
```

## Avoid

- Enabling the viewer in production without the `viewSchema` gate or `SchemaViewer::auth()`; schema details help attackers.
- Expecting the viewer to show a table altered by hand or a model just edited: run `php artisan schema-viewer:build` first.
- Relation methods that do more than build the relation: the build runs them, with jobs, mail, HTTP, events and the cache faked, but file writes and other clients still run.
- Writing `hints.ignore` rules by hand: copy the rule `schema-viewer:check` prints or the JSON export's `ignore` field.
- Ignoring `relation_without_foreign_key` on a regular `belongsTo`: add the foreign key unless the relation crosses connections.
- Publishing an HTML export where the schema should not be seen.
