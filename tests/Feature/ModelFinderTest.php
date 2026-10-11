<?php

declare(strict_types=1);

use Laranex\LaravelSchemaViewer\Inspection\ModelFinder;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\Models;

it('finds the models that can be built without arguments', function (): void {
    $models = (new ModelFinder)->find([__DIR__.'/../Fixtures/Models', __DIR__.'/../Fixtures/does-not-exist']);

    expect($models)->toBe([
        Models\Category::class,
        Models\Comment::class,
        Models\Country::class,
        Models\Ghost::class,
        Models\Post::class,
        Models\Role::class,
        Models\RoleUser::class,
        Models\Tag::class,
        Models\User::class,
    ]);
});

it('reads class names from a file without loading it', function (): void {
    $code = <<<'PHP'
        <?php
        namespace App\Models;

        $name = Foo::class;
        $object = new class {};

        final class Invoice extends \Illuminate\Database\Eloquent\Model {}
        PHP;

    expect((new ModelFinder)->classesIn($code))->toBe(['App\Models\Invoice']);
});
