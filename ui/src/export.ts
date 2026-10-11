import { relationLabel, type Schema } from './graph';

const ident = (s: string) => s.replace(/[^A-Za-z0-9_]/g, '_');

// Mermaid erDiagram for one connection: solid lines are foreign keys, dotted lines are relations without one.
export function toMermaid(schema: Schema, connection: string): string {
    const lines = ['erDiagram'];
    for (const table of schema.tables(connection)) {
        const fkColumns = new Set(table.foreignKeys.flatMap((f) => f.columns));
        const pk = new Set(table.indexes.find((i) => i.primary)?.columns ?? []);
        lines.push(`    ${ident(table.name)} {`);
        for (const c of table.columns) {
            const keys = [pk.has(c.name) ? 'PK' : '', fkColumns.has(c.name) ? 'FK' : ''].filter(Boolean).join(', ');
            lines.push(`        ${ident(c.typeName)} ${ident(c.name)}${keys ? ` ${keys}` : ''}`);
        }
        lines.push('    }');
    }
    for (const link of schema.links(connection)) {
        if (link.from.connection !== connection || link.to.connection !== connection) {
            continue;
        }
        const many = !schema.isUnique(connection, link.from.table, link.from.columns);
        const line = link.foreignKey ? '--' : '..';
        const label = link.relations.length ? link.relations.map(relationLabel).join(', ') : link.foreignKey;
        lines.push(`    ${ident(link.to.table)} ||${line}${many ? 'o{' : 'o|'} ${ident(link.from.table)} : "${label}"`);
    }
    return lines.join('\n');
}

export function toJson(schema: Schema): string {
    return JSON.stringify(schema.doc, null, 2);
}
