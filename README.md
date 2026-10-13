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

Open `/schema-viewer` in your local environment and the diagram is there; nothing to configure.

## Installation

Requires PHP 8.1+ and Laravel 10 (10.36 or later), 11, 12 or 13.

```bash
composer require laranex/laravel-schema-viewer --dev
```

## Documentation

The full documentation lives at **[laranex.vercel.app/laravel-schema-viewer](https://laranex.vercel.app/laravel-schema-viewer)**:

- [Laravel Schema Viewer introduction and live demo](https://laranex.vercel.app/laravel-schema-viewer/introduction): why use it, with a live demo of a 200-table schema
- [Installing Laravel Schema Viewer](https://laranex.vercel.app/laravel-schema-viewer/installation): Composer, opening the viewer and who may see it
- [Laravel Schema Viewer configuration](https://laranex.vercel.app/laravel-schema-viewer/configuration): every setting and its default
- [Using the schema viewer](https://laranex.vercel.app/laravel-schema-viewer/viewer): the floating bar, Browse, Details and Hints, focus mode, shared links and shortcuts
- [Relationships in the schema diagram](https://laranex.vercel.app/laravel-schema-viewer/relationships): how foreign keys and Eloquent relations become lines
- [Schema hints](https://laranex.vercel.app/laravel-schema-viewer/hints): where the database and the models disagree, and how to act on each
- [Schema downloads and commands](https://laranex.vercel.app/laravel-schema-viewer/exports): Mermaid, DBML, Markdown, JSON and HTML exports, and the CI check
- [Laravel Schema Viewer AI agent skills](https://laranex.vercel.app/laravel-schema-viewer/ai-agent-skill): the skills for Claude Code, Codex, Cursor and other coding agents

## License

MIT. See [LICENSE.md](LICENSE.md).
