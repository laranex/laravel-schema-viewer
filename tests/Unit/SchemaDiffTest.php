<?php

declare(strict_types=1);

use Laranex\LaravelSchemaViewer\Diff\SchemaDiff;

/**
 * A small document: one connection with an orders table, a model and a relation on it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function diffDocument(array $overrides = []): array
{
    return array_replace_recursive([
        'connections' => [[
            'name' => 'mysql',
            'tables' => [
                'orders' => [
                    'name' => 'orders', 'comment' => null, 'note' => null,
                    'columns' => [
                        'id' => ['name' => 'id', 'type' => 'bigint', 'nullable' => false, 'default' => null, 'autoIncrement' => true, 'comment' => null, 'note' => null],
                        'status' => ['name' => 'status', 'type' => 'varchar(20)', 'nullable' => false, 'default' => null, 'autoIncrement' => false, 'comment' => null, 'note' => null],
                        'user_id' => ['name' => 'user_id', 'type' => 'bigint', 'nullable' => false, 'default' => null, 'autoIncrement' => false, 'comment' => null, 'note' => null],
                    ],
                    'indexes' => ['primary' => ['name' => 'primary', 'columns' => ['id'], 'unique' => true, 'primary' => true, 'partial' => false, 'expression' => false]],
                    'foreignKeys' => [],
                ],
                'users' => ['name' => 'users', 'comment' => null, 'columns' => ['id' => ['name' => 'id', 'type' => 'bigint']], 'indexes' => [], 'foreignKeys' => []],
            ],
        ]],
        'models' => [['class' => 'App\Models\Order', 'connection' => 'mysql', 'table' => 'orders', 'fillable' => ['status'], 'softDeletes' => false]],
        'relations' => [['id' => 'App\Models\Order::user', 'type' => 'BelongsTo', 'related' => 'App\Models\User', 'keys' => null, 'pivot' => null, 'morph' => null, 'through' => null]],
        'links' => [[
            'id' => 'l1', 'from' => ['connection' => 'mysql', 'table' => 'orders', 'columns' => ['user_id']], 'to' => ['connection' => 'mysql', 'table' => 'users', 'columns' => ['id']],
            'foreignKey' => null, 'relations' => ['App\Models\Order::user'], 'state' => 'relation',
        ]],
        'hints' => [['kind' => 'relation_without_foreign_key', 'connection' => 'mysql', 'table' => 'orders', 'columns' => 'user_id', 'relation' => null, 'message' => 'No foreign key.']],
    ], $overrides);
}

/**
 * The lists keyed by name above, as the JSON lists the document has.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>
 */
function asLists(array $document): array
{
    foreach ($document['connections'] as &$connection) {
        foreach ($connection['tables'] as &$table) {
            $table['columns'] = array_values($table['columns']);
            $table['indexes'] = array_values($table['indexes']);
        }
        $connection['tables'] = array_values($connection['tables']);
    }

    return $document;
}

it('finds nothing between equal documents', function (): void {
    expect(SchemaDiff::diff(asLists(diffDocument()), asLists(diffDocument())))->toBe([]);
});

it('lists added, removed and changed tables, columns, indexes and foreign keys', function (): void {
    $before = diffDocument();
    $after = diffDocument([
        'connections' => [[
            'tables' => [
                'orders' => [
                    'note' => 'One row per checkout.',
                    'columns' => ['status' => ['type' => 'varchar(50)', 'nullable' => true]],
                    'indexes' => ['orders_status_index' => ['name' => 'orders_status_index', 'columns' => ['status'], 'unique' => false, 'primary' => false]],
                    'foreignKeys' => [['name' => 'orders_user_id_foreign', 'columns' => ['user_id'], 'foreignTable' => 'users', 'foreignColumns' => ['id'], 'onUpdate' => 'no action', 'onDelete' => 'cascade']],
                ],
                'invoices' => ['name' => 'invoices', 'columns' => ['id' => ['name' => 'id']], 'indexes' => [], 'foreignKeys' => []],
            ],
        ]],
    ]);
    unset($after['connections'][0]['tables']['users'], $after['connections'][0]['tables']['orders']['columns']['user_id']);

    $changes = SchemaDiff::diff(asLists($before), asLists($after));
    $find = fn (string $type, string $change): ?array => collect($changes)->first(fn (array $c): bool => $c['type'] === $type && $c['change'] === $change);

    expect($find('table', 'added'))->toBe(['type' => 'table', 'change' => 'added', 'connection' => 'mysql', 'table' => 'invoices', 'after' => ['columns' => 1]])
        ->and($find('table', 'removed'))->toBe(['type' => 'table', 'change' => 'removed', 'connection' => 'mysql', 'table' => 'users', 'before' => ['columns' => 1]])
        ->and($find('table', 'changed'))->toBe(['type' => 'table', 'change' => 'changed', 'connection' => 'mysql', 'table' => 'orders', 'fields' => ['note' => [null, 'One row per checkout.']]])
        ->and($find('column', 'changed'))->toBe(['type' => 'column', 'change' => 'changed', 'connection' => 'mysql', 'table' => 'orders', 'name' => 'status', 'fields' => ['type' => ['varchar(20)', 'varchar(50)'], 'nullable' => [false, true]]])
        ->and($find('column', 'removed'))->toBe(['type' => 'column', 'change' => 'removed', 'connection' => 'mysql', 'table' => 'orders', 'name' => 'user_id', 'before' => ['type' => 'bigint']])
        ->and($find('index', 'added')['name'])->toBe('orders_status_index')
        ->and($find('foreign_key', 'added'))->toBe(['type' => 'foreign_key', 'change' => 'added', 'connection' => 'mysql', 'table' => 'orders', 'name' => 'orders_user_id_foreign', 'after' => ['references' => 'users']])
        // A removed table's columns are not listed one by one.
        ->and(collect($changes)->where('table', 'users')->count())->toBe(1);
});

it('lists models, relations and links, but not hints', function (): void {
    $after = diffDocument([
        'models' => [['fillable' => ['status', 'total'], 'softDeletes' => true]],
        'relations' => [['type' => 'HasOne']],
        'links' => [['foreignKey' => 'orders_user_id_foreign', 'state' => 'both']],
        'hints' => [['kind' => 'morph_without_map', 'message' => 'Class names.']],
    ]);
    $after['relations'][] = ['id' => 'App\Models\Order::items', 'type' => 'HasMany', 'related' => 'App\Models\Item'];

    $changes = collect(SchemaDiff::diff(asLists(diffDocument()), asLists($after)));

    expect($changes->firstWhere('type', 'model'))->toBe(['type' => 'model', 'change' => 'changed', 'name' => 'App\Models\Order', 'connection' => 'mysql', 'table' => 'orders', 'fields' => ['fillable' => [['status'], ['status', 'total']], 'softDeletes' => [false, true]]])
        ->and($changes->where('type', 'relation')->pluck('change', 'name')->all())->toBe(['App\Models\Order::items' => 'added', 'App\Models\Order::user' => 'changed'])
        ->and($changes->firstWhere('type', 'link')['message'])->toBe('Order::user() is now backed by a foreign key')
        ->and($changes->firstWhere('type', 'link')['fields'])->toBe(['state' => ['relation', 'both']])
        ->and($changes->where('type', 'hint')->all())->toBe([]);
});

it('lists a relationship whose note changed', function (): void {
    $before = diffDocument(['links' => [['note' => null]]]);
    $after = diffDocument(['links' => [['note' => 'The buyer.']]]);

    $change = collect(SchemaDiff::diff(asLists($before), asLists($after)))->firstWhere('type', 'link');

    expect($change['fields'])->toBe(['note' => [null, 'The buyer.']])
        ->and($change['message'])->toBe('Relationship orders.user_id → users.id note: none → The buyer.')
        ->and(SchemaDiff::describe($change))->toBe('Relationship orders.user_id → users.id note: none → The buyer.')
        // A snapshot from before relationship notes has no field to compare.
        ->and(SchemaDiff::diff(asLists(diffDocument()), asLists($after)))->toBe([]);
});

it('ignores fields one side does not have', function (): void {
    $before = diffDocument();
    unset($before['connections'][0]['tables']['orders']['note'], $before['connections'][0]['tables']['orders']['columns']['status']['note'], $before['connections'][0]['tables']['orders']['indexes']['primary']['partial']);
    $after = diffDocument(['connections' => [['tables' => ['orders' => ['note' => 'New field.', 'columns' => ['status' => ['note' => 'New too.']], 'indexes' => ['primary' => ['partial' => true]]]]]]]);

    expect(SchemaDiff::diff(asLists($before), asLists($after)))->toBe([]);
});

it('lists connections added and removed', function (): void {
    $after = diffDocument();
    $after['connections'][0]['name'] = 'analytics';

    expect(array_map(fn (array $c): string => $c['change'].' '.$c['connection'], SchemaDiff::diff(asLists(diffDocument()), asLists($after))))
        ->toBe(['added analytics', 'removed mysql']);
});

it('sorts the changes the same way every time', function (): void {
    $after = diffDocument(['hints' => [['message' => 'Changed message is the same hint.']]]);
    $after['connections'][0]['tables']['orders']['columns']['a'] = ['name' => 'a', 'type' => 'int'];
    $after['connections'][0]['tables']['orders']['columns']['z'] = ['name' => 'z', 'type' => 'int'];
    $reversed = $after;
    $reversed['connections'][0]['tables']['orders']['columns'] = array_reverse($reversed['connections'][0]['tables']['orders']['columns'], true);

    $changes = SchemaDiff::diff(asLists(diffDocument()), asLists($after));

    expect($changes)->toBe(SchemaDiff::diff(asLists(diffDocument()), asLists($reversed)))
        ->and(array_column($changes, 'name'))->toBe(['a', 'z']);
});

it('describes each change as a line', function (array $change, string $line): void {
    expect(SchemaDiff::describe($change))->toBe($line);
})->with([
    'a column' => [['type' => 'column', 'change' => 'changed', 'table' => 'orders', 'name' => 'status', 'fields' => ['type' => ['varchar(20)', 'varchar(50)'], 'nullable' => [false, true]]], 'Column orders.status changed: type varchar(20) → varchar(50), nullable no → yes'],
    'a table' => [['type' => 'table', 'change' => 'removed', 'table' => 'users', 'before' => ['columns' => 7]], 'Table users removed: columns 7'],
    'a connection' => [['type' => 'connection', 'change' => 'added', 'connection' => 'analytics', 'after' => ['tables' => 2]], 'Connection analytics added: tables 2'],
    'an index' => [['type' => 'index', 'change' => 'added', 'table' => 'orders', 'name' => 'orders_status_index', 'after' => ['columns' => ['status']]], 'Index orders_status_index on orders added: columns ["status"]'],
    'a link' => [['type' => 'link', 'change' => 'changed', 'message' => 'Order::user() is now backed by a foreign key'], 'Order::user() is now backed by a foreign key'],
    'a model' => [['type' => 'model', 'change' => 'removed', 'name' => 'App\Models\Order', 'before' => ['table' => 'orders']], 'Model App\Models\Order removed: table orders'],
    'a relation' => [['type' => 'relation', 'change' => 'changed', 'name' => 'App\Models\Order::user', 'fields' => ['pivot' => [null, ['table' => 'x']]]], 'Relation App\Models\Order::user changed: pivot none → {"table":"x"}'],
    'a foreign key' => [['type' => 'foreign_key', 'change' => 'changed', 'table' => 'orders', 'name' => 'fk', 'fields' => ['onDelete' => ['restrict', 'cascade']]], 'Foreign key fk on orders changed: onDelete restrict → cascade'],
]);
