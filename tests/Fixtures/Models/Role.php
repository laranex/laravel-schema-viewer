<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->using(RoleUser::class)->withPivot('assigned_at');
    }
}
