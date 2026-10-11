<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Comment extends Model
{
    protected $casts = ['approved' => 'boolean'];

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}
