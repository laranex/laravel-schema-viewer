<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

use JsonSerializable;

/**
 * Every table of one database connection.
 */
final class ConnectionSchema implements JsonSerializable
{
    /**
     * @param  list<Table>  $tables
     */
    public function __construct(
        public readonly string $name,
        public readonly string $driver,
        public readonly array $tables,
        public readonly ?string $defaultSchema = null,
        /** @var list<string>|null The schemas read, or null when Laravel does not say. */
        public readonly ?array $schemas = null,
    ) {}

    /**
     * The tables by name, built on first lookup.
     *
     * @var array<string, Table>|null
     */
    private ?array $byName = null;

    public function table(string $name): ?Table
    {
        if ($this->byName === null) {
            $this->byName = [];

            foreach ($this->tables as $table) {
                $this->byName[$table->name] ??= $table;
            }
        }

        return $this->byName[$name] ?? null;
    }

    /**
     * @return array{name: string, driver: string, tables: list<Table>}
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'driver' => $this->driver,
            'tables' => $this->tables,
        ];
    }
}
