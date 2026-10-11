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

export type ServerConfig = {
    schemaUrl: string;
    layoutUrl: string;
    csrfToken: string | null;
    canSaveLayout: boolean;
};

// Where table positions are kept: the committed layout file through the server, or this browser.
export type LayoutStore = {
    canSave: boolean;
    load(connection: string): Promise<Positions | null>;
    save(connection: string, positions: Positions): Promise<string>;
};

export function serverLayoutStore(config: ServerConfig): LayoutStore {
    let file: Promise<Record<string, Positions>> | null = null;
    const read = () =>
        (file ??= fetch(config.layoutUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : { connections: {} }))
            .then((json: { connections?: Record<string, Positions> }) => json.connections ?? {})
            .catch((): Record<string, Positions> => ({})));

    return {
        canSave: config.canSaveLayout,
        async load(connection) {
            return (await read())[connection] ?? null;
        },
        async save(connection, positions) {
            const response = await fetch(config.layoutUrl, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...(config.csrfToken ? { 'X-CSRF-TOKEN': config.csrfToken } : {}) },
                body: JSON.stringify({ connection, positions }),
            });
            const json = (await response.json().catch(() => ({}))) as { message?: string };
            if (!response.ok) {
                throw new Error(json.message ?? `The layout could not be saved (HTTP ${response.status}).`);
            }
            file = null;
            return 'Layout saved to schema-viewer.json. Commit it to share it.';
        },
    };
}

const storageKey = (connection: string) => `schema-viewer.layout.v2.${connection}`;

export const browserLayoutStore: LayoutStore = {
    canSave: true,
    async load(connection) {
        try {
            const raw = localStorage.getItem(storageKey(connection));
            return raw ? (JSON.parse(raw) as Positions) : null;
        } catch {
            return null;
        }
    },
    async save(connection, positions) {
        try {
            localStorage.setItem(storageKey(connection), JSON.stringify(positions));
            return 'Layout saved in this browser.';
        } catch {
            throw new Error('This browser blocks storage, so the layout was not saved.');
        }
    },
};
