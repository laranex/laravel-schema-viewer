# Changelog

All notable changes to `laravel-schema-viewer` will be documented in this file.

## Unreleased

### Added
- `php artisan schema-viewer:export --format=html` writes the whole viewer as one standalone HTML file, with the schema, fonts and every connection's Mermaid, DBML, Markdown and JSON downloads inlined. It opens from disk or any static host, without the Laravel app or its API. `--output` takes a `.html` file or a folder (default `schema-viewer`, written as `index.html`).

## v1.0.0-alpha.1 - 2026-10-12

First release.

### Added
- An interactive viewer at `/schema-viewer`: every table of each connection, with the Eloquent models that use them and every relation drawn as an edge, labeled with its methods and kind (one-to-one, one-to-many, many-to-many, polymorphic, has-many-through). Search, details per table, hints, a guided tour and keyboard shortcuts; works on phones.
- Tables come from the database (`Schema::getTables()`, `getColumns()`, `getIndexes()`, `getForeignKeys()`, no doctrine/dbal); relations are read from the objects the model methods return, without running a query. Every built-in relation type, dynamic relations and relations without a return type are covered.
- Foreign keys and relations on the same columns become one link, marked as backed by both, by the relation only or by the foreign key only.
- Hints: relations without a foreign key, foreign keys without a relation or an index, models without a table, morph columns that store class names, and relation methods that cannot be read. Framework tables get none.
- Downloads of one connection's database schema as Mermaid, DBML, Markdown or JSON, built by the server.
- `schema-viewer:export --format=json|mermaid|dbml|markdown` and `schema-viewer:check`, which fails when a hint of a chosen kind remains.
- Access: always in `local`; elsewhere only when the `viewSchema` gate allows. Off outside `local` unless `SCHEMA_VIEWER_ENABLED` is set.
- Supports PHP 8.1+ and Laravel 10.36 to 13 on MySQL, MariaDB, PostgreSQL, SQLite and SQL Server.
- Agent skill in `resources/boost/skills/laravel-schema-viewer` (Laravel Boost) and `skills/laravel-schema-viewer` (`npx skills add laranex/laravel-schema-viewer`).
