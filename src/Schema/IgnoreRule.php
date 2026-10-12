<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Schema;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One hints.ignore rule: a hint is left out when it matches every field the rule names; a field left out matches
 * anything. Connection and table take wildcards.
 */
final class IgnoreRule
{
    public const FIELDS = ['connection', 'table', 'kind', 'columns', 'relation'];

    public function __construct(
        public readonly ?string $connection = null,
        public readonly ?string $table = null,
        public readonly ?HintKind $kind = null,
        public readonly ?string $columns = null,
        public readonly ?string $relation = null,
    ) {}

    /**
     * Read a rule from the config, refusing unknown fields and kinds so a typo cannot ignore the wrong hints.
     *
     * @throws InvalidArgumentException
     */
    public static function fromConfig(mixed $rule): self
    {
        if (! is_array($rule) || $rule === []) {
            throw new InvalidArgumentException(sprintf(
                'Each hints.ignore rule is an array of %s, such as [\'kind\' => \'morph_without_map\']; got %s.',
                implode(', ', self::FIELDS), is_scalar($rule) ? (string) json_encode($rule) : get_debug_type($rule),
            ));
        }

        $unknown = array_diff(array_map('strval', array_keys($rule)), self::FIELDS);

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf('Unknown hints.ignore field [%s]; use %s.', implode(', ', $unknown), implode(', ', self::FIELDS)));
        }

        $fields = [];

        foreach ($rule as $field => $value) {
            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException(sprintf('The hints.ignore field [%s] must be a non-empty string.', $field));
            }

            $fields[(string) $field] = $value;
        }

        $rule = $fields;
        $kind = isset($rule['kind']) ? HintKind::tryFrom($rule['kind']) : null;

        if (isset($rule['kind']) && $kind === null) {
            throw new InvalidArgumentException(sprintf('Unknown hint kind [%s] in hints.ignore.', $rule['kind']));
        }

        return new self(
            connection: $rule['connection'] ?? null,
            table: $rule['table'] ?? null,
            kind: $kind,
            columns: isset($rule['columns']) ? str_replace(' ', '', $rule['columns']) : null,
            relation: isset($rule['relation']) ? ltrim($rule['relation'], '\\') : null,
        );
    }

    public function matches(Hint $hint): bool
    {
        return ($this->connection === null || Str::is($this->connection, $hint->connection))
            && ($this->table === null || Str::is($this->table, $hint->table))
            && ($this->kind === null || $this->kind === $hint->kind)
            && ($this->columns === null || $this->columns === $hint->columns)
            && ($this->relation === null || $this->relation === $hint->relation);
    }

    /**
     * The rule as a PHP array literal, ready to paste into config/schema-viewer.php.
     *
     * @param  array<string, string>  $rule
     */
    public static function php(array $rule): string
    {
        return '['.implode(', ', array_map(
            fn (string $field, string $value): string => "'".$field."' => '".str_replace("'", "\\'", $value)."'",
            array_keys($rule), $rule,
        )).']';
    }
}
