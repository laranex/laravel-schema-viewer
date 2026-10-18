---
name: laravel-schema-viewer
description: >
  Install, configure and share laranex/laravel-schema-viewer in a Laravel app: building the schema snapshot with schema-viewer:build, who may open the viewer, which connections and models it reads, editor links, accepting hints with hints.ignore, failing CI with schema-viewer:check, scanning the repository for committed snapshots with schema-viewer:scan, and exporting a standalone HTML viewer. To read the schema itself before changing the database, use the laravel-schema-viewer-schema skill.
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

Install it as a dev dependency: the viewer is a development tool (running it on a server is a security exception; see Open it on a server).

`schema-viewer:build` reads the database and the models and writes `schema-viewer.json` in the project root, always there: a laranex-schema 2.0 document ([the shared contract](https://laranex.github.io/schema-viewer-ui/)) in canonical JSON, the format the Laranex schema viewers for Laravel, Python and Node.js share, plus `schema-viewer.changes.json` beside it with what changed since the previous build. The viewer is the shared Laranex viewer, shipped inside the package (no Node.js or npm needed); it has no API: its page carries the document from that file, and no model code runs in a web request. Its Download dialog makes the Mermaid, DBML, Markdown, JSON, SVG, PNG and offline HTML files in the browser. A `schema-viewer.json` written by laravel-schema-viewer 1.x is refused with a message to build it again. Without the file, the page says to run the build. Run it again after migrating or changing a model; on a server, run it in the deploy script after `migrate`. Never commit the file, even in a private repository: it lists every table and column, the models' fillable, hidden, casts and scopes, and source file paths. Add `/schema-viewer.json`, `/schema-viewer.changes.json` and `/schema-viewer/` (the default HTML export folder) to `.gitignore`, and build it on each machine, in CI and in the deploy script. Inside a Git work tree, `schema-viewer:build` warns on standard error when a file it writes is not ignored, and exits 1 without writing when Git already tracks it (run `git rm --cached -- schema-viewer.json`, then ignore it); `schema-viewer:export --output` checks its file the same way.

Building constructs every model and calls its relation methods with every connection pretending, model events off, and jobs, mail, notifications, HTTP calls, events and the cache faked; everything is put back afterwards. Code that goes around Laravel's facades and clients still runs for real: a direct Guzzle or SDK call, a raw Redis write, a file write, a log line. `SchemaViewer::building()` is `true` only while the package reads the models (build, export and check), so a constructor, `booted()` or relation method can skip that work. Guard it with `class_exists()`, since a server installed with `composer install --no-dev` has no `SchemaViewer` class, and still return the relation:

```php
use Laranex\LaravelSchemaViewer\SchemaViewer;

protected static function booted(): void
{
    if (class_exists(SchemaViewer::class) && SchemaViewer::building()) {
        return;
    }

    // Calls to Slack, Stripe, Redis…
}
```

## Configure

Every setting has a default, so most apps configure nothing. Publish `config/schema-viewer.php` with `php artisan vendor:publish --tag="laravel-schema-viewer-config"` only to change:

- `enabled` (`SCHEMA_VIEWER_ENABLED`): unset means on in `local`, off elsewhere.
- `path` (`SCHEMA_VIEWER_PATH`, default `schema-viewer`), `domain`, `middleware` (default `['web']`).
- `connections`: null reads the default connection plus every connection a model uses; a list reads exactly those.
- `default_connection` (`SCHEMA_VIEWER_DEFAULT_CONNECTION`): what the viewer opens on, `all` (every connection on one diagram) or one connection's name.
- `editor` (`SCHEMA_VIEWER_EDITOR`): `phpstorm`, `vscode`, `cursor`, `zed` or another name Laravel's `app.editor` takes, or `['href' => '…{file}…{line}']`, plus `base_path` when the editor sees the project elsewhere (Docker, WSL); null uses `app.editor` in `local`. Links with a `javascript:`, `data:` or similar scheme are dropped. Models and relations in the viewer then open at their line.
- `models`: folders scanned for models; null scans `app/Models`.
- `exclude_tables` (never shown) and `framework_tables` (shown, tagged, no hints); wildcards allowed.
- `package_tables`: maps Composer package names to table patterns (default: Laravel's own packages, Passport `oauth_*`, Cashier, Cashier Paddle, Pennant `features`, Pulse `pulse_*`, Sanctum `personal_access_tokens`, Telescope `telescope_*`); while a package is installed its tables are tagged with its name and still get hints. Add third-party packages there, or set `false` to tag nothing.
- `notes`: your own notes, shown in the viewer next to the database's comments and written into the exports: `project` (one string), `tables` (`['orders' => '…']`), `columns` (`['orders' => ['status' => '…']]`) and `relationships`, by the table and the columns that hold the key (`['orders' => ['user_id' => '…']]`, composite keys comma separated; JSON and viewer only). A table name applies on every connection with that table; `'analytics:events'` picks one connection and wins. Values must be strings; `schema-viewer:build` warns about notes that match nothing.
- `hints.ignore`: a list of rules; a hint is left out when it matches every field a rule names (`connection` and `table` take wildcards, plus `kind`, `columns`, `relation`, and `certainty`: `certain` or `likely`), e.g. `['kind' => 'morph_without_map']`, `['connection' => 'mysql', 'table' => 'audit_logs']`, `['table' => 'oauth_*']`, `['certainty' => 'likely']`, `['connection' => 'mysql', 'table' => 'comments', 'kind' => 'relation_without_foreign_key', 'columns' => 'user_id']`. Each hint is a diagnostic in the document with its narrowest rule in `extensions.laravel.ignore` (and as PHP in `ignorePhp`), and `schema-viewer:check` prints it too; copy those rather than writing rules by hand. Unknown fields, kinds or certainties are rejected.
- `hints.fail_on`: kinds that fail `schema-viewer:check`.

Outside `local`, and for any request to a local app from another machine, the viewer needs a gate:

```php
Gate::define('viewSchema', fn ($user) => $user->isAdmin());
```

To decide in every environment, set a callback in a service provider's `boot()`; it replaces both the local default and the gate:

```php
use Illuminate\Http\Request;
use Laranex\LaravelSchemaViewer\SchemaViewer;

SchemaViewer::auth(fn (Request $request): bool => $request->user()?->isAdmin() ?? false);
```

Without a callback, `local` opens only to requests from the same machine: a loopback address (127.0.0.1, ::1) and a `localhost`, `*.localhost` or loopback host, read from the request itself, never from proxy headers. A teammate on the network, a tunnel (ngrok, Expose) or a public name needs the `viewSchema` gate, as every request outside `local` does.

## Use

### Enforce the hints in CI

```bash
php artisan schema-viewer:check
php artisan schema-viewer:check --fail-on=foreign_key_without_index --fail-on=unresolved_relation
```

The command lists every hint and exits non-zero when one of a `hints.fail_on` kind remains (or of the kinds passed with `--fail-on`). `--format=json` prints the same as one JSON document for scripts and agents, with the same exit codes. Under each hint it prints the narrowest `hints.ignore` rule that leaves it out; add that rule to the config when the team accepts the hint, rather than lowering `fail_on`.

### Build the document in CI

Build the document in CI instead of committing it, so every job reads the schema the migrations and models make now:

```bash
php artisan migrate --force && php artisan schema-viewer:build && php artisan schema-viewer:check
```

To put a pull request's schema changes in the job summary, build the base branch's document first (with that branch's dependencies) and keep it outside the working tree:

```bash
git checkout --quiet origin/main
php artisan migrate:fresh --force && php artisan schema-viewer:build && mv schema-viewer.json "$RUNNER_TEMP/base.json"
git checkout --quiet -
php artisan migrate:fresh --force && php artisan schema-viewer:build
php artisan schema-viewer:diff "$RUNNER_TEMP/base.json" --against=schema-viewer.json --format=markdown >> "$GITHUB_STEP_SUMMARY"
```

The summary names tables and columns to everyone who can read the job. `schema-viewer.changes.json` keeps what changed since the previous build on the same machine, which the viewer's Changes tab and `php artisan schema-viewer:diff` show. `php artisan schema-viewer:build --check` builds in memory, never writes, and exits 1 when the `schema-viewer.json` on disk is missing or out of date (with a short summary of what differs): use it to confirm a deployed build is current.

### Keep snapshots out of Git

```bash
php artisan schema-viewer:scan --tracked --untracked
php artisan schema-viewer:scan --artifacts public/build --report build/schema-scan.json
```

Finds laranex-schema documents by content, not by name: in the Git index (the staged bytes, and files tracked before they were ignored) with `--tracked`, in files Git would add with `--untracked`, in a build output directory (every file, ignore rules not applied) with `--artifacts DIR`, and in every past version with `--history` (fails on a shallow clone). Name at least one scope. It prints one line of counts; `--json` prints the `laranex-schema-scan` report and `--report FILE` writes it, with paths and reasons but never file content. Exit codes: 0 clean, 1 a snapshot found, 2 the scan failed or is incomplete, 3 something looks like a snapshot; fail CI on any non-zero exit. A synthetic fixture committed on purpose goes in `.laranex-schema-allowlist.json` at the project root by exact path and SHA-256 (`{"format":"laranex-schema-allowlist","version":"1.0","entries":[{"path":"…","sha256":"…"}]}`); a changed or moved file is reported again. Run `schema-viewer:scan --tracked` in a Git pre-commit hook. Base64-compressed payloads, SVG, PNG, DBML, Mermaid and Markdown exports are not detected: keep them out of the repository yourself.

### Share the viewer without the app

```bash
php artisan schema-viewer:export --format=html --output=build/schema-viewer
```

Writes `build/schema-viewer/index.html` (or the `.html` file you name): the whole viewer, its fonts and the document in one file, the same page as the viewer's Download → "This page as HTML", with its own Content-Security-Policy. It opens from disk or any static host without the Laravel app, its database or the network, so it suits a pull request artifact, CI pages or a teammate without a database. It shows the schema as it was when exported; export again after migrations. An export of a private schema is sensitive: once exported, storing and sharing the file is up to you, and the package does not track it. The command refuses to write into `public/` or `storage/app/public`; never commit an export or host it where the schema should not be seen.

### Open it on a server

The viewer is off outside `local`, and should stay off in production: turning it on there is a security exception, not a recommended deployment, since the schema helps attackers. When a team accepts that risk for a staging or internal server, set `SCHEMA_VIEWER_ENABLED=true`, define the application's own `viewSchema` gate (or `SchemaViewer::auth()`, above) for the few users who need it, and run `php artisan schema-viewer:build` in the deploy script after `migrate`. Without a gate or callback the viewer answers 403 to everyone. Use `path` or `domain` to move it, and `middleware` to add your own (the access check is always added). Serve it over HTTPS only, and keep proxies, CDNs and full-page caches from storing the page: it carries the whole schema. The package's [security policy](https://github.com/laranex/laravel-schema-viewer/blob/main/.github/SECURITY.md) lists what the package enforces and what is left to the app.

## Test your app

The package reads schema only and never queries rows. Migrations in the test environment do not build the snapshot. In a feature test, assert on the document:

```php
$document = app(\Laranex\LaravelSchemaViewer\SchemaViewer::class)->document();

expect(collect($document->hints)->pluck('kind'))->not->toContain(\Laranex\LaravelSchemaViewer\Schema\HintKind::ModelWithoutTable);
```

## Avoid

- Enabling the viewer in production without the `viewSchema` gate or `SchemaViewer::auth()`; schema details help attackers.
- Expecting the viewer to show a table altered by hand or a model just edited: run `php artisan schema-viewer:build` first.
- Relation methods, constructors and `booted()` that call services, write files or use Redis directly: the build runs them for real; skip that work when `SchemaViewer::building()` is `true`, behind a `class_exists()` check.
- Calling `SchemaViewer::building()` without `class_exists(SchemaViewer::class)` when the package is a dev dependency: it throws on servers installed with `--no-dev`.
- Writing `hints.ignore` rules by hand: copy the rule `schema-viewer:check` prints or a diagnostic's `extensions.laravel.ignore`.
- Ignoring `relation_without_foreign_key` on a regular `belongsTo`: add the foreign key unless the relation crosses connections.
- Dropping an index the check calls redundant without checking the queries that use it.
- Committing `schema-viewer.json`, `schema-viewer.changes.json` or an export, even in a private repository: build them where they are needed instead.
- Publishing an HTML export where the schema should not be seen.
- Allowlisting a real application's snapshot to make `schema-viewer:scan` pass: remove it from Git instead (`git rm --cached`), and from the history if it was pushed.
