# Changelog

All notable changes to `laravel-schema-viewer` will be documented in this file.

## Unreleased

### Breaking
- `schema-viewer.json` is now a [laranex-schema 2.0](https://laranex.github.io/schema-viewer-ui/) document in canonical JSON, the contract the Laranex schema viewers for Laravel, Python and Node.js share, written and checked strictly. Snapshots written by 1.x (`"format": 2`) are no longer read: the viewer, `schema-viewer:diff` and `schema-viewer:build --check` say to run `php artisan schema-viewer:build` again. There is no converter.
- `schema-viewer:export --format=json` writes that document: `connections` with table ids such as `mysql:orders`, `models` with Eloquent facts in `extensions.eloquent`, `relationships` (was `relations`) with `cardinality`, `links` with `state` `relationship` (was `relation`), `inferredAssociations`, and `diagnostics` (was `hints`) with each hint's Laravel kind and ignore rule in `extensions.laravel`. `generatedAt`, `unresolved` and the per-connection `downloads` are gone. `hints.ignore`, `hints.fail_on` and `schema-viewer:check` keep the hint kinds they had.
- What changed since the previous build moves from the snapshot to `schema-viewer.changes.json` beside it, kept only for the document it was built with. `schema-viewer:diff --format=json` prints version 2 changes: `type` (`foreignKey`, `relationship`), `change`, `subject`, `label`, `message`, `fields`, `connection`, `table`.
- The viewer is the shared Laranex viewer (laranex-schema-viewer-ui 0.4.0), served from the package's verified copy: same layout, icons and line ends, in the shared palette. Links copied from the old viewer no longer open their view: the hash now names ids, such as `#table=mysql:orders&focus=mysql:orders&depth=1`. The old Laravel-specific React viewer (`ui/`, its committed build `dist/`, and its Playwright tests and fixture) is removed.
- Mermaid, DBML and Markdown exports follow the shared exporters, byte for byte what the viewer's Download dialog makes: `UK` marks, nullable foreign keys as `|o`, coverage notes, and notes about what a format leaves out (on standard error). The standalone HTML export is the shared offline page with its own Content-Security-Policy.

### Security
- In the local environment the viewer opens only to requests from this machine to this machine: a loopback peer address (127.0.0.0/8, ::1) and a `localhost`, `*.localhost` or loopback Host, read from the request itself, never from proxy headers. Any other request (another machine on the network, a tunnel such as ngrok, a public name) needs the `viewSchema` gate or `SchemaViewer::auth()`, as outside `local`.
- `schema-viewer:export` refuses to write into `public/` or `storage/app/public` (resolved through symbolic links), so an export is never published by the application's own web root, and notes on standard error that an export of a private schema is sensitive. Once exported, storing and sharing the file is up to you.
- The documentation and agent skills no longer suggest committing `schema-viewer.json`, even in a private repository: add it, `schema-viewer.changes.json` and `/schema-viewer/` to `.gitignore` and build the document in CI and in the deploy script. `schema-viewer:build --check` no longer says to commit the file. Enabling the viewer outside `local` is documented as a security exception that needs the application's own `viewSchema` gate.
- `schema-viewer:build` and `schema-viewer:export --output` warn on standard error when the file they write is not git-ignored, and exit 1 before writing a file Git already tracks, with the `git rm --cached` command that untracks it.
- Denials (403) and missing viewer files (404) are sent with `Cache-Control: no-store` and say nothing about the schema; viewer files are cached privately, never publicly.

### Added
- `php artisan schema-viewer:scan` finds schema snapshots by content, with the options, report and exit codes of the shared scanner (laranex-schema-viewer-ui's `contract/scan.mjs`): `--tracked` (the Git index, staged bytes included, and files tracked before they were ignored), `--untracked` (files Git would add), `--artifacts DIR` (build output, ignore rules not applied) and `--history` (every past version; a shallow clone fails). Name at least one scope. It prints one line of counts; `--json` and `--report FILE` give the `laranex-schema-scan` report, with paths but never file content. Exit codes: 0 clean, 1 snapshot found, 2 scan failed or incomplete (usage errors included), 3 uncertain. Synthetic fixtures committed on purpose are listed by path and SHA-256 in `.laranex-schema-allowlist.json`. Base64 or compressed payloads and SVG, PNG, DBML, Mermaid or Markdown exports are not detected.
- The viewer page sends a strict Content-Security-Policy (`script-src 'self'`, no inline script) and serves only the files of the viewer's manifest, under its fingerprint.
- `schema-viewer:export --orm` adds the Eloquent relationships and inferred associations to Mermaid and Markdown, labelled apart from the foreign keys; `--connection` scopes the JSON export to one connection.
- The document says what the inspection covers per connection (check constraints are not read, unique constraints only as indexes, index expressions and predicates only as flags), and notes relations whose related model, keys or pivot table the schema does not have.

## v1.3.1 - 2026-10-14

### Changed
- A package's table without a model reads "package table" next to the package's name, instead of "no model". Your own tables without a model still say "no model".

## v1.3.0 - 2026-10-14

### Added
- The Tables menu shows your own tables, framework tables and package tables, each with its count; **Your tables** (`Y`) hides or shows the application's own tables. Package tables is always listed.

### Changed
- `package_tables` lists table patterns by Composer package name in the config, with Laravel's own packages by default (Passport, Cashier, Cashier Paddle, Pennant). A package's tables are tagged while Composer has it installed; add any other package to the list. `true`, from an older published config, still means the default list, and `false` tags nothing. spatie/laravel-permission and Filament Breezy are no longer built in; add them to the list to tag their tables.
- Access works exactly as Telescope's: everyone in the local environment, the `viewSchema` gate elsewhere, and `SchemaViewer::auth()` to decide yourself. The local host check from v1.2.0 is gone.

## v1.2.1 - 2026-10-13

### Fixed
- The light and dark switch in the top bar is square again instead of squeezed to its icon.

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
