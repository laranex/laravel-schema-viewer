<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Tests\Fixtures\Fragile;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\Models\Post;

/**
 * A model with a method whose return type names a class that fails to load: checking whether that type is a relation
 * throws while the connections pretend. Kept out of the model folders the other tests scan.
 */
class Fragile extends Model
{
    protected $table = 'users';

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'user_id');
    }

    public function legacy(): LegacyRelation
    {
        return new LegacyRelation;
    }
}
