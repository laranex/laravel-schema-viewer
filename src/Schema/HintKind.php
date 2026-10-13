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
    case UnresolvedPackageRelation = 'unresolved_package_relation';
    case CustomRelation = 'custom_relation';

    public function severity(): string
    {
        return match ($this) {
            self::ForeignKeyWithoutRelation, self::MorphWithoutMap, self::UnresolvedPackageRelation, self::CustomRelation => 'info',
            default => 'warning',
        };
    }

    /**
     * How sure the hint is: certain when it states a fact read from the database or the models, likely when it is
     * inferred from names or conventions and is worth checking before acting on it.
     *
     * @return 'certain'|'likely'
     */
    public function certainty(): string
    {
        return 'certain';
    }
}
