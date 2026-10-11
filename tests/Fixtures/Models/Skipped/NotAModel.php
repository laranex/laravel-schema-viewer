<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Tests\Fixtures\Models\Skipped;

final class NotAModel
{
    public function name(): string
    {
        return self::class;
    }
}
