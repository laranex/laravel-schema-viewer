import type { DerivedLink, Hint, Link, Model, Relation, SchemaDocument, Table } from './types';

export const NODE_WIDTH = 268;
export const HEADER_HEIGHT = 52;
export const ROW_HEIGHT = 26;

export const nodeId = (connection: string, table: string) => `${connection}.${table}`;

// How much of a table card shows: every column, only the key columns, or the header alone.
export type Fold = 'all' | 'keys' | 'none';

// The rows a card shows: its columns, and with some folded away a last row counting them.
export const shownColumns = (table: Table, fold: Fold, keys: Set<string>) =>
    fold === 'all' ? table.columns.map((c) => c.name) : fold === 'keys' ? table.columns.filter((c) => keys.has(c.name)).map((c) => c.name) : [];

export const nodeHeight = (table: Table, shown = table.columns.length, noteRows = 0) =>
    shown === 0 && table.columns.length > 0 ? HEADER_HEIGHT + 2 : HEADER_HEIGHT + (shown + noteRows + (shown < table.columns.length ? 1 : 0)) * ROW_HEIGHT + 8;

// While Notes is on, a card gives each note a row of its own: the table's under the header, a column's under the
// column. Edges are routed by row, so the rows list names them too.
export const NOTE_ROW = '\u0000note';
export const cardRows = (table: Table, shown: string[], notes: boolean): string[] => {
    if (!notes || shown.length === 0) {
        return shown;
    }
    const noted = new Set(table.columns.filter((c) => c.note).map((c) => c.name));
    return [...(table.note ? [NOTE_ROW] : []), ...shown.flatMap((c) => (noted.has(c) ? [c, `${NOTE_ROW}:${c}`] : [c]))];
};

export const shortClass = (cls: string) => cls.split('\\').pop() ?? cls;

export const relationLabel = (id: string) => {
    const [cls, name] = id.split('::');
    return `${shortClass(cls)}::${name}`;
};

// Lookups over one schema document: indexes built once, per-table answers cached, so a lookup never scans the document.
export class Schema {
    readonly doc: SchemaDocument;
    private readonly relationById = new Map<string, Relation>();
    private readonly modelByClass = new Map<string, Model>();
    private readonly tablesByConnection = new Map<string, Table[]>();
    private readonly tableByKey = new Map<string, Table>();
    private readonly modelsByTable = new Map<string, Model[]>();
    private readonly relationsByTable = new Map<string, Relation[]>();
    private readonly incomingByTable = new Map<string, Relation[]>();
    private readonly linksByConnection = new Map<string, Link[]>();
    private readonly derivedByConnection = new Map<string, DerivedLink[]>();
    private readonly hintsByConnection = new Map<string, Hint[]>();
    private readonly hintsByTable = new Map<string, Hint[]>();
    private readonly linkColumnsByTable = new Map<string, string[]>();
    private readonly morphTypesByTable = new Map<string, string[]>();
    private readonly keyCache = new Map<string, Set<string>>();
    private readonly pivotCache = new Map<string, Set<string>>();
    private readonly adjacency = new Map<string, Map<string, string[]>>();
    private readonly haystacks = new Map<string, string[]>();

    constructor(doc: SchemaDocument) {
        this.doc = doc;
        const push = <T>(map: Map<string, T[]>, key: string, value: T) => {
            const list = map.get(key);
            if (list) {
                list.push(value);
            } else {
                map.set(key, [value]);
            }
        };

        doc.relations.forEach((r) => this.relationById.set(r.id, r));
        doc.models.forEach((m) => {
            this.modelByClass.set(m.class, m);
            push(this.modelsByTable, nodeId(m.connection, m.table), m);
        });
        doc.connections.forEach((c) => {
            this.tablesByConnection.set(c.name, c.tables);
            c.tables.forEach((t) => {
                const key = nodeId(c.name, t.name);
                if (!this.tableByKey.has(key)) {
                    this.tableByKey.set(key, t);
                }
            });
        });

        const tableOf = (cls: string) => {
            const m = this.modelByClass.get(cls);
            return m ? nodeId(m.connection, m.table) : undefined;
        };
        doc.relations.forEach((r) => {
            const own = tableOf(r.model);
            const related = tableOf(r.related);
            if (own !== undefined) {
                push(this.relationsByTable, own, r);
            }
            if (related !== undefined && related !== own) {
                push(this.incomingByTable, related, r);
            }
            if (r.morph && r.keys) {
                push(this.morphTypesByTable, nodeId(r.keys.from.connection, r.keys.from.table), r.morph.type);
            }
        });

        doc.links.forEach((l) => {
            push(this.linksByConnection, l.from.connection, l);
            if (l.to.connection !== l.from.connection) {
                push(this.linksByConnection, l.to.connection, l);
            }
            l.from.columns.forEach((c) => push(this.linkColumnsByTable, nodeId(l.from.connection, l.from.table), c));
            l.to.columns.forEach((c) => push(this.linkColumnsByTable, nodeId(l.to.connection, l.to.table), c));
        });
        doc.derivedLinks.forEach((d) => push(this.derivedByConnection, d.connection, d));
        doc.hints.forEach((h) => {
            push(this.hintsByConnection, h.connection, h);
            push(this.hintsByTable, nodeId(h.connection, h.table), h);
        });
    }

    tables(connection: string): Table[] {
        return this.tablesByConnection.get(connection) ?? [];
    }

    table(connection: string, name: string): Table | undefined {
        return this.tableByKey.get(nodeId(connection, name));
    }

    relation(id: string): Relation | undefined {
        return this.relationById.get(id);
    }

    model(cls: string): Model | undefined {
        return this.modelByClass.get(cls);
    }

    modelsFor(connection: string, table: string): Model[] {
        return this.modelsByTable.get(nodeId(connection, table)) ?? [];
    }

    tableOfModel(cls: string): { connection: string; table: string } | undefined {
        const m = this.model(cls);
        return m && { connection: m.connection, table: m.table };
    }

    relationsOf(connection: string, table: string): Relation[] {
        return this.relationsByTable.get(nodeId(connection, table)) ?? [];
    }

    // Relations on other tables' models that point at this table.
    incoming(connection: string, table: string): Relation[] {
        return this.incomingByTable.get(nodeId(connection, table)) ?? [];
    }

    links(connection: string): Link[] {
        return this.linksByConnection.get(connection) ?? [];
    }

    derived(connection: string): DerivedLink[] {
        return this.derivedByConnection.get(connection) ?? [];
    }

    hints(connection: string, table?: string): Hint[] {
        return (table === undefined ? this.hintsByConnection.get(connection) : this.hintsByTable.get(nodeId(connection, table))) ?? [];
    }

    pivotTables(connection: string): Set<string> {
        let names = this.pivotCache.get(connection);
        if (!names) {
            names = new Set<string>();
            for (const r of this.doc.relations) {
                if (r.pivot && this.model(r.model)?.connection === connection) {
                    names.add(r.pivot.table);
                }
            }
            this.pivotCache.set(connection, names);
        }
        return names;
    }

    // The columns a folded card keeps: the primary key, foreign keys and every column a link or relation uses.
    keyColumns(connection: string, table: string): Set<string> {
        const key = nodeId(connection, table);
        let keys = this.keyCache.get(key);
        if (!keys) {
            const t = this.table(connection, table);
            keys = new Set([
                ...(t?.indexes.find((i) => i.primary)?.columns ?? []),
                ...(t?.foreignKeys.flatMap((f) => f.columns) ?? []),
                ...(this.linkColumnsByTable.get(key) ?? []),
                ...(this.morphTypesByTable.get(key) ?? []),
            ]);
            this.keyCache.set(key, keys);
        }
        return keys;
    }

    // Foreign key columns with a unique index are one-to-one.
    isUnique(connection: string, table: string, columns: string[]): boolean {
        const t = this.table(connection, table);
        return !!t?.indexes.some((i) => i.unique && i.columns.length === columns.length && i.columns.every((c, n) => c === columns[n]));
    }

    // Tables linked to the start table, up to the given depth.
    neighbors(connection: string, start: string, depth: number): Set<string> {
        const edges = this.edgesOf(connection);
        const seen = new Set([nodeId(connection, start)]);
        let frontier = [nodeId(connection, start)];
        for (let level = 0; level < depth; level++) {
            const next: string[] = [];
            for (const id of frontier) {
                for (const other of edges.get(id) ?? []) {
                    if (!seen.has(other)) {
                        seen.add(other);
                        next.push(other);
                    }
                }
            }
            frontier = next;
        }
        return seen;
    }

    // Each table's linked tables, through links and through relations, as seen from one connection.
    private edgesOf(connection: string): Map<string, string[]> {
        let edges = this.adjacency.get(connection);
        if (!edges) {
            const map = new Map<string, string[]>();
            const add = (a: string, b: string) => {
                const list = map.get(a);
                if (list) {
                    list.push(b);
                } else {
                    map.set(a, [b]);
                }
            };
            const pairs = [
                ...this.links(connection).map((l) => [nodeId(l.from.connection, l.from.table), nodeId(l.to.connection, l.to.table)]),
                ...this.derived(connection).map((d) => [nodeId(connection, d.from), nodeId(connection, d.to)]),
            ];
            for (const [a, b] of pairs) {
                add(a, b);
                add(b, a);
            }
            edges = map;
            this.adjacency.set(connection, edges);
        }
        return edges;
    }

    matches(connection: string, table: Table, query: string): boolean {
        const q = query.trim().toLowerCase();
        if (!q) {
            return true;
        }
        const key = nodeId(connection, table.name);
        let haystack = this.haystacks.get(key);
        if (!haystack) {
            haystack = [
                table.name,
                ...table.columns.map((c) => c.name),
                table.note ?? '',
                ...table.columns.map((c) => c.note ?? ''),
                ...this.links(connection)
                    .filter((l) => l.from.table === table.name)
                    .map((l) => l.note ?? ''),
                ...this.modelsFor(connection, table.name).map((m) => m.class),
                ...this.relationsOf(connection, table.name).map((r) => r.name),
            ].map((s) => s.toLowerCase());
            this.haystacks.set(key, haystack);
        }
        return haystack.some((s) => s.includes(q));
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
