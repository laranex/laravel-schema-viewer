<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Tests\Fixtures\Analytics;

use Illuminate\Database\Eloquent\Model;

/**
 * Lives in the analytics connection's users table, a name the default connection has too.
 */
class Visitor extends Model
{
    protected $connection = 'analytics';

    protected $table = 'users';
}
