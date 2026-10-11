import type { DerivedLink, Hint, Link, Model, Relation, SchemaDocument, Table } from './types';

export const NODE_WIDTH = 268;
export const HEADER_HEIGHT = 52;
export const ROW_HEIGHT = 26;

export const nodeId = (connection: string, table: string) => `${connection}.${table}`;

export const nodeHeight = (table: Table) => HEADER_HEIGHT + table.columns.length * ROW_HEIGHT + 8;

export const shortClass = (cls: string) => cls.split('\\').pop() ?? cls;

export const relationLabel = (id: string) => {
    const [cls, name] = id.split('::');
    return `${shortClass(cls)}::${name}`;
};

// Lookups over one schema document, built once.
export class Schema {
    readonly doc: SchemaDocument;
    private readonly relationById = new Map<string, Relation>();
    private readonly modelByClass = new Map<string, Model>();

    constructor(doc: SchemaDocument) {
        this.doc = doc;
        doc.relations.forEach((r) => this.relationById.set(r.id, r));
        doc.models.forEach((m) => this.modelByClass.set(m.class, m));
    }

    tables(connection: string): Table[] {
        return this.doc.connections.find((c) => c.name === connection)?.tables ?? [];
    }

    table(connection: string, name: string): Table | undefined {
        return this.tables(connection).find((t) => t.name === name);
    }

    relation(id: string): Relation | undefined {
        return this.relationById.get(id);
    }

    model(cls: string): Model | undefined {
        return this.modelByClass.get(cls);
    }

    modelsFor(connection: string, table: string): Model[] {
        return this.doc.models.filter((m) => m.connection === connection && m.table === table);
    }

    tableOfModel(cls: string): { connection: string; table: string } | undefined {
        const m = this.model(cls);
        return m && { connection: m.connection, table: m.table };
    }

    relationsOf(connection: string, table: string): Relation[] {
        const classes = this.modelsFor(connection, table).map((m) => m.class);
        return this.doc.relations.filter((r) => classes.includes(r.model));
    }

    // Relations on other tables' models that point at this table.
    incoming(connection: string, table: string): Relation[] {
        const classes = this.modelsFor(connection, table).map((m) => m.class);
        return this.doc.relations.filter((r) => classes.includes(r.related) && !classes.includes(r.model));
    }

    links(connection: string): Link[] {
        return this.doc.links.filter((l) => l.from.connection === connection || l.to.connection === connection);
    }

    derived(connection: string): DerivedLink[] {
        return this.doc.derivedLinks.filter((d) => d.connection === connection);
    }

    hints(connection: string, table?: string): Hint[] {
        return this.doc.hints.filter((h) => h.connection === connection && (table === undefined || h.table === table));
    }

    pivotTables(connection: string): Set<string> {
        const names = new Set<string>();
        this.doc.relations.forEach((r) => {
            if (r.pivot && this.model(r.model)?.connection === connection) {
                names.add(r.pivot.table);
            }
        });
        return names;
    }

    // Foreign key columns with a unique index are one-to-one.
    isUnique(connection: string, table: string, columns: string[]): boolean {
        const t = this.table(connection, table);
        return !!t?.indexes.some((i) => i.unique && i.columns.length === columns.length && i.columns.every((c, n) => c === columns[n]));
    }

    // Tables linked to the start table, up to the given depth.
    neighbors(connection: string, start: string, depth: number): Set<string> {
        const seen = new Set([nodeId(connection, start)]);
        let frontier = [nodeId(connection, start)];
        const edges = [
            ...this.links(connection).map((l) => [nodeId(l.from.connection, l.from.table), nodeId(l.to.connection, l.to.table)]),
            ...this.derived(connection).map((d) => [nodeId(connection, d.from), nodeId(connection, d.to)]),
        ];
        for (let level = 0; level < depth; level++) {
            const next: string[] = [];
            for (const [a, b] of edges) {
                if (frontier.includes(a) && !seen.has(b)) {
                    seen.add(b);
                    next.push(b);
                }
                if (frontier.includes(b) && !seen.has(a)) {
                    seen.add(a);
                    next.push(a);
                }
            }
            frontier = next;
        }
        return seen;
    }

    matches(connection: string, table: Table, query: string): boolean {
        const q = query.trim().toLowerCase();
        if (!q) {
            return true;
        }
        const haystack = [
            table.name,
            ...table.columns.map((c) => c.name),
            ...this.modelsFor(connection, table.name).map((m) => m.class),
            ...this.relationsOf(connection, table.name).map((r) => r.name),
        ];
        return haystack.some((s) => s.toLowerCase().includes(q));
    }
}

// The kind of relationship a Laravel relation type describes.
const RELATION_KINDS: Record<string, string> = {
    HasOne: 'one-to-one',
    BelongsTo: 'one-to-many',
    HasMany: 'one-to-many',
    BelongsToMany: 'many-to-many',
    MorphOne: 'polymorphic one-to-one',
    MorphMany: 'polymorphic one-to-many',
    MorphTo: 'polymorphic one-to-many',
    MorphToMany: 'polymorphic many-to-many',
    MorphedByMany: 'polymorphic many-to-many',
    HasOneThrough: 'has-one-through',
    HasManyThrough: 'has-many-through',
};

export const relationKind = (type: string) => RELATION_KINDS[type] ?? type;

// The relationship a link stands for, from its relations, or from the foreign key alone.
export function linkKind(schema: Schema, link: Link): string {
    const relations = link.relations.map((id) => schema.relation(id)).filter((r): r is Relation => r !== undefined);
    if (relations.some((r) => r.pivot)) {
        return link.morph ? 'polymorphic many-to-many' : 'many-to-many';
    }
    const unique = schema.isUnique(link.from.connection, link.from.table, link.from.columns);
    const oneSided = relations.length > 0 && relations.every((r) => r.type === 'HasOne' || r.type === 'MorphOne' || r.type === 'BelongsTo' || r.type === 'MorphTo');
    const many = !unique && !(oneSided && relations.some((r) => (r.type === 'HasOne' || r.type === 'MorphOne') && !r.constraints.length));
    return `${link.morph ? 'polymorphic ' : ''}${many ? 'one-to-many' : 'one-to-one'}`;
}
