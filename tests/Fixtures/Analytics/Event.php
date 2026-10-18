<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Tests\Fixtures\Analytics;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laranex\LaravelSchemaViewer\Tests\Fixtures\Models\User;

/**
 * A model on a second connection, with a relation to a model on the default connection.
 */
class Event extends Model
{
    protected $connection = 'analytics';

    /**
     * User sets no connection, so Laravel reads it on this model's connection: analytics.users.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Member fixes its connection: testing.users, across connections.
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'user_id');
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class, 'visitor_id');
    }
}
