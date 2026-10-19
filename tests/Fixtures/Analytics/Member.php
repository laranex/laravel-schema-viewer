<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Tests\Fixtures\Analytics;

use Illuminate\Database\Eloquent\Model;

/**
 * The default connection's users, named by a model that fixes its connection, so relations from another connection
 * reach it there.
 */
class Member extends Model
{
    protected $connection = 'testing';

    protected $table = 'users';
}
