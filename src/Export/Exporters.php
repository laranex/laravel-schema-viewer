<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Export;

use Laranex\LaravelSchemaViewer\Contract\Json;

/**
 * The text exporters by format.
 */
final class Exporters
{
    /**
     * @return array<string, Exporter>
     */
    public static function all(): array
    {
        return ['mermaid' => new MermaidExporter, 'dbml' => new DbmlExporter, 'markdown' => new MarkdownExporter, 'json' => new JsonExporter];
    }

    /**
     * The document as the viewer reads it from its canonical JSON: object keys in code point order, so every exporter
     * walks objects in the same order as the browser does.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function normalized(array $document): array
    {
        /** @var array<string, mixed> $normalized */
        $normalized = Json::decode(Json::canonical($document));

        return $normalized;
    }
}
