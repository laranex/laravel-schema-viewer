<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laranex\LaravelSchemaViewer\Inspection\DatabaseInspector;
use Laranex\LaravelSchemaViewer\Schema\Hint;
use Laranex\LaravelSchemaViewer\Schema\HintKind;
use Laranex\LaravelSchemaViewer\Schema\Index;
use Laranex\LaravelSchemaViewer\SchemaViewer;

/**
 * The hints of one kind, optionally on one table.
 *
 * @return list<Hint>
 */
function structuralHints(HintKind $kind, ?string $table = null): array
{
    return array_values(array_filter(
        app(SchemaViewer::class)->document()->hints,
        fn (Hint $hint): bool => $hint->kind === $kind && ($table === null || $hint->table === $table),
    ));
}

/**
 * One index of a table on the testing connection, as the inspector reads it.
 */
function inspectedIndex(string $table, string $name): ?Index
{
    foreach (app(DatabaseInspector::class)->inspect('testing')->table($table)->indexes ?? [] as $index) {
        if ($index->name === $name) {
            return $index;
        }
    }

    return null;
}

/**
 * A table with a primary key and room for every kind of index.
 */
function createGadgets(): void
{
    Schema::create('gadgets', function (Blueprint $table): void {
        $table->id();
        $table->string('sku')->nullable();
        $table->string('code');
        $table->string('name');
        $table->string('title');
    });
}

afterEach(function (): void {
    InstalledVersions::reload(require dirname(__DIR__, 2).'/vendor/composer/installed.php');
});

it('flags a table without a primary key, and names a unique index that could become one', function (): void {
    Schema::create('settings', function (Blueprint $table): void {
        $table->string('key')->unique();
        $table->text('value')->nullable();
    });
    Schema::create('visits', function (Blueprint $table): void {
        $table->string('path')->nullable()->unique();
    });

    $settings = structuralHints(HintKind::TableWithoutPrimaryKey, 'settings');
    $visits = structuralHints(HintKind::TableWithoutPrimaryKey, 'visits');

    expect($settings)->toHaveCount(1)
        ->and($settings[0]->message)->toBe('settings has no primary key, so one row cannot be updated or deleted reliably and some replication setups refuse the table. Its unique index settings_key_unique (key) could become the primary key.')
        ->and($settings[0]->columns)->toBeNull()
        ->and($settings[0]->jsonSerialize()['severity'])->toBe('warning')
        ->and($settings[0]->jsonSerialize()['certainty'])->toBe('certain')
        // A nullable column cannot be part of a primary key.
        ->and($visits[0]->message)->not->toContain('could become the primary key');
});

it('asks for a composite primary key on a pivot table without one', function (): void {
    $taggables = structuralHints(HintKind::TableWithoutPrimaryKey, 'taggables');

    expect($taggables)->toHaveCount(1)
        ->and($taggables[0]->message)->toMatch('/ Add a composite primary key on \((tag_id, taggable_type, taggable_id|taggable_type, taggable_id, tag_id)\) so a pair cannot be attached twice\.$/')
        ->and(structuralHints(HintKind::TableWithoutPrimaryKey, 'role_user'))->toBe([]);
});

it('flags duplicate indexes, one hint per set of columns', function (): void {
    createGadgets();
    Schema::table('gadgets', function (Blueprint $table): void {
        $table->index('sku', 'gadgets_sku_a');
        $table->index('sku', 'gadgets_sku_b');
        $table->index('sku', 'gadgets_sku_c');
        $table->unique('code', 'gadgets_code_a');
        $table->unique('code', 'gadgets_code_b');
    });

    $messages = array_map(fn (Hint $hint): string => $hint->message, structuralHints(HintKind::DuplicateIndex, 'gadgets'));
    sort($messages);

    expect($messages)->toBe([
        'gadgets has 2 unique indexes on (code): gadgets_code_a, gadgets_code_b. Every write updates each one; keep one.',
        'gadgets has 3 indexes on (sku): gadgets_sku_a, gadgets_sku_b, gadgets_sku_c. Every write updates each one; keep one.',
    ])
        ->and(structuralHints(HintKind::DuplicateIndex, 'gadgets')[0]->kind->certainty())->toBe('certain')
        ->and(structuralHints(HintKind::RedundantIndex, 'gadgets'))->toBe([]);
});

it('flags an index on the primary key\'s columns', function (): void {
    createGadgets();
    Schema::table('gadgets', fn (Blueprint $table) => $table->index('id', 'gadgets_id_index'));

    $hints = structuralHints(HintKind::IndexDuplicatesPrimaryKey, 'gadgets');

    expect($hints)->toHaveCount(1)
        ->and($hints[0]->message)->toBe('gadgets.gadgets_id_index indexes (id), the same columns as the primary key, so it only adds work to every write. Drop it.')
        ->and($hints[0]->columns)->toBe('id');
});

it('flags an index whose columns another index starts with, but never a unique one', function (): void {
    createGadgets();
    Schema::table('gadgets', function (Blueprint $table): void {
        $table->index('name', 'gadgets_name_index');
        $table->index(['name', 'title'], 'gadgets_name_title_index');
        $table->unique('code', 'gadgets_code_unique');
        $table->unique(['code', 'title'], 'gadgets_code_title_unique');
        $table->index('title', 'gadgets_title_index');
        $table->unique('title', 'gadgets_title_unique');
    });

    $hints = collect(structuralHints(HintKind::RedundantIndex, 'gadgets'))->keyBy('columns');

    expect($hints->keys()->sort()->values()->all())->toBe(['name', 'title'])
        ->and($hints['name']->message)->toBe('gadgets.gadgets_name_index on (name) is covered by gadgets_name_title_index on (name, title), which starts with the same columns, so it adds work to every write. Drop it unless a query needs the smaller index.')
        ->and($hints['title']->message)->toContain('gadgets.gadgets_title_index on (title) is covered by gadgets_title_unique on (title)')
        ->and($hints['name']->jsonSerialize()['severity'])->toBe('info')
        ->and($hints['name']->jsonSerialize()['certainty'])->toBe('likely');
});

it('raises no index hint on a composite primary key', function (): void {
    foreach ([HintKind::IndexDuplicatesPrimaryKey, HintKind::DuplicateIndex, HintKind::RedundantIndex] as $kind) {
        expect(structuralHints($kind, 'role_user'))->toBe([]);
    }

    expect(collect(app(DatabaseInspector::class)->inspect('testing')->table('role_user')?->indexes)->where('primary', true)->pluck('columns')->unique()->values()->all())
        ->toBe([['user_id', 'role_id']]);
});

it('reads partial, expression, prefix and INCLUDE indexes, and never compares them', function (): void {
    createGadgets();
    Schema::table('gadgets', fn (Blueprint $table) => $table->index('sku', 'gadgets_sku_index'));
    $driver = testDriver();

    $statements = match ($driver) {
        'sqlite' => [
            'partial' => 'create index gadgets_sku_partial on gadgets (sku) where sku is not null',
            'expression' => 'create index gadgets_sku_lower on gadgets (lower(sku))',
        ],
        'pgsql' => [
            'partial' => 'create index gadgets_sku_partial on gadgets (sku) where sku is not null',
            'expression' => 'create index gadgets_sku_lower on gadgets (lower(sku))',
            'included' => 'create index gadgets_sku_included on gadgets (sku) include (name)',
        ],
        'mysql' => [
            'expression' => 'create index gadgets_sku_lower on gadgets ((lower(sku)))',
            'prefix' => 'create index gadgets_sku_prefix on gadgets (sku(10))',
        ],
        'mariadb' => [
            'prefix' => 'create index gadgets_sku_prefix on gadgets (sku(10))',
        ],
        'sqlsrv' => [
            'partial' => 'create index gadgets_sku_partial on gadgets (sku) where sku is not null',
            'included' => 'create index gadgets_sku_included on gadgets (sku) include (name)',
        ],
    };

    foreach ($statements as $statement) {
        DB::statement($statement);
    }

    if (isset($statements['partial'])) {
        expect(inspectedIndex('gadgets', 'gadgets_sku_partial'))
            ->partial->toBeTrue()
            ->expression->toBeFalse()
            ->plain()->toBeFalse();
    }

    if (isset($statements['expression'])) {
        expect(inspectedIndex('gadgets', 'gadgets_sku_lower'))
            ->expression->toBeTrue()
            ->partial->toBeFalse()
            ->columns->toBe([]);
    }

    if (isset($statements['prefix'])) {
        expect(inspectedIndex('gadgets', 'gadgets_sku_prefix'))
            ->expression->toBeTrue()
            ->columns->toBe(['sku']);
    }

    if (isset($statements['included'])) {
        expect(inspectedIndex('gadgets', 'gadgets_sku_included'))
            ->included->toBe(['name'])
            ->keyColumns()->toBe(['sku'])
            ->plain()->toBeFalse();
    }

    expect(inspectedIndex('gadgets', 'gadgets_sku_index'))
        ->plain()->toBeTrue()
        ->partial->toBeFalse()
        ->expression->toBeFalse()
        ->included->toBe([])
        ->and(structuralHints(HintKind::DuplicateIndex, 'gadgets'))->toBe([])
        ->and(structuralHints(HintKind::RedundantIndex, 'gadgets'))->toBe([]);
});

it('compares only indexes of the same structure', function (): void {
    createGadgets();
    Schema::table('gadgets', fn (Blueprint $table) => $table->index('title', 'gadgets_title_index'));

    $other = match (testDriver()) {
        'mysql', 'mariadb' => ['create fulltext index gadgets_title_fulltext on gadgets (title)', 'fulltext'],
        'pgsql' => ['create index gadgets_title_hash on gadgets using hash (title)', 'hash'],
        default => null,
    };

    if ($other === null) {
        $this->markTestSkipped(testDriver().' has one index structure for these columns.');
    }

    DB::statement($other[0]);

    expect(collect(app(DatabaseInspector::class)->inspect('testing')->table('gadgets')?->indexes)->pluck('type')->all())->toContain($other[1], 'btree')
        ->and(structuralHints(HintKind::DuplicateIndex, 'gadgets'))->toBe([])
        ->and(structuralHints(HintKind::RedundantIndex, 'gadgets'))->toBe([]);
});

it('reports each index type the database names', function (): void {
    $types = collect(app(DatabaseInspector::class)->inspect('testing')->table('posts')?->indexes)->pluck('type', 'name');

    expect($types->unique()->sort()->values()->all())->toBe(match (testDriver()) {
        'sqlite' => [null],
        'sqlsrv' => ['clustered', 'nonclustered'],
        default => ['btree'],
    });
});

it('flags a foreign key whose column type differs from the column it references', function (): void {
    if (testDriver() === 'sqlsrv') {
        $this->markTestSkipped('SQL Server refuses a foreign key between columns of different types.');
    }

    Schema::create('owners', function (Blueprint $table): void {
        testDriver() === 'mysql' || testDriver() === 'mariadb' ? $table->string('id', 255)->primary() : $table->id();
    });
    Schema::create('pets', function (Blueprint $table): void {
        $table->id();
        match (testDriver()) {
            'pgsql' => $table->integer('owner_id'),
            'sqlite' => $table->string('owner_id'),
            default => $table->string('owner_id', 36),
        };
        $table->foreign('owner_id')->references('id')->on('owners');
    });

    $hints = structuralHints(HintKind::ForeignKeyTypeMismatch);

    expect($hints)->toHaveCount(1)
        ->and($hints[0]->table)->toBe('pets')
        ->and($hints[0]->columns)->toBe('owner_id')
        ->and($hints[0]->message)->toMatch('/^pets\.owner_id is .+ but owners\.id, which it references, is .+; give them the same type so every value fits and lookups use the index\.$/');
});

it('raises no type hint for the blog, whose keys match', function (): void {
    expect(structuralHints(HintKind::ForeignKeyTypeMismatch))->toBe([]);
});

it('flags a foreign key named after another table than the one it references', function (): void {
    Schema::create('post_categories', fn (Blueprint $table) => $table->id());
    Schema::create('reviews', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_id')->constrained('countries');
        $table->foreignId('category_id')->constrained('post_categories');
        $table->foreignId('author_id')->constrained('users');
    });

    $hints = structuralHints(HintKind::ForeignKeyNameMismatch);

    expect($hints)->toHaveCount(1)
        ->and($hints[0]->table)->toBe('reviews')
        ->and($hints[0]->columns)->toBe('user_id')
        ->and($hints[0]->message)->toBe('reviews.user_id is named after users, but its foreign key reviews_user_id_foreign references countries. Rename the column or point the key at users.')
        ->and($hints[0]->jsonSerialize()['certainty'])->toBe('likely');
});

it('flags a polymorphic pair no index covers', function (): void {
    Schema::create('attachments', function (Blueprint $table): void {
        $table->id();
        $table->string('attachable_type');
        $table->unsignedBigInteger('attachable_id');
        $table->string('kind_type');
    });

    $hints = structuralHints(HintKind::MorphWithoutIndex);

    expect($hints)->toHaveCount(1)
        ->and($hints[0]->table)->toBe('attachments')
        ->and($hints[0]->columns)->toBe('attachable_type,attachable_id')
        ->and($hints[0]->message)->toBe("attachments.attachable_type and attachable_id look like a polymorphic pair, but no index covers both, so loading these relations scans the table. Add \$table->index(['attachable_type', 'attachable_id']).");

    Schema::table('attachments', fn (Blueprint $table) => $table->index(['attachable_id', 'attachable_type']));

    expect(structuralHints(HintKind::MorphWithoutIndex))->toBe([]);
});

it('raises no structural hint on a framework table, and still does on a package table', function (): void {
    Schema::create('settings', fn (Blueprint $table) => $table->string('key'));

    config()->set('schema-viewer.framework_tables', ['settings']);
    expect(structuralHints(HintKind::TableWithoutPrimaryKey, 'settings'))->toBe([]);

    config()->set('schema-viewer.framework_tables', []);
    InstalledVersions::reload([
        'root' => ['name' => 'test/app', 'pretty_version' => '1.0.0', 'version' => '1.0.0.0', 'reference' => null, 'type' => 'project', 'install_path' => '', 'aliases' => [], 'dev' => true],
        'versions' => ['acme/settings' => ['pretty_version' => '1.0.0', 'version' => '1.0.0.0', 'reference' => null, 'type' => 'library', 'install_path' => '', 'aliases' => [], 'dev_requirement' => false]],
    ]);
    config()->set('schema-viewer.package_tables', ['acme/settings' => ['settings']]);

    $hints = structuralHints(HintKind::TableWithoutPrimaryKey, 'settings');

    expect($hints)->toHaveCount(1)
        ->and(app(DatabaseInspector::class)->inspect('testing', packages: ['acme/settings' => ['settings']])->table('settings')?->package)->toBe('acme/settings');
});

it('reads the index details on every database', function (): void {
    expect(collect(app(DatabaseInspector::class)->inspect('testing')->tables)->every(fn ($table): bool => $table->indexDetails))->toBeTrue();
});
