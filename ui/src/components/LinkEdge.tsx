import { BaseEdge, EdgeLabelRenderer, type Edge, type EdgeProps } from '@xyflow/react';
import { createContext, memo, useContext, useSyncExternalStore } from 'react';
import { LABEL_WIDTH, type Route } from '../routing';
import { LazyTooltip } from './ui';

export type EdgeKind = 'both' | 'relation' | 'foreign_key' | 'through';

export type LinkEdgeData = {
    kind: EdgeKind;
    morph: boolean;
    relationship: string;
    many: boolean;
    label: string;
    detail: string;
    showLabel: boolean;
    emphasized: boolean;
    dim: boolean;
    // While a card it ends on is dragged, the route is stale: the edge runs through the middle instead.
    floating: boolean;
    route: Route;
};

export type LinkEdgeType = Edge<LinkEdgeData, 'link'>;

const KIND_LABEL: Record<EdgeKind, string> = {
    both: 'foreign key and relation',
    relation: 'relation without a foreign key',
    foreign_key: 'foreign key without a relation',
    through: 'through relation',
};

// The edge under the pointer. Edges read it from this store, so hovering re-renders the two edges it changes, not the
// whole diagram, and never routes the edges again.
export class HoverStore {
    private id: string | null = null;
    private readonly listeners = new Set<() => void>();

    set(id: string | null) {
        if (id !== this.id) {
            this.id = id;
            this.listeners.forEach((l) => l());
        }
    }

    get = () => this.id;

    subscribe = (listener: () => void) => {
        this.listeners.add(listener);
        return () => this.listeners.delete(listener);
    };
}

export const EdgeHover = createContext(new HoverStore());

function useHovered(id: string) {
    const store = useContext(EdgeHover);
    return useSyncExternalStore(store.subscribe, () => store.get() === id);
}

// An orthogonal path with rounded corners: across to the lane, along it, across to the target.
function orthogonal(sx: number, sy: number, cx: number, tx: number, ty: number): string {
    const dy = ty - sy;
    const r = Math.min(10, Math.abs(dy) / 2, Math.abs(cx - sx), Math.abs(tx - cx));
    if (r < 1) {
        return `M ${sx} ${sy} L ${cx} ${sy} L ${cx} ${ty} L ${tx} ${ty}`;
    }
    const dirA = Math.sign(cx - sx) || 1;
    const dirB = Math.sign(tx - cx) || 1;
    const dirY = Math.sign(dy);
    return [
        `M ${sx} ${sy}`,
        `L ${cx - dirA * r} ${sy}`,
        `Q ${cx} ${sy} ${cx} ${sy + dirY * r}`,
        `L ${cx} ${ty - dirY * r}`,
        `Q ${cx} ${ty} ${cx + dirB * r} ${ty}`,
        `L ${tx} ${ty}`,
    ].join(' ');
}

function LinkEdge({ id, sourceX, sourceY, targetX, targetY, data, selected }: EdgeProps<LinkEdgeType>) {
    const hovered = useHovered(id);
    if (!data) {
        return null;
    }

    const { kind } = data;
    const route = data.floating ? { ...data.route, channelX: (sourceX + targetX) / 2, labelX: (sourceX + targetX) / 2, labelY: (sourceY + targetY) / 2 } : data.route;
    const path = orthogonal(sourceX, sourceY, route.channelX, targetX, targetY);
    const emphasized = data.emphasized || hovered;
    const className = ['link-edge', `link-edge--${kind}`, data.morph ? 'link-edge--morph' : '', data.dim ? 'is-dim' : '', selected ? 'is-selected' : '', hovered ? 'is-hovered' : '']
        .filter(Boolean)
        .join(' ');
    const showLabel = (data.showLabel || hovered) && !data.dim;

    return (
        <>
            <BaseEdge
                id={id}
                path={path}
                className={className}
                markerStart={kind === 'through' ? undefined : `url(#marker-${data.many ? 'many' : 'one'}-${kind})`}
                markerEnd={kind === 'through' ? `url(#marker-arrow-through)` : `url(#marker-parent-${kind})`}
                interactionWidth={16}
            />
            {showLabel && (
                <EdgeLabelRenderer>
                    <LazyTooltip
                        content={
                            <>
                                <code>{data.label}</code>
                                <br />
                                {data.relationship}, {KIND_LABEL[kind]}
                                <br />
                                <code>{data.detail}</code>
                            </>
                        }
                    >
                        <div
                            className={`edge-label edge-label--${kind}${emphasized ? ' is-emphasized' : ''} nodrag nopan`}
                            style={{ width: LABEL_WIDTH, transform: `translate(-50%, -50%) translate(${route.labelX}px, ${route.labelY}px)` }}
                        >
                            <span className="edge-label__text">{data.label}</span>
                            <span className="edge-label__chip">{data.relationship}</span>
                        </div>
                    </LazyTooltip>
                </EdgeLabelRenderer>
            )}
        </>
    );
}

// Arrowheads and cardinality marks, one set per edge kind so each takes its color.
export function EdgeMarkers() {
    const kinds: EdgeKind[] = ['both', 'relation', 'foreign_key'];
    return (
        <svg className="edge-markers" aria-hidden="true">
            <defs>
                {kinds.map((k) => (
                    <g key={k}>
                        <marker id={`marker-many-${k}`} viewBox="0 0 14 14" refX="1" refY="7" markerWidth="14" markerHeight="14" markerUnits="userSpaceOnUse" orient="auto-start-reverse">
                            <path d="M13 7 L1 1 M13 7 L1 7 M13 7 L1 13" className={`marker-stroke marker--${k}`} fill="none" />
                        </marker>
                        <marker id={`marker-one-${k}`} viewBox="0 0 14 14" refX="1" refY="7" markerWidth="14" markerHeight="14" markerUnits="userSpaceOnUse" orient="auto-start-reverse">
                            <path d="M5 1 L5 13" className={`marker-stroke marker--${k}`} fill="none" />
                        </marker>
                        <marker id={`marker-parent-${k}`} viewBox="0 0 14 14" refX="13" refY="7" markerWidth="14" markerHeight="14" markerUnits="userSpaceOnUse" orient="auto">
                            <path d="M9 1 L9 13 M5 1 L5 13" className={`marker-stroke marker--${k}`} fill="none" />
                        </marker>
                    </g>
                ))}
                <marker id="marker-arrow-through" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="9" markerHeight="9" markerUnits="userSpaceOnUse" orient="auto">
                    <path d="M0 0 L10 5 L0 10 z" className="marker-fill marker--through" />
                </marker>
            </defs>
        </svg>
    );
}

export const LinkEdgeView = memo(LinkEdge);
