import { useId, useMemo, useState, type RefObject } from 'react';
import { shortClass, type Schema } from '../graph';
import type { Table } from '../types';

type Props = {
    schema: Schema;
    // The tables in view, each with its connection; with several connections, results name theirs.
    tables: { connection: string; table: Table }[];
    multiple: boolean;
    query: string;
    onQuery: (query: string) => void;
    onPick: (connection: string, table: string) => void;
    inputRef?: RefObject<HTMLInputElement | null>;
};

type Result = { connection: string; table: Table; models: string[]; reason: string | null };

const LIMIT = 8;

// Search field with a list of matching tables; picking one goes to it on the diagram.
export function SearchBox({ schema, tables, multiple, query, onQuery, onPick, inputRef }: Props) {
    const listId = useId();
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);

    const results = useMemo((): Result[] => {
        const q = query.trim().toLowerCase();
        if (!q) {
            return [];
        }
        const ranked = tables
            .filter(({ connection, table: t }) => schema.matches(connection, t, q))
            .map(({ connection, table: t }) => {
                const models = schema.modelsFor(connection, t.name).map((m) => shortClass(m.class));
                const name = t.name.toLowerCase();
                const rank = name === q ? 0 : name.startsWith(q) ? 1 : name.includes(q) ? 2 : 3;
                return { connection, table: t, models, rank, reason: rank < 3 ? null : reasonFor(schema, connection, t, q) };
            })
            .sort((a, b) => a.rank - b.rank || a.table.name.localeCompare(b.table.name));
        return ranked.slice(0, LIMIT);
    }, [schema, tables, query]);

    const pick = (r: Result) => {
        onPick(r.connection, r.table.name);
        onQuery('');
        setOpen(false);
    };

    const onKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setOpen(true);
            setActive((i) => Math.min(i + 1, results.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((i) => Math.max(i - 1, 0));
        } else if (e.key === 'Enter' && results[active]) {
            e.preventDefault();
            pick(results[active]);
        } else if (e.key === 'Escape') {
            if (query) {
                onQuery('');
            } else {
                e.currentTarget.blur();
            }
        }
    };

    const showList = open && query.trim() !== '';

    return (
        <div className="field field--search search" data-tour="search">
            <label htmlFor="search" className="sr-only">
                Search tables, columns, models and relations
            </label>
            <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" className="field__icon">
                <circle cx="11" cy="11" r="6.5" />
                <path d="M16 16 L20.5 20.5" />
            </svg>
            <input
                ref={inputRef}
                id="search"
                type="text"
                role="combobox"
                aria-expanded={showList}
                aria-controls={listId}
                aria-autocomplete="list"
                aria-activedescendant={showList && results[active] ? `${listId}-${active}` : undefined}
                autoComplete="off"
                placeholder="Search tables, columns, models, relations"
                aria-keyshortcuts="/ Control+K Meta+K"
                value={query}
                onChange={(e) => {
                    onQuery(e.target.value);
                    setActive(0);
                    setOpen(true);
                }}
                onFocus={() => setOpen(true)}
                onBlur={() => setOpen(false)}
                onKeyDown={onKeyDown}
            />
            {query === '' && <kbd className="kbd-hint search__hint">/</kbd>}
            {query !== '' && (
                <button type="button" className="search__clear" aria-label="Clear search" onMouseDown={(e) => e.preventDefault()} onClick={() => onQuery('')}>
                    <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" className="ui-icon">
                        <path d="M4 4 L12 12 M12 4 L4 12" />
                    </svg>
                </button>
            )}
            {showList && (
                <ul className="search__list" id={listId} role="listbox" aria-label="Matching tables">
                    {results.length === 0 && <li className="search__empty">No table matches “{query.trim()}”.</li>}
                    {results.map((r, i) => (
                        <li
                            key={`${r.connection}.${r.table.name}`}
                            id={`${listId}-${i}`}
                            role="option"
                            aria-selected={i === active}
                            className={i === active ? 'is-active' : undefined}
                            // Keep focus in the input so the click lands before blur closes the list.
                            onMouseDown={(e) => e.preventDefault()}
                            onMouseEnter={() => setActive(i)}
                            onClick={() => pick(r)}
                        >
                            <span className="search__name">{r.table.name}</span>
                            <span className="search__meta">
                                {multiple && `${r.connection} · `}
                                {r.models.length ? r.models.join(', ') : r.table.framework ? 'framework table' : (r.table.package ?? 'no model')}
                                {r.reason && <span className="search__reason"> · {r.reason}</span>}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

// Why a table matched when its name did not: a column, model or relation.
function reasonFor(schema: Schema, connection: string, table: Table, q: string): string | null {
    const column = table.columns.find((c) => c.name.toLowerCase().includes(q));
    if (column) {
        return `column ${column.name}`;
    }
    const relation = schema.relationsOf(connection, table.name).find((r) => r.name.toLowerCase().includes(q));
    if (relation) {
        return `relation ${relation.name}()`;
    }
    const model = schema.modelsFor(connection, table.name).find((m) => m.class.toLowerCase().includes(q));
    if (model) {
        return `model ${shortClass(model.class)}`;
    }
    const noted = table.columns.find((c) => c.note?.toLowerCase().includes(q));
    return noted ? `note on ${noted.name}` : table.note?.toLowerCase().includes(q) ? 'its note' : null;
}
