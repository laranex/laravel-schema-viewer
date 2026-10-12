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
    | In the local environment there is no gate: anyone who can reach the
    | app opens the viewer. Keep APP_ENV=local off shared machines, and set
    | this to false before exposing the app with Herd Share, Expose or ngrok.
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
    | Package tables tags the tables of installed packages (Passport's
    | oauth_*, spatie/laravel-permission, Filament Breezy) with the package,
    | so the viewer can hide them with one toggle. They still get hints.
    |
    */

    'exclude_tables' => [],

    'framework_tables' => [
        'migrations', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks',
        'sessions', 'password_reset_tokens', 'personal_access_tokens', 'notifications',
        'telescope_*', 'pulse_*',
    ],

    'package_tables' => true,

    /*
    |--------------------------------------------------------------------------
    | Hints
    |--------------------------------------------------------------------------
    |
    | Ignore lists the hints you have accepted. A hint is left out when it
    | matches every field a rule names; a field left out matches anything.
    | Fields: connection, table (both take wildcards), kind, columns and
    | relation. Each hint in the viewer and in schema-viewer:check shows the
    | rule that ignores it, ready to copy:
    |
    |   ['kind' => 'morph_without_map']                     a kind, everywhere
    |   ['connection' => 'legacy']                          a connection
    |   ['connection' => 'mysql', 'table' => 'audit_logs']  a table
    |   ['table' => 'oauth_*']                              tables, by wildcard
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
