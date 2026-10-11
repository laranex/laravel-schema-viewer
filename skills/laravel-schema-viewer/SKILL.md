---
name: laravel-schema-viewer
description: >
  Understand a Laravel app's database before changing it, using laranex/laravel-schema-viewer: read every table, column, index and foreign key together with the Eloquent models and relations that use them, find mismatches between the two, and export the schema as Markdown, JSON, Mermaid or DBML.
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
- `models`: folders scanned for models; null scans `app/Models`.
- `exclude_tables` (never shown) and `framework_tables` (shown, tagged, no hints); wildcards allowed.
- `hints.ignore` (kinds or `kind:table` pairs) and `hints.fail_on` (kinds that fail `schema-viewer:check`).

Outside `local`, the viewer needs a gate:

```php
Gate::define('viewSchema', fn ($user) => $user->isAdmin());
```

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
- `unresolved_relation`: a relation method threw when called; fix the method.

The command exits non-zero when a hint of a `fail_on` kind remains, so it fits CI.

### Open the viewer

Visit `/schema-viewer` in the local environment: every table of the connection, the models on them, and each relation drawn as an edge with its methods and kind (one-to-many, many-to-many, polymorphic, has-many-through).

## Test your app

The package reads schema only and never queries rows. In a feature test, assert on the document:

```php
$document = app(\Laranex\LaravelSchemaViewer\SchemaViewer::class)->document();

expect(collect($document->hints)->pluck('kind'))->not->toContain(\Laranex\LaravelSchemaViewer\Schema\HintKind::ModelWithoutTable);
```

## Avoid

- Guessing a table's columns from old migrations: export the schema instead.
- Enabling the viewer in production without the `viewSchema` gate; schema details help attackers.
- Ignoring `relation_without_foreign_key` on a regular `belongsTo`: add the foreign key unless the relation crosses connections.
- Expecting the exports to include data: they describe structure only.
