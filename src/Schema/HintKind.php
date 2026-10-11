<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

/**
 * Things in a schema worth a second look.
 */
enum HintKind: string
{
    case RelationWithoutForeignKey = 'relation_without_foreign_key';
    case ForeignKeyWithoutRelation = 'foreign_key_without_relation';
    case ForeignKeyWithoutIndex = 'foreign_key_without_index';
    case ModelWithoutTable = 'model_without_table';
    case MorphWithoutMap = 'morph_without_map';
    case UnresolvedRelation = 'unresolved_relation';

    public function severity(): string
    {
        return match ($this) {
            self::ForeignKeyWithoutRelation, self::MorphWithoutMap => 'info',
            default => 'warning',
        };
    }
}
