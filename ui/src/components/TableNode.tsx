import { Handle, Position, type Node, type NodeProps } from '@xyflow/react';
import { memo } from 'react';
import type { Table } from '../types';
import { Tooltip } from './ui';

export type TableNodeData = {
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
};

export type TableNodeType = Node<TableNodeData, 'table'>;

const hidden = { opacity: 0, width: 1, height: 1, minWidth: 0, minHeight: 0, border: 0 };

function TableNode({ data, selected }: NodeProps<TableNodeType>) {
    const { table } = data;
    const classes = ['table-node', selected ? 'is-selected' : '', data.dim ? 'is-dim' : '', data.active ? 'is-active' : '', table.framework ? 'is-framework' : '', data.pivot ? 'is-pivot' : '']
        .filter(Boolean)
        .join(' ');

    return (
        <div className={classes}>
            <header className="table-node__header">
                <div className="table-node__title">
                    <span className="table-node__name">{table.name}</span>
                    {data.hintCount > 0 && (
                        <Tooltip content={`${data.hintCount} hint${data.hintCount > 1 ? 's' : ''}: open the Hints tab`}>
                            <span className="table-node__hints">{data.hintCount}</span>
                        </Tooltip>
                    )}
                </div>
                <div className="table-node__meta">
                    {data.models.length > 0 ? data.models.map((m) => <span key={m}>{m}</span>) : <span className="muted">{table.framework ? 'framework table' : 'no model'}</span>}
                    {data.pivot && <span className="tag">pivot</span>}
                </div>
            </header>
            <ul className="table-node__columns">
                {table.columns.map((c) => {
                    const isPk = data.primary.includes(c.name);
                    const isFk = data.foreign.includes(c.name);
                    return (
                        <li key={c.name} className={data.linked.includes(c.name) ? 'is-linked' : undefined}>
                            <Handle type="source" position={Position.Left} id={`${c.name}|sl`} style={hidden} isConnectable={false} />
                            <Handle type="source" position={Position.Right} id={`${c.name}|sr`} style={hidden} isConnectable={false} />
                            <Handle type="target" position={Position.Left} id={`${c.name}|tl`} style={hidden} isConnectable={false} />
                            <Handle type="target" position={Position.Right} id={`${c.name}|tr`} style={hidden} isConnectable={false} />
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
            </ul>
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

export const TableNodeView = memo(TableNode);
export const ExternalNodeView = memo(ExternalNode);
