import { Handle, Position, type Node, type NodeProps } from '@xyflow/react';
import { createContext, memo, useContext } from 'react';
import type { Fold } from '../graph';
import type { Table } from '../types';
import { LazyTooltip as Tooltip } from './ui';

export type TableNodeData = {
    connection: string;
    table: Table;
    models: string[];
    pivot: boolean;
    hintCount: number;
    dim: boolean;
    active: boolean;
    primary: string[];
    foreign: string[];
    linked: string[];
    casts: Record<string, string>;
    fold: Fold;
    // The columns the card shows, in table order.
    shown: string[];
    // The columns edges can end on, the only ones that get handles.
    ends: string[];
};

// What a card's own controls do, provided by the viewer.
export const TableActions = createContext<{
    setFold: (id: string, fold: Fold) => void;
    highlightColumn: (connection: string, table: string, column: string) => void;
    focusColumn: (connection: string, table: string, column: string) => void;
}>({
    setFold: () => undefined,
    highlightColumn: () => undefined,
    focusColumn: () => undefined,
});

const NEXT_FOLD: Record<Fold, { fold: Fold; label: string }> = {
    all: { fold: 'keys', label: 'Fold to the key columns' },
    keys: { fold: 'none', label: 'Fold to the header' },
    none: { fold: 'all', label: 'Show every column' },
};

export type TableNodeType = Node<TableNodeData, 'table'>;

const hidden = { opacity: 0, width: 1, height: 1, minWidth: 0, minHeight: 0, border: 0 };

function ColumnHandles({ column }: { column: string }) {
    return (
        <>
            <Handle type="source" position={Position.Left} id={`${column}|sl`} style={hidden} isConnectable={false} />
            <Handle type="source" position={Position.Right} id={`${column}|sr`} style={hidden} isConnectable={false} />
            <Handle type="target" position={Position.Left} id={`${column}|tl`} style={hidden} isConnectable={false} />
            <Handle type="target" position={Position.Right} id={`${column}|tr`} style={hidden} isConnectable={false} />
        </>
    );
}

function TableNode({ id, data, selected }: NodeProps<TableNodeType>) {
    const { table } = data;
    const { setFold, highlightColumn, focusColumn } = useContext(TableActions);
    const shown = new Set(data.shown);
    const ends = new Set(data.ends);
    const folded = table.columns.filter((c) => !shown.has(c.name));
    const next = NEXT_FOLD[data.fold];
    const classes = ['table-node', data.shown.length === 0 ? 'is-folded' : '', selected ? 'is-selected' : '', data.dim ? 'is-dim' : '', data.active ? 'is-active' : '', table.framework ? 'is-framework' : '', data.pivot ? 'is-pivot' : '']
        .filter(Boolean)
        .join(' ');

    return (
        <div className={classes}>
            <header className="table-node__header">
                {/* Folded columns keep their edges: they meet the header. */}
                {folded
                    .filter((c) => ends.has(c.name))
                    .map((c) => (
                        <ColumnHandles key={c.name} column={c.name} />
                    ))}
                <div className="table-node__title">
                    <span className="table-node__name">{table.name}</span>
                    {data.hintCount > 0 && (
                        <Tooltip content={`${data.hintCount} hint${data.hintCount > 1 ? 's' : ''}: open the Hints tab`}>
                            <span className="table-node__hints">{data.hintCount}</span>
                        </Tooltip>
                    )}
                    {table.columns.length > 0 && (
                        <Tooltip content={next.label}>
                            <button
                                type="button"
                                className={`table-node__fold nodrag is-${data.fold}`}
                                aria-label={`${next.label}: ${table.name}`}
                                onClick={(e) => {
                                    e.stopPropagation();
                                    setFold(id, next.fold);
                                }}
                            >
                                <svg viewBox="0 0 16 16" aria-hidden="true" className="ui-icon">
                                    <path d="M4.5 6.5 L8 10 L11.5 6.5" />
                                </svg>
                            </button>
                        </Tooltip>
                    )}
                </div>
                <div className="table-node__meta">
                    {data.models.length > 0 ? data.models.map((m) => <span key={m}>{m}</span>) : <span className="muted">{table.framework ? 'framework table' : 'no model'}</span>}
                    {data.pivot && <span className="tag">pivot</span>}
                    {table.package && <span className="tag">{table.package}</span>}
                </div>
            </header>
            {data.shown.length > 0 && (
                <ul className="table-node__columns">
                    {table.columns.filter((c) => shown.has(c.name)).map((c) => {
                        const isPk = data.primary.includes(c.name);
                        const isFk = data.foreign.includes(c.name);
                        return (
                            <li
                                key={c.name}
                                className={data.linked.includes(c.name) ? 'is-linked' : undefined}
                                // A click on a column shows that column, not the whole table.
                                onClick={(e) => {
                                    e.stopPropagation();
                                    highlightColumn(data.connection, table.name, c.name);
                                }}
                                // A double-click focuses on the column, not the whole table.
                                onDoubleClick={(e) => {
                                    e.stopPropagation();
                                    focusColumn(data.connection, table.name, c.name);
                                }}
                            >
                                {ends.has(c.name) && <ColumnHandles column={c.name} />}
                                <span className="key">{isPk ? 'PK' : isFk ? 'FK' : ''}</span>
                                <span className="col">
                                    {c.name}
                                    {c.nullable && <span className="nullable">?</span>}
                                </span>
                                <Tooltip
                                    content={
                                        <>
                                            <code>{c.type}</code>
                                            {c.nullable ? ', nullable' : ''}
                                            {c.default !== null ? `, default ${c.default}` : ''}
                                            {data.casts[c.name] && (
                                                <>
                                                    <br />
                                                    cast to <code>{data.casts[c.name]}</code>
                                                </>
                                            )}
                                            {c.comment && (
                                                <>
                                                    <br />
                                                    {c.comment}
                                                </>
                                            )}
                                        </>
                                    }
                                    side="right"
                                >
                                    <span className="type">{data.casts[c.name] ? data.casts[c.name].split('\\').pop() : c.type}</span>
                                </Tooltip>
                            </li>
                        );
                    })}
                    {folded.length > 0 && (
                        <li className="table-node__more">
                            <button
                                type="button"
                                className="nodrag"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    setFold(id, 'all');
                                }}
                            >
                                + {folded.length} more {folded.length === 1 ? 'column' : 'columns'}
                            </button>
                        </li>
                    )}
                </ul>
            )}
        </div>
    );
}

export type ExternalNodeData = { connection: string; table: string; dim: boolean };
export type ExternalNodeType = Node<ExternalNodeData, 'external'>;

function ExternalNode({ data }: NodeProps<ExternalNodeType>) {
    return (
        <div className={`external-node${data.dim ? ' is-dim' : ''}`}>
            <Handle type="target" position={Position.Left} id="id|tl" style={hidden} isConnectable={false} />
            <Handle type="target" position={Position.Right} id="id|tr" style={hidden} isConnectable={false} />
            <span className="external-node__label">Other connection</span>
            <span className="external-node__name">
                {data.connection}.{data.table}
            </span>
            <span className="external-node__hint">Click to open</span>
        </div>
    );
}

// The frame around one connection's tables when every connection is on the diagram.
export type RegionNodeData = { connection: string; driver: string; tables: number; hints: number };
export type RegionNodeType = Node<RegionNodeData, 'region'>;

function RegionNode({ data }: NodeProps<RegionNodeType>) {
    return (
        <div className="region-node">
            <span className="region-node__label">
                <span className="region-node__name">{data.connection}</span>
                <span className="region-node__meta">
                    {data.driver} · {data.tables} {data.tables === 1 ? 'table' : 'tables'}
                    {data.hints > 0 && ` · ${data.hints} ${data.hints === 1 ? 'hint' : 'hints'}`}
                </span>
            </span>
        </div>
    );
}

export const TableNodeView = memo(TableNode);
export const RegionNodeView = memo(RegionNode);
export const ExternalNodeView = memo(ExternalNode);
