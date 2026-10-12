---
name: laravel-schema-viewer
description: >
  Understand a Laravel app's database before changing it, using laranex/laravel-schema-viewer: read every table, column, index and foreign key together with the Eloquent models and relations that use them, find mismatches between the two, and export the schema as Markdown, JSON, Mermaid, DBML or a standalone HTML viewer.
license: MIT
metadata:
  author: Nay Thu Khant
---

# Laravel Schema Viewer

## When to use

Use this skill before writing a migration, a model, a relation or a query in a Laravel app that has `laranex/laravel-schema-viewer` installed, and when someone asks how tables relate. The package reads the live database and the Eloquent models together, so its output is the source of truth for what exists: prefer it over guessing from migration files, which may not match the database.

## Install

```bash
composer require laranex/laravel-schema-viewer:^1.0@alpha --dev
```

Requires PHP 8.1+ and Laravel 10.36 to 13. The service provider registers itself. Install it as a dev dependency unless the team wants the viewer on a server.

## Configure

Every setting has a default, so most apps configure nothing. Publish `config/schema-viewer.php` with `php artisan vendor:publish --tag="laravel-schema-viewer-config"` only to change:

- `enabled` (`SCHEMA_VIEWER_ENABLED`): unset means on in `local`, off elsewhere.
- `path` (`SCHEMA_VIEWER_PATH`, default `schema-viewer`), `domain`, `middleware` (default `['web']`).
- `connections`: null reads the default connection plus every connection a model uses; a list reads exactly those.
- `default_connection` (`SCHEMA_VIEWER_DEFAULT_CONNECTION`): what the viewer opens on, `all` (every connection on one diagram) or one connection's name.
- `editor` (`SCHEMA_VIEWER_EDITOR`): `phpstorm`, `vscode`, `cursor`, `zed` or another name Laravel's `app.editor` takes, or `['href' => '…{file}…{line}']`, plus `base_path` when the editor sees the project elsewhere (Docker, WSL); null uses `app.editor` in `local`. Links with a `javascript:`, `data:` or similar scheme are dropped. Models and relations in the viewer then open at their line.
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

### Read the schema before changing it

```bash
php artisan schema-viewer:export --format=markdown
php artisan schema-viewer:export --format=markdown --connection=analytics --output=storage/schema.md
```

Markdown lists every table of one connection with its columns (type, null, default, key, comment), indexes and foreign keys: read it before adding a column, an index or a foreign key so names, types and conventions match.

```bash
php artisan schema-viewer:export --format=json
```

JSON covers every connection and adds the Laravel side: `models` (table, casts, fillable, hidden, scopes, observers), `relations` (type, keys, pivot, morph columns, constraints, inverse), `links` (one per pair of columns, with `state` `both`, `relation` or `foreign_key`) and `hints`. Use it to answer "which relation uses this column" or "is this relation backed by a foreign key".

`--format=mermaid` and `--format=dbml` produce an ER diagram of one connection's database for a README, a pull request or dbdiagram.io.

### Share the viewer without the app

```bash
php artisan schema-viewer:export --format=html --output=build/schema-viewer
```

Writes `build/schema-viewer/index.html` (or the `.html` file you name): the whole viewer with the schema and the downloads inlined. It opens from disk or any static host without the Laravel app or its API, so it suits a pull request artifact, CI pages or a teammate without a database. It shows the schema as it was when exported; export again after migrations. Host it only where the schema may be seen.

### Check the schema and the models agree

```bash
php artisan schema-viewer:check
php artisan schema-viewer:check --fail-on=foreign_key_without_index --fail-on=unresolved_relation
```

Hint kinds:

- `relation_without_foreign_key`: a relation uses columns with no foreign key; add one in a migration, or ignore it when intended (polymorphic and cross-connection relations never get this hint).
- `foreign_key_without_relation`: a foreign key no model declares; add the `belongsTo` / `hasMany` pair.
- `foreign_key_without_index`: a foreign key column with no index starting with it; add the index.
- `model_without_table`: a model whose table does not exist on its connection.
- `morph_without_map`: morph columns store class names; register `Relation::enforceMorphMap()`.
- `unresolved_relation`: a relation method of the app threw when called on a blank model (no row loaded); fix the method.
- `unresolved_package_relation` (note): the same for a method inherited from a package, such as Passport's `Token::user()`; usually nothing to do.
- `custom_relation` (note): a relation without a foreign key that joins other tables or matches a column that is not unique, so its foreign key is not checked.

The command exits non-zero when a hint of a `fail_on` kind remains, so it fits CI.

### Open the viewer

Visit `/schema-viewer` in the local environment: every table of the connection, the models on them, and each relation drawn as an edge with its methods and kind (one-to-many, many-to-many, polymorphic, has-many-through).

To point someone at one table, link to it: `/schema-viewer#connection=all&table=mysql:orders&focus=1` opens every connection with the `orders` table of the `mysql` connection selected and focus mode on it and its direct neighbors (`focus` takes 1 to 3 links; leave it out to show the whole diagram). The same hash works on a static HTML export.

JSON exports record where each model class and relation method is declared (`source`: a file relative to the app's root and a line); use it to find the code behind a relation.

## Test your app

The package reads schema only and never queries rows. In a feature test, assert on the document:

```php
$document = app(\Laranex\LaravelSchemaViewer\SchemaViewer::class)->document();

expect(collect($document->hints)->pluck('kind'))->not->toContain(\Laranex\LaravelSchemaViewer\Schema\HintKind::ModelWithoutTable);
```

## Avoid

- Guessing a table's columns from old migrations: export the schema instead.
- Enabling the viewer in production without the `viewSchema` gate; schema details help attackers.
- Sharing a local app (Herd Share, Expose, ngrok) with the viewer on: in `local`, anyone who reaches the app can open it.
- Ignoring `relation_without_foreign_key` on a regular `belongsTo`: add the foreign key unless the relation crosses connections.
- Expecting the exports to include data: they describe structure only.
