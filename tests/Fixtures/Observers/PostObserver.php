<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Tests\Fixtures\Observers;

use Laranex\LaravelSchemaViewer\Tests\Fixtures\Models\Post;

class PostObserver
{
    public function created(Post $post): void {}

    public function deleted(Post $post): void {}
}
