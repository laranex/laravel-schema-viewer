import ELK from 'elkjs/lib/elk.bundled.js';
import { NODE_WIDTH, nodeId, type Schema } from './graph';
import { GAP } from './routing';

export type Positions = Record<string, { x: number; y: number }>;

const elk = new ELK();

// Layered layout, referenced tables to the left of the tables that point at them.
export async function autoLayout(schema: Schema, connection: string, visible: Set<string>, sizes: Record<string, number>): Promise<Positions> {
    const ids = [...visible];
    const edges = [
        ...schema.links(connection).map((l) => ({ source: nodeId(l.to.connection, l.to.table), target: nodeId(l.from.connection, l.from.table), id: l.id })),
        ...schema.derived(connection).map((d) => ({ source: nodeId(connection, d.from), target: nodeId(connection, d.to), id: d.id })),
    ].filter((e) => e.source !== e.target && visible.has(e.source) && visible.has(e.target));

    const result = await elk.layout({
        id: 'root',
        layoutOptions: {
            'elk.algorithm': 'layered',
            'elk.direction': 'RIGHT',
            'elk.layered.spacing.nodeNodeBetweenLayers': String(GAP),
            'elk.spacing.nodeNode': '40',
            'elk.spacing.componentComponent': '80',
            'elk.layered.nodePlacement.strategy': 'BRANDES_KOEPF',
            'elk.layered.crossingMinimization.strategy': 'LAYER_SWEEP',
        },
        children: ids.map((id) => ({ id, width: NODE_WIDTH, height: sizes[id] ?? 120 })),
        edges: edges.map((e) => ({ id: e.id, sources: [e.source], targets: [e.target] })),
    });

    const positions: Positions = {};
    result.children?.forEach((c) => {
        positions[c.id] = { x: c.x ?? 0, y: c.y ?? 0 };
    });
    return positions;
}

const storageKey = (connection: string) => `schema-viewer.layout.v2.${connection}`;

export function loadLayout(connection: string): Positions | null {
    try {
        const raw = localStorage.getItem(storageKey(connection));
        return raw ? (JSON.parse(raw) as Positions) : null;
    } catch {
        return null;
    }
}

export function saveLayout(connection: string, positions: Positions): boolean {
    try {
        localStorage.setItem(storageKey(connection), JSON.stringify(positions));
        return true;
    } catch {
        return false;
    }
}

export function clearLayout(connection: string): void {
    try {
        localStorage.removeItem(storageKey(connection));
    } catch {
        // Storage unavailable: nothing was saved.
    }
}
