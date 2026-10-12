<?php

declare(strict_types=1);

use Laranex\LaravelSchemaViewer\Export\MarkdownExporter;
use Laranex\LaravelSchemaViewer\Export\MermaidExporter;
use Laranex\LaravelSchemaViewer\Schema\Column;
use Laranex\LaravelSchemaViewer\Schema\ConnectionSchema;
use Laranex\LaravelSchemaViewer\Schema\ForeignKey;
use Laranex\LaravelSchemaViewer\Schema\Index;
use Laranex\LaravelSchemaViewer\Schema\Table;

function hostileConnection(): ConnectionSchema
{
    $parent = new Table('parents', null, false, [new Column('id', 'integer', 'integer', false, null, true, null)], [new Index('primary', ['id'], true, true)], []);
    $child = new Table(
        name: 'kids<img src=x onerror=alert(1)>',
        comment: "Kids & <script>alert(1)</script>\n| injected | row |",
        framework: false,
        columns: [
            new Column('id', 'integer', 'integer', false, null, true, null),
            new Column('na`me|x', 'varchar<b>', 'varchar', true, "a`b|c\nd", false, '<a href="javascript:x">click</a> `code`'),
            new Column("parent\"id\nx", 'integer', 'integer', false, null, false, null),
        ],
        indexes: [new Index('primary', ['id'], true, true), new Index('idx`<b>', ['na`me|x'], false, false)],
        foreignKeys: [new ForeignKey('fk`<i>', ["parent\"id\nx"], 'parents', ['id'], 'cascade', 'no action')],
    );

    return new ConnectionSchema('main<x>', 'sqlite', [$parent, $child]);
}

it('escapes database text in the Markdown export', function (): void {
    $markdown = (new MarkdownExporter)->export(hostileConnection());

    // Code spans show their text literally; outside them, no HTML may remain.
    $prose = (string) preg_replace('/(?<!\\\\)`[^`]*`/', '', $markdown);

    expect($prose)->not->toContain('<')
        ->and($prose)->not->toContain('>')
        ->and($markdown)->toContain('## kids&lt;img src=x onerror=alert(1)&gt;')
        ->and($markdown)->toContain('Kids &amp; &lt;script&gt;alert(1)&lt;/script&gt; \| injected \| row \|')
        ->and($markdown)->toContain("| `na'me\\|x` | varchar&lt;b&gt; | yes | `a'b\\|c d` |")
        ->and($markdown)->toContain('&lt;a href="javascript:x"&gt;click&lt;/a&gt; \`code\` |')
        ->and($markdown)->toContain("- `idx'<b>` (na\\`me\\|x)");

    // Every table row keeps its six cells.
    foreach (explode("\n", $markdown) as $line) {
        if (str_starts_with($line, '| ')) {
            expect(preg_match_all('/(?<!\\\\)\|/', $line))->toBe(7);
        }
    }
});

it('keeps Mermaid labels on one line and inside their quotes', function (): void {
    $mermaid = (new MermaidExporter)->export(hostileConnection());

    expect($mermaid)->toContain('parents ||--o{ kids_img_src_x_onerror_alert_1__ : "parent\'id x"')
        ->and(substr_count($mermaid, '"'))->toBe(2);
});
