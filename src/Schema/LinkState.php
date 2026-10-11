<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

/**
 * What backs a link between two tables.
 */
enum LinkState: string
{
    case Both = 'both';
    case RelationOnly = 'relation';
    case ForeignKeyOnly = 'foreign_key';
}
