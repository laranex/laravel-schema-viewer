<?php

declare(strict_types=1);

use Laranex\LaravelSchemaViewer\Inspection\ModelInspector;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\Models;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\Observers\PostObserver;

it('lists the observers listening to a model', function (): void {
    expect(app(ModelInspector::class)->inspect(new Models\Post)->observers)->toBe([]);

    Models\Post::observe(PostObserver::class);

    expect(app(ModelInspector::class)->inspect(new Models\Post)->observers)->toBe([PostObserver::class])
        ->and(app(ModelInspector::class)->inspect(new Models\User)->observers)->toBe([]);
});
