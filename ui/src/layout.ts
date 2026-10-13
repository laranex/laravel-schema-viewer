import { elkLayout } from './elk';
import { NODE_WIDTH, nodeId, type Schema } from './graph';
import { GAP } from './routing';
import type { Change, SchemaDocument } from './types';

export type Positions = Record<string, { x: number; y: number }>;

// Layouts already worked out, per schema, by the tables and their heights: going back to a view is instant.
const layouts = new WeakMap<Schema, Map<string, Positions>>();
const KEEP = 24;
// Above this many tables a connection is always packed into columns; from PACKABLE on, when layers come out tall.
const LARGE = 40;
// The shape a packed layout aims for: a desktop's, and a phone held upright.
export const WIDE = 1.6;
export const TALL = 0.6;
const PACKABLE = 12;

// Layered layout, referenced tables to the left of the tables that point at them.
// aspect: the width:height a packed layout aims for, wide on a desktop and tall on a phone.
export async function autoLayout(schema: Schema, connection: string, visible: Set<string>, sizes: Record<string, number>, aspect = WIDE): Promise<Positions> {
    const ids = [...visible];
    const key = `${connection}@${aspect}#${ids.map((id) => `${id}:${sizes[id] ?? 120}`).join('|')}`;
    const cache = layouts.get(schema) ?? new Map<string, Positions>();
    layouts.set(schema, cache);
    const known = cache.get(key);
    if (known) {
        // Most recently used last, so the oldest is dropped first.
        cache.delete(key);
        cache.set(key, known);
        return known;
    }

    const edges = [
        ...schema.links(connection).map((l) => ({ source: nodeId(l.to.connection, l.to.table), target: nodeId(l.from.connection, l.from.table), id: l.id })),
        ...schema.derived(connection).map((d) => ({ source: nodeId(connection, d.from), target: nodeId(connection, d.to), id: d.id })),
    ].filter((e) => e.source !== e.target && visible.has(e.source) && visible.has(e.target));

    const result = await elkLayout({
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

    let positions: Positions = {};
    result.children?.forEach((c) => {
        positions[c.id] = { x: c.x ?? 0, y: c.y ?? 0 };
    });
    if (ids.length > LARGE || (ids.length >= PACKABLE && tallerThanWide(positions, sizes))) {
        positions = packColumns(positions, sizes, aspect);
    }
    cache.set(key, positions);
    if (cache.size > KEEP) {
        cache.delete(cache.keys().next().value as string);
    }
    return positions;
}

function tallerThanWide(positions: Positions, sizes: Record<string, number>): boolean {
    const boxes = Object.entries(positions);
    const width = Math.max(...boxes.map(([, p]) => p.x)) - Math.min(...boxes.map(([, p]) => p.x)) + NODE_WIDTH;
    const height = Math.max(...boxes.map(([id, p]) => p.y + (sizes[id] ?? 120))) - Math.min(...boxes.map(([, p]) => p.y));
    return height > width;
}

// Vertical space between two cards in a packed column.
const STACK_GAP = 48;

// A large schema laid out in layers puts dozens of tables in the column beside a table most others point at (users,
// say), and the diagram becomes one tall strip. Packed columns keep the layered order, so referenced tables stay to the
// left and related tables stay close, but fill evenly sized columns until the diagram is about a screen's shape.
function packColumns(layered: Positions, sizes: Record<string, number>, aspect: number): Positions {
    const order = Object.entries(layered)
        .sort(([, a], [, b]) => a.x - b.x || a.y - b.y)
        .map(([id]) => id);
    const heightOf = (id: string) => (sizes[id] ?? 120) + STACK_GAP;
    const total = order.reduce((sum, id) => sum + heightOf(id), 0);
    const step = NODE_WIDTH + GAP;
    // Columns for a width about `aspect` times the height.
    const columns = Math.max(1, Math.round(Math.sqrt((aspect * total) / step)));
    const target = total / columns;

    const positions: Positions = {};
    let column = 0;
    let y = 0;
    for (const id of order) {
        if (y > 0 && y + heightOf(id) / 2 > target && column < columns - 1) {
            column++;
            y = 0;
        }
        positions[id] = { x: column * step, y };
        y += heightOf(id);
    }
    return positions;
}

export type Region = { connection: string; x: number; y: number; width: number; height: number };

// Room around a connection's tables, its label above them, and the space between two connections.
const REGION_PAD = 60;
const REGION_HEAD = 70;
const REGION_GAP = 160;

// Every connection laid out on its own inside a labeled region; the regions are then packed in rows, left to right,
// wrapping so the whole diagram stays closer to a screen's shape than one tall column would.
export async function layoutConnections(
    schema: Schema,
    connections: string[],
    ids: Set<string>,
    sizes: Record<string, number>,
    aspect = WIDE,
): Promise<{ positions: Positions; regions: Region[] }> {
    if (connections.length === 1) {
        return { positions: await autoLayout(schema, connections[0], ids, sizes, aspect), regions: [] };
    }
    const blocks: { connection: string; placed: Positions; width: number; height: number }[] = [];
    for (const connection of connections) {
        const own = new Set(schema.tables(connection).map((t) => nodeId(connection, t.name)).filter((id) => ids.has(id)));
        if (own.size === 0) {
            continue;
        }
        const raw = await autoLayout(schema, connection, own, sizes, aspect);
        const boxes = Object.entries(raw).map(([id, p]) => ({ id, ...p, bottom: p.y + (sizes[id] ?? 120) }));
        const minX = Math.min(...boxes.map((b) => b.x));
        const minY = Math.min(...boxes.map((b) => b.y));
        const placed: Positions = {};
        boxes.forEach((b) => (placed[b.id] = { x: b.x - minX + REGION_PAD, y: b.y - minY + REGION_HEAD + REGION_PAD }));
        blocks.push({
            connection,
            placed,
            width: Math.max(...boxes.map((b) => b.x + NODE_WIDTH)) - minX + REGION_PAD * 2,
            height: Math.max(...boxes.map((b) => b.bottom)) - minY + REGION_HEAD + REGION_PAD * 2,
        });
    }

    const area = blocks.reduce((sum, b) => sum + b.width * b.height, 0);
    // Rows of regions about as wide as the screen is shaped: several side by side on a desktop, stacked on a phone.
    const rowLimit = Math.max(...blocks.map((b) => b.width), Math.sqrt(area) * (aspect >= 1 ? 1.7 : 0.6));
    const positions: Positions = {};
    const regions: Region[] = [];
    let x = 0;
    let y = 0;
    let rowHeight = 0;
    for (const block of blocks) {
        if (x > 0 && x + block.width > rowLimit) {
            x = 0;
            y += rowHeight + REGION_GAP;
            rowHeight = 0;
        }
        for (const [id, p] of Object.entries(block.placed)) {
            positions[id] = { x: p.x + x, y: p.y + y };
        }
        regions.push({ connection: block.connection, x, y, width: block.width, height: block.height });
        x += block.width + REGION_GAP;
        rowHeight = Math.max(rowHeight, block.height);
    }
    return { positions, regions };
}

// What the page hands the viewer: the schema and every connection's downloads, in the app's page (from the snapshot
// php artisan schema-viewer:build wrote) and in a static export alike.
export type ServerConfig = {
    schema?: SchemaDocument;
    downloads?: StaticDownloads;
    // What the viewer opens on: 'all' or one connection's name (config schema-viewer.default_connection).
    defaultConnection?: string;
    // Opens a file in the developer's editor: {file} (relative to the app's root) and {line}. Never in a static export.
    editor?: string | null;
    // Set when the app has no readable snapshot yet: what to run to build it.
    missing?: string | null;
    // What changed since the previous build that changed the schema; null before there was one to compare with.
    changes?: Change[] | null;
};

// Each connection's Mermaid, DBML and Markdown files, carried by the page.
export type StaticDownloads = Record<string, Record<'mermaid' | 'dbml' | 'markdown', string>>;
