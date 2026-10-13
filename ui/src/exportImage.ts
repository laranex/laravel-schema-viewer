// The diagram as an image: an SVG drawn from the cards and edges on the diagram, with the theme's colors and the page's
// fonts inlined, and a PNG painted from it. Nothing here reads the DOM; the viewer hands over what is shown.
import { HEADER_HEIGHT, ROW_HEIGHT } from './graph';
import { LABEL_HEIGHT_PX, LABEL_WIDTH, orthogonal } from './routing';

// The theme's colors as literal values (rgb(...)), by token name without the leading dashes.
export const COLOR_TOKENS = ['bg', 'surface', 'surface-2', 'line', 'line-strong', 'fg', 'muted', 'accent', 'accent-ink', 'accent-soft', 'warn', 'warn-soft', 'warn-ink', 'fk', 'through', 'through-soft'] as const;
export type Colors = Record<(typeof COLOR_TOKENS)[number], string>;

export type ImageRow = { name: string; key: 'PK' | 'FK' | ''; type: string; nullable: boolean };

export type ImageNode =
    | {
          kind: 'table';
          x: number;
          y: number;
          width: number;
          height: number;
          name: string;
          // Model names, or what the table is when it has none.
          meta: string[];
          noModel: string | null;
          tags: string[];
          pivot: boolean;
          framework: boolean;
          hints: number;
          change: 'new' | 'changed' | null;
          rows: ImageRow[];
          more: number;
      }
    | { kind: 'external'; x: number; y: number; width: number; height: number; connection: string; table: string }
    | { kind: 'region'; x: number; y: number; width: number; height: number; connection: string; meta: string };

export type ImageEdge = {
    kind: 'both' | 'relation' | 'foreign_key' | 'through';
    many: boolean;
    sx: number;
    sy: number;
    cx: number;
    tx: number;
    ty: number;
    label: { x: number; y: number; text: string; chip: string } | null;
};

export type Bounds = { x: number; y: number; width: number; height: number };

const MONO = "'IBM Plex Mono', ui-monospace, Menlo, Consolas, monospace";
const SANS = "'IBM Plex Sans', ui-sans-serif, system-ui, sans-serif";

const escape = (text: string) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

// Text cut to a width, by the advance of IBM Plex Mono (0.6em) or an average one for Plex Sans.
export function fit(text: string, width: number, size: number, mono = true): string {
    const advance = size * (mono ? 0.6 : 0.56);
    const max = Math.floor(width / advance);
    return text.length <= max ? text : `${text.slice(0, Math.max(1, max - 1))}…`;
}

// The smallest box around every card, with room around it.
export function boundsOf(nodes: ImageNode[], padding = 32): Bounds {
    if (!nodes.length) {
        return { x: 0, y: 0, width: 400, height: 200 };
    }
    const left = Math.min(...nodes.map((n) => n.x));
    const top = Math.min(...nodes.map((n) => n.y));
    const right = Math.max(...nodes.map((n) => n.x + n.width));
    const bottom = Math.max(...nodes.map((n) => n.y + n.height));
    return { x: left - padding, y: top - padding, width: right - left + padding * 2, height: bottom - top + padding * 2 };
}

const STROKE: Record<ImageEdge['kind'], { color: keyof Colors; width: number; dash: string | null }> = {
    both: { color: 'accent', width: 1.8, dash: null },
    relation: { color: 'warn', width: 1.8, dash: '6 4' },
    foreign_key: { color: 'fk', width: 1.4, dash: null },
    through: { color: 'through', width: 1.8, dash: '1.5 4' },
};

function markers(c: Colors): string {
    const out: string[] = [];
    for (const k of ['both', 'relation', 'foreign_key'] as const) {
        const color = c[STROKE[k].color];
        const stroke = `fill="none" stroke="${color}" stroke-width="1.6" stroke-linecap="round"`;
        out.push(
            `<marker id="m-many-${k}" viewBox="0 0 14 14" refX="1" refY="7" markerWidth="14" markerHeight="14" markerUnits="userSpaceOnUse" orient="auto-start-reverse"><path d="M13 7 L1 1 M13 7 L1 7 M13 7 L1 13" ${stroke}/></marker>`,
            `<marker id="m-one-${k}" viewBox="0 0 14 14" refX="1" refY="7" markerWidth="14" markerHeight="14" markerUnits="userSpaceOnUse" orient="auto-start-reverse"><path d="M5 1 L5 13" ${stroke}/></marker>`,
            `<marker id="m-parent-${k}" viewBox="0 0 14 14" refX="13" refY="7" markerWidth="14" markerHeight="14" markerUnits="userSpaceOnUse" orient="auto"><path d="M9 1 L9 13 M5 1 L5 13" ${stroke}/></marker>`,
        );
    }
    out.push(`<marker id="m-arrow-through" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="9" markerHeight="9" markerUnits="userSpaceOnUse" orient="auto"><path d="M0 0 L10 5 L0 10 z" fill="${c.through}"/></marker>`);
    return out.join('');
}

function table(n: Extract<ImageNode, { kind: 'table' }>, c: Colors, i: number): string {
    const out: string[] = [];
    const clip = `card-${i}`;
    out.push(`<g transform="translate(${n.x} ${n.y})">`);
    out.push(`<clipPath id="${clip}"><rect width="${n.width}" height="${n.height}" rx="10"/></clipPath>`);
    out.push(`<rect width="${n.width}" height="${n.height}" rx="10" fill="${c.surface}"/>`);
    out.push(`<g clip-path="url(#${clip})">`);
    out.push(`<rect width="${n.width}" height="${HEADER_HEIGHT}" fill="${c['surface-2']}"/>`);
    if (n.pivot) {
        for (let x = -HEADER_HEIGHT; x < n.width; x += 12) {
            out.push(`<path d="M ${x} ${HEADER_HEIGHT} L ${x + HEADER_HEIGHT} 0" stroke="${c.surface}" stroke-width="4.2"/>`);
        }
    }
    if (n.rows.length || n.more) {
        out.push(`<line x1="0" y1="${HEADER_HEIGHT - 0.5}" x2="${n.width}" y2="${HEADER_HEIGHT - 0.5}" stroke="${c.line}"/>`);
    }
    out.push('</g>');

    // Header: the name with its marks on the right, then the models or what the table is.
    let right = n.width - 12;
    const marks: string[] = [];
    if (n.hints > 0) {
        const label = String(n.hints);
        const w = Math.max(18, 10 + label.length * 6.6);
        right -= w;
        marks.push(`<rect x="${right}" y="9" width="${w}" height="18" rx="9" fill="${c['warn-soft']}"/><text x="${right + w / 2}" y="22" text-anchor="middle" font-family="${SANS}" font-size="11" font-weight="600" fill="${c['warn-ink']}">${label}</text>`);
        right -= 6;
    }
    if (n.change) {
        right -= 8;
        marks.push(
            n.change === 'new'
                ? `<circle cx="${right + 4}" cy="18" r="4" fill="${c.accent}"/>`
                : `<circle cx="${right + 4}" cy="18" r="3" fill="none" stroke="${c.accent}" stroke-width="2"/>`,
        );
        right -= 6;
    }
    out.push(`<text x="12" y="23" font-family="${MONO}" font-size="14" font-weight="500" fill="${c.fg}">${escape(fit(n.name, right - 12, 14))}</text>`, ...marks);

    let x = 12;
    const meta: string[] = [];
    const metaText = n.meta.length ? n.meta.join('  ') : n.noModel ?? '';
    if (metaText) {
        const text = fit(metaText, n.width - 24, 11.5, false);
        meta.push(`<text x="${x}" y="43" font-family="${SANS}" font-size="11.5" fill="${n.meta.length ? c['accent-ink'] : c.muted}"${n.meta.length ? '' : ' font-style="italic"'}>${escape(text)}</text>`);
        x += text.length * 11.5 * 0.56 + 6;
    }
    for (const tag of n.tags) {
        const text = tag.toUpperCase();
        const w = text.length * 10.5 * 0.62 + 12;
        if (x + w > n.width - 8) {
            break;
        }
        meta.push(`<rect x="${x}" y="33" width="${w}" height="14" rx="4" fill="${c['through-soft']}"/><text x="${x + 6}" y="44" font-family="${SANS}" font-size="10.5" font-weight="600" letter-spacing="0.4" fill="${c.through}">${escape(text)}</text>`);
        x += w + 6;
    }
    out.push(...meta);

    // Rows: the key mark, the name (with ? when nullable) and the type on the right.
    let y = HEADER_HEIGHT + 4;
    for (const row of n.rows) {
        const base = y + ROW_HEIGHT / 2 + 4;
        if (row.key) {
            out.push(`<text x="8" y="${base - 0.5}" font-family="${SANS}" font-size="9.5" font-weight="600" letter-spacing="0.4" fill="${c['accent-ink']}">${row.key}</text>`);
        }
        const type = fit(row.type, 104, 11.5);
        const typeWidth = type.length * 11.5 * 0.6;
        const name = fit(row.name, n.width - 38 - 12 - typeWidth - 6 - (row.nullable ? 8 : 0), 12.5);
        out.push(
            `<text x="38" y="${base}" font-family="${MONO}" font-size="12.5" fill="${c.fg}">${escape(name)}${row.nullable ? `<tspan fill="${c.muted}">?</tspan>` : ''}</text>`,
            `<text x="${n.width - 12}" y="${base}" text-anchor="end" font-family="${MONO}" font-size="11.5" fill="${c.muted}">${escape(type)}</text>`,
        );
        y += ROW_HEIGHT;
    }
    if (n.more) {
        out.push(`<text x="38" y="${y + ROW_HEIGHT / 2 + 4}" font-family="${SANS}" font-size="11.5" fill="${c.muted}">+ ${n.more} more ${n.more === 1 ? 'column' : 'columns'}</text>`);
    }

    out.push(`<rect x="0.5" y="0.5" width="${n.width - 1}" height="${n.height - 1}" rx="9.5" fill="none" stroke="${c.line}"${n.framework ? ' stroke-dasharray="4 3"' : ''}/>`);
    out.push('</g>');
    return out.join('');
}

function node(n: ImageNode, c: Colors, i: number): string {
    if (n.kind === 'table') {
        return table(n, c, i);
    }
    if (n.kind === 'external') {
        return [
            `<g transform="translate(${n.x} ${n.y})">`,
            `<rect x="0.75" y="0.75" width="${n.width - 1.5}" height="${n.height - 1.5}" rx="10" fill="${c['through-soft']}" stroke="${c.through}" stroke-width="1.5" stroke-dasharray="5 4"/>`,
            `<text x="14" y="26" font-family="${SANS}" font-size="10.5" font-weight="600" letter-spacing="0.6" fill="${c.through}">OTHER CONNECTION</text>`,
            `<text x="14" y="46" font-family="${MONO}" font-size="13.5" font-weight="500" fill="${c.fg}">${escape(fit(`${n.connection}.${n.table}`, n.width - 28, 13.5))}</text>`,
            '</g>',
        ].join('');
    }
    return [
        `<g transform="translate(${n.x} ${n.y})">`,
        `<rect x="0.75" y="0.75" width="${n.width - 1.5}" height="${n.height - 1.5}" rx="22" fill="none" stroke="${c['line-strong']}" stroke-width="1.5" stroke-dasharray="6 5"/>`,
        `<text x="28" y="48" font-family="${MONO}" font-size="26" font-weight="500" fill="${c.fg}">${escape(n.connection)}<tspan dx="12" font-family="${SANS}" font-size="15" font-weight="400" fill="${c.muted}">${escape(n.meta)}</tspan></text>`,
        '</g>',
    ].join('');
}

function edge(e: ImageEdge, c: Colors): string {
    const s = STROKE[e.kind];
    const start = e.kind === 'through' ? '' : ` marker-start="url(#m-${e.many ? 'many' : 'one'}-${e.kind})"`;
    const end = e.kind === 'through' ? ' marker-end="url(#m-arrow-through)"' : ` marker-end="url(#m-parent-${e.kind})"`;
    return `<path d="${orthogonal(e.sx, e.sy, e.cx, e.tx, e.ty)}" fill="none" stroke="${c[s.color]}" stroke-width="${s.width}"${s.dash ? ` stroke-dasharray="${s.dash}"` : ''}${e.kind === 'through' ? ' stroke-linecap="round"' : ''}${start}${end}/>`;
}

function label(e: ImageEdge, c: Colors): string {
    if (!e.label) {
        return '';
    }
    const chipWidth = e.label.chip.length * 7.5 * 0.56 + 6;
    const text = fit(e.label.text, LABEL_WIDTH - 10 - chipWidth - 3, 9);
    const width = text.length * 9 * 0.6 + 3 + chipWidth + 10;
    const x = e.label.x - width / 2;
    const y = e.label.y - LABEL_HEIGHT_PX / 2;
    const border = e.kind === 'both' ? c.accent : c.line;
    return [
        `<rect x="${x}" y="${y}" width="${width}" height="${LABEL_HEIGHT_PX}" rx="${LABEL_HEIGHT_PX / 2}" fill="${c.surface}" stroke="${border}" stroke-opacity="${e.kind === 'both' ? 0.5 : 1}"/>`,
        `<text x="${x + 5}" y="${e.label.y + 3}" font-family="${MONO}" font-size="9" fill="${c.fg}">${escape(text)}</text>`,
        `<rect x="${x + width - 5 - chipWidth}" y="${e.label.y - 5.5}" width="${chipWidth}" height="11" rx="3" fill="${c['through-soft']}"/>`,
        `<text x="${x + width - 5 - chipWidth / 2}" y="${e.label.y + 2.5}" text-anchor="middle" font-family="${SANS}" font-size="7.5" font-weight="600" fill="${c.through}">${escape(e.label.chip)}</text>`,
    ].join('');
}

// The diagram as one standalone SVG document: regions, then edges, then cards, then labels, as the viewer stacks them.
export function diagramSvg({ nodes, edges, colors, fonts, bounds }: { nodes: ImageNode[]; edges: ImageEdge[]; colors: Colors; fonts: string; bounds: Bounds }): string {
    const { x, y, width, height } = bounds;
    const regions = nodes.filter((n) => n.kind === 'region');
    const cards = nodes.filter((n) => n.kind !== 'region');
    return [
        `<svg xmlns="http://www.w3.org/2000/svg" width="${Math.ceil(width)}" height="${Math.ceil(height)}" viewBox="${x} ${y} ${width} ${height}">`,
        fonts ? `<style><![CDATA[\n${fonts.replace(/]]>/g, ']] >')}\n]]></style>` : '',
        `<defs>${markers(colors)}</defs>`,
        `<rect x="${x}" y="${y}" width="${width}" height="${height}" fill="${colors.bg}"/>`,
        ...regions.map((n, i) => node(n, colors, i)),
        ...edges.map((e) => edge(e, colors)),
        ...cards.map((n, i) => node(n, colors, i + regions.length)),
        ...edges.map((e) => label(e, colors)),
        '</svg>',
    ].join('\n');
}

// The largest scale, up to the wanted one, that keeps the PNG within what browsers paint: 16384 pixels a side and an
// area budget.
export function pngScale(width: number, height: number, wanted: number, area: number): number {
    return Math.min(wanted, 16384 / width, 16384 / height, Math.sqrt(area / (width * height)));
}

// Paint the SVG on a canvas and encode it as PNG, halving the area until the browser manages it.
export async function svgToPng(svg: string, width: number, height: number, wanted = 2): Promise<{ blob: Blob; scale: number }> {
    const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }));
    try {
        const img = new Image();
        img.src = url;
        await img.decode();
        for (let area = 120_000_000; area >= 16_700_000; area /= 2) {
            const scale = pngScale(width, height, wanted, area);
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(width * scale));
            canvas.height = Math.max(1, Math.round(height * scale));
            const context = canvas.getContext('2d');
            if (!context) {
                continue;
            }
            context.drawImage(img, 0, 0, canvas.width, canvas.height);
            const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/png'));
            canvas.width = canvas.height = 0;
            if (blob) {
                return { blob, scale };
            }
        }
        throw new Error('The diagram is too large to paint as a PNG; download the SVG instead.');
    } finally {
        URL.revokeObjectURL(url);
    }
}
