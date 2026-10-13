import { useEffect, useMemo, useRef } from 'react';
import { shortClass, type Schema } from '../graph';
import type { Table } from '../types';

type Props = {
    schema: Schema;
    // The tables in view, each with its connection, in connection order.
    tables: { connection: string; table: Table }[];
    several: boolean;
    selected: { connection: string; table: string } | null;
    onOpen: (connection: string, table: string) => void;
    // The filter, kept by the panel so it survives switching tabs.
    filter: string;
    onFilter: (filter: string) => void;
};

// Every table in view, grouped by connection, with a filter; picking one opens its details and goes to it.
export function TableList({ schema, tables, several, selected, onOpen, filter, onFilter: setFilter }: Props) {
    const current = useRef<HTMLButtonElement>(null);

    // Coming back to the list shows the selected table.
    useEffect(() => {
        current.current?.scrollIntoView({ block: 'center' });
    }, []);

    const groups = useMemo(() => {
        const byConnection = new Map<string, Table[]>();
        for (const { connection, table } of tables) {
            if (schema.matches(connection, table, filter)) {
                byConnection.set(connection, [...(byConnection.get(connection) ?? []), table]);
            }
        }
        return [...byConnection].map(([connection, list]) => ({ connection, tables: list.sort((a, b) => a.name.localeCompare(b.name)) }));
    }, [schema, tables, filter]);

    const count = groups.reduce((n, g) => n + g.tables.length, 0);
    const first = groups[0];

    return (
        <div className="table-list">
            <div className="field field--filter">
                <label htmlFor="table-filter" className="sr-only">
                    Filter tables
                </label>
                <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" className="field__icon">
                    <circle cx="11" cy="11" r="6.5" />
                    <path d="M16 16 L20.5 20.5" />
                </svg>
                <input
                    id="table-filter"
                    type="text"
                    autoComplete="off"
                    placeholder={`Filter ${tables.length} tables`}
                    value={filter}
                    onChange={(e) => setFilter(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && first) {
                            onOpen(first.connection, first.tables[0].name);
                        } else if (e.key === 'Escape' && filter) {
                            e.stopPropagation();
                            setFilter('');
                        }
                    }}
                />
            </div>
            {count === 0 && <p className="panel__empty">No table matches “{filter.trim()}”.</p>}
            {groups.map((g) => (
                <section key={g.connection} className="table-list__group" aria-label={several ? g.connection : 'Tables'}>
                    {several && (
                        <h3 className="table-list__connection">
                            {g.connection} <span className="muted">{g.tables.length}</span>
                        </h3>
                    )}
                    <ul>
                        {g.tables.map((t) => {
                            const models = schema.modelsFor(g.connection, t.name).map((m) => shortClass(m.class));
                            const relations = schema.relationsOf(g.connection, t.name).length;
                            const hints = schema.hints(g.connection, t.name).length;
                            const isCurrent = selected?.connection === g.connection && selected.table === t.name;
                            return (
                                <li key={t.name}>
                                    <button type="button" ref={isCurrent ? current : undefined} className={`table-list__row${isCurrent ? ' is-current' : ''}`} aria-current={isCurrent || undefined} onClick={() => onOpen(g.connection, t.name)}>
                                        <span className="table-list__name">
                                            <code>{t.name}</code>
                                            {models.length > 0 && <span className="muted">{models.join(', ')}</span>}
                                            {models.length === 0 && (t.framework || t.package) && <span className="muted">{t.package ?? 'framework'}</span>}
                                        </span>
                                        <span className="table-list__meta">
                                            {t.columns.length} col{relations > 0 && ` · ${relations} rel`}
                                            {hints > 0 && (
                                                <span className="count" title={`${hints} ${hints === 1 ? 'hint' : 'hints'}`}>
                                                    {hints}
                                                </span>
                                            )}
                                        </span>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                </section>
            ))}
        </div>
    );
}
