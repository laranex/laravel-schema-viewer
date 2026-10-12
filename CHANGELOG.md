# Changelog

All notable changes to `laravel-schema-viewer` will be documented in this file.

## v1.0.0 - Unreleased

First release.

### Added
- An interactive viewer at `/schema-viewer`: every table of each connection, with the Eloquent models that use them and every relation drawn as an edge, labeled with its methods and kind (one-to-one, one-to-many, many-to-many, polymorphic, has-many-through). Search, details per table, hints, a guided tour and keyboard shortcuts.
- All connections on one diagram, each in its own labeled region, with relations across connections drawn as lines; the viewer opens there unless `default_connection` names one connection.
- Tables come from the database (`Schema::getTables()`, `getColumns()`, `getIndexes()`, `getForeignKeys()`, no doctrine/dbal); relations are read from the objects the model methods return, without running a query. Every built-in relation type, dynamic relations and relations without a return type are covered.
- Foreign keys and relations on the same columns become one link, marked as backed by both, by the relation only or by the foreign key only.
- Hints: relations without a foreign key, foreign keys without a relation or an index, models without a table, morph columns that store class names, and relation methods that cannot be read. Framework tables get none, and neither do the tables of installed Passport, spatie/laravel-permission and Filament Breezy. Relations inherited from a package that cannot be read, and relations that join other tables or match a column that is not unique, are notes rather than warnings.
- Folding: each table card folds to its key columns or to its name, and every card at once from the Columns choice; edges of folded columns meet the card's header.
- Focus mode: one table and its neighbors, one to three links away.
- Links that open the same view: the URL keeps the connection, the selected table and focus mode, also in a static export.
- Models and relations open in your editor at the right line, set with `editor` or Laravel's `app.editor`; the schema document records where each model and relation method is declared.
- A details panel you can widen by dragging its edge.
- Downloads of one connection's database schema as Mermaid, DBML, Markdown or JSON, and of the whole viewer as one standalone HTML file that opens without the app.
- `schema-viewer:export --format=json|mermaid|dbml|markdown|html` and `schema-viewer:check`, which fails when a hint of a chosen kind remains.
- Access: always in `local`; elsewhere only when the `viewSchema` gate allows. Off outside `local` unless `SCHEMA_VIEWER_ENABLED` is set.
- Supports PHP 8.1+ and Laravel 10.36 to 13 on MySQL, MariaDB, PostgreSQL, SQLite and SQL Server.
- Agent skill in `resources/boost/skills/laravel-schema-viewer` (Laravel Boost) and `skills/laravel-schema-viewer` (`npx skills add laranex/laravel-schema-viewer`).
