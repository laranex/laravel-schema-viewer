import { linkKind, relationKind, shortClass, type Schema } from '../graph';
import { columnHints, linkHints, type Highlight } from './Browse';
import { Certainty, HINT_TITLE, IgnoreRules, RelationKeys, SourceLink } from './SidePanel';
import { Tooltip } from './ui';
import type { Hint, Link, Relation } from '../types';

type Props = {
    schema: Schema;
    highlight: Highlight;
    editor: string | null;
    onOpen: (connection: string, table: string) => void;
    onHighlight: (highlight: Highlight) => void;
    focusedOn: Highlight | null;
    onFocus: (highlight: Highlight) => void;
    onLeaveFocus: () => void;
    onCopyLink: () => void;
};

const same = (a: Highlight | null, b: Highlight) => !!a && JSON.stringify(a) === JSON.stringify(b);

// Focus mode for this relationship or column, or the badge that leaves it.
// Focus and Copy link, as a table's details have them.
function Actions({ highlight, focusedOn, onFocus, onLeaveFocus, onCopyLink, children }: Pick<Props, 'highlight' | 'focusedOn' | 'onFocus' | 'onLeaveFocus' | 'onCopyLink'> & { children?: React.ReactNode }) {
    return (
        <div className="details__actions">
            <FocusButton highlight={highlight} focusedOn={focusedOn} onFocus={onFocus} onLeaveFocus={onLeaveFocus} />
            <Tooltip content={<>A link that opens exactly this view <kbd>Shift C</kbd></>}>
                <button type="button" className="button button--small" onClick={onCopyLink}>
                    Copy link
                </button>
            </Tooltip>
            {children}
        </div>
    );
}

function FocusButton({ highlight, focusedOn, onFocus, onLeaveFocus }: Pick<Props, 'highlight' | 'focusedOn' | 'onFocus' | 'onLeaveFocus'>) {
    return same(focusedOn, highlight) ? (
        <Tooltip content={<>Leave focus mode <kbd>Esc</kbd></>}>
            <button type="button" className="badge badge--focus" aria-pressed="true" onClick={onLeaveFocus}>
                In focus
            </button>
        </Tooltip>
    ) : (
        <Tooltip content={<>Show only the tables it touches, and what is around them <kbd>Shift F</kbd></>}>
            <button type="button" className="button button--small" onClick={() => onFocus(highlight)}>
                Focus
            </button>
        </Tooltip>
    );
}

const STATE_TEXT: Record<Link['state'], { label: string; text: string }> = {
    both: { label: 'FK + relation', text: 'A foreign key, and a relation that uses it.' },
    relation: { label: 'relation only', text: 'A relation without a foreign key, so rows can point at deleted ones.' },
    foreign_key: { label: 'FK only', text: 'A foreign key no model declares a relation for.' },
};

// Details of a highlighted relationship or column; a table's details are TableDetails.
export function HighlightDetails(props: Props) {
    return props.highlight.kind === 'link' ? <RelationshipDetails {...props} id={props.highlight.id} connection={props.highlight.connection} /> : <ColumnDetails {...props} />;
}

function Hints({ hints }: { hints: Hint[] }) {
    return (
        <>
            {hints.map((h, i) => (
                <div key={i} className={`callout callout--${h.severity}`}>
                    <strong>{HINT_TITLE[h.kind]}.</strong> <Certainty hint={h} /> {h.message}
                    <IgnoreRules hint={h} />
                </div>
            ))}
        </>
    );
}

// A table.column that highlights the column, and its table that opens the table.
function ColumnRef({ connection, table, columns, onOpen, onHighlight, cross = false }: { connection: string; table: string; columns: string[]; onOpen: Props['onOpen']; onHighlight: Props['onHighlight']; cross?: boolean }) {
    return (
        <span className="ref">
            <button type="button" className="link-button" onClick={() => onOpen(connection, table)}>
                {cross ? `${connection}.` : ''}
                {table}
            </button>
            .
            {columns.map((c, i) => (
                <span key={c}>
                    {i > 0 && ', '}
                    <button type="button" className="link-button" onClick={() => onHighlight({ kind: 'column', connection, table, column: c })}>
                        {c}
                    </button>
                </span>
            ))}
        </span>
    );
}

function RelationItem({ relation: r, schema, editor }: { relation: Relation; schema: Schema; editor: string | null }) {
    return (
        <li>
            <div className="relation__head">
                <SourceLink source={r.source} editor={editor}>
                    <code>
                        {shortClass(r.model)}::{r.name}()
                    </code>
                </SourceLink>
                <span className="relation__type">
                    {r.type} · {relationKind(r.type)}
                </span>
            </div>
            <RelationKeys schema={schema} relation={r} />
        </li>
    );
}

function RelationshipDetails({ schema, connection, id, editor, onOpen, onHighlight, highlight, focusedOn, onFocus, onLeaveFocus, onCopyLink }: Props & { connection: string; id: string }) {
    const focusButton = <Actions highlight={highlight} focusedOn={focusedOn} onFocus={onFocus} onLeaveFocus={onLeaveFocus} onCopyLink={onCopyLink} />;
    const link = schema.links(connection).find((l) => l.id === id);
    const derived = link ? undefined : schema.derived(connection).find((d) => d.id === id);

    if (derived) {
        const r = schema.relation(derived.relation);
        return (
            <div className="details">
                <header className="details__head">
                    <h2>
                        {derived.from} → {derived.to}
                    </h2>
                    <p className="details__sub">
                        {connection} · through {derived.via.join(' → ')} · {relationKind(r?.type ?? 'HasManyThrough')}
                    </p>
                    {focusButton}
                </header>
                <section>
                    <h3>Path</h3>
                    <p className="ref">
                        {[derived.from, ...derived.via, derived.to].map((t, i) => (
                            <span key={`${t}${i}`}>
                                {i > 0 && ' → '}
                                <button type="button" className="link-button" onClick={() => onOpen(connection, t)}>
                                    {t}
                                </button>
                            </span>
                        ))}
                    </p>
                </section>
                {r && (
                    <section>
                        <h3>Relation</h3>
                        <ul className="relations">
                            <RelationItem relation={r} schema={schema} editor={editor} />
                        </ul>
                    </section>
                )}
            </div>
        );
    }
    if (!link) {
        return <p className="panel__empty">This relationship is not on the diagram any more.</p>;
    }

    const relations = link.relations.map((r) => schema.relation(r)).filter((r): r is Relation => r !== undefined);
    const fk = link.foreignKey ? schema.table(link.from.connection, link.from.table)?.foreignKeys.find((f) => f.name === link.foreignKey) : undefined;
    const indexed = schema.table(link.from.connection, link.from.table)?.indexes.find((i) => link.from.columns.every((c, n) => i.columns[n] === c));
    const state = STATE_TEXT[link.state];
    const cross = link.to.connection !== link.from.connection;

    return (
        <div className="details">
            <header className="details__head">
                <h2>
                    {link.from.table} → {link.to.table}
                </h2>
                <p className="details__sub">
                    {link.from.connection} · {linkKind(schema, link)} · <span className={`state state--${link.state}`}>{state.label}</span>
                </p>
                {focusButton}
            </header>

            <Hints hints={linkHints(schema, link)} />

            <section>
                <h3>Keys</h3>
                <p className="ref">
                    <ColumnRef connection={link.from.connection} table={link.from.table} columns={link.from.columns} onOpen={onOpen} onHighlight={onHighlight} /> →{' '}
                    <ColumnRef connection={link.to.connection} table={link.to.table} columns={link.to.columns} onOpen={onOpen} onHighlight={onHighlight} cross={cross} />
                </p>
                <p className="muted">{state.text}</p>
            </section>

            <section>
                <h3>Foreign key</h3>
                {fk ? (
                    <dl className="facts">
                        <dt>Name</dt>
                        <dd>
                            <code>{fk.name}</code>
                        </dd>
                        <dt>On update</dt>
                        <dd>{fk.onUpdate}</dd>
                        <dt>On delete</dt>
                        <dd>{fk.onDelete}</dd>
                        <dt>Index</dt>
                        <dd>{indexed ? <code>{indexed.name}</code> : 'none'}</dd>
                    </dl>
                ) : (
                    <p className="muted">None: the database does not enforce this relationship.</p>
                )}
            </section>

            <section>
                <h3>Relations</h3>
                {relations.length ? (
                    <ul className="relations">
                        {relations.map((r) => (
                            <RelationItem key={r.id} relation={r} schema={schema} editor={editor} />
                        ))}
                    </ul>
                ) : (
                    <p className="muted">No model declares a relation over these columns.</p>
                )}
            </section>
        </div>
    );
}

function ColumnDetails({ schema, highlight, onOpen, onHighlight, focusedOn, onFocus, onLeaveFocus, onCopyLink }: Props) {
    if (highlight.kind !== 'column') {
        return null;
    }
    const { connection, table: name, column: columnName } = highlight;
    const table = schema.table(connection, name);
    const column = table?.columns.find((c) => c.name === columnName);
    if (!table || !column) {
        return <p className="panel__empty">This column is not on the diagram any more.</p>;
    }

    const models = schema.modelsFor(connection, name);
    const indexes = table.indexes.filter((i) => i.columns.includes(columnName));
    const outgoing = table.foreignKeys.filter((f) => f.columns.includes(columnName));
    const incoming = schema.tables(connection).flatMap((t) => t.foreignKeys.filter((f) => f.foreignTable === name && f.foreignColumns.includes(columnName)).map((f) => ({ table: t.name, fk: f })));
    const uses = schema.links(connection).filter((l) => (l.from.table === name && l.from.connection === connection && l.from.columns.includes(columnName)) || (l.to.table === name && l.to.connection === connection && l.to.columns.includes(columnName)));

    return (
        <div className="details">
            <header className="details__head">
                <h2>
                    {name}.{columnName}
                </h2>
                <p className="details__sub">
                    {connection} · {column.type}
                    {column.nullable ? ' · nullable' : ''}
                </p>
                {column.comment && <p className="details__comment">{column.comment}</p>}
                <Actions highlight={highlight} focusedOn={focusedOn} onFocus={onFocus} onLeaveFocus={onLeaveFocus} onCopyLink={onCopyLink}>
                    <button type="button" className="button button--small" onClick={() => onOpen(connection, name)}>
                        Open {name}
                    </button>
                </Actions>
            </header>

            <Hints hints={columnHints(schema, connection, name, columnName)} />

            <section>
                <h3>Column</h3>
                <dl className="facts">
                    <dt>Type</dt>
                    <dd>
                        <code>{column.type}</code>
                    </dd>
                    <dt>Null</dt>
                    <dd>{column.nullable ? 'allowed' : 'not null'}</dd>
                    {column.default !== null && (
                        <>
                            <dt>Default</dt>
                            <dd>
                                <code>{column.default}</code>
                            </dd>
                        </>
                    )}
                    {column.autoIncrement && (
                        <>
                            <dt>Increments</dt>
                            <dd>auto increment</dd>
                        </>
                    )}
                    {models.map((m) =>
                        m.casts[columnName] || m.hidden.includes(columnName) || m.fillable.includes(columnName) ? (
                            <span key={m.class} style={{ display: 'contents' }}>
                                <dt>{shortClass(m.class)}</dt>
                                <dd>
                                    {[m.casts[columnName] ? `cast ${shortClass(m.casts[columnName])}` : '', m.fillable.includes(columnName) ? 'fillable' : '', m.hidden.includes(columnName) ? 'hidden' : ''].filter(Boolean).join(', ')}
                                </dd>
                            </span>
                        ) : null,
                    )}
                </dl>
            </section>

            <section>
                <h3>Indexes</h3>
                {indexes.length ? (
                    <ul className="plain">
                        {indexes.map((i) => (
                            <li key={i.name}>
                                <code>{i.name}</code>
                                <span className="muted">
                                    {' '}
                                    ({i.columns.join(', ')}){i.primary ? ' primary' : i.unique ? ' unique' : ''}
                                </span>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="muted">Not in any index.</p>
                )}
            </section>

            {(outgoing.length > 0 || incoming.length > 0) && (
                <section>
                    <h3>Foreign keys</h3>
                    <ul className="plain">
                        {outgoing.map((f) => (
                            <li key={f.name}>
                                → <ColumnRef connection={connection} table={f.foreignTable} columns={f.foreignColumns} onOpen={onOpen} onHighlight={onHighlight} />{' '}
                                <span className="muted">
                                    <code>{f.name}</code>, on delete {f.onDelete}
                                </span>
                            </li>
                        ))}
                        {incoming.map(({ table: t, fk }) => (
                            <li key={`${t}.${fk.name}`}>
                                ← <ColumnRef connection={connection} table={t} columns={fk.columns} onOpen={onOpen} onHighlight={onHighlight} />{' '}
                                <span className="muted">
                                    <code>{fk.name}</code>
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <section>
                <h3>Relationships</h3>
                {uses.length ? (
                    <ul className="plain">
                        {uses.map((l) => {
                            const names = [...new Set(l.relations.map((r) => schema.relation(r)?.name ?? r))];
                            return (
                                <li key={l.id}>
                                    <button type="button" className="link-button" onClick={() => onHighlight({ kind: 'link', connection, id: l.id })}>
                                        {l.from.table}.{l.from.columns.join(', ')} → {l.to.table}.{l.to.columns.join(', ')}
                                    </button>
                                    <span className="muted">
                                        {' '}
                                        {names.length ? names.join(' · ') : STATE_TEXT[l.state].label}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                ) : (
                    <p className="muted">No relationship uses this column.</p>
                )}
            </section>
        </div>
    );
}
