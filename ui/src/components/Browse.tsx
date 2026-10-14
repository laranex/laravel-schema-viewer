import { useEffect, useMemo, useRef } from 'react';
import { linkKind, relationKind, shortClass, type Schema } from '../graph';
import type { Column, DerivedLink, Hint, Link, Table } from '../types';

export type BrowseMode = 'tables' | 'relationships' | 'columns';

// What the panel can show in Details besides a table: one relationship (a link or a through relation) or one column.
export type Highlight = { kind: 'link'; connection: string; id: string } | { kind: 'column'; connection: string; table: string; column: string };

type Props = {
    schema: Schema;
    // The tables in view, each with its connection, in connection order.
    tables: { connection: string; table: Table }[];
    connections: string[];
    several: boolean;
    selected: { connection: string; table: string } | null;
    highlight: Highlight | null;
    mode: BrowseMode;
    onMode: (mode: BrowseMode) => void;
    // The filter, kept by the panel so it survives switching tabs; one for all three lists.
    filter: string;
    onFilter: (filter: string) => void;
    onOpen: (connection: string, table: string) => void;
    onHighlight: (highlight: Highlight) => void;
};

const MODES: { value: BrowseMode; label: string }[] = [
    { value: 'tables', label: 'Tables & Models' },
    { value: 'relationships', label: 'Relationships' },
    { value: 'columns', label: 'Columns' },
];


const STATE_LABEL: Record<Link['state'] | 'through', string> = {
    both: 'FK + relation',
    relation: 'relation only',
    foreign_key: 'FK only',
    through: 'through',
};

// The hints about one link: its relations, or its columns on the table that holds the key.
export function linkHints(schema: Schema, link: Link): Hint[] {
    const columns = (h: Hint) => (h.columns ?? '').split(/[\s,]+/).filter(Boolean);
    return [...schema.hints(link.from.connection, link.from.table), ...(link.to.table === link.from.table ? [] : schema.hints(link.to.connection, link.to.table))].filter(
        (h) => (h.relation && link.relations.includes(h.relation)) || (h.table === link.from.table && h.columns && link.from.columns.every((c) => columns(h).includes(c))),
    );
}

// The hints about one column.
export function columnHints(schema: Schema, connection: string, table: string, column: string): Hint[] {
    return schema.hints(connection, table).filter((h) => (h.columns ?? '').split(/[\s,]+/).includes(column));
}

const includes = (q: string, ...texts: (string | null | undefined)[]) => texts.some((t) => (t ?? '').toLowerCase().includes(q));

// Every table, relationship or column in view, with one filter; picking a row shows it in Details and on the diagram.
export function Browse({ schema, tables, connections, several, selected, highlight, mode, onMode, filter, onFilter, onOpen, onHighlight }: Props) {
    const current = useRef<HTMLButtonElement>(null);
    const q = filter.trim().toLowerCase();

    // Coming back to the list shows what is selected.
    useEffect(() => {
        current.current?.scrollIntoView({ block: 'center' });
    }, [mode]);

    const visible = useMemo(() => new Set(tables.map((e) => `${e.connection}.${e.table.name}`)), [tables]);

    const tableGroups = useMemo(() => {
        if (mode !== 'tables') {
            return [];
        }
        const byConnection = new Map<string, Table[]>();
        for (const { connection, table } of tables) {
            if (schema.matches(connection, table, filter)) {
                byConnection.set(connection, [...(byConnection.get(connection) ?? []), table]);
            }
        }
        return [...byConnection].map(([connection, list]) => ({ connection, rows: list.sort((a, b) => a.name.localeCompare(b.name)) }));
    }, [schema, tables, filter, mode]);

    type RelationRow = { connection: string; link: Link | null; derived: DerivedLink | null; id: string };
    const relationGroups = useMemo(() => {
        if (mode !== 'relationships') {
            return [];
        }
        return connections
            .map((connection) => {
                const rows: RelationRow[] = [];
                for (const link of schema.links(connection)) {
                    if (link.from.connection !== connection || !visible.has(`${link.from.connection}.${link.from.table}`)) {
                        continue;
                    }
                    const names = link.relations.map((r) => schema.relation(r)?.name ?? r);
                    if (!q || includes(q, link.from.table, link.to.table, link.foreignKey, ...link.from.columns, ...link.to.columns, ...names, ...link.relations)) {
                        rows.push({ connection, link, derived: null, id: link.id });
                    }
                }
                for (const d of schema.derived(connection)) {
                    if (visible.has(`${connection}.${d.from}`) && (!q || includes(q, d.from, d.to, d.relation, ...d.via))) {
                        rows.push({ connection, link: null, derived: d, id: d.id });
                    }
                }
                const key = (r: RelationRow) => (r.link ? `${r.link.from.table}.${r.link.from.columns.join(',')}` : `${r.derived?.from}.~`);
                return { connection, rows: rows.sort((a, b) => key(a).localeCompare(key(b))) };
            })
            .filter((g) => g.rows.length > 0);
    }, [schema, connections, visible, q, mode]);

    type ColumnRow = { connection: string; table: Table; column: Column };
    const columnGroups = useMemo(() => {
        if (mode !== 'columns') {
            return [];
        }
        const byConnection = new Map<string, ColumnRow[]>();
        for (const { connection, table } of tables) {
            for (const column of table.columns) {
                if (!q || includes(q, column.name, table.name, `${table.name}.${column.name}`, column.type)) {
                    byConnection.set(connection, [...(byConnection.get(connection) ?? []), { connection, table, column }]);
                }
            }
        }
        return [...byConnection].map(([connection, rows]) => ({
            connection,
            rows: rows.sort((a, b) => a.table.name.localeCompare(b.table.name) || a.table.columns.indexOf(a.column) - b.table.columns.indexOf(b.column)),
        }));
    }, [tables, q, mode]);

    const totals = {
        tables: tables.length,
        relationships: connections.reduce((n, c) => n + schema.links(c).filter((l) => l.from.connection === c).length + schema.derived(c).length, 0),
        columns: tables.reduce((n, e) => n + e.table.columns.length, 0),
    };
    const count = mode === 'tables' ? tableGroups.reduce((n, g) => n + g.rows.length, 0) : mode === 'relationships' ? relationGroups.reduce((n, g) => n + g.rows.length, 0) : columnGroups.reduce((n, g) => n + g.rows.length, 0);

    const pickFirst = () => {
        if (mode === 'tables' && tableGroups[0]) {
            onOpen(tableGroups[0].connection, tableGroups[0].rows[0].name);
        } else if (mode === 'relationships' && relationGroups[0]) {
            onHighlight({ kind: 'link', connection: relationGroups[0].connection, id: relationGroups[0].rows[0].id });
        } else if (mode === 'columns' && columnGroups[0]) {
            const r = columnGroups[0].rows[0];
            onHighlight({ kind: 'column', connection: r.connection, table: r.table.name, column: r.column.name });
        }
    };

    return (
        <div className="table-list">
            <div className="browse__modes" role="radiogroup" aria-label="Browse">
                {MODES.map((m) => (
                    <button key={m.value} type="button" role="radio" aria-checked={mode === m.value} className="browse__mode" onClick={() => onMode(m.value)}>
                        {m.label}
                    </button>
                ))}
            </div>
            <div className="field field--filter">
                <label htmlFor="table-filter" className="sr-only">
                    Filter {mode}
                </label>
                <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" className="field__icon">
                    <circle cx="11" cy="11" r="6.5" />
                    <path d="M16 16 L20.5 20.5" />
                </svg>
                <input
                    id="table-filter"
                    type="text"
                    autoComplete="off"
                    placeholder={`Filter ${totals[mode]} ${mode}`}
                    value={filter}
                    onChange={(e) => onFilter(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            pickFirst();
                        } else if (e.key === 'Escape' && filter) {
                            e.stopPropagation();
                            onFilter('');
                        }
                    }}
                />
            </div>
            {count === 0 && <p className="panel__empty">{q ? `Nothing matches “${filter.trim()}”.` : `No ${mode} here.`}</p>}

            {tableGroups.map((g) => (
                <Group key={g.connection} label={g.connection} count={g.rows.length} several={several}>
                    {g.rows.map((t) => {
                        const models = schema.modelsFor(g.connection, t.name).map((m) => shortClass(m.class));
                        const relations = schema.relationsOf(g.connection, t.name).length;
                        const hints = schema.hints(g.connection, t.name).length;
                        const isCurrent = !highlight && selected?.connection === g.connection && selected.table === t.name;
                        return (
                            <Row key={t.name} current={isCurrent} innerRef={isCurrent ? current : undefined} onClick={() => onOpen(g.connection, t.name)} hints={hints} meta={`${t.columns.length} col${relations > 0 ? ` · ${relations} rel` : ''}`}>
                                <code>{t.name}</code>
                                {models.length > 0 && <span className="muted">{models.join(', ')}</span>}
                                {models.length === 0 && (t.framework || t.package) && <span className="muted">{t.package ?? 'framework'}</span>}
                            </Row>
                        );
                    })}
                </Group>
            ))}

            {relationGroups.map((g) => (
                <Group key={g.connection} label={g.connection} count={g.rows.length} several={several}>
                    {g.rows.map((r) => {
                        const isCurrent = highlight?.kind === 'link' && highlight.id === r.id;
                        if (r.link) {
                            const l = r.link;
                            const names = [...new Set(l.relations.map((id) => schema.relation(id)?.name ?? id))];
                            const cross = l.to.connection !== l.from.connection ? `${l.to.connection}.` : '';
                            return (
                                <Row key={r.id} current={isCurrent} innerRef={isCurrent ? current : undefined} onClick={() => onHighlight({ kind: 'link', connection: g.connection, id: r.id })} hints={linkHints(schema, l).length} meta={STATE_LABEL[l.state]} state={l.state}>
                                    <code>
                                        {l.from.table}.{l.from.columns.join(', ')} → {cross}
                                        {l.to.table}.{l.to.columns.join(', ')}
                                    </code>
                                    <span className="muted">{names.length ? `${names.join(' · ')} · ${linkKind(schema, l)}` : (l.foreignKey ?? linkKind(schema, l))}</span>
                                </Row>
                            );
                        }
                        const d = r.derived as DerivedLink;
                        const relation = schema.relation(d.relation);
                        return (
                            <Row key={r.id} current={isCurrent} innerRef={isCurrent ? current : undefined} onClick={() => onHighlight({ kind: 'link', connection: g.connection, id: r.id })} meta={STATE_LABEL.through} state="through">
                                <code>
                                    {d.from} → {d.via.join(' → ')} → {d.to}
                                </code>
                                <span className="muted">
                                    {relation?.name ?? d.relation} · {relationKind(relation?.type ?? 'HasManyThrough')}
                                </span>
                            </Row>
                        );
                    })}
                </Group>
            ))}

            {columnGroups.map((g) => (
                <Group key={g.connection} label={g.connection} count={g.rows.length} several={several}>
                    {g.rows.map(({ table, column }) => {
                        const isCurrent = highlight?.kind === 'column' && highlight.connection === g.connection && highlight.table === table.name && highlight.column === column.name;
                        const primary = table.indexes.some((i) => i.primary && i.columns.includes(column.name));
                        const foreign = table.foreignKeys.some((f) => f.columns.includes(column.name));
                        return (
                            <Row
                                key={`${table.name}.${column.name}`}
                                current={isCurrent}
                                innerRef={isCurrent ? current : undefined}
                                onClick={() => onHighlight({ kind: 'column', connection: g.connection, table: table.name, column: column.name })}
                                hints={columnHints(schema, g.connection, table.name, column.name).length}
                                meta={[primary ? 'PK' : '', foreign ? 'FK' : '', column.type].filter(Boolean).join(' · ')}
                            >
                                <code>
                                    <span className="muted">{table.name}.</span>
                                    {column.name}
                                    {column.nullable ? '?' : ''}
                                </code>
                            </Row>
                        );
                    })}
                </Group>
            ))}

        </div>
    );
}

function Group({ label, count, several, children }: { label: string; count: number; several: boolean; children: React.ReactNode }) {
    return (
        <section className="table-list__group" aria-label={label}>
            {several && (
                <h3 className="table-list__connection">
                    {label} <span className="muted">{count}</span>
                </h3>
            )}
            <ul>{children}</ul>
        </section>
    );
}

function Row({
    current,
    innerRef,
    onClick,
    hints = 0,
    meta,
    state,
    children,
}: {
    current: boolean;
    innerRef?: React.Ref<HTMLButtonElement>;
    onClick: () => void;
    hints?: number;
    meta: string;
    state?: Link['state'] | 'through';
    children: React.ReactNode;
}) {
    return (
        <li>
            <button type="button" ref={innerRef} className={`table-list__row${current ? ' is-current' : ''}`} aria-current={current || undefined} onClick={onClick}>
                <span className="table-list__name">{children}</span>
                <span className="table-list__meta">
                    {state && <span className={`browse__swatch browse__swatch--${state}`} aria-hidden="true" />}
                    {meta}
                    {hints > 0 && (
                        <span className="count" title={`${hints} ${hints === 1 ? 'hint' : 'hints'}`}>
                            {hints}
                        </span>
                    )}
                </span>
            </button>
        </li>
    );
}
