---
name: laravel-schema-viewer-schema
description: >
  Learn a Laravel app's real database before changing it, using laranex/laravel-schema-viewer: every table, column, index and foreign key on every connection together with the Eloquent models and relations that use them, the hints where the two disagree or the database itself could be better, and where each model and relation is declared. Use it before writing a migration, a model, a relation or a query.
license: MIT
metadata:
  author: Nay Thu Khant
---

# Laravel Schema Viewer: read the schema

## When to use

Use this skill before writing a migration, a model, a relation, a factory or a query in a Laravel app that has `laranex/laravel-schema-viewer` installed, and whenever someone asks how tables relate. The package reads the live database and the Eloquent models together, so its output is the source of truth for what exists: prefer it over guessing from migration files, which may not match the database.

## Install

The app needs the package (`composer require laranex/laravel-schema-viewer --dev`); see the `laravel-schema-viewer` skill to install or configure it. The export commands below read the database fresh every time and never read rows.

## Use

### Read the snapshot

When `schema-viewer.json` exists in the project root, read it first: it is what the viewer shows, written by `php artisan schema-viewer:build`. `document` holds the same JSON as `schema-viewer:export --format=json` below, and `downloads.<connection>` the Mermaid, DBML and Markdown of each connection. It is as fresh as the last time someone ran the build; if a migration or model changed since, run `php artisan schema-viewer:build` or use the export commands.

### Read the database

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

### Answer questions from the JSON

- Which relation uses this column? Find the `links` entry whose `from` or `to` holds it; its `relations` name the methods (`App\Models\Order::user`).
- Is this relation backed by a foreign key? Its link's `state` is `both` (foreign key and relation), `relation` (no foreign key) or `foreign_key` (no relation).
- Where is it declared? Each model and relation has a `source`: a file relative to the app's root and a line.
- What does a model add on top of its table? `models[]` lists `casts`, `fillable`, `guarded`, `hidden`, `appends`, `scopes`, `globalScopes` and `observers`.
- Through relations (has-one-through, has-many-through) are in `derivedLinks`, with the tables they pass `via`.
- What did the team write about it? The document's `note`, and each table's and column's `note`, come from the app's notes config, next to the database's own `comment`; read both before naming or changing something.

### Find where the database and the models disagree

```bash
php artisan schema-viewer:check
php artisan schema-viewer:check --format=json
```

Read `--format=json` rather than the text: one JSON document with `passed`, `failOn`, a `summary` (counts by severity, certainty and kind) and every hint with its `kind`, `severity`, `certainty`, `connection`, `table`, `columns`, `relation`, `message`, `fails` and the `ignorePhp` rule. The exit code stays 0 (passed), 1 (a failing hint) or 2 (invalid options or config, with an `error` field).

Hint kinds:

- `relation_without_foreign_key`: a relation uses columns with no foreign key; add one in a migration, or ignore it when intended (polymorphic and cross-connection relations never get this hint).
- `foreign_key_without_relation`: a foreign key no model declares; add the `belongsTo` / `hasMany` pair.
- `foreign_key_without_index`: a foreign key column with no index starting with it; add the index.
- `model_without_table`: a model whose table does not exist on its connection.
- `morph_without_map`: morph columns store class names; register `Relation::enforceMorphMap()`.
- `unresolved_relation`: a relation method of the app threw when called on a blank model (no row loaded); fix the method.
- `unresolved_package_relation` (note): the same for a method inherited from a package, such as Passport's `Token::user()`; usually nothing to do.
- `custom_relation` (note): a relation without a foreign key that joins other tables or matches a column that is not unique, so its foreign key is not checked.

Hints about the database itself (framework tables get none):

- `table_without_primary_key`: a table with no primary key; the message names a unique index that could become one, or the composite key a pivot table needs.
- `foreign_key_type_mismatch`: a foreign key column whose type, sign or length differs from the column it references; give them the same type.
- `foreign_key_name_mismatch` (note, likely): a `<name>_id` column whose foreign key references another table than the `<name>` table that exists; rename the column or point the key at that table.
- `index_duplicates_primary_key`: an index on exactly the primary key's columns; drop it.
- `duplicate_index`: several indexes on the same columns; keep one.
- `redundant_index` (note, likely): an index whose columns another index starts with; drop it only after checking the queries that use it.
- `morph_without_index` (likely): a `<name>_type` and `<name>_id` pair that no index covers; add `$table->index(['<name>_type', '<name>_id'])`.

Partial, expression, prefix and `INCLUDE` indexes, and indexes of another structure (full-text, GIN, hash), are never called duplicate or redundant; each index in the JSON says `type`, `partial`, `expression` and `included`.

Each hint has a `severity` (`warning` or `info`) and a `certainty`: `certain` hints state a fact read from the database or the models; `likely` hints are inferred from names or conventions (the check marks them `· likely`), so verify them against the code and the queries before acting on one. Each hint names its connection, table and columns or relation. Fix the cause in a migration or the model when the task touches it; mention the others to the person rather than changing them unasked.

### See what changed

```bash
php artisan schema-viewer:diff
php artisan schema-viewer:diff base.json --format=json
php artisan schema-viewer:diff base.json --against=schema-viewer.json --format=markdown
```

Without arguments it prints the `changes` stored in `schema-viewer.json`: what the last build that found a difference changed (null before there was one; hints are not included), without touching the database. With an older snapshot it compares that file with the schema now, or with `--against` two files without running any model code. Each change has a `type` (connection, table, column, index, foreign_key, model, relation, link), a `change` (added, removed, changed), where it is, and `fields` ([before, after]) for a change. A renamed table or column shows as one removed and one added. To review a branch, compare with the base branch's file: `git show origin/main:schema-viewer.json > base.json`.

### Point a person at a table, relationship or column

Visit `/schema-viewer` in the local environment (after `php artisan schema-viewer:build`): every table of the connection, the models on them, and each relation drawn as an edge with its methods and kind (one-to-many, many-to-many, polymorphic, has-many-through).

To point someone at one table, link to it: `/schema-viewer#connection=all&table=mysql:orders&focus=1` opens every connection with the `orders` table of the `mysql` connection selected and focus mode on it and its direct neighbors (`focus` takes 1 to 3 links; leave it out to show the whole diagram). To point at a column instead, use `column=mysql:orders.user_id`; Copy link in the viewer's details panel (Shift C) copies a link to exactly what is on screen, relationships included. The same hash works on a static HTML export.

## Avoid

- Guessing a table's columns from old migrations: read `schema-viewer.json` or export the schema instead.
- Adding a relation without checking whether a foreign key already backs it, or a foreign key without an index.
- Expecting the exports to include data: they describe structure only.
- Changing the package's settings to make a hint go away; fix the schema or the model, or accept the hint with the rule the check prints.
