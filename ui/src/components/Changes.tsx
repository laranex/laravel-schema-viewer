import { nodeId, shortClass, type Schema } from '../graph';
import type { Change } from '../types';
import type { Highlight } from './Browse';

type Props = {
    schema: Schema;
    // Null or missing: there was no earlier snapshot to compare with.
    changes: Change[] | null | undefined;
    // The connections in view: their changes are listed.
    connections: string[];
    onOpen: (connection: string, table: string) => void;
    onHighlight: (highlight: Highlight) => void;
};

const show = (value: unknown): string => (value === null || value === undefined ? 'none' : typeof value === 'boolean' ? (value ? 'yes' : 'no') : typeof value === 'object' ? JSON.stringify(value) : String(value));

const KIND: Record<Change['type'], string> = {
    connection: 'Connection',
    table: 'Table',
    column: 'Column',
    index: 'Index',
    foreign_key: 'Foreign key',
    model: 'Model',
    relation: 'Relation',
    link: 'Relationship',
    hint: 'Hint',
};

// One change in words: what it is, and what about it changed.
export function changeText(c: Change): string {
    if (c.type === 'link') {
        return c.message ?? `${c.name} changed`;
    }
    if (c.type === 'hint') {
        return `${c.change === 'added' ? 'New hint' : 'Hint gone'}: ${c.message ?? c.name}`;
    }
    const subject = c.type === 'connection' ? c.connection : c.type === 'table' ? c.table : c.type === 'model' ? shortClass(c.name ?? '') : c.type === 'relation' ? `${shortClass(c.name ?? '')}()` : c.name;
    const details = [
        ...Object.entries(c.fields ?? {}).map(([field, [before, after]]) => `${field} ${show(before)} → ${show(after)}`),
        ...Object.entries(c.after ?? {}).map(([field, value]) => `${field} ${show(value)}`),
        ...Object.entries(c.before ?? {}).map(([field, value]) => `${field} ${show(value)}`),
    ];
    return `${KIND[c.type]} ${subject} ${c.change}${details.length ? `: ${details.join(', ')}` : ''}`;
}

// The cards that changed since the previous build, by node id: new tables, and tables with changed parts.
export function changedTables(changes: Change[] | null | undefined): Map<string, 'new' | 'changed'> {
    const marks = new Map<string, 'new' | 'changed'>();
    for (const c of changes ?? []) {
        if (!c.connection || !c.table || c.type === 'hint' || c.type === 'link' || (c.type === 'table' && c.change === 'removed')) {
            continue;
        }
        const id = nodeId(c.connection, c.table);
        if (c.type === 'table' && c.change === 'added') {
            marks.set(id, 'new');
        } else if (!marks.has(id)) {
            marks.set(id, 'changed');
        }
    }
    return marks;
}

// What changed in the schema since the previous build that changed it, grouped by table.
export function ChangeList({ schema, changes, connections, onOpen, onHighlight }: Props) {
    if (!changes) {
        return <p className="panel__empty">Nothing to compare yet. Once a later schema-viewer:build finds that tables, columns, indexes, foreign keys, models or relations changed, this tab lists what changed.</p>;
    }
    const inView = changes.filter((c) => !c.connection || connections.includes(c.connection));
    if (!inView.length) {
        return (
            <>
                <h2 className="changes__head">Changed by the last build that found a difference</h2>
                <p className="panel__empty">{changes.length ? 'Nothing changed on this connection.' : 'Nothing this tab follows changed.'}</p>
            </>
        );
    }
    const groups = new Map<string, Change[]>();
    for (const c of inView) {
        const key = c.table ? `${c.connection}\u0000${c.table}` : c.type === 'connection' ? 'Connections' : c.type === 'model' ? 'Models' : 'Relations';
        groups.set(key, [...(groups.get(key) ?? []), c]);
    }
    const several = connections.length > 1;

    // Where a row leads: a table, a column or a relationship that is still there.
    const target = (c: Change): (() => void) | null => {
        if (c.change === 'removed') {
            return null;
        }
        if (c.type === 'column' && c.connection && c.table && c.name && schema.table(c.connection, c.table)?.columns.some((col) => col.name === c.name)) {
            return () => onHighlight({ kind: 'column', connection: c.connection!, table: c.table!, column: c.name! });
        }
        if (c.type === 'link' && c.connection && c.id && schema.links(c.connection).some((l) => l.id === c.id)) {
            return () => onHighlight({ kind: 'link', connection: c.connection!, id: c.id! });
        }
        if (c.type === 'relation' || c.type === 'model') {
            const model = c.type === 'model' ? c.name : c.name?.split('::')[0];
            const at = model ? schema.tableOfModel(model) : null;
            return at ? () => onOpen(at.connection, at.table) : null;
        }
        if (c.connection && c.table && schema.table(c.connection, c.table)) {
            return () => onOpen(c.connection!, c.table!);
        }
        return null;
    };

    return (
        <div className="changes">
            <h2 className="changes__head">
                Changed by the last build that found a difference <span className="muted">· {inView.length} {inView.length === 1 ? 'change' : 'changes'}</span>
            </h2>
            {[...groups].map(([key, items]) => {
                const [connection, table] = key.split('\u0000');
                return (
                    <section key={key} className="changes__group">
                        <h3>{table ? <code>{several ? `${connection}.${table}` : table}</code> : connection}</h3>
                        <ul className="changes__list">
                            {items.map((c, i) => {
                                const go = target(c);
                                const text = changeText(c);
                                return (
                                    <li key={i} className={`change change--${c.change}`}>
                                        <span className={`change__mark change__mark--${c.change}`}>{c.change === 'added' ? 'new' : c.change}</span>
                                        {go ? (
                                            <button type="button" className="link-button change__text" onClick={go}>
                                                {text}
                                            </button>
                                        ) : (
                                            <span className="change__text">{c.change === 'removed' ? <del>{text}</del> : text}</span>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                );
            })}
        </div>
    );
}
