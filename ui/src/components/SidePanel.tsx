import { relationKind, relationLabel, shortClass, type Schema } from '../graph';
import { Tab, TabList, TabPanel, Tabs, Tooltip } from './ui';
import type { Hint, Relation } from '../types';

type Props = {
    schema: Schema;
    connection: string;
    selected: string | null;
    tab: 'table' | 'hints';
    onTab: (tab: 'table' | 'hints') => void;
    onOpen: (connection: string, table: string) => void;
    sheet: boolean;
    sheetOpen: boolean;
    onSheet: (open: boolean) => void;
};

const HINT_TITLE: Record<Hint['kind'], string> = {
    relation_without_foreign_key: 'Relation without a foreign key',
    foreign_key_without_relation: 'Foreign key without a relation',
    foreign_key_without_index: 'Foreign key without an index',
    model_without_table: 'Model without a table',
    morph_without_map: 'Morph map not enforced',
    unresolved_relation: 'Relation could not be resolved',
};

export function SidePanel({ schema, connection, selected, tab, onTab, onOpen, sheet, sheetOpen, onSheet }: Props) {
    const hints = schema.hints(connection);
    const models = selected ? schema.modelsFor(connection, selected) : [];

    return (
        <aside className={`panel${sheet ? ' panel--sheet' : ''}${sheet && sheetOpen ? ' is-open' : ''}`} aria-label="Details">
            {sheet && (
                <div className="sheet-bar">
                    <span className="sheet-bar__grip" aria-hidden="true" />
                    <button type="button" className="sheet-bar__main" aria-expanded={sheetOpen} onClick={() => onSheet(!sheetOpen)}>
                        <span className="sheet-bar__title">
                            {selected ? <code>{selected}</code> : <span>Tap a table for details</span>}
                            {selected && models.length > 0 && <span className="muted">{models.map((m) => shortClass(m.class)).join(', ')}</span>}
                        </span>
                    </button>
                    {hints.length > 0 && !(sheetOpen && tab === 'hints') && (
                        <button type="button" className="sheet-bar__hints" onClick={() => onTab('hints')}>
                            <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" className="ui-icon">
                                <path d="M8 2 L14.5 13.5 H1.5 Z M8 6.5 V9.5 M8 11.6 V11.7" />
                            </svg>
                            {hints.length} {hints.length === 1 ? 'hint' : 'hints'}
                        </button>
                    )}
                    <button type="button" className="sheet-bar__action" aria-expanded={sheetOpen} onClick={() => onSheet(!sheetOpen)}>
                        {sheetOpen ? 'Close' : 'Details'}
                    </button>
                </div>
            )}
            <Tabs value={tab} onValueChange={(v) => onTab(v as 'table' | 'hints')} className="panel__tabs-root">
                <TabList label="Details">
                    <Tab value="table">
                        Table <kbd className="kbd-hint">T</kbd>
                    </Tab>
                    <Tab value="hints">
                        Hints <span className="count">{hints.length}</span> <kbd className="kbd-hint">H</kbd>
                    </Tab>
                </TabList>
                <TabPanel value="table" className="panel__body">
                    {selected ? (
                        <TableDetails schema={schema} connection={connection} name={selected} onOpen={onOpen} />
                    ) : (
                        <p className="panel__empty">Select a table to see its columns, relations, indexes and model.</p>
                    )}
                </TabPanel>
                <TabPanel value="hints" className="panel__body">
                    <HintList hints={hints} onOpen={(t) => onOpen(connection, t)} />
                </TabPanel>
            </Tabs>
        </aside>
    );
}

function HintList({ hints, onOpen }: { hints: Hint[]; onOpen: (table: string) => void }) {
    if (!hints.length) {
        return <p className="panel__empty">No hints on this connection.</p>;
    }
    return (
        <ul className="hints">
            {hints.map((h, i) => (
                <li key={i} className={`hint hint--${h.severity}`}>
                    <div className="hint__head">
                        <span className="hint__severity">{h.severity}</span>
                        <span className="hint__title">{HINT_TITLE[h.kind]}</span>
                    </div>
                    <p>{h.message}</p>
                    <button type="button" className="link-button" onClick={() => onOpen(h.table)}>
                        Show {h.table}
                    </button>
                </li>
            ))}
        </ul>
    );
}

function TableDetails({ schema, connection, name, onOpen }: { schema: Schema; connection: string; name: string; onOpen: (connection: string, table: string) => void }) {
    const table = schema.table(connection, name);
    if (!table) {
        return null;
    }
    const models = schema.modelsFor(connection, name);
    const relations = schema.relationsOf(connection, name);
    const incoming = schema.incoming(connection, name);
    const hints = schema.hints(connection, name);
    const primary = table.indexes.find((i) => i.primary)?.columns ?? [];
    const foreign = new Map(table.foreignKeys.flatMap((f) => f.columns.map((c) => [c, f])));
    const casts = Object.assign({}, ...models.map((m) => m.casts)) as Record<string, string>;
    const hidden = new Set(models.flatMap((m) => m.hidden));

    const openRelated = (r: Relation) => {
        const target = schema.tableOfModel(r.related);
        if (target) {
            onOpen(target.connection, target.table);
        }
    };

    return (
        <div className="details">
            <header className="details__head">
                <h2>{table.name}</h2>
                <p className="details__sub">
                    {connection} · {table.columns.length} columns{table.framework ? ' · framework table' : ''}
                </p>
                {table.comment && <p className="details__comment">{table.comment}</p>}
                <div className="chips">
                    {models.length ? models.map((m) => <code key={m.class} className="chip chip--model">{m.class}</code>) : <span className="chip chip--none">No Eloquent model uses this table</span>}
                </div>
            </header>

            {hints.map((h, i) => (
                <div key={i} className={`callout callout--${h.severity}`}>
                    <strong>{HINT_TITLE[h.kind]}.</strong> {h.message}
                </div>
            ))}

            <section>
                <h3>Columns</h3>
                <div className="scroll-x">
                    <table className="grid">
                        <thead>
                            <tr>
                                <th scope="col">Name</th>
                                <th scope="col">Type</th>
                                <th scope="col">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            {table.columns.map((c) => {
                                const f = foreign.get(c.name);
                                return (
                                    <tr key={c.name}>
                                        <td>
                                            <code>{c.name}</code>
                                        </td>
                                        <td className="muted">{c.type}</td>
                                        <td>
                                            <div className="badges">
                                                {primary.includes(c.name) && <span className="badge badge--pk">PK</span>}
                                                {f && (
                                                    <Tooltip
                                                        content={
                                                            <>
                                                                <code>{f.name}</code>
                                                                <br />
                                                                on update {f.onUpdate}, on delete {f.onDelete}
                                                            </>
                                                        }
                                                    >
                                                        <button type="button" className="badge badge--fk" onClick={() => onOpen(connection, f.foreignTable)}>
                                                            FK → {f.foreignTable}
                                                        </button>
                                                    </Tooltip>
                                                )}
                                                {c.autoIncrement && <span className="badge">auto</span>}
                                                {c.nullable && <span className="badge">nullable</span>}
                                                {c.default !== null && <span className="badge">default {c.default}</span>}
                                                {casts[c.name] && <span className="badge badge--cast">cast {shortClass(casts[c.name])}</span>}
                                                {hidden.has(c.name) && <span className="badge">hidden</span>}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </section>

            {relations.length > 0 && (
                <section>
                    <h3>Relations</h3>
                    <ul className="relations">
                        {relations.map((r) => (
                            <li key={r.id}>
                                <div className="relation__head">
                                    <code>{r.name}()</code>
                                    <span className="relation__type">
                                        {r.type} · {relationKind(r.type)}
                                    </span>
                                    <button type="button" className="link-button" onClick={() => openRelated(r)}>
                                        {shortClass(r.related)}
                                    </button>
                                </div>
                                <RelationKeys schema={schema} relation={r} />
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {incoming.length > 0 && (
                <section>
                    <h3>Used by</h3>
                    <ul className="relations">
                        {incoming.map((r) => (
                            <li key={r.id}>
                                <div className="relation__head">
                                    <code>{relationLabel(r.id)}()</code>
                                    <span className="relation__type">
                                        {r.type} · {relationKind(r.type)}
                                    </span>
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <section>
                <h3>Indexes</h3>
                <ul className="plain">
                    {table.indexes.map((i) => (
                        <li key={i.name}>
                            <code>{i.name}</code>
                            <span className="muted">
                                {' '}
                                ({i.columns.join(', ')}){i.primary ? ' primary' : i.unique ? ' unique' : ''}
                            </span>
                        </li>
                    ))}
                </ul>
            </section>

            {models.map((m) => (
                <section key={m.class}>
                    <h3>{shortClass(m.class)} model</h3>
                    <dl className="facts">
                        <dt>Key</dt>
                        <dd>
                            {m.primaryKey} ({m.keyType}
                            {m.incrementing ? ', incrementing' : ''})
                        </dd>
                        <dt>Behavior</dt>
                        <dd>{[m.timestamps ? 'timestamps' : 'no timestamps', m.softDeletes ? 'soft deletes' : ''].filter(Boolean).join(', ')}</dd>
                        {m.fillable.length > 0 && (
                            <>
                                <dt>Fillable</dt>
                                <dd>{m.fillable.join(', ')}</dd>
                            </>
                        )}
                        {m.guarded.length > 0 && (
                            <>
                                <dt>Guarded</dt>
                                <dd>{m.guarded.join(', ')}</dd>
                            </>
                        )}
                        {m.hidden.length > 0 && (
                            <>
                                <dt>Hidden</dt>
                                <dd>{m.hidden.join(', ')}</dd>
                            </>
                        )}
                        {m.appends.length > 0 && (
                            <>
                                <dt>Appends</dt>
                                <dd>{m.appends.join(', ')}</dd>
                            </>
                        )}
                        {m.scopes.length > 0 && (
                            <>
                                <dt>Scopes</dt>
                                <dd>{m.scopes.join(', ')}</dd>
                            </>
                        )}
                        {m.globalScopes.length > 0 && (
                            <>
                                <dt>Global scopes</dt>
                                <dd>{m.globalScopes.map(shortClass).join(', ')}</dd>
                            </>
                        )}
                        {m.observers.length > 0 && (
                            <>
                                <dt>Observers</dt>
                                <dd>{m.observers.map(shortClass).join(', ')}</dd>
                            </>
                        )}
                    </dl>
                </section>
            ))}
        </div>
    );
}

function RelationKeys({ schema, relation: r }: { schema: Schema; relation: Relation }) {
    const parts: string[] = [];
    if (r.pivot) {
        parts.push(`via ${r.pivot.table} (${r.pivot.foreignPivotKey}, ${r.pivot.relatedPivotKey})`);
        if (r.pivot.columns.length) {
            parts.push(`pivot columns: ${r.pivot.columns.join(', ')}`);
        }
        if (r.pivot.using) {
            parts.push(`using ${shortClass(r.pivot.using)}, as ${r.pivot.accessor}`);
        }
    } else if (r.through) {
        parts.push(`through ${r.through.join(' → ')}`);
    } else if (r.keys) {
        const cross = r.keys.from.connection !== r.keys.to.connection;
        parts.push(`${r.keys.from.table}.${r.keys.from.columns.join(', ')} → ${cross ? `${r.keys.to.connection}.` : ''}${r.keys.to.table}.${r.keys.to.columns.join(', ')}`);
    }
    if (r.morph) {
        parts.push(`morph: ${r.morph.type}, ${r.morph.id}`);
    }
    const link = schema.doc.links.find((l) => l.relations.includes(r.id));
    const state = r.through ? 'derived' : link?.state === 'both' ? 'FK + relation' : link ? 'relation only' : r.pivot ? 'via pivot' : '';

    return (
        <div className="relation__keys">
            {parts.map((p) => (
                <span key={p}>{p}</span>
            ))}
            <div className="badges">
                {state && <span className={`badge badge--state-${link?.state ?? 'through'}`}>{state}</span>}
                {r.constraints.map((c) => (
                    <span key={c} className="badge badge--constraint">
                        {c}
                    </span>
                ))}
            </div>
        </div>
    );
}
