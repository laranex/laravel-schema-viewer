<?php

declare(strict_types=1);

namespace Laranex\LaravelSchemaViewer\Export;

/**
 * One text format of the shared export system (laranex-schema-viewer-ui/export): the same document and options give the
 * same bytes and warnings here as in the viewer's Download dialog.
 */
interface Exporter
{
    /**
     * The file extension, without the dot.
     */
    public function extension(): string;

    /**
     * @param  array<string, mixed>  $document  A laranex-schema 2.x document.
     * @param  list<string>|null  $connections  Connection ids; every connection when null.
     * @param  bool  $orm  Mermaid and Markdown: also the ORM's relationships and the inferred associations, labelled.
     * @return array{content: string, warnings: list<array{code: string, subject: array{type: string, id: string|null, label: string|null}, message: string}>}
     */
    public function export(array $document, ?array $connections = null, bool $orm = false): array;
}
