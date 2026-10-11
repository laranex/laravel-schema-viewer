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
    */

    'exclude_tables' => [],

    'framework_tables' => [
        'migrations', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks',
        'sessions', 'password_reset_tokens', 'personal_access_tokens', 'notifications',
        'telescope_*', 'pulse_*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Hints
    |--------------------------------------------------------------------------
    |
    | Ignore takes hint kinds, or kind:table pairs, that you have accepted.
    | schema-viewer:check exits non-zero when a hint of a fail_on kind remains.
    |
    | Kinds: relation_without_foreign_key, foreign_key_without_relation,
    | foreign_key_without_index, model_without_table, morph_without_map,
    | unresolved_relation.
    |
    */

    'hints' => [
        'ignore' => [],
        'fail_on' => ['foreign_key_without_index', 'unresolved_relation', 'model_without_table'],
    ],

];
