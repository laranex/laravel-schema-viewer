<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Tests\Fixtures\SideEffects;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laranex\LaravelSchemaViewer\SchemaViewer;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\Models\Post;

/**
 * A model whose construction, boot and relation methods do more than build relations. The viewer keeps their queries
 * from reaching any database and their model events from firing; schema-viewer:build also fakes the rest.
 */
class Noisy extends Model
{
    protected $table = 'users';

    /**
     * @var list<string>
     */
    public static array $calls = [];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        self::$calls[] = 'constructed';
        self::$calls[] = SchemaViewer::building() ? 'constructed while building' : 'constructed outside a build';
    }

    protected static function booted(): void
    {
        self::$calls[] = 'booted';

        // Runs on the first `new Noisy`.
        DB::table('posts')->insert(['user_id' => 1, 'title' => 'written while booting']);
    }

    public function cachedPosts(): HasMany
    {
        Cache::put('noisy.cache', 'written by a relation method');

        return $this->hasMany(Post::class, 'user_id');
    }

    public function queuedPosts(): HasMany
    {
        dispatch(new NoisyJob);

        return $this->hasMany(Post::class, 'user_id');
    }

    public function announcedPosts(): HasMany
    {
        event('noisy.announced');
        Log::info('noisy relation read');

        return $this->hasMany(Post::class, 'user_id');
    }

    public function guardedPosts(): HasMany
    {
        if (! SchemaViewer::building()) {
            Http::post('https://hooks.example.test/guarded');
        }

        return $this->hasMany(Post::class, 'user_id');
    }

    public function calledPosts(): HasMany
    {
        Http::post('https://hooks.example.test/noisy');

        return $this->hasMany(Post::class, 'user_id');
    }

    public function savedPosts(): HasMany
    {
        // Its insert and its saving, creating and created events.
        (new Post)->forceFill(['user_id' => 1, 'title' => 'saved by a relation method'])->save();

        return $this->hasMany(Post::class, 'user_id');
    }

    public function auditedPosts(): HasMany
    {
        // A connection no model uses and nothing opened before.
        DB::connection('audit')->table('audit')->insert(['note' => 'written by a relation method']);

        return $this->hasMany(Post::class, 'user_id');
    }

    /**
     * Not a relation at all; only a comment mentions $this->hasMany( so the source scan picks it.
     */
    public function flushEverything()
    {
        // Used to be: return $this->hasMany(Post::class);
        Cache::put('noisy.comment', 'a method that only mentions a builder in a comment ran');

        return 'done';
    }
}
