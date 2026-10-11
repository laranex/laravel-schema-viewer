<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends Model
{
    protected $fillable = ['name', 'email', 'password', 'country_id'];

    protected $hidden = ['password'];

    protected $casts = ['email_verified_at' => 'datetime'];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function latestPost(): HasOne
    {
        return $this->hasOne(Post::class)->latestOfMany();
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->using(RoleUser::class)->withPivot('assigned_at')->as('assignment');
    }

    /**
     * @param  Builder<User>  $query
     */
    public function scopeVerified(Builder $query): void
    {
        $query->whereNotNull('email_verified_at');
    }
}
