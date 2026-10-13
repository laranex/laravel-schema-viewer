<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Laranex\LaravelSchemaViewer\Inspection\RelationResolver;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\Fragile\Fragile;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\Fragile\LegacyRelation;

/*
 * Connection::pretend() leaves the connection pretending (Laravel 10, 11 and 12 before 12.41) and logging queries
 * (before 13.33) when its callback throws. A long-running worker would then drop every write it serves afterwards.
 */

it('leaves no connection pretending or logging when reading a model throws', function (): void {
    config()->set('database.connections.side', config('database.connections.testing'));
    $default = DB::connection();
    $side = DB::connection('side');
    $logging = [$default->logging(), $side->logging()];

    // A return type whose class file has a syntax error: autoloading it throws a ParseError.
    $broken = tempnam(sys_get_temp_dir(), 'broken').'.php';
    file_put_contents($broken, "<?php\nnamespace Laranex\\LaravelSchemaViewer\\Tests\\Fixtures\\Fragile;\nclass LegacyRelation {{\n");
    $loader = function (string $class) use ($broken): void {
        if ($class === LegacyRelation::class) {
            require $broken;
        }
    };
    spl_autoload_register($loader);

    $thrown = null;

    try {
        app(RelationResolver::class)->resolve(new Fragile);
    } catch (Throwable $e) {
        $thrown = $e::class;
    } finally {
        spl_autoload_unregister($loader);
        @unlink($broken);
    }

    DB::table('users')->insert(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret']);

    expect($thrown)->toBe(ParseError::class)
        ->and($default->pretending())->toBeFalse()
        ->and($side->pretending())->toBeFalse()
        ->and([$default->logging(), $side->logging()])->toBe($logging)
        ->and(DB::table('users')->count())->toBe(1);
});
