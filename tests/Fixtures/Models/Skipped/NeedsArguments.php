<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Tests\Fixtures\Models\Skipped;

use Illuminate\Database\Eloquent\Model;

class NeedsArguments extends Model
{
    public function __construct(public string $required)
    {
        parent::__construct();
    }
}
