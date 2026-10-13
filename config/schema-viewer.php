<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Unset, the viewer is on in the local environment and off everywhere
    | else. Set it to true or false to decide for every environment.
    |
    | In the local environment everyone may open it; elsewhere only users
    | the viewSchema gate allows, as with Telescope. Decide yourself with
    | SchemaViewer::auth() in a service provider.
    |
    | The viewer shows schema-viewer.json in the project root, which php
    | artisan schema-viewer:build writes. No model code runs in a request.
    |
    */

    'enabled' => env('SCHEMA_VIEWER_ENABLED'),

    /*
    |--------------------------------------------------------------------------
    | Route
    |--------------------------------------------------------------------------
    |
    | Where the viewer is served. Outside the local environment, a user also
    | needs the viewSchema gate: Gate::define('viewSchema', fn ($user) => ...).
    | SchemaViewer::auth(fn ($request) => ...) replaces both checks.
    |
    */

    'path' => env('SCHEMA_VIEWER_PATH', 'schema-viewer'),

    'domain' => env('SCHEMA_VIEWER_DOMAIN'),

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Null reads the default connection and every connection a model uses.
    | A list reads exactly those, e.g. ['mysql', 'analytics'].
    |
    */

    'connections' => null,

    /*
    |--------------------------------------------------------------------------
    | Default Connection
    |--------------------------------------------------------------------------
    |
    | What the viewer opens on: 'all' puts every connection on one diagram,
    | or name one connection, e.g. 'mysql'. An unknown name opens on all.
    |
    */

    'default_connection' => env('SCHEMA_VIEWER_DEFAULT_CONNECTION', 'all'),

    /*
    |--------------------------------------------------------------------------
    | Editor
    |--------------------------------------------------------------------------
    |
    | Models and relations in the viewer open in this editor: phpstorm, vscode,
    | cursor, zed, sublime and the other names Laravel's app.editor takes, or
    | ['href' => 'myeditor://open?file={file}&line={line}']. Add 'base_path'
    | when the editor sees the project at another path (Docker, Sail, WSL).
    | Null uses app.editor in the local environment only, since the links
    | carry the app's path; without either, the viewer shows no editor links.
    |
    */

    'editor' => env('SCHEMA_VIEWER_EDITOR'),

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Folders scanned for Eloquent models. Null scans app/Models.
    |
    */

    'models' => null,

    /*
    |--------------------------------------------------------------------------
    | Tables
    |--------------------------------------------------------------------------
    |
    | Excluded tables are never shown. Framework tables are shown and tagged,
    | so the viewer can hide them with one toggle, and get no hints since you
    | do not own their schema. Both accept wildcards.
    |
    | Package tables tags the tables a package creates with the package's
    | name, while Composer has that package installed, so the viewer can hide
    | them with one toggle. They still get hints. The list holds Laravel's own
    | packages; add others, such as spatie/laravel-permission, or set it to
    | false to tag nothing. Wildcards allowed.
    |
    */

    'exclude_tables' => [],

    'framework_tables' => [
        'migrations', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks',
        'sessions', 'password_reset_tokens', 'personal_access_tokens', 'notifications',
        'telescope_*', 'pulse_*',
    ],

    'package_tables' => [
        'laravel/passport' => ['oauth_*'],
        'laravel/cashier' => ['subscriptions', 'subscription_items'],
        'laravel/cashier-paddle' => ['customers', 'subscriptions', 'subscription_items', 'transactions'],
        'laravel/pennant' => ['features'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Hints
    |--------------------------------------------------------------------------
    |
    | Ignore lists the hints you have accepted. A hint is left out when it
    | matches every field a rule names; a field left out matches anything.
    | Fields: connection, table (both take wildcards), kind, columns,
    | relation and certainty: 'certain' for facts read from the database or
    | the models, 'likely' for hints inferred from names or conventions.
    | Each hint in the viewer and in schema-viewer:check shows the rule that
    | ignores it, ready to copy:
    |
    |   ['kind' => 'morph_without_map']                     a kind, everywhere
    |   ['connection' => 'legacy']                          a connection
    |   ['connection' => 'mysql', 'table' => 'audit_logs']  a table
    |   ['table' => 'oauth_*']                              tables, by wildcard
    |   ['certainty' => 'likely']                           every likely hint
    |   ['connection' => 'mysql', 'table' => 'comments',
    |    'kind' => 'relation_without_foreign_key', 'columns' => 'user_id']
    |                                                       one hint
    |
    | schema-viewer:check exits non-zero when a hint of a fail_on kind remains.
    |
    | Kinds: relation_without_foreign_key, foreign_key_without_relation,
    | foreign_key_without_index, model_without_table, morph_without_map,
    | unresolved_relation, unresolved_package_relation, custom_relation.
    |
    */

    'hints' => [
        'ignore' => [],
        'fail_on' => ['foreign_key_without_index', 'unresolved_relation', 'model_without_table'],
    ],

];
