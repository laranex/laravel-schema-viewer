import { HEADER_HEIGHT, NODE_WIDTH, ROW_HEIGHT } from './graph';

// Space between layout columns. Edges run vertically in these gaps and their labels sit there too.
export const GAP = 240;
export const LABEL_WIDTH = GAP - 28;
const LABEL_HEIGHT = 24;
const LANE_STEP = 8;

export type Box = { id: string; x: number; y: number; width: number; height: number; rows: string[] };

export type RouteRequest = {
    id: string;
    source: string;
    target: string;
    sourceColumn: string;
    targetColumn: string;
    labelled: boolean;
};

export type Route = {
    sourceSide: 'l' | 'r';
    targetSide: 'l' | 'r';
    channelX: number;
    labelX: number;
    labelY: number;
};

const rowY = (box: Box, column: string) => {
    const index = Math.max(0, box.rows.indexOf(column));
    return box.y + 1 + HEADER_HEIGHT + 4 + index * ROW_HEIGHT + ROW_HEIGHT / 2;
};

const overlaps = (a: { x: number; y: number; w: number; h: number }, b: { x: number; y: number; w: number; h: number }) =>
    a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;

// Orthogonal routes: out of a column row, along a lane in the gap next to the target, into the target row.
// Labels go in the middle of that gap, moved up or down until they clear cards and other labels.
export function route(boxes: Map<string, Box>, requests: RouteRequest[]): Map<string, Route> {
    type Draft = RouteRequest & { sy: number; ty: number; gap: number; same: boolean; sourceSide: 'l' | 'r'; targetSide: 'l' | 'r'; base: number };
    const drafts: Draft[] = [];

    for (const r of requests) {
        const s = boxes.get(r.source);
        const t = boxes.get(r.target);
        if (!s || !t) {
            continue;
        }
        const sy = rowY(s, r.sourceColumn);
        const ty = rowY(t, r.targetColumn);
        const sc = s.x + s.width / 2;
        const tc = t.x + t.width / 2;
        const same = r.source === r.target || Math.abs(sc - tc) < (s.width + t.width) / 2 - 20;

        if (same) {
            const right = Math.max(s.x + s.width, t.x + t.width);
            drafts.push({ ...r, sy, ty, same, sourceSide: 'r', targetSide: 'r', gap: right + GAP / 2, base: right + 28 });
        } else if (sc < tc) {
            drafts.push({ ...r, sy, ty, same, sourceSide: 'r', targetSide: 'l', gap: t.x - GAP / 2, base: t.x - GAP / 2 });
        } else {
            drafts.push({ ...r, sy, ty, same, sourceSide: 'l', targetSide: 'r', gap: t.x + t.width + GAP / 2, base: t.x + t.width + GAP / 2 });
        }
    }

    // Edges sharing a gap get their own lanes, ordered top to bottom, so vertical runs never sit on each other.
    const lanes = new Map<number, Draft[]>();
    for (const d of drafts) {
        const key = Math.round(d.gap / 10);
        lanes.set(key, [...(lanes.get(key) ?? []), d]);
    }
    const offsets = new Map<string, number>();
    for (const group of lanes.values()) {
        group.sort((a, b) => (a.sy + a.ty) / 2 - (b.sy + b.ty) / 2);
        const limit = GAP / 2 - 20;
        group.forEach((d, i) => offsets.set(d.id, Math.max(-limit, Math.min(limit, (i - (group.length - 1) / 2) * LANE_STEP))));
    }

    const cards = [...boxes.values()].map((b) => ({ x: b.x - 6, y: b.y - 6, w: b.width + 12, h: b.height + 12 }));
    const placed: { x: number; y: number; w: number; h: number }[] = [];
    const routes = new Map<string, Route>();

    // Place labels for shorter vertical runs first: they have the least room to move.
    const order = [...drafts].sort((a, b) => Math.abs(a.ty - a.sy) - Math.abs(b.ty - b.sy));

    for (const d of order) {
        const channelX = (d.same ? d.base : d.gap) + (offsets.get(d.id) ?? 0);
        let labelY = (d.sy + d.ty) / 2;

        if (d.labelled) {
            const top = Math.min(d.sy, d.ty);
            const bottom = Math.max(d.sy, d.ty);
            const candidates = [0];
            for (let step = 1; step <= 24; step++) {
                candidates.push(step * (LABEL_HEIGHT + 4), -step * (LABEL_HEIGHT + 4));
            }
            const fits = (y: number) => {
                const box = { x: d.gap - LABEL_WIDTH / 2, y: y - LABEL_HEIGHT / 2, w: LABEL_WIDTH, h: LABEL_HEIGHT };
                return !cards.some((c) => overlaps(box, c)) && !placed.some((p) => overlaps(box, p));
            };
            // Stay on the vertical run when it is long enough, otherwise move freely along the gap.
            const onRun = candidates.map((c) => labelY + c).filter((y) => bottom - top < LABEL_HEIGHT * 2 || (y >= top && y <= bottom));
            const pick = onRun.find(fits) ?? candidates.map((c) => labelY + c).find(fits) ?? labelY;
            labelY = pick;
            placed.push({ x: d.gap - LABEL_WIDTH / 2, y: labelY - LABEL_HEIGHT / 2, w: LABEL_WIDTH, h: LABEL_HEIGHT });
        }

        routes.set(d.id, { sourceSide: d.sourceSide, targetSide: d.targetSide, channelX, labelX: d.gap, labelY });
    }

    return routes;
}

export const boxWidth = (type: string | undefined) => (type === 'external' ? 200 : NODE_WIDTH);
