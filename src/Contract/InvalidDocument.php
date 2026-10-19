<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Contract;

use InvalidArgumentException;

/**
 * A laranex-schema 2.x document that fails validation; the message names the first five errors.
 */
final class InvalidDocument extends InvalidArgumentException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        $more = count($errors) > 5 ? '; and '.(count($errors) - 5).' more' : '';

        parent::__construct('Invalid laranex-schema document: '.implode('; ', array_slice($errors, 0, 5)).$more);
    }
}
