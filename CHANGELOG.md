# Changelog

All notable changes to `laravel-schema-viewer` will be documented in this file.

## v1.2.0 - 2026-10-13

### Added
- `php artisan schema-viewer:build` reads the database and the models and writes `schema-viewer.json` in the project root: the schema and each connection's downloads, as readable JSON that changes only when the schema does. Commit it if the repository is private, or ignore it and build it on each machine and in your deploy script.
- While it builds, jobs, mail, notifications, HTTP calls, events and the cache are faked, every database connection pretends and model events are off, so the models' side effects stay out of the outside world.
- `SchemaViewer::auth(fn (Request $request) => …)` decides who may open the viewer, as Telescope's `auth()` does; it replaces the default check.

### Changed
- The viewer page carries the snapshot itself, as the downloaded HTML file does; it never reads the database or runs model code in a web request, and the `api/schema` and `api/export` routes are gone. Without a snapshot the page says to run the build. The refresh button and the `cache` settings are gone with them.
- In the local environment the viewer answers only on `APP_URL`'s host, `localhost`, `127.0.0.1` and `::1`, which keeps out tunnels and DNS rebinding; `SchemaViewer::auth()` opens it to other hosts.
- A model whose constructor or casts throw becomes a hint instead of failing the whole schema.
- Literals in raw relation conditions show as `?`.

### Fixed
- Reading a model that throws no longer leaves database connections pretending or logging queries on Laravel 10, 11 and 12 before 12.41, which in a long-running worker silently dropped every later write.
- Connections that relation methods open for the first time pretend too.

## v1.1.0 - 2026-10-13

### Added
- A floating bar at the bottom of the diagram: zoom out and in, a zoom level you can type, fit, pan mode (or hold Space), arrange, read again, and three menus: Relationships (which kinds of line show), Tables (framework and package tables) and Show (relation names, the grid, and how many columns each card shows). On a narrow diagram the menus become icons.
- The panel has three tabs: Browse lists every table, relationship and column with one filter; Details shows whatever is picked, a table, a relationship (keys, foreign key, on update and on delete, index, relations) or a column (type, null, default, casts, indexes, foreign keys in and out, the relationships that use it); Hints.
- Pick a line or a column on the diagram as well as a table; the pick lights up and the rest dims. Double-click a table, a line or a column to focus on it; focus mode works for all three.
- Copy link copies a link to exactly the current view, with the relationship or column picked, focus mode, the columns shown, the zoom and the position. A link to a table, relationship, column or connection the schema no longer has opens the whole diagram and says so.
- A light and a dark theme, light by default, remembered per browser.
- Download opens a dialog to pick the connection and the format, or the whole viewer as an offline page; Ignore… opens a dialog with the rules to copy.
- A second agent skill, `laravel-schema-viewer-schema`, for reading an app's schema before changing it; `laravel-schema-viewer` now covers setting up and configuring the package.

### Changed
- Large schemas lay out in packed columns that fill the screen, upright on a phone; with more than 60 tables on screen, lines are thin and muted and relation names show only on the picked table's lines.
- Smaller relation names, and lines between two tables in the same column loop outside the cards.
- The guided tour explains the floating bar and each panel tab in turn, zooms in where it points at a table or a line, and moves the spotlight and its card together.
- Phones keep the floating bar, drag the diagram instead of the cards, and show a one-time note that the diagram is easier on a larger screen.
- The panel can be widened only as far as the diagram keeps room for the floating bar.

## v1.0.0 - 2026-10-13

First release.

### Added
- An interactive viewer at `/schema-viewer`: every table of each connection, with the Eloquent models that use them and every relation drawn as an edge, labeled with its methods and kind (one-to-one, one-to-many, many-to-many, polymorphic, has-many-through). Search, details per table, hints, a guided tour and keyboard shortcuts.
- All connections on one diagram, each in its own labeled region, with relations across connections drawn as lines; the viewer opens there unless `default_connection` names one connection.
- Tables come from the database (`Schema::getTables()`, `getColumns()`, `getIndexes()`, `getForeignKeys()`, no doctrine/dbal); relations are read from the objects the model methods return, without running a query. Every built-in relation type, dynamic relations and relations without a return type are covered.
- Foreign keys and relations on the same columns become one link, marked as backed by both, by the relation only or by the foreign key only.
- Hints: relations without a foreign key, foreign keys without a relation or an index, models without a table, morph columns that store class names, and relation methods that cannot be read. Framework tables get none. Relations inherited from a package that cannot be read, and relations that join other tables or match a column that is not unique, are notes rather than warnings.
- Ignore rules: leave out hints by connection, table (both with wildcards), kind, columns or relation, in any combination; each hint offers its rules to copy, in the viewer and in `schema-viewer:check`.
- Tables of installed Passport, spatie/laravel-permission and Filament Breezy are tagged with their package, with a toggle to hide them.
- Folding: each table card folds to its key columns or to its name, and every card at once from the Columns choice; edges of folded columns meet the card's header.
- Focus mode: one table and its neighbors, one to three links away.
- Links that open the same view: the URL keeps the connection, the selected table and focus mode, also in a static export.
- Models and relations open in your editor at the right line, set with `editor` or Laravel's `app.editor`; the schema document records where each model and relation method is declared.
- A details panel you can widen by dragging its edge.
- The viewer caches the schema it read until a model file, the config, a connection's migrations or an SQLite file changes (`cache.enabled`, `cache.store`, `cache.ttl`); a refresh button reads it again.
- Downloads of one connection's database schema as Mermaid, DBML, Markdown or JSON, and of the whole viewer as one standalone HTML file that opens without the app, compressed to about a tenth of its size for a large schema.
- `schema-viewer:export --format=json|mermaid|dbml|markdown|html` and `schema-viewer:check`, which fails when a hint of a chosen kind remains.
- Access: always in `local`; elsewhere only when the `viewSchema` gate allows. Off outside `local` unless `SCHEMA_VIEWER_ENABLED` is set.
- Supports PHP 8.1+ and Laravel 10.36 to 13 on MySQL, MariaDB, PostgreSQL, SQLite and SQL Server.
- Agent skill in `resources/boost/skills/laravel-schema-viewer` (Laravel Boost) and `skills/laravel-schema-viewer` (`npx skills add laranex/laravel-schema-viewer`).
