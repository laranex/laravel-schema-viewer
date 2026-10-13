---
name: laravel-schema-viewer-schema
description: >
  Learn a Laravel app's real database before changing it, using laranex/laravel-schema-viewer: every table, column, index and foreign key on every connection together with the Eloquent models and relations that use them, the hints where the two disagree, and where each model and relation is declared. Use it before writing a migration, a model, a relation or a query.
license: MIT
metadata:
  author: Nay Thu Khant
---

# Laravel Schema Viewer: read the schema

## When to use

Use this skill before writing a migration, a model, a relation, a factory or a query in a Laravel app that has `laranex/laravel-schema-viewer` installed, and whenever someone asks how tables relate. The package reads the live database and the Eloquent models together, so its output is the source of truth for what exists: prefer it over guessing from migration files, which may not match the database.

## Install

The app needs the package (`composer require laranex/laravel-schema-viewer --dev`); see the `laravel-schema-viewer` skill to install or configure it. The commands below read the database fresh every time and never read rows.

## Use

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

### Find where the database and the models disagree

```bash
php artisan schema-viewer:check
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

Each hint names its connection, table and columns or relation. Fix the cause in a migration or the model when the task touches it; mention the others to the person rather than changing them unasked.

### Point a person at a table, relationship or column

Visit `/schema-viewer` in the local environment: every table of the connection, the models on them, and each relation drawn as an edge with its methods and kind (one-to-many, many-to-many, polymorphic, has-many-through).

To point someone at one table, link to it: `/schema-viewer#connection=all&table=mysql:orders&focus=1` opens every connection with the `orders` table of the `mysql` connection selected and focus mode on it and its direct neighbors (`focus` takes 1 to 3 links; leave it out to show the whole diagram). To point at a column instead, use `column=mysql:orders.user_id`; the link button in the viewer (Shift C) copies a link to exactly what is on screen, relationships included. The same hash works on a static HTML export.

## Avoid

- Guessing a table's columns from old migrations: export the schema instead.
- Adding a relation without checking whether a foreign key already backs it, or a foreign key without an index.
- Expecting the exports to include data: they describe structure only.
- Changing the package's settings to make a hint go away; fix the schema or the model, or accept the hint with the rule the check prints.
