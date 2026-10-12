<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Tests\Fixtures\Unsafe;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\Models\Post;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\Models\User;

/**
 * Methods that look like relations but write: reading them must never reach the database.
 */
class Wiper extends Model
{
    protected $table = 'users';

    public function wipePosts()
    {
        return $this->hasMany(Post::class, 'user_id')->delete();
    }

    public function renameUsers()
    {
        return $this->hasMany(User::class, 'country_id')->update(['name' => 'wiped']);
    }

    public function addPost()
    {
        $this->hasMany(Post::class, 'user_id')->insert(['user_id' => 1, 'title' => 'injected']);

        return DB::table('users')->delete();
    }
}
