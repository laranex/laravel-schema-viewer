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
    ) {}

    public function table(string $name): ?Table
    {
        foreach ($this->tables as $table) {
            if ($table->name === $name) {
                return $table;
            }
        }

        return null;
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
