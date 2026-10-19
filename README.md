# Laravel Schema Viewer

[![Latest Version on Packagist](https://img.shields.io/packagist/v/laranex/laravel-schema-viewer.svg?style=flat-square)](https://packagist.org/packages/laranex/laravel-schema-viewer)
[![Tests](https://github.com/laranex/laravel-schema-viewer/actions/workflows/tests.yml/badge.svg)](https://github.com/laranex/laravel-schema-viewer/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/laranex/laravel-schema-viewer.svg?style=flat-square)](https://packagist.org/packages/laranex/laravel-schema-viewer)
[![License](https://img.shields.io/packagist/l/laranex/laravel-schema-viewer.svg?style=flat-square)](LICENSE.md)

Schema viewer for Laravel: an interactive diagram of your database tables and Eloquent relationships. Built for humans and AI agents.

Laravel has no single schema file. The tables live in the database, and the relationships live in your Eloquent models. Laravel Schema Viewer reads both and draws them together, so anyone on the team can see how the data fits without reading migrations or models.

**[Live demo](https://laranex.vercel.app/laravel-schema-viewer/introduction#live-demo):** A sample Laravel store with three database connections (the store, billing and analytics), 200 tables, 162 models and 236 relations. Everything works as it does in your app, downloads included.

## Why Laravel Schema Viewer

- **Every table, with or without a model.** Tables, columns, indexes and foreign keys come straight from the database, framework tables included.
- **Every relation your models declare.** `belongsTo`, `hasMany`, `belongsToMany` with its pivot, polymorphic relations, through relations, dynamic relations and third-party relation types. Each one is read from the object the method returns, without running a query.
- **One line per relationship.** A foreign key and the relations that use the same columns become one edge, labeled with the methods and the kind of relationship: one-to-many, many-to-many, polymorphic, has-many-through.
- **Every connection.** Each database connection your app and its models use, side by side, with relations across connections drawn as lines.
- **Hints where the two disagree.** Relations without a foreign key, foreign keys without a relation or an index, models without a table.
- **Made for big schemas.** Browse every table, relationship and column with one filter, pick any of them for its details, fold tables to their keys or their names, focus on a table, a relationship or a column and what is around it, and share a link that opens exactly that view. See [Using the Viewer](https://laranex.vercel.app/laravel-schema-viewer/viewer).
- **Straight to the code.** Model and relation names open in your editor at the line that declares them.
- **Downloads and commands.** The database schema as Mermaid, DBML, Markdown or JSON, the whole viewer as one HTML file, and a check that fails your CI on the hints you choose.

Run `php artisan schema-viewer:build` and open `/schema-viewer` in your local environment. The build writes `schema-viewer.json` in your project root, a [laranex-schema 2.0](https://laranex.github.io/schema-viewer-ui/) document, and the viewer's page carries it; run it again after migrating. It lists your whole schema: keep it out of version control (add `/schema-viewer.json`, `/schema-viewer.changes.json` and `/schema-viewer/` to `.gitignore`) and build it where you need it, in CI included. The viewer is the shared Laranex Schema Viewer, the same one the Python and Node.js packages use, shipped inside the package: nothing to build, and no Node.js or npm needed.

## Installation

Requires PHP 8.1+ and Laravel 10 (10.36 or later), 11, 12 or 13.

```bash
composer require laranex/laravel-schema-viewer --dev
```

## Keep the schema out of Git

`schema-viewer:build` (and `schema-viewer:export --output`) warn when the file they write is not git-ignored, and refuse to write one Git already tracks (`git rm --cached -- schema-viewer.json` untracks it). `schema-viewer:scan` finds schema snapshots by content in what Git tracks or would add, in build output and, on request, in the history, and fails on any it finds; exit codes 0 clean, 1 found, 2 scan failed or incomplete, 3 uncertain. Name at least one scope:

```bash
php artisan schema-viewer:scan --tracked --untracked                       # the repository
php artisan schema-viewer:scan --artifacts public/build --report scan.json  # build output; the report has paths, never content
```

A Git pre-commit hook (`.git/hooks/pre-commit`, executable):

```sh
#!/bin/sh
exec php artisan schema-viewer:scan --tracked
```

or a Composer script, `"scan": "@php artisan schema-viewer:scan --tracked --untracked"`, run with `composer scan`. In CI, run it after the build and fail the job on any non-zero exit:

```yaml
- run: php artisan schema-viewer:scan --tracked --untracked --report schema-scan.json
```

A synthetic fixture you commit on purpose is listed by exact path and SHA-256 in `.laranex-schema-allowlist.json` at the project root. The scan does not decode compressed or encoded content, and does not read SVG, PNG, DBML, Mermaid or Markdown exports: once exported, a file is yours to keep out of the repository.

## Security notice

- The viewer is a development tool: install the package with `--dev` and never expose the viewer in production. Turning it on outside `local` is a security exception that needs your own `viewSchema` gate or `SchemaViewer::auth()`.
- Schema snapshots and every export (JSON, HTML, DBML, Mermaid, Markdown, SVG, PNG) are sensitive: never commit or publish them.
- Protect every path the schema takes (the page, its assets, downloads, caches and CDNs) on the server; protecting the page alone is not enough.
- Run `php artisan schema-viewer:scan --tracked --untracked` in a pre-commit hook and in CI.

See [SECURITY.md](.github/SECURITY.md) for the security model and how to report a vulnerability, and the shared viewer's [security page](https://laranex.github.io/schema-viewer-ui/security.html) for the generic policy.

## Documentation

The full documentation lives at **[laranex.vercel.app/laravel-schema-viewer](https://laranex.vercel.app/laravel-schema-viewer)**:

- [Laravel Schema Viewer introduction and live demo](https://laranex.vercel.app/laravel-schema-viewer/introduction): why use it, with a live demo of a 200-table schema
- [Installing Laravel Schema Viewer](https://laranex.vercel.app/laravel-schema-viewer/installation): Composer, the schema snapshot, opening the viewer and who may see it
- [Laravel Schema Viewer configuration](https://laranex.vercel.app/laravel-schema-viewer/configuration): every setting and its default
- [Using the schema viewer](https://laranex.vercel.app/laravel-schema-viewer/viewer): the floating bar, Browse, Details and Hints, focus mode, shared links and shortcuts
- [Relationships in the schema diagram](https://laranex.vercel.app/laravel-schema-viewer/relationships): how foreign keys and Eloquent relations become lines
- [What runs on build](https://laranex.vercel.app/laravel-schema-viewer/build): the model code `schema-viewer:build`, `export` and `check` run (none with `--snapshot`), what they fake, and skipping side effects with `SchemaViewer::building()`
- [Schema hints](https://laranex.vercel.app/laravel-schema-viewer/hints): where the database and the models disagree, and how to act on each
- [Schema downloads and commands](https://laranex.vercel.app/laravel-schema-viewer/exports): Mermaid, DBML, Markdown, JSON and HTML exports, and the CI check
- [Laravel Schema Viewer AI agent skills](https://laranex.vercel.app/laravel-schema-viewer/ai-agent-skill): the skills for Claude Code, Codex, Cursor and other coding agents

## License

MIT. See [LICENSE.md](LICENSE.md).
