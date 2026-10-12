<?php

declare(strict_types=1);

namespace Acme\Tokens;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A package model whose relation, like Passport's Token::user(), needs a loaded model to know its target.
 */
abstract class PackageToken extends Model
{
    protected $table = 'tokens';

    public function owner(): BelongsTo
    {
        $provider = $this->getAttribute('provider');

        return is_string($provider) ? $this->belongsTo($provider) : throw new LogicException('The token has no provider.');
    }
}
