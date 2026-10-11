<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

class Post extends Model
{
    use SoftDeletes;

    protected $casts = ['published_at' => 'datetime'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function approvedComments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable')->where('approved', true);
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /**
     * An older relation without a return type, found from its body.
     */
    public function writer()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function broken(): BelongsTo
    {
        return $this->belongsTo('Laranex\\LaravelSchemaViewer\\Tests\\Fixtures\\Models\\Missing');
    }

    /**
     * Not a relation: must never be called.
     */
    public function publish(): void
    {
        throw new LogicException('The resolver must not call methods that are not relations.');
    }
}
