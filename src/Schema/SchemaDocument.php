<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

/**
 * Everything the viewer, the exports and the agent skill read: tables, models, relations, links and hints.
 */
final class SchemaDocument
{
    /**
     * @param  list<ConnectionSchema>  $connections
     * @param  list<ModelInfo>  $models
     * @param  list<RelationInfo>  $relations
     * @param  list<UnresolvedRelation>  $unresolved
     * @param  list<Link>  $links
     * @param  list<DerivedLink>  $derivedLinks
     * @param  list<Hint>  $hints
     */
    public function __construct(
        public readonly array $connections,
        public readonly array $models,
        public readonly array $relations,
        public readonly array $unresolved,
        public readonly array $links,
        public readonly array $derivedLinks,
        public readonly array $hints,
        public readonly ?string $note = null,
    ) {}

    public function connection(string $name): ?ConnectionSchema
    {
        foreach ($this->connections as $connection) {
            if ($connection->name === $name) {
                return $connection;
            }
        }

        return null;
    }
}
