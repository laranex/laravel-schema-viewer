<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Tests\Fixtures\Unreadable;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A model that cannot be constructed without the request it expects.
 */
class Exploding extends Model
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        throw new RuntimeException('No tenant is set.');
    }
}
