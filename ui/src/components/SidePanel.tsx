import { useState, type ReactNode } from 'react';
import { relationKind, relationLabel, shortClass, type Schema } from '../graph';
import { Browse, type BrowseMode, type Highlight } from './Browse';
import { ChangeList } from './Changes';
import { HighlightDetails } from './Details';
import { Dialog, Tab, TabList, TabPanel, Tabs, Tooltip } from './ui';
import type { Change, Hint, IgnoreRule, Index, Relation, Source, Table } from '../types';

export type PanelTab = 'browse' | 'details' | 'hints' | 'changes';

type Props = {
    schema: Schema;
    // The connections in view: their hints are listed.
    connections: string[];
    // The tables in view, listed in the Tables tab.
    tables: { connection: string; table: Table }[];
    selected: { connection: string; table: string } | null;
    // Several cards picked at once, by node id; Details lists them.
    group?: string[];
    // A relationship or column shown in Details instead of the selected table.
    highlight: Highlight | null;
    onHighlight: (highlight: Highlight) => void;
    tab: PanelTab;
    onTab: (tab: PanelTab) => void;
    onOpen: (connection: string, table: string) => void;
    sheet: boolean;
    sheetOpen: boolean;
    onSheet: (open: boolean) => void;
    // The editor link format, with {file} and {line}; null when no editor is configured.
    editor: string | null;
    // The table focus mode is on, if any.
    focused: { connection: string; table: string } | null;
    onFocus: (table: { connection: string; table: string }) => void;
    // The relationship or column focus mode is on, if any.
    focusedOn: Highlight | null;
    onFocusHighlight: (highlight: Highlight) => void;
    onLeaveFocus: () => void;
    onCopyLink: () => void;
    // What changed since the previous build; null before there was one to compare with.
    changes: Change[] | null | undefined;
};

type TableProps = Pick<Props, 'schema' | 'onOpen' | 'onHighlight' | 'editor' | 'focused' | 'onFocus' | 'onLeaveFocus' | 'onCopyLink'> & { connection: string; name: string };

// Schemes a link must never carry; the server drops them too.
const UNSAFE_HREF = /^[\s\0-\x1f]*(javascript|data|vbscript|blob|file):/i;

// Where a model or relation is declared: a link that opens the editor, or the path to copy when there is none.
export function SourceLink({ source, editor, children }: { source: Source | null | undefined; editor: string | null; children: ReactNode }) {
    if (!source) {
        return <>{children}</>;
    }
    const where = `${source.file}:${source.line}`;
    if (!editor || UNSAFE_HREF.test(editor)) {
        return (
            <Tooltip content={<code>{where}</code>}>
                <span className="source">{children}</span>
            </Tooltip>
        );
    }
    const href = editor.replace('{file}', () => encodeURI(source.file)).replace('{line}', () => String(source.line));
    return (
        <Tooltip content={<>Open <code>{where}</code> in your editor</>}>
            <a className="source source--link" href={href}>
                {children}
                <svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true" className="ui-icon">
                    <path d="M9.5 2.5 H13.5 V6.5 M13.5 2.5 L7.5 8.5 M11.5 9.5 V13.5 H2.5 V4.5 H6.5" />
                </svg>
            </a>
        </Tooltip>
    );
}

export const HINT_TITLE: Record<Hint['kind'], string> = {
    relation_without_foreign_key: 'Relation without a foreign key',
    foreign_key_without_relation: 'Foreign key without a relation',
    foreign_key_without_index: 'Foreign key without an index',
    model_without_table: 'Model without a table',
    morph_without_map: 'Morph map not enforced',
    unresolved_relation: 'Relation could not be resolved',
    unresolved_package_relation: 'Package relation could not be resolved',
    custom_relation: 'Custom relation, foreign key not checked',
    table_without_primary_key: 'Table without a primary key',
    foreign_key_type_mismatch: 'Foreign key type differs from what it references',
    foreign_key_name_mismatch: 'Foreign key named after another table',
    index_duplicates_primary_key: 'Index on the primary key columns',
    duplicate_index: 'Duplicate indexes',
    redundant_index: 'Index covered by another',
    morph_without_index: 'Polymorphic columns without an index',
};

// A small "likely" mark on a hint inferred from names or conventions; nothing on a certain one.
export function Certainty({ hint }: { hint: Hint }) {
    if (hint.certainty !== 'likely') {
        return null;
    }
    return (
        <Tooltip content="Found from names or conventions; check before changing it">
            <span className="badge badge--likely" tabIndex={0}>
                likely
            </span>
        </Tooltip>
    );
}

// One index: its name, key columns and kind, with what makes it more than a plain index.
export function IndexLine({ index: i }: { index: Index }) {
    const included = i.included ?? [];
    const key = i.columns.filter((c) => !included.includes(c));
    const type = i.type && !['btree', 'clustered', 'nonclustered'].includes(i.type) ? i.type : null;
    return (
        <li>
            <code>{i.name}</code>
            <span className="muted">
                {' '}
                ({key.length ? key.join(', ') : 'expression'}){i.primary ? ' primary' : i.unique ? ' unique' : ''}
                {included.length > 0 && ` + includes ${included.join(', ')}`}
            </span>
            {(i.partial || i.expression || type) && (
                <span className="badges badges--inline">
                    {type && <span className="badge">{type}</span>}
                    {i.partial && (
                        <Tooltip content="Covers only the rows its WHERE clause picks">
                            <span className="badge" tabIndex={0}>
                                partial
                            </span>
                        </Tooltip>
                    )}
                    {i.expression && (
                        <Tooltip content="A key part is an expression or a prefix, so the columns shown are not its whole key">
                            <span className="badge" tabIndex={0}>
                                expression
                            </span>
                        </Tooltip>
                    )}
                </span>
            )}
        </li>
    );
}

// A relationship's short name for the phone's sheet bar: its two tables.
function relationshipTitle(schema: Schema, connection: string, id: string): string {
    const l = schema.links(connection).find((x) => x.id === id);
    if (l) {
        return `${l.from.table} → ${l.to.table}`;
    }
    const d = schema.derived(connection).find((x) => x.id === id);
    return d ? `${d.from} → ${d.to}` : 'Relationship';
}

export function SidePanel({ schema, connections, tables, selected, group = [], highlight, onHighlight, tab, onTab, onOpen, sheet, sheetOpen, onSheet, editor, focused, onFocus, focusedOn, onFocusHighlight, onLeaveFocus, onCopyLink, changes }: Props) {
    const hints = connections.flatMap((c) => schema.hints(c));
    const [filter, setFilter] = useState('');
    const [mode, setMode] = useState<BrowseMode>('tables');
    const models = selected ? schema.modelsFor(selected.connection, selected.table) : [];
    const changeCount = (changes ?? []).filter((c) => !c.connection || connections.includes(c.connection)).length;

    return (
        <aside className={`panel${sheet ? ' panel--sheet' : ''}${sheet && sheetOpen ? ' is-open' : ''}`} aria-label="Details">
            {sheet && (
                <div className="sheet-bar">
                    <span className="sheet-bar__grip" aria-hidden="true" />
                    <button type="button" className="sheet-bar__main" aria-expanded={sheetOpen} onClick={() => onSheet(!sheetOpen)}>
                        <span className="sheet-bar__title">
                            {highlight ? (
                                <code>{highlight.kind === 'column' ? `${highlight.table}.${highlight.column}` : relationshipTitle(schema, highlight.connection, highlight.id)}</code>
                            ) : selected ? (
                                <code>{selected.table}</code>
                            ) : (
                                <span>Tap a table, a column or a line</span>
                            )}
                            {!highlight && selected && models.length > 0 && <span className="muted">{models.map((m) => shortClass(m.class)).join(', ')}</span>}
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
                    <button type="button" className="sheet-bar__action" aria-expanded={sheetOpen} onClick={() => (sheetOpen ? onSheet(false) : onTab(selected || highlight ? 'details' : 'browse'))}>
                        {sheetOpen ? 'Close' : selected || highlight ? 'Details' : 'Browse'}
                    </button>
                </div>
            )}
            <Tabs value={tab} onValueChange={(v) => onTab(v as PanelTab)} className="panel__tabs-root">
                <TabList label="Details">
                    <Tab value="browse">
                        Browse <kbd className="kbd-hint">A</kbd>
                    </Tab>
                    <Tab value="details">
                        Details <kbd className="kbd-hint">D</kbd>
                    </Tab>
                    <Tab value="hints">
                        Hints <span className="count">{hints.length}</span> <kbd className="kbd-hint">H</kbd>
                    </Tab>
                    <Tab value="changes">
                        Changes {changeCount > 0 && <span className="count count--changes">{changeCount}</span>} <kbd className="kbd-hint">W</kbd>
                    </Tab>
                </TabList>
                <TabPanel value="browse" className="panel__body">
                    <Browse
                        schema={schema}
                        tables={tables}
                        connections={connections}
                        several={connections.length > 1}
                        selected={selected}
                        highlight={highlight}
                        mode={mode}
                        onMode={setMode}
                        filter={filter}
                        onFilter={setFilter}
                        onOpen={onOpen}
                        onHighlight={onHighlight}
                    />
                </TabPanel>
                <TabPanel value="details" className="panel__body">
                    {highlight ? (
                        <HighlightDetails schema={schema} highlight={highlight} editor={editor} onOpen={onOpen} onHighlight={onHighlight} focusedOn={focusedOn} onFocus={onFocusHighlight} onLeaveFocus={onLeaveFocus} onCopyLink={onCopyLink} />
                    ) : selected ? (
                        <TableDetails
                            schema={schema}
                            connection={selected.connection}
                            name={selected.table}
                            onOpen={onOpen}
                            onHighlight={onHighlight}
                            editor={editor}
                            focused={focused}
                            onFocus={onFocus}
                            onLeaveFocus={onLeaveFocus}
                            onCopyLink={onCopyLink}
                        />
                    ) : group.length > 1 ? (
                        <GroupDetails schema={schema} group={group} onOpen={onOpen} several={connections.length > 1} />
                    ) : (
                        <>
                            {schema.doc.note && (
                                <div className="project-note">
                                    <h2 className="project-note__title">About this schema</h2>
                                    <p>{schema.doc.note}</p>
                                </div>
                            )}
                            <p className="panel__empty">
                                Select a table, a line or a column on the diagram, or pick one in{' '}
                                <button type="button" className="link-button" onClick={() => onTab('browse')}>
                                    Browse
                                </button>
                                , to see its details here.
                            </p>
                        </>
                    )}
                </TabPanel>
                <TabPanel value="hints" className="panel__body">
                    <HintList hints={hints} several={connections.length > 1} onOpen={onOpen} />
                </TabPanel>
                <TabPanel value="changes" className="panel__body">
                    <ChangeList schema={schema} changes={changes} connections={connections} onOpen={onOpen} onHighlight={onHighlight} />
                </TabPanel>
            </Tabs>
        </aside>
    );
}

const KIND_LABEL: Record<Hint['kind'], string> = {
    relation_without_foreign_key: 'relation without FK',
    foreign_key_without_relation: 'FK without relation',
    foreign_key_without_index: 'FK without index',
    model_without_table: 'model without table',
    morph_without_map: 'morph without map',
    unresolved_relation: 'unresolved relation',
    unresolved_package_relation: 'package relation',
    custom_relation: 'custom relation',
    table_without_primary_key: 'no primary key',
    foreign_key_type_mismatch: 'FK type mismatch',
    foreign_key_name_mismatch: 'FK name mismatch',
    index_duplicates_primary_key: 'index on primary key',
    duplicate_index: 'duplicate index',
    redundant_index: 'redundant index',
    morph_without_index: 'morph without index',
};

// A rule as the PHP array to paste into hints.ignore in config/schema-viewer.php.
function php(rule: IgnoreRule): string {
    const quote = (value: string) => `'${value.replace(/'/g, "\\'")}'`;
    return `[${Object.entries(rule)
        .map(([field, value]) => `${quote(field)} => ${quote(value ?? '')}`)
        .join(', ')}],`;
}

// The hints.ignore rules that leave a hint out, each ready to copy.
export function IgnoreRules({ hint }: { hint: Hint }) {
    const [copied, setCopied] = useState<string | null>(null);
    const [open, setOpen] = useState(false);
    if (!hint.ignore) {
        return null;
    }
    const { ignore } = hint;
    const rows: [string, string, IgnoreRule][] = [
        ['hint', 'Only this hint', ignore.hint],
        ['kindOnTable', `Every ${KIND_LABEL[hint.kind]} hint on ${hint.table}`, ignore.kindOnTable],
        ['table', `Every hint on ${hint.table}`, ignore.table],
        ['kind', `Every ${KIND_LABEL[hint.kind]} hint`, ignore.kind],
    ];
    const seen = new Set<string>();
    const unique = rows.filter(([, , rule]) => {
        const code = php(rule);
        return seen.has(code) ? false : (seen.add(code), true);
    });
    const copy = (key: string, code: string) => {
        navigator.clipboard?.writeText(code).then(
            () => {
                setCopied(key);
                window.setTimeout(() => setCopied((current) => (current === key ? null : current)), 1500);
            },
            () => setCopied(null),
        );
    };
    return (
        <>
            <button type="button" className="link-button ignore__open" onClick={() => setOpen(true)}>
                Ignore…
            </button>
            <Dialog open={open} onOpenChange={setOpen} title="Ignore this hint">
                <div className="ignore">
                    <p className="ignore__hint">
                        <strong>{HINT_TITLE[hint.kind]}.</strong> {hint.message}
                    </p>
                    <p className="ignore__help">
                        Add one of these rules to <code>hints.ignore</code> in <code>config/schema-viewer.php</code>, from the narrowest to the widest:
                    </p>
                    <ul className="ignore__rules">
                        {unique.map(([key, label, rule]) => {
                            const code = php(rule);
                            return (
                                <li key={key}>
                                    <span className="ignore__label">{label}</span>
                                    <span className="ignore__row">
                                        <code>{code}</code>
                                        <button type="button" className="button button--small" onClick={() => copy(key, code)} aria-label={`Copy the rule: ${label}`}>
                                            {copied === key ? 'Copied' : 'Copy'}
                                        </button>
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            </Dialog>
        </>
    );
}

type CertaintyFilter = 'all' | 'certain' | 'likely';

function HintList({ hints, several, onOpen }: { hints: Hint[]; several: boolean; onOpen: (connection: string, table: string) => void }) {
    const [certainty, setCertainty] = useState<CertaintyFilter>('all');
    if (!hints.length) {
        return <p className="panel__empty">{several ? 'No hints on any connection.' : 'No hints on this connection.'}</p>;
    }
    const likely = hints.filter((h) => h.certainty === 'likely').length;
    // The filter shows only while both kinds are listed.
    const mixed = likely > 0 && likely < hints.length;
    const shown = mixed && certainty !== 'all' ? hints.filter((h) => (h.certainty ?? 'certain') === certainty) : hints;
    return (
        <>
            {mixed && (
                <div className="hints__filter">
                    <div className="browse__modes" role="radiogroup" aria-label="Show hints">
                        {(
                            [
                                ['all', `All ${hints.length}`],
                                ['certain', `Certain ${hints.length - likely}`],
                                ['likely', `Likely ${likely}`],
                            ] as [CertaintyFilter, string][]
                        ).map(([value, label]) => (
                            <button key={value} type="button" role="radio" aria-checked={certainty === value} className="browse__mode" onClick={() => setCertainty(value)}>
                                {label}
                            </button>
                        ))}
                    </div>
                </div>
            )}
            <HintItems hints={shown} several={several} onOpen={onOpen} />
        </>
    );
}

function HintItems({ hints, several, onOpen }: { hints: Hint[]; several: boolean; onOpen: (connection: string, table: string) => void }) {
    return (
        <ul className="hints">
            {hints.map((h, i) => (
                <li key={i} className={`hint hint--${h.severity}`}>
                    <div className="hint__head">
                        <span className="hint__severity">{h.severity}</span>
                        <span className="hint__title">
                            {HINT_TITLE[h.kind]} <Certainty hint={h} />
                        </span>
                    </div>
                    <p>{h.message}</p>
                    <div className="hint__actions">
                        <button type="button" className="link-button" onClick={() => onOpen(h.connection, h.table)}>
                            Show {several ? `${h.connection}.${h.table}` : h.table}
                        </button>
                        <IgnoreRules hint={h} />
                    </div>
                </li>
            ))}
        </ul>
    );
}

function TableDetails({ schema, connection, name, onOpen, onHighlight, editor, focused, onFocus, onLeaveFocus, onCopyLink }: TableProps) {
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
                    {connection} · {table.columns.length} columns{table.framework ? ' · framework table' : ''}{table.package ? ` · from ${table.package}` : ''}
                </p>
                {table.comment && <p className="details__comment">{table.comment}</p>}
                {table.note && (
                    <p className="details__comment details__note">
                        <b>Note</b> {table.note}
                    </p>
                )}
                <div className="chips">
                    {models.length ? (
                        models.map((m) => (
                            <SourceLink key={m.class} source={m.source} editor={editor}>
                                <code className="chip chip--model">{m.class}</code>
                            </SourceLink>
                        ))
                    ) : (
                        <span className="chip chip--none">{table.package && !table.framework ? 'A package table' : 'No Eloquent model uses this table'}</span>
                    )}
                </div>
                <div className="details__actions">
                    {focused?.connection === connection && focused.table === name ? (
                        <Tooltip content={<>Leave focus mode <kbd>Esc</kbd></>}>
                            <button type="button" className="badge badge--focus" aria-pressed="true" onClick={onLeaveFocus}>
                                In focus
                            </button>
                        </Tooltip>
                    ) : (
                        <Tooltip content={<>Show only this table and its neighbors <kbd>Shift F</kbd></>}>
                            <button type="button" className="button button--small" onClick={() => onFocus({ connection, table: name })}>
                                Focus
                            </button>
                        </Tooltip>
                    )}
                    <Tooltip content={<>A link that opens exactly this view <kbd>Shift C</kbd></>}>
                        <button type="button" className="button button--small" onClick={onCopyLink}>
                            Copy link
                        </button>
                    </Tooltip>
                </div>
            </header>

            {hints.map((h, i) => (
                <div key={i} className={`callout callout--${h.severity}`}>
                    <strong>{HINT_TITLE[h.kind]}.</strong> <Certainty hint={h} /> {h.message}
                    <IgnoreRules hint={h} />
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
                                            <button type="button" className="link-button link-button--code" onClick={() => onHighlight({ kind: 'column', connection, table: name, column: c.name })}>
                                                {c.name}
                                            </button>
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
                                            {c.comment && <p className="grid__note">{c.comment}</p>}
                                            {c.note && (
                                                <p className="grid__note">
                                                    <b>Note</b> {c.note}
                                                </p>
                                            )}
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
                        {relations.map((r, i) => (
                            <li key={r.id}>
                                <div className="relation__head">
                                    <SourceLink source={r.source} editor={editor}>
                                        <code>{r.name}()</code>
                                    </SourceLink>
                                    <span className="relation__type">
                                        {r.type} · {relationKind(r.type)}
                                    </span>
                                    <button type="button" className="link-button" onClick={() => openRelated(r)}>
                                        {shortClass(r.related)}
                                    </button>
                                </div>
                                {/* A relationship's note shows once, under the first relation that draws it. */}
                                <RelationKeys schema={schema} relation={r} note={!relations.slice(0, i).some((o) => sameLink(schema, o, r))} />
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
                                    <SourceLink source={r.source} editor={editor}>
                                        <code>{relationLabel(r.id)}()</code>
                                    </SourceLink>
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
                        <IndexLine key={i.name} index={i} />
                    ))}
                </ul>
            </section>

            {models.map((m) => (
                <section key={m.class}>
                    <h3>
                        <SourceLink source={m.source} editor={editor}>
                            {shortClass(m.class)} model
                        </SourceLink>
                    </h3>
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

// Whether two relations draw the same relationship.
const sameLink = (schema: Schema, a: Relation, b: Relation): boolean => {
    const link = schema.doc.links.find((l) => l.relations.includes(a.id));
    return link !== undefined && link.relations.includes(b.id);
};

export function RelationKeys({ schema, relation: r, note = true }: { schema: Schema; relation: Relation; note?: boolean }) {
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
            {note && link?.note && (
                <p className="grid__note">
                    <b>Note</b> {link.note}
                </p>
            )}
        </div>
    );
}

// Several cards picked at once: how many, and each, to open on its own.
function GroupDetails({ schema, group, onOpen, several }: { schema: Schema; group: string[]; onOpen: (connection: string, table: string) => void; several: boolean }) {
    const tables = group
        .map((id) => schema.doc.connections.flatMap((c) => c.tables.map((t) => ({ connection: c.name, table: t }))).find((e) => `${e.connection}.${e.table.name}` === id))
        .filter((e): e is { connection: string; table: Table } => e !== undefined);
    return (
        <div className="details">
            <header className="details__head">
                <h2>{tables.length} tables selected</h2>
                <p className="details__sub">Drag one to move them together. Shift+click a table to add or remove it; Esc clears the selection.</p>
            </header>
            <section>
                <ul className="plain">
                    {tables.map(({ connection, table }) => (
                        <li key={`${connection}.${table.name}`}>
                            <button type="button" className="link-button link-button--code" onClick={() => onOpen(connection, table.name)}>
                                {several ? `${connection}.` : ''}
                                {table.name}
                            </button>{' '}
                            <span className="muted">
                                {table.columns.length} col{schema.modelsFor(connection, table.name).length > 0 ? ` · ${schema.modelsFor(connection, table.name).map((m) => shortClass(m.class)).join(', ')}` : ''}
                            </span>
                        </li>
                    ))}
                </ul>
            </section>
        </div>
    );
}
