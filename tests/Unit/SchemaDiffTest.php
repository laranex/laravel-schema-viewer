<?php

declare(strict_types=1);

use Laranex\LaravelSchemaViewer\Diff\SchemaDiff;

/**
 * A column of a small laranex-schema 2.0 document.
 *
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function diffColumn(string $name, string $type, array $fields = []): array
{
    return [
        'name' => $name, 'type' => $type, 'typeName' => $type, 'nullable' => false, 'autoIncrement' => false, 'timezone' => null, 'collation' => null,
        'enum' => null, 'default' => null, 'ormDefault' => null, 'generated' => null, 'comment' => null, 'note' => null, 'provenance' => ['database'], ...$fields,
    ];
}

/**
 * A table of a small laranex-schema 2.0 document.
 *
 * @param  list<array<string, mixed>>  $columns
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function diffTable(string $name, array $columns, array $fields = []): array
{
    return [
        'id' => 'mysql:'.$name, 'name' => $name, 'schema' => null, 'comment' => null, 'note' => null, 'owner' => ['kind' => 'application', 'package' => null],
        'primaryKey' => ['id'], 'columns' => $columns, 'indexes' => [], 'foreignKeys' => [], 'constraints' => [], 'provenance' => ['database'], ...$fields,
    ];
}

/**
 * A small document: one connection with orders and users, an Order model and its user relationship.
 *
 * @return array<string, mixed>
 */
function diffDocument(): array
{
    return [
        'format' => 'laranex-schema',
        'version' => '2.0',
        'connections' => [[
            'id' => 'connection:mysql', 'name' => 'mysql', 'driver' => 'mysql', 'defaultSchema' => null,
            'tables' => [
                diffTable('orders', [diffColumn('id', 'bigint'), diffColumn('status', 'varchar(20)'), diffColumn('user_id', 'bigint')]),
                diffTable('users', [diffColumn('id', 'bigint')]),
            ],
        ]],
        'models' => [['id' => 'App\Models\Order', 'className' => 'App\Models\Order', 'tables' => ['mysql:orders'], 'primaryKey' => ['id'], 'source' => null, 'extensions' => ['eloquent' => ['fillable' => ['status'], 'softDeletes' => false]]]],
        'relationships' => [[
            'id' => 'App\Models\Order::user', 'name' => 'user', 'model' => 'App\Models\Order', 'table' => 'mysql:orders', 'relatedModel' => null, 'relatedTable' => 'mysql:users',
            'type' => 'BelongsTo', 'cardinality' => 'many-to-one', 'keys' => ['from' => ['table' => 'mysql:orders', 'columns' => ['user_id']], 'to' => ['table' => 'mysql:users', 'columns' => ['id']]],
            'pivot' => null, 'polymorphic' => null, 'through' => null,
        ]],
        'links' => [[
            'id' => 'l1', 'from' => ['table' => 'mysql:orders', 'columns' => ['user_id']], 'to' => ['table' => 'mysql:users', 'columns' => ['id']],
            'foreignKey' => null, 'relationships' => ['App\Models\Order::user'], 'state' => 'relationship', 'polymorphic' => false, 'note' => null,
        ]],
        'diagnostics' => [['id' => 'n1', 'kind' => 'relationship_without_foreign_key', 'message' => 'No foreign key.']],
    ];
}

it('finds nothing between equal documents', function (): void {
    expect(SchemaDiff::diff(diffDocument(), diffDocument()))->toBe([]);
});

it('lists added, removed and changed tables, columns, indexes and foreign keys, each with the id the viewer opens', function (): void {
    $after = diffDocument();
    $orders = &$after['connections'][0]['tables'][0];
    $orders['note'] = 'One row per checkout.';
    $orders['columns'] = [diffColumn('id', 'bigint'), diffColumn('status', 'varchar(50)', ['nullable' => true])];
    $orders['indexes'] = [['id' => 'mysql:orders:index:orders_status_index', 'name' => 'orders_status_index', 'parts' => [['column' => 'status', 'expression' => null]], 'unique' => false, 'backs' => null, 'method' => null, 'partial' => false, 'expression' => false, 'included' => []]];
    $orders['foreignKeys'] = [['id' => 'mysql:orders:fk:orders_user_id_foreign', 'name' => 'orders_user_id_foreign', 'columns' => ['user_id'], 'foreignTable' => 'mysql:users', 'foreignColumns' => ['id'], 'onUpdate' => null, 'onDelete' => 'CASCADE']];
    unset($orders);
    $after['connections'][0]['tables'][1] = diffTable('invoices', [diffColumn('id', 'bigint')]);

    $changes = SchemaDiff::diff(diffDocument(), $after);
    $find = fn (string $type, string $change): ?array => collect($changes)->first(fn (array $c): bool => $c['type'] === $type && $c['change'] === $change);

    expect($find('table', 'added'))->toBe(['type' => 'table', 'change' => 'added', 'subject' => 'mysql:invoices', 'label' => 'invoices', 'message' => '1 column', 'connection' => 'mysql', 'table' => 'invoices'])
        ->and($find('table', 'removed'))->toBe(['type' => 'table', 'change' => 'removed', 'subject' => 'mysql:users', 'label' => 'users', 'message' => '1 column', 'connection' => 'mysql', 'table' => 'users'])
        ->and($find('table', 'changed'))->toBe(['type' => 'table', 'change' => 'changed', 'subject' => 'mysql:orders', 'label' => 'orders', 'fields' => ['note' => [null, 'One row per checkout.']], 'connection' => 'mysql', 'table' => 'orders'])
        ->and($find('column', 'changed'))->toBe(['type' => 'column', 'change' => 'changed', 'subject' => 'mysql:orders', 'label' => 'orders.status', 'fields' => ['type' => ['varchar(20)', 'varchar(50)'], 'nullable' => [false, true]], 'connection' => 'mysql', 'table' => 'orders'])
        ->and($find('column', 'removed'))->toBe(['type' => 'column', 'change' => 'removed', 'subject' => 'mysql:orders', 'label' => 'orders.user_id', 'message' => 'bigint', 'connection' => 'mysql', 'table' => 'orders'])
        ->and($find('index', 'added')['subject'])->toBe('mysql:orders:index:orders_status_index')
        ->and($find('foreignKey', 'added'))->toBe(['type' => 'foreignKey', 'change' => 'added', 'subject' => 'mysql:orders:fk:orders_user_id_foreign', 'label' => 'orders_user_id_foreign on orders', 'message' => 'references mysql:users', 'connection' => 'mysql', 'table' => 'orders'])
        // A removed table's columns are not listed one by one.
        ->and(collect($changes)->where('table', 'users')->count())->toBe(1);
});

it('lists models, relationships and links, but not diagnostics', function (): void {
    $after = diffDocument();
    $after['models'][0]['extensions']['eloquent'] = ['fillable' => ['status', 'total'], 'softDeletes' => true];
    $after['relationships'][0]['type'] = 'HasOne';
    $after['relationships'][] = ['id' => 'App\Models\Order::items', 'name' => 'items', 'type' => 'HasMany', 'cardinality' => 'one-to-many'];
    $after['links'][0] = ['foreignKey' => 'mysql:orders:fk:orders_user_id_foreign', 'state' => 'both'] + $after['links'][0];
    $after['diagnostics'] = [['id' => 'n2', 'kind' => 'morph_without_map', 'message' => 'Class names.']];

    $changes = collect(SchemaDiff::diff(diffDocument(), $after));

    expect($changes->firstWhere('type', 'model'))->toBe(['type' => 'model', 'change' => 'changed', 'subject' => 'App\Models\Order', 'label' => 'App\Models\Order', 'fields' => ['extensions' => [['eloquent' => ['fillable' => ['status'], 'softDeletes' => false]], ['eloquent' => ['fillable' => ['status', 'total'], 'softDeletes' => true]]]]])
        ->and($changes->where('type', 'relationship')->pluck('change', 'subject')->all())->toBe(['App\Models\Order::items' => 'added', 'App\Models\Order::user' => 'changed'])
        ->and($changes->firstWhere('type', 'link')['message'])->toBe('Order::user() is now backed by a foreign key')
        ->and($changes->firstWhere('type', 'link')['subject'])->toBe('l1')
        ->and($changes->firstWhere('type', 'link')['fields'])->toBe(['state' => ['relationship', 'both']])
        ->and($changes->where('type', 'diagnostic')->all())->toBe([]);
});

it('lists a relationship whose note changed', function (): void {
    $after = diffDocument();
    $after['links'][0]['note'] = 'The buyer.';

    $change = collect(SchemaDiff::diff(diffDocument(), $after))->firstWhere('type', 'link');

    expect($change['fields'])->toBe(['note' => [null, 'The buyer.']])
        ->and($change['message'])->toBe('Relationship orders.user_id → users.id note: none → The buyer.')
        ->and(SchemaDiff::describe($change))->toBe('Relationship orders.user_id → users.id note: none → The buyer.');
});

it('lists connections added and removed', function (): void {
    $after = diffDocument();
    $after['connections'][0] = ['id' => 'connection:analytics', 'name' => 'analytics'] + $after['connections'][0];

    expect(array_map(fn (array $c): string => $c['change'].' '.$c['label'], SchemaDiff::diff(diffDocument(), $after)))
        ->toBe(['added analytics', 'removed mysql']);
});

it('sorts the changes the same way every time', function (): void {
    $after = diffDocument();
    $after['connections'][0]['tables'][0]['columns'][] = diffColumn('a', 'int');
    $after['connections'][0]['tables'][0]['columns'][] = diffColumn('z', 'int');
    $reversed = $after;
    $reversed['connections'][0]['tables'][0]['columns'] = array_reverse($reversed['connections'][0]['tables'][0]['columns']);

    $changes = SchemaDiff::diff(diffDocument(), $after);

    expect($changes)->toBe(SchemaDiff::diff(diffDocument(), $reversed))
        ->and(array_column($changes, 'label'))->toBe(['orders.a', 'orders.z']);
});

it('describes each change as a line', function (array $change, string $line): void {
    expect(SchemaDiff::describe($change))->toBe($line);
})->with([
    'a column' => [['type' => 'column', 'change' => 'changed', 'label' => 'orders.status', 'fields' => ['type' => ['varchar(20)', 'varchar(50)'], 'nullable' => [false, true]]], 'Column orders.status changed: type varchar(20) → varchar(50), nullable no → yes'],
    'a table' => [['type' => 'table', 'change' => 'removed', 'label' => 'users', 'message' => '7 columns'], 'Table users removed: 7 columns'],
    'a connection' => [['type' => 'connection', 'change' => 'added', 'label' => 'analytics', 'message' => '2 tables'], 'Connection analytics added: 2 tables'],
    'an index' => [['type' => 'index', 'change' => 'added', 'label' => 'orders_status_index on orders', 'message' => '(status)'], 'Index orders_status_index on orders added: (status)'],
    'a link' => [['type' => 'link', 'change' => 'changed', 'label' => 'orders.user_id → users.id', 'message' => 'Order::user() is now backed by a foreign key'], 'Order::user() is now backed by a foreign key'],
    'a model' => [['type' => 'model', 'change' => 'removed', 'label' => 'App\Models\Order', 'message' => 'tables mysql:orders'], 'Model App\Models\Order removed: tables mysql:orders'],
    'a relationship' => [['type' => 'relationship', 'change' => 'changed', 'label' => 'Order::user()', 'fields' => ['pivot' => [null, ['table' => 'x']]]], 'Relationship Order::user() changed: pivot none → {"table":"x"}'],
    'a foreign key' => [['type' => 'foreignKey', 'change' => 'changed', 'label' => 'fk on orders', 'fields' => ['onDelete' => ['RESTRICT', 'CASCADE']]], 'Foreign key fk on orders changed: onDelete RESTRICT → CASCADE'],
]);
