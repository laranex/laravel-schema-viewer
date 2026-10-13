<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

use JsonSerializable;

/**
 * Everything the viewer, the exports and the agent skill read: tables, models, relations, links and hints.
 */
final class SchemaDocument implements JsonSerializable
{
    public const VERSION = 1;

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
        public readonly string $generatedAt,
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

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'version' => self::VERSION,
            'generatedAt' => $this->generatedAt,
            'note' => $this->note,
            'connections' => $this->connections,
            'models' => $this->models,
            'relations' => $this->relations,
            'unresolved' => $this->unresolved,
            'links' => $this->links,
            'derivedLinks' => $this->derivedLinks,
            'hints' => $this->hints,
        ];
    }
}
