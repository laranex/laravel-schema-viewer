import {
    Background,
    BackgroundVariant,
    MiniMap,
    ReactFlow,
    ReactFlowProvider,
    SelectionMode,
    useNodesInitialized,
    useNodesState,
    useReactFlow,
    useStore,
    useViewport,
    ViewportPortal,
    type Node,
    type NodeMouseHandler,
} from '@xyflow/react';
import { useCallback, useDeferredValue, useEffect, useMemo, useRef, useState, type CSSProperties, type ReactNode, type PointerEvent as ReactPointerEvent } from 'react';
import { EdgeHover, EdgeMarkers, HoverStore, LinkEdgeView, type EdgeKind, type LinkEdgeData, type LinkEdgeType } from './components/LinkEdge';
import { SearchBox } from './components/SearchBox';
import { Tour, type TourStep } from './components/Tour';
import { TOUR_STEPS } from './tourSteps';
import { Dialog, Menu, Select, Tooltip, TooltipProvider, type MenuChoice, type MenuToggle } from './components/ui';
import type { Highlight } from './components/Browse';
import { DownloadDialog, type ExportFormat } from './components/DownloadDialog';
import { SidePanel, type PanelTab } from './components/SidePanel';
import { ExternalNodeView, RegionNodeView, TableActions, TableNodeView, type ExternalNodeType, type RegionNodeType, type TableNodeType } from './components/TableNode';
import { cardRows, linkKind, NODE_WIDTH, nodeHeight, nodeId, relationKind, Schema, shortClass, shownColumns, type Fold } from './graph';
import { boxWidth, route, rowY, type Box, type Route } from './routing';
import { boundsOf, COLOR_TOKENS, diagramSvg, svgToCanvas, svgToPng, type Bounds, type Colors, type ImageEdge, type ImageNode } from './exportImage';
import { ARRANGEMENTS, layoutConnections, TALL, WIDE, type Arrangement, type StaticDownloads } from './layout';
import { fontFaces, standaloneHtml } from './standalone';
import { changedTables } from './components/Changes';
import type { Change, SchemaDocument, Table } from './types';

type AppNode = TableNodeType | ExternalNodeType | RegionNodeType;

// A table on the diagram: which connection it is on, and its name.
type TableRef = { connection: string; table: string };

// The connection picker's value for every connection at once.
const ALL = '*';

// From this many tables on screen, edges are drawn thin and muted until a table is selected.
const DENSE = 60;
// At this zoom or below, the diagram shows a picture of itself with its columns as placeholder bars, as dbdiagram does:
// column text is too small to read there, and moving one picture is far smoother than moving thousands of cards and
// lines. The picture is made a little earlier, so it is ready.
const PICTURE_ZOOM = 0.4;
const PREPARE_ZOOM = 0.55;
// The narrowest the panel goes: its four tabs and their counts still fit on one row.
const PANEL_MIN = 340;
// The narrowest the panel may leave the diagram: room for the floating bar with every control in it.
const DIAGRAM_ROOM = 480;

type Layers = Record<EdgeKind, boolean> & { app: boolean; framework: boolean; package: boolean; allLabels: boolean; notes: boolean };

const nodeTypes = { table: TableNodeView, external: ExternalNodeView, region: RegionNodeView };
const edgeTypes = { link: LinkEdgeView };

const EXTERNAL_HEIGHT = 76;

const NONE: string[] = [];

// Equal node or edge data: the same values, arrays compared item by item, so unchanged cards and edges keep their
// objects and skip rendering.
function sameData(a: Record<string, unknown>, b: Record<string, unknown>): boolean {
    for (const key in b) {
        const x = a[key];
        const y = b[key];
        if (x === y) {
            continue;
        }
        if (!Array.isArray(x) || !Array.isArray(y) || x.length !== y.length || x.some((v, i) => v !== y[i])) {
            return false;
        }
    }
    return true;
}

const sameRoute = (a: Route, b: Route) => a.sourceSide === b.sourceSide && a.targetSide === b.targetSide && a.channelX === b.channelX && a.labelX === b.labelX && a.labelY === b.labelY;

// Only the theme is remembered in this browser; storage can be blocked, and then it is not. Earlier versions also
// kept the view settings below, folded tables by name included; they are cleared, so nothing else stays behind.
const FORGOTTEN = ['columns', 'folds', 'panel-width', 'arrangement', 'small-screen-note-closed', 'grid', 'shortcut-hints'];
try {
    FORGOTTEN.forEach((key) => localStorage.removeItem(`schema-viewer.${key}`));
} catch {
    // Storage blocked: there is nothing to clear.
}

function stored<T>(key: string, fallback: T, valid: (value: unknown) => boolean): T {
    try {
        const value: unknown = JSON.parse(localStorage.getItem(key) ?? 'null');
        return valid(value) ? (value as T) : fallback;
    } catch {
        return fallback;
    }
}

function store(key: string, value: unknown) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Storage blocked: the choice lasts until reload.
    }
}

const FOLDS: Fold[] = ['all', 'keys', 'none'];
const isFold = (v: unknown): v is Fold => FOLDS.includes(v as Fold);

// What a link to the viewer opens on, kept in the URL's hash so it works from a static export too:
// #connection=all&table=billing:invoices&focus=2, or a relationship or column instead of the table:
// #connection=all&relationship=mysql:<link id>&focus=1, #connection=all&column=mysql:orders.user_id
type LinkState = { scope?: string; table?: TableRef; focus?: number; highlight?: Highlight; at?: { x: number; y: number; zoom: number }; columns?: Fold; missing?: string };

function readHash(doc: SchemaDocument): LinkState {
    const params = new URLSearchParams(window.location.hash.slice(1));
    const scope = params.get('connection');
    const table = params.get('table');
    const at = table?.indexOf(':') ?? -1;
    const ref = table && at > 0 ? { connection: table.slice(0, at), table: table.slice(at + 1) } : undefined;
    const focus = Number(params.get('focus'));
    const connectionOf = (value: string | null) => {
        const i = value?.indexOf(':') ?? -1;
        return value && i > 0 ? { connection: value.slice(0, i), rest: value.slice(i + 1) } : null;
    };
    const tablesOf = (connection: string) => doc.connections.find((c) => c.name === connection)?.tables ?? [];
    let highlight: Highlight | undefined;
    const rel = connectionOf(params.get('relationship'));
    const col = connectionOf(params.get('column'));
    // What the link names but the schema lacks: the link then opens like a plain one, and the viewer says so.
    let missing: string | undefined;
    if (scope && scope !== 'all' && !doc.connections.some((c) => c.name === scope)) {
        missing = `the connection ${scope}`;
    }
    if (params.has('relationship')) {
        if (rel && (doc.links.some((l) => l.id === rel.rest && l.from.connection === rel.connection) || doc.derivedLinks.some((d) => d.id === rel.rest && d.connection === rel.connection))) {
            highlight = { kind: 'link', connection: rel.connection, id: rel.rest };
        } else {
            missing = 'this relationship';
        }
    } else if (params.has('column')) {
        const rest = col?.rest ?? '';
        const dot = rest.lastIndexOf('.');
        const t = rest.slice(0, dot);
        const c = rest.slice(dot + 1);
        if (col && dot > 0 && tablesOf(col.connection).some((x) => x.name === t && x.columns.some((y) => y.name === c))) {
            highlight = { kind: 'column', connection: col.connection, table: t, column: c };
        } else {
            missing = `the column ${rest || params.get('column')}`;
        }
    }
    const tableFound = ref && doc.connections.find((c) => c.name === ref.connection)?.tables.some((t) => t.name === ref.table);
    if (table && !highlight && !missing && !tableFound) {
        missing = `the table ${ref?.table ?? table}`;
    }
    const view = (params.get('at') ?? '').split(',').map(Number);
    const columns = params.get('columns');
    return {
        missing,
        highlight,
        // A link to something missing opens on the whole diagram, not on where that was.
        at: !missing && view.length === 3 && view.every(Number.isFinite) && view[2] > 0 ? { x: view[0], y: view[1], zoom: view[2] } : undefined,
        columns: isFold(columns) ? columns : undefined,
        scope: scope === 'all' && doc.connections.length > 1 ? ALL : doc.connections.some((c) => c.name === scope) ? (scope as string) : undefined,
        table: tableFound ? ref : undefined,
        focus: focus >= 1 && focus <= 3 ? focus : undefined,
    };
}

function writeHash(scope: string, table: TableRef | null, focus: number | null, highlight: Highlight | null) {
    const params = new URLSearchParams();
    params.set('connection', scope === ALL ? 'all' : scope);
    if (highlight?.kind === 'link') {
        params.set('relationship', `${highlight.connection}:${highlight.id}`);
    } else if (highlight?.kind === 'column') {
        params.set('column', `${highlight.connection}:${highlight.table}.${highlight.column}`);
    } else if (table) {
        params.set('table', `${table.connection}:${table.table}`);
    }
    if ((table || highlight) && focus) {
        params.set('focus', String(focus));
    }
    const hash = `#${params.toString().replace(/%3A/g, ':')}`;
    if (hash !== window.location.hash) {
        window.history.replaceState(window.history.state, '', hash);
    }
}

function useMedia(media: string) {
    const [matches, setMatches] = useState(() => window.matchMedia(media).matches);
    useEffect(() => {
        const query = window.matchMedia(media);
        const update = () => setMatches(query.matches);
        query.addEventListener('change', update);
        return () => query.removeEventListener('change', update);
    }, [media]);
    return matches;
}

const useNarrow = () => useMedia('(max-width: 760px)');

type ViewerProps = {
    doc: SchemaDocument;
    example?: boolean;
    downloads?: StaticDownloads;
    defaultConnection?: string;
    editor?: string | null;
    changes?: Change[] | null;
};

export default function App(props: ViewerProps) {
    return (
        <ReactFlowProvider>
            <TooltipProvider>
                <Viewer {...props} />
            </TooltipProvider>
        </ReactFlowProvider>
    );
}

function Viewer({ doc, example = false, downloads, defaultConnection, editor, changes }: ViewerProps) {
    const schema = useMemo(() => new Schema(doc), [doc]);
    const flow = useReactFlow<AppNode, LinkEdgeType>();
    const [linked] = useState(() => readHash(doc));

    // One connection, or ALL to put every connection on the diagram. It opens on the link's connection, else the
    // configured one when the schema has it; otherwise on every connection, or the only one.
    const [scope, setScope] = useState(() => {
        const opening =
            linked.scope ??
            (doc.connections.some((c) => c.name === defaultConnection) ? (defaultConnection as string) : doc.connections.length > 1 ? ALL : doc.connections[0].name);
        // The linked table's connection wins over a single other connection.
        return linked.table && opening !== ALL && opening !== linked.table.connection ? linked.table.connection : opening;
    });
    const [selected, setSelected] = useState<TableRef | null>(null);
    // Several cards picked at once, by Shift+click or a box dragged on the background: they move together. Empty when
    // one card or none is picked.
    const [group, setGroup] = useState<string[]>([]);
    // A relationship or column picked in Browse or on the diagram: Details shows it and the diagram lights it up.
    const [highlight, setHighlight] = useState<Highlight | null>(() => linked.highlight ?? null);
    // A relationship or column from the link, brought into view once the diagram is drawn.
    const [pendingHighlight, setPendingHighlight] = useState<Highlight | null>(() => linked.highlight ?? null);
    // A table to select and center once the diagram has placed it.
    const [pending, setPending] = useState<TableRef | null>(() => linked.table ?? null);
    // Focus mode: only a table and the tables within `depth` links of it.
    // Focus mode: a table, or a relationship or column (on), and how many links around it stay on the diagram.
    const [focus, setFocus] = useState<(TableRef & { depth: number; on?: Highlight }) | null>(() =>
        linked.highlight && linked.focus ? { ...seedsOf(schema, linked.highlight)[0], depth: linked.focus, on: linked.highlight } : linked.table && linked.focus ? { ...linked.table, depth: linked.focus } : null,
    );
    // How much of each card shows: one choice for every table, and the tables folded or unfolded one by one.
    const [columns, setColumnsState] = useState<Fold>(() => linked.columns ?? 'all');
    const [folds, setFolds] = useState<Record<string, Fold>>({});
    // The details panel's width, dragged by its edge; null keeps the default.
    const [panelWidth, setPanelWidth] = useState<number | null>(null);
    const scopeConnections = useMemo(() => (scope === ALL ? doc.connections.map((c) => c.name) : [scope]), [doc, scope]);
    const selectedId = selected ? nodeId(selected.connection, selected.table) : null;
    // The picked cards as of the last click, ahead of React's next render: two quick Shift+clicks each add their card.
    const pickedRef = useRef<string[]>([]);
    pickedRef.current = group.length > 0 ? group : selectedId ? [selectedId] : [];
    const [tab, setTab] = useState<PanelTab>(() => (linked.table || linked.highlight ? 'details' : 'browse'));
    const [query, setQuery] = useState('');
    const [layers, setLayers] = useState<Layers>(() => ({
        both: true,
        relation: true,
        foreign_key: true,
        through: true,
        app: true,
        framework: true,
        package: true,
        allLabels: true,
        notes: false,
    }));
    const [nodes, setNodes, onNodesChange] = useNodesState<AppNode>([]);
    const [toast, setToast] = useState<string | null>(null);
    const [layoutVersion, setLayoutVersion] = useState(0);
    // How Arrange last placed the tables.
    const [arrangement, setArrangement] = useState<Arrangement>('left-right');
    const [needsFit, setNeedsFit] = useState(false);
    const [sheetOpen, setSheetOpen] = useState(false);
    // Pan mode: dragging anywhere moves the diagram, even over a table. The hand button switches it; holding Space
    // turns it on until the key is released.
    const [panMode, setPanMode] = useState(false);
    // A phone gets a note that the diagram is easier on a larger screen, until it is closed.
    const [smallScreenNote, setSmallScreenNote] = useState(true);
    const closeSmallScreenNote = () => setSmallScreenNote(false);
    // The dotted grid behind the diagram.
    const [grid, setGrid] = useState(true);
    const switchGrid = () => setGrid((on) => !on);
    const [spacePan, setSpacePan] = useState(false);
    const panning = panMode || spacePan;
    const narrow = useNarrow();
    // Light by default, or dark once the person picks it, remembered in this browser. A page that embeds the viewer can
    // still set data-theme on it.
    const [theme, setTheme] = useState<'light' | 'dark' | null>(() => stored('schema-viewer.theme', null, (v) => v === 'light' || v === 'dark'));
    useEffect(() => {
        if (theme) {
            document.documentElement.dataset.theme = theme;
        }
    }, [theme]);
    const dark = theme ? theme === 'dark' : document.documentElement.dataset.theme === 'dark';
    const switchTheme = () => {
        const next = dark ? 'light' : 'dark';
        setTheme(next);
        store('schema-viewer.theme', next);
    };
    const roomy = useMedia('(min-width: 1100px)');
    // The panel sits beside the diagram, so its width can be dragged.
    const sideBySide = useMedia('(min-width: 961px)');
    const [hover] = useState(() => new HoverStore());
    // The card being dragged: its edges float until it is dropped, then every edge is routed again.
    const [dragging, setDragging] = useState<Set<string> | null>(null);
    const searchRef = useRef<HTMLInputElement>(null);
    const [helpOpen, setHelpOpen] = useState(false);
    // Whether each control shows its shortcut key beside it. Off by default.
    const [keysOn, setKeysOn] = useState(false);
    const switchKeys = (on: boolean) => setKeysOn(on);
    const [tourOpen, setTourOpen] = useState(false);
    const [menuOpen, setMenuOpen] = useState(false);
    // While a tour step explains part of the phone menu, the tour keeps the menu open.
    const [tourMenu, setTourMenu] = useState<TourStep['menu']>(undefined);
    // A wider screen's toolbar menu the tour holds open for its step (Download).
    const [tourOpens, setTourOpens] = useState<TourStep['opens']>(undefined);
    const [downloadOpen, setDownloadOpen] = useState(false);
    const [tourLabels, setTourLabels] = useState(false);
    const onTourStep = useCallback((step: TourStep | null) => {
        setTourMenu(step?.menu);
        // A short phone has no room for both an open floating-bar menu and the card; the step then shows the button only.
        const roomFor = step?.opens === 'download' || !(window.innerWidth <= 760 && window.innerHeight < 760);
        setTourOpens(roomFor ? step?.opens : undefined);
        setTourLabels(!!step?.labels);
        if (step?.tab) {
            setTab(step.tab);
        }
        document.querySelectorAll('[data-tour-pick]').forEach((el) => el.removeAttribute('data-tour-pick'));
        // Bring what the step shows to the middle at a readable size in one smooth move: the label nearest the middle
        // once the labels are drawn, or the table nearest it (its header and first columns, when it is taller than the
        // screen). On a phone the card takes the top of the screen, so a table goes lower, clear of it.
        if (step?.closeUp) {
            window.setTimeout(() => {
                const cx = window.innerWidth / 2;
                const cy = window.innerHeight / 2;
                const zoom = Math.max(flow.getZoom(), 0.9);
                const picked = [...document.querySelectorAll(step.labels ? '.edge-label' : '.react-flow__node-table')]
                    .map((el) => ({ el, r: el.getBoundingClientRect() }))
                    .sort((a, b) => Math.hypot(a.r.x + a.r.width / 2 - cx, a.r.y + a.r.height / 2 - cy) - Math.hypot(b.r.x + b.r.width / 2 - cx, b.r.y + b.r.height / 2 - cy))[0];
                // The tour spotlights this one, so it follows it through the move.
                document.querySelectorAll('[data-tour-pick]').forEach((el) => el.removeAttribute('data-tour-pick'));
                picked?.el.setAttribute('data-tour-pick', '');
                const nearest = picked?.r;
                if (!nearest) {
                    flow.zoomTo(zoom, { duration: 450 });
                    return;
                }
                const scale = zoom / flow.getZoom();
                const at = flow.screenToFlowPosition({ x: nearest.x + nearest.width / 2, y: nearest.y + Math.min(nearest.height * scale, window.innerHeight * 0.4) / scale / 2 });
                const lower = !step.labels && window.innerWidth <= 760 ? (window.innerHeight * 0.18) / zoom : 0;
                const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                flow.setCenter(at.x, at.y - lower, { zoom, duration: still ? 0 : 450 });
            }, 60);
        }
    }, [flow]);
    const endTour = useCallback(() => setTourOpen(false), []);
    const toastTimer = useRef<number | undefined>(undefined);
    const initialized = useNodesInitialized();

    // Fit the diagram once React Flow has measured the newly placed tables.
    useEffect(() => {
        if (needsFit && initialized) {
            setNeedsFit(false);
            userMoved.current = false;
            fitRef.current();
        }
    }, [needsFit, initialized]);

    const atRef = useRef(linked.at ?? null);
    // Phones start on the selected table at a readable size; larger screens see the whole diagram.
    const fitRef = useRef(() => undefined as unknown);
    fitRef.current = () => {
        // A copied link opens where it was copied, once.
        if (atRef.current) {
            const at = atRef.current;
            atRef.current = null;
            return flow.setCenter(at.x, at.y, { zoom: at.zoom });
        }
        const target = narrow && selectedId ? [{ id: selectedId }] : undefined;
        return flow.fitView({ padding: narrow ? 0.25 : 0.08, nodes: target, maxZoom: narrow ? 1.1 : 1 });
    };

    // The canvas can change size after the first fit (a phone's browser bars settling, a rotated screen):
    // fit again until the person pans or zooms themselves.
    const userMoved = useRef(false);
    const canvasRef = useRef<HTMLDivElement>(null);
    // Ctrl or Cmd (the Windows key) with a mouse wheel zooms one step a notch, like the + and − buttons. React Flow
    // zooms ten times faster with Ctrl held, made for a trackpad pinch, which sends Ctrl and small steps: those still go
    // to React Flow, and so does every scroll without a key, which moves the diagram.
    useEffect(() => {
        const canvas = canvasRef.current;
        if (!canvas) {
            return;
        }
        const onWheel = (e: WheelEvent) => {
            const notch = e.deltaMode !== 0 || Math.abs(e.deltaY) >= 40;
            if (!(e.ctrlKey || e.metaKey) || !notch || e.deltaY === 0) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            const { x, y, zoom } = flow.getViewport();
            const next = Math.min(2, Math.max(0.02, zoom * (e.deltaY < 0 ? 1.2 : 1 / 1.2)));
            const box = canvas.getBoundingClientRect();
            const px = e.clientX - box.left;
            const py = e.clientY - box.top;
            flow.setViewport({ x: px - ((px - x) * next) / zoom, y: py - ((py - y) * next) / zoom, zoom: next }, { duration: 80 });
        };
        canvas.addEventListener('wheel', onWheel, { capture: true, passive: false });
        return () => canvas.removeEventListener('wheel', onWheel, { capture: true });
    }, [flow]);
    useEffect(() => {
        const canvas = canvasRef.current;
        if (!canvas) {
            return;
        }
        let last = '';
        const observer = new ResizeObserver(([entry]) => {
            const size = `${Math.round(entry.contentRect.width)}x${Math.round(entry.contentRect.height)}`;
            if (size !== last && last !== '' && !userMoved.current) {
                fitRef.current();
            }
            last = size;
        });
        observer.observe(canvas);
        return () => observer.disconnect();
    }, []);

    const notify = useCallback((message: string, ms = 2600) => {
        setToast(message);
        window.clearTimeout(toastTimer.current);
        toastTimer.current = window.setTimeout(() => setToast(null), ms);
    }, []);

    // A shared link to a table, relationship, column or connection this schema no longer has.
    const missingNote = useCallback((what: string) => notify(`This link points at ${what}, which this schema does not have. Showing the whole diagram instead.`, 6000), [notify]);
    useEffect(() => {
        if (linked.missing) {
            missingNote(linked.missing);
        }
    }, [linked.missing, missingNote]);

    // Every table in view, with its connection.
    const visibleTables = useMemo(
        () =>
            scopeConnections.flatMap((c) =>
                schema
                    .tables(c)
                    .filter((t) => (t.framework ? layers.framework : t.package ? layers.package : layers.app))
                    .map((table) => ({ connection: c, table })),
            ),
        [schema, scopeConnections, layers.app, layers.framework, layers.package],
    );
    const layerToggles = LAYER_TOGGLES;
    // How many tables of each kind the connections in view hold, shown beside each Tables switch.
    const tableCounts = useMemo(() => {
        const counts = { app: 0, framework: 0, package: 0 };
        for (const c of scopeConnections) {
            for (const t of schema.tables(c)) {
                counts[t.framework ? 'framework' : t.package ? 'package' : 'app'] += 1;
            }
        }
        return counts;
    }, [schema, scopeConnections]);
    // The diagram's switches, in two menus: which lines show, and which tables.
    const asMenuToggle = (t: (typeof LAYER_TOGGLES)[number]) => ({
        label: t.label,
        checked: layers[t.key],
        onChange: () => toggle(t.key),
        icon: t.swatch ? <Swatch kind={t.swatch} /> : undefined,
        hotkey: t.hotkey,
    });
    const lineToggles = layerToggles.filter((t) => t.swatch).map(asMenuToggle);
    const showToggles = [...layerToggles.filter((t) => t.key === 'allLabels' || t.key === 'notes').map(asMenuToggle), { label: 'Grid', checked: grid, onChange: switchGrid }];
    const tableToggles = layerToggles
        .filter((t) => t.key === 'app' || t.key === 'framework' || t.key === 'package')
        .map((t) => ({ ...asMenuToggle(t), icon: <span className="ui-item__count">{tableCounts[t.key as keyof typeof tableCounts]}</span> }));
    const tablesById = useMemo(() => new Map(visibleTables.map((e) => [nodeId(e.connection, e.table.name), e])), [visibleTables]);

    // In focus mode the diagram holds only the focused table and its neighbors; search still finds every table.
    // A relationship or column in focus starts from every table it touches: 1 link keeps just those tables.
    const focusSet = useMemo(() => {
        if (!focus) {
            return null;
        }
        if (!focus.on) {
            return schema.neighbors(focus.connection, focus.table, focus.depth);
        }
        const out = new Set<string>();
        for (const seed of seedsOf(schema, focus.on)) {
            schema.neighbors(seed.connection, seed.table, focus.depth - 1).forEach((id) => out.add(id));
        }
        return out;
    }, [schema, focus]);
    const shownTables = useMemo(() => (focusSet ? visibleTables.filter((e) => focusSet.has(nodeId(e.connection, e.table.name))) : visibleTables), [visibleTables, focusSet]);

    // The columns each card shows.
    const keysById = useMemo(() => new Map(visibleTables.map((e) => [nodeId(e.connection, e.table.name), schema.keyColumns(e.connection, e.table.name)])), [schema, visibleTables]);
    const shownOf = useCallback(
        (id: string, table: Table) => shownColumns(table, folds[id] ?? columns, keysById.get(id) ?? new Set()),
        [folds, columns, keysById],
    );
    const shownOfRef = useRef(shownOf);
    shownOfRef.current = shownOf;

    // The links and through links in view: each once, even when it joins two connections in view.
    const links = useMemo(() => [...new Map(scopeConnections.flatMap((c) => schema.links(c)).map((l) => [l.id, l])).values()], [schema, scopeConnections]);
    const derived = useMemo(() => scopeConnections.flatMap((c) => schema.derived(c)), [schema, scopeConnections]);

    // What a highlight lights up: its tables (main: the one it sits on), its lines, and the rows they use.
    const spot = useMemo(() => {
        if (!highlight) {
            return null;
        }
        const nodes = new Set<string>();
        const edges = new Set<string>();
        const rows = new Map<string, string[]>();
        const addRows = (end: { connection: string; table: string; columns: string[] }) => {
            const id = nodeId(end.connection, end.table);
            nodes.add(id);
            rows.set(id, [...(rows.get(id) ?? []), ...end.columns]);
        };
        let main: string;
        if (highlight.kind === 'link') {
            const l = links.find((x) => x.id === highlight.id);
            const d = l ? undefined : derived.find((x) => x.id === highlight.id);
            if (l) {
                addRows(l.from);
                addRows(l.to);
                main = nodeId(l.from.connection, l.from.table);
            } else if (d) {
                [d.from, ...d.via, d.to].forEach((t) => nodes.add(nodeId(d.connection, t)));
                main = nodeId(d.connection, d.from);
            } else {
                return null;
            }
            edges.add(highlight.id);
        } else {
            main = nodeId(highlight.connection, highlight.table);
            nodes.add(main);
            rows.set(main, [highlight.column]);
            for (const l of links) {
                const at = (e: { connection: string; table: string; columns: string[] }) => e.connection === highlight.connection && e.table === highlight.table && e.columns.includes(highlight.column);
                if (at(l.from) || at(l.to)) {
                    edges.add(l.id);
                    addRows(l.from);
                    addRows(l.to);
                }
            }
        }
        return { main, nodes, edges, rows };
    }, [highlight, links, derived]);

    // Tables on a connection out of view that the relations in view point at.
    const externals = useMemo(() => {
        const out = new Map<string, TableRef>();
        links.forEach((l) => {
            const id = nodeId(l.to.connection, l.to.table);
            if (!scopeConnections.includes(l.to.connection) && (!focusSet || focusSet.has(id))) {
                out.set(id, { connection: l.to.connection, table: l.to.table });
            }
        });
        return out;
    }, [links, scopeConnections, focusSet]);

    // Place the nodes automatically; with every connection in view, each connection gets its own region.
    useEffect(() => {
        let cancelled = false;
        const ids = new Set([...shownTables.map((e) => nodeId(e.connection, e.table.name)), ...externals.keys()]);
        const sizes: Record<string, number> = {};
        shownTables.forEach((e) => {
            const id = nodeId(e.connection, e.table.name);
            const shown = shownOfRef.current(id, e.table);
            sizes[id] = nodeHeight(e.table, shown.length, cardRows(e.table, shown, layers.notes).length - shown.length);
        });
        externals.forEach((_, id) => (sizes[id] = EXTERNAL_HEIGHT));

        const place = async () => {
            const { positions, regions } = await layoutConnections(schema, scopeConnections, ids, sizes, narrow ? TALL : WIDE, arrangement);
            if (cancelled) {
                return;
            }
            const next: AppNode[] = [
                ...regions.map(
                    (r): RegionNodeType => ({
                        id: `region:${r.connection}`,
                        type: 'region',
                        position: { x: r.x, y: r.y },
                        width: r.width,
                        height: r.height,
                        zIndex: -1,
                        draggable: false,
                        selectable: false,
                        focusable: false,
                        data: {
                            connection: r.connection,
                            driver: doc.connections.find((c) => c.name === r.connection)?.driver ?? '',
                            tables: shownTables.filter((e) => e.connection === r.connection).length,
                            hints: schema.hints(r.connection).length,
                        },
                    }),
                ),
                ...shownTables.map(
                    ({ connection: c, table: t }): TableNodeType => ({
                        id: nodeId(c, t.name),
                        type: 'table',
                        position: positions[nodeId(c, t.name)] ?? { x: 0, y: 0 },
                        data: { connection: c, table: t, models: [], pivot: false, hintCount: 0, dim: false, active: false, primary: [], foreign: [], linked: [], casts: {}, fold: 'all', shown: [], ends: [], notes: false },
                        width: NODE_WIDTH,
                    }),
                ),
                ...[...externals.entries()].map(
                    ([id, e]): ExternalNodeType => ({
                        id,
                        type: 'external',
                        position: positions[id] ?? { x: 0, y: 0 },
                        data: { connection: e.connection, table: e.table, dim: false },
                        width: 200,
                    }),
                ),
            ];
            setNodes(next.map((n) => decorateRef.current(n)));
            setNeedsFit(true);
        };
        place();
        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [schema, scopeConnections, shownTables, externals, layoutVersion, narrow, layers.notes, arrangement]);

    const neighbors = useMemo(() => (selected ? schema.neighbors(selected.connection, selected.table, 1) : null), [schema, selected]);
    const pivots = useMemo(() => new Set(scopeConnections.flatMap((c) => [...schema.pivotTables(c)].map((t) => nodeId(c, t)))), [schema, scopeConnections]);

    // Search dims the cards it does not match. It runs on a deferred query, so typing stays responsive on large schemas.
    const deferredQuery = useDeferredValue(query);
    const matched = useMemo(() => {
        const q = deferredQuery.trim();
        if (q === '') {
            return null;
        }
        const ids = new Set<string>();
        tablesById.forEach((e, id) => schema.matches(e.connection, e.table, q) && ids.add(id));
        return ids;
    }, [schema, tablesById, deferredQuery]);
    const isDim = useCallback((id: string) => matched !== null && !matched.has(id), [matched]);

    // The columns of each card that the selected table's links use.
    const linkedColumns = useMemo(() => {
        const out = new Map<string, string[]>();
        if (!selected) {
            return out;
        }
        const add = (end: { connection: string; table: string; columns: string[] }) => {
            const id = nodeId(end.connection, end.table);
            out.set(id, [...(out.get(id) ?? []), ...end.columns]);
        };
        const at = (end: { connection: string; table: string }) => end.connection === selected.connection && end.table === selected.table;
        for (const l of schema.links(selected.connection)) {
            if (at(l.from) || at(l.to)) {
                add(l.from);
                add(l.to);
            }
        }
        return out;
    }, [schema, selected]);

    // What a card shows that only the schema decides, worked out once per table.
    const cards = useMemo(() => {
        const cache = new Map<string, { models: string[]; primary: string[]; foreign: string[]; casts: Record<string, string>; ends: string[]; hintCount: number; change?: 'new' | 'changed' }>();
        const marks = changedTables(changes);
        return (c: string, t: Table) => {
            const id = nodeId(c, t.name);
            let card = cache.get(id);
            if (!card) {
                const models = schema.modelsFor(c, t.name);
                const keys = schema.keyColumns(c, t.name);
                card = {
                    models: models.map((m) => shortClass(m.class)),
                    primary: t.indexes.find((i) => i.primary)?.columns ?? NONE,
                    foreign: t.foreignKeys.flatMap((f) => f.columns),
                    casts: Object.assign({}, ...models.map((m) => m.casts)) as Record<string, string>,
                    // Edges end on key columns, and through links on the first column.
                    ends: t.columns.filter((col, i) => i === 0 || keys.has(col.name)).map((col) => col.name),
                    hintCount: schema.hints(c, t.name).length,
                    change: marks.get(id),
                };
                cache.set(id, card);
            }
            return card;
        };
    }, [schema, changes]);

    // What each node shows for the current selection and search.
    const decorate = useCallback(
        (n: AppNode): AppNode => {
            if (n.type === 'region') {
                return n;
            }
            if (n.type === 'external') {
                const dim = isDim(n.id);
                return dim === n.data.dim ? n : { ...n, data: { ...n.data, dim } };
            }
            const t = n.data.table;
            const data = {
                ...n.data,
                ...cards(n.data.connection, t),
                pivot: pivots.has(n.id),
                dim: isDim(n.id) || (spot !== null && !spot.nodes.has(n.id)),
                active: spot ? spot.nodes.has(n.id) && n.id !== spot.main : neighbors !== null && neighbors.has(n.id) && n.id !== selectedId,
                linked: (spot ? spot.rows.get(n.id) : linkedColumns.get(n.id)) ?? NONE,
                fold: folds[n.id] ?? columns,
                shown: shownOf(n.id, t),
                notes: layers.notes,
            };
            const isSelected = group.length > 0 ? group.includes(n.id) : spot ? n.id === spot.main : n.id === selectedId;
            if (!!n.selected === isSelected && sameData(n.data, data)) {
                return n;
            }
            return { ...n, selected: isSelected, data };
        },
        [cards, selectedId, neighbors, pivots, isDim, linkedColumns, folds, columns, shownOf, spot, layers.notes, group],
    );
    const decorateRef = useRef(decorate);
    decorateRef.current = decorate;

    useEffect(() => {
        setNodes((current) => {
            const next = current.map(decorate);
            return next.some((n, i) => n !== current[i]) ? next : current;
        });
    }, [decorate, setNodes]);

    // The cards on the diagram: the same set while cards only move or change their decoration.
    const shownIdsRef = useRef<Set<string>>(new Set());
    const shownIds = useMemo(() => {
        const next = new Set(nodes.filter((n) => !n.hidden && n.type !== 'region').map((n) => n.id));
        const prev = shownIdsRef.current;
        if (prev.size === next.size && [...next].every((id) => prev.has(id))) {
            return prev;
        }
        shownIdsRef.current = next;
        return next;
    }, [nodes]);

    // What each edge connects and shows. Hovering an edge is read by the edge itself (EdgeHover), so it changes nothing here.
    type Spec = { id: string; source: string; target: string; sourceColumn: string; targetColumn: string; data: Omit<LinkEdgeData, 'route' | 'floating'> };
    const specs = useMemo((): Spec[] => {
        const visible = (id: string) => shownIds.has(id);
        const touches = (a: string, b: string, id: string) => (spot ? spot.edges.has(id) : selectedId !== null && (a === selectedId || b === selectedId));
        const quiet = spot !== null || selected !== null;
        const firstColumn = (c: string, name: string) => schema.table(c, name)?.columns[0]?.name ?? 'id';

        const specs: Spec[] = [];

        for (const l of links) {
            const kind: EdgeKind = l.state;
            const source = nodeId(l.from.connection, l.from.table);
            const target = nodeId(l.to.connection, l.to.table);
            if (!layers[kind] || !visible(source) || !visible(target)) {
                continue;
            }
            const names = [...new Set(l.relations.map((r) => r.split('::')[1]))];
            const touch = touches(source, target, l.id);
            specs.push({
                id: l.id,
                source,
                target,
                sourceColumn: l.from.columns[0],
                targetColumn: l.to.columns[0],
                data: {
                    kind,
                    morph: l.morph,
                    relationship: linkKind(schema, l),
                    many: !schema.isUnique(l.from.connection, l.from.table, l.from.columns),
                    label: names.length ? names.join(' · ') : (l.foreignKey ?? ''),
                    detail: `${l.from.table}.${l.from.columns.join(', ')} → ${l.to.table}.${l.to.columns.join(', ')}`,
                    note: l.note ?? null,
                    showLabel: layers.allLabels || tourLabels || touch,
                    emphasized: touch,
                    dim: isDim(source) || isDim(target) || (quiet && !touch),
                },
            });
        }

        if (layers.through) {
            for (const d of derived) {
                const source = nodeId(d.connection, d.from);
                const target = nodeId(d.connection, d.to);
                if (!visible(source) || !visible(target)) {
                    continue;
                }
                const touch = touches(source, target, d.id);
                specs.push({
                    id: d.id,
                    source,
                    target,
                    sourceColumn: firstColumn(d.connection, d.from),
                    targetColumn: firstColumn(d.connection, d.to),
                    data: {
                        kind: 'through',
                        morph: false,
                        relationship: relationKind(schema.relation(d.relation)?.type ?? 'HasManyThrough'),
                        many: true,
                        label: `${d.relation.split('::')[1]} via ${d.via.join(', ')}`,
                        detail: `${d.from} → ${d.via.join(' → ')} → ${d.to}`,
                        showLabel: layers.allLabels || tourLabels || touch,
                        emphasized: touch,
                        dim: isDim(source) || isDim(target) || (quiet && !touch),
                    },
                });
            }
        }

        return specs;
    }, [schema, links, derived, layers, tourLabels, selectedId, selected, isDim, shownIds, spot]);

    // Where each card is and which rows it shows. While a card is dragged this keeps the last place, so edges are
    // routed again once, on drop, instead of on every frame.
    const routedNodes = useRef(nodes);
    if (dragging === null) {
        routedNodes.current = nodes;
    }
    const boxes = useMemo(() => {
        const map = new Map<string, Box>();
        const parts: string[] = [];
        routedNodes.current.forEach((n) => {
            if (n.hidden || n.type === 'region') {
                return;
            }
            const box: Box = {
                id: n.id,
                x: n.position.x,
                y: n.position.y,
                width: n.measured?.width ?? boxWidth(n.type),
                height: n.measured?.height ?? (n.type === 'table' ? nodeHeight(n.data.table, n.data.shown.length, cardRows(n.data.table, n.data.shown, n.data.notes).length - n.data.shown.length) : EXTERNAL_HEIGHT),
                rows: n.type === 'table' ? cardRows(n.data.table, n.data.shown, n.data.notes) : ['id'],
                folded: n.type === 'table',
            };
            map.set(n.id, box);
            parts.push(`${box.id}@${box.x},${box.y},${box.width},${box.height}:${box.rows.join(',')}`);
        });
        return { map, key: parts.join('|') };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [routedNodes.current]);

    // Routes are worked out again only when a card moved or resized, or the edges or their labels changed.
    const routesRef = useRef<{ key: string; routes: Map<string, Route> } | null>(null);
    const routes = useMemo(() => {
        const requests = specs.map((s) => ({ id: s.id, source: s.source, target: s.target, sourceColumn: s.sourceColumn, targetColumn: s.targetColumn, labelled: s.data.showLabel }));
        const key = `${boxes.key}#${requests.map((r) => `${r.id}:${r.source}.${r.sourceColumn}>${r.target}.${r.targetColumn}${r.labelled ? '+' : ''}`).join(',')}`;
        if (routesRef.current?.key !== key) {
            routesRef.current = { key, routes: route(boxes.map, requests) };
        }
        return routesRef.current.routes;
    }, [boxes, specs]);

    // The edges, keeping each unchanged edge's object so React Flow skips it.
    const edgeCache = useRef(new Map<string, LinkEdgeType>());
    const edges = useMemo((): LinkEdgeType[] => {
        const cache = edgeCache.current;
        const next = new Map<string, LinkEdgeType>();
        const out: LinkEdgeType[] = [];
        for (const s of specs) {
            const r = routes.get(s.id);
            if (!r) {
                continue;
            }
            const floating = dragging !== null && (dragging.has(s.source) || dragging.has(s.target));
            const sourceHandle = `${s.sourceColumn}|s${r.sourceSide}`;
            const targetHandle = `${s.targetColumn}|t${r.targetSide}`;
            const zIndex = s.data.emphasized ? 10 : 0;
            const prev = cache.get(s.id);
            const edge: LinkEdgeType =
                prev &&
                prev.data &&
                prev.source === s.source &&
                prev.target === s.target &&
                prev.sourceHandle === sourceHandle &&
                prev.targetHandle === targetHandle &&
                prev.zIndex === zIndex &&
                prev.data.floating === floating &&
                sameRoute(prev.data.route, r) &&
                sameData(prev.data, s.data)
                    ? prev
                    : { id: s.id, type: 'link', source: s.source, target: s.target, sourceHandle, targetHandle, zIndex, data: { ...s.data, floating, route: r } };
            next.set(s.id, edge);
            out.push(edge);
        }
        edgeCache.current = next;
        return out;
    }, [specs, routes, dragging]);

    const centerOn = useCallback(
        (id: string) => {
            const n = flow.getNode(id);
            if (n) {
                const h = n.measured?.height ?? 200;
                flow.setCenter(n.position.x + NODE_WIDTH / 2, n.position.y + h / 2, { zoom: Math.max(flow.getZoom(), 0.9), duration: 400 });
            }
        },
        [flow],
    );

    // After switching connection from an external node, select the table once it is placed.
    useEffect(() => {
        if (pending && nodes.some((n) => n.id === nodeId(pending.connection, pending.table))) {
            setSelected(pending);
            setPending(null);
            window.setTimeout(() => centerOn(nodeId(pending.connection, pending.table)), 350);
        }
    }, [pending, nodes, centerOn]);

    const open = useCallback(
        (conn: string, table: string) => {
            setTab('details');
            setHighlight(null);
            if (!scopeConnections.includes(conn)) {
                setScope(conn);
                setFocus(null);
                setSelected(null);
                setPending({ connection: conn, table });
                return;
            }
            // A table outside the focus ends focus mode; it is selected once the whole diagram is back.
            if (focusSet && !focusSet.has(nodeId(conn, table))) {
                setFocus(null);
                setSelected(null);
                setPending({ connection: conn, table });
                return;
            }
            setSelected({ connection: conn, table });
            centerOn(nodeId(conn, table));
        },
        [scopeConnections, focusSet, centerOn],
    );

    // Light up a relationship or column, show it in Details and bring it into view. Outside the connection or focus in
    // view, the whole diagram of its connection comes back first.
    const highlightThing = useCallback(
        (h: Highlight) => {
            setTab('details');
            setHighlight(h);
            const table = h.kind === 'column' ? h.table : null;
            if (!scopeConnections.includes(h.connection)) {
                setScope(h.connection);
                setFocus(null);
            } else if (focusSet && table && !focusSet.has(nodeId(h.connection, table))) {
                setFocus(null);
            }
            window.setTimeout(() => {
                if (h.kind === 'column') {
                    // The column's table and the tables its lines reach, when they fit at a readable size.
                    const seeds = seedsOf(schema, h);
                    if (seeds.length > 1 && seeds.length <= 6) {
                        flow.fitView({ nodes: seeds.map((t) => ({ id: nodeId(t.connection, t.table) })), padding: 0.25, maxZoom: 1, duration: 400 });
                    } else {
                        centerOn(nodeId(h.connection, h.table));
                    }
                    return;
                }
                const l = schema.links(h.connection).find((x) => x.id === h.id);
                const d = l ? undefined : schema.derived(h.connection).find((x) => x.id === h.id);
                const ids = l ? [nodeId(l.from.connection, l.from.table), nodeId(l.to.connection, l.to.table)] : d ? [d.from, ...d.via, d.to].map((t) => nodeId(d.connection, t)) : [];
                if (ids.length) {
                    flow.fitView({ nodes: ids.map((id) => ({ id })), padding: 0.25, maxZoom: 1, duration: 400 });
                }
            }, 60);
        },
        [scopeConnections, focusSet, centerOn, schema, flow],
    );

    const highlightThingRef = useRef(highlightThing);
    highlightThingRef.current = highlightThing;

    useEffect(() => {
        if (pendingHighlight && initialized && nodes.length > 0) {
            setPendingHighlight(null);
            // A link that carries its own view keeps it; otherwise the highlight is brought into view.
            if (!linked.at) {
                window.setTimeout(() => highlightThingRef.current(pendingHighlight), 300);
            }
        }
    }, [pendingHighlight, initialized, nodes.length, linked.at]);

    // Focus mode on a table, keeping the depth already chosen.
    const focusOn = useCallback((ref: TableRef, depth?: number) => {
        setSelected(ref);
        setTab('details');
        setFocus((f) => ({ ...ref, depth: depth ?? f?.depth ?? 1 }));
    }, []);

    // Focus mode on a relationship or column, keeping it highlighted.
    const focusHighlight = useCallback((h: Highlight, depth?: number) => {
        const main = seedsOf(schema, h)[0];
        setHighlight(h);
        setTab('details');
        setFocus((f) => ({ ...main, depth: depth ?? f?.depth ?? 1, on: h }));
    }, [schema]);

    const endFocus = useCallback(() => {
        const on = focus?.on;
        setFocus(null);
        if (on) {
            // Once the whole diagram is back, bring the relationship or column into view again.
            window.setTimeout(() => highlightThingRef.current(on), 400);
        } else if (selected) {
            setPending(selected);
        }
    }, [selected, focus]);

    // One card folded or unfolded; the rest stay where they are.
    const setFold = useCallback(
        (id: string, fold: Fold) => {
            setFolds((current) => {
                const next = { ...current };
                if (fold === columns) {
                    delete next[id];
                } else {
                    next[id] = fold;
                }
                return next;
            });
        },
        [columns],
    );
    const highlightColumn = useCallback((connection: string, table: string, column: string) => highlightThing({ kind: 'column', connection, table, column }), [highlightThing]);
    const focusColumn = useCallback((connection: string, table: string, column: string) => focusHighlight({ kind: 'column', connection, table, column }), [focusHighlight]);
    const tableActions = useMemo(() => ({ setFold, highlightColumn, focusColumn }), [setFold, highlightColumn, focusColumn]);

    // Every card at once, then the diagram is arranged again around the new sizes.
    const setColumns = (fold: Fold) => {
        setColumnsState(fold);
        setFolds({});
        setLayoutVersion((v) => v + 1);
        notify(fold === 'all' ? 'Every column shown.' : fold === 'keys' ? 'Tables folded to their key columns.' : 'Tables folded to their headers.');
    };

    // The URL always opens what is on screen: the connection, the selected table and focus mode.
    useEffect(() => {
        if (!pending) {
            // A relationship or column wins; focus on a table counts only while no relationship or column is picked.
            const focusDepth = focus && (focus.on ? highlight && JSON.stringify(focus.on) === JSON.stringify(highlight) : !highlight) ? focus.depth : null;
            writeHash(scope, focus ?? selected, focusDepth, highlight);
        }
    }, [scope, selected, focus, pending, highlight]);

    // A link pasted into the same tab.
    useEffect(() => {
        const onHash = () => {
            const next = readHash(doc);
            if (next.missing) {
                missingNoteRef.current(next.missing);
            }
            if (next.scope) {
                setScope(next.scope);
            }
            setFocus(next.highlight && next.focus ? { ...seedsOf(schema, next.highlight)[0], depth: next.focus, on: next.highlight } : next.table && next.focus ? { ...next.table, depth: next.focus } : null);
            setSelected(null);
            setHighlight(next.highlight ?? null);
            setPending(next.highlight ? null : (next.table ?? null));
            if (next.highlight) {
                setPendingHighlight(next.highlight);
            }
        };
        window.addEventListener('hashchange', onHash);
        return () => window.removeEventListener('hashchange', onHash);
    }, [doc, schema]);

    // The link to exactly what is on screen: the hash (connection, table, relationship or column, focus) plus how many
    // columns the cards show and where the diagram is centered and zoomed.
    const missingNoteRef = useRef(missingNote);
    missingNoteRef.current = missingNote;

    const copyLink = () => {
        const params = new URLSearchParams(window.location.hash.slice(1));
        const box = canvasRef.current?.getBoundingClientRect();
        if (box) {
            const at = flow.screenToFlowPosition({ x: box.left + box.width / 2, y: box.top + box.height / 2 });
            params.set('at', `${Math.round(at.x)},${Math.round(at.y)},${Math.round(flow.getZoom() * 1000) / 1000}`);
        }
        if (columns !== 'all') {
            params.set('columns', columns);
        }
        const url = `${window.location.href.split('#')[0]}#${params.toString().replace(/%3A/g, ':').replace(/%2C/g, ',')}`;
        const done = () => notify('Link copied. It opens this exact view.');
        navigator.clipboard?.writeText(url).then(done, () => notify('The link could not be copied.'));
    };

    // Dragging the panel's edge sets its width, kept between the panel's minimum and room for the diagram.
    const mainRef = useRef<HTMLElement>(null);
    // The panel's width as the resizer announces it: the dragged width, or the width the layout gives it.
    const panelDefaultWidth = Math.round(document.querySelector('.panel')?.getBoundingClientRect().width ?? 360);
    const resizePanel = (width: number | null) => {
        const room = mainRef.current?.getBoundingClientRect().width ?? window.innerWidth;
        const next = width === null ? null : Math.round(Math.min(Math.max(width, PANEL_MIN), room - DIAGRAM_ROOM));
        setPanelWidth(next);
        return next;
    };
    const onResizeStart = (e: ReactPointerEvent<HTMLDivElement>) => {
        const main = mainRef.current;
        if (!main) {
            return;
        }
        e.preventDefault();
        userMoved.current = true;
        const handle = e.currentTarget;
        handle.setPointerCapture(e.pointerId);
        const right = main.getBoundingClientRect().right;
        const move = (ev: PointerEvent) => {
            resizePanel(right - ev.clientX);
        };
        const up = () => {
            handle.removeEventListener('pointermove', move);
            handle.removeEventListener('pointerup', up);
            handle.removeEventListener('pointercancel', up);
        };
        handle.addEventListener('pointermove', move);
        handle.addEventListener('pointerup', up);
        handle.addEventListener('pointercancel', up);
    };

    const onNodeClick: NodeMouseHandler<AppNode> = useCallback(
        (event, node: Node) => {
            if (node.type === 'external') {
                const d = node.data as { connection: string; table: string };
                open(d.connection, d.table);
                return;
            }
            if (node.type === 'region') {
                return;
            }
            const d = node.data as { connection: string; table: Table };
            setHighlight(null);
            // Shift+click adds the card to the picked ones, or takes it out.
            if (event.shiftKey) {
                const picked = pickedRef.current;
                pickGroup(picked.includes(node.id) ? picked.filter((id) => id !== node.id) : [...picked, node.id]);
                return;
            }
            pickedRef.current = [node.id];
            setGroup([]);
            setSelected({ connection: d.connection, table: d.table.name });
            setTab('details');
        },
        [open],
    );

    // Several cards picked: two or more make a group; one is an ordinary pick, none clears it.
    const pickGroup = (ids: string[]) => {
        const tables = ids.map((id) => flow.getNode(id)).filter((n): n is TableNodeType => n?.type === 'table');
        pickedRef.current = tables.map((n) => n.id);
        if (tables.length > 1) {
            setGroup(tables.map((n) => n.id));
            setSelected(null);
            setTab('details');
        } else {
            setGroup([]);
            setSelected(tables[0] ? { connection: tables[0].data.connection, table: tables[0].data.table.name } : null);
        }
    };

    const switchConnection = (name: string) => {
        setScope(name);
        setFocus(null);
        setSelected(null);
        setPending(null);
    };

    // The page carries every connection's files, built with the snapshot; JSON is the connection itself.
    const download = (format: ExportFormat, connection: string) => {
        if (!downloads) {
            notify('Downloads are blocked in this preview. In your app the file downloads.');
            return;
        }
        const content = format === 'json' ? `${JSON.stringify(doc.connections.find((c) => c.name === connection), null, 4)}\n` : downloads[connection]?.[format];
        if (content === undefined) {
            notify('This page has no such file. Build the snapshot again to include it.');
            return;
        }
        const url = URL.createObjectURL(new Blob([content], { type: format === 'json' ? 'application/json' : 'text/plain' }));
        save(url, `schema-${connection.replace(/[^A-Za-z0-9_-]/g, '-')}.${EXTENSIONS[format]}`);
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    };

    // The diagram as an image, as it is shown: the connections in view, the kinds of lines and tables shown, focus mode,
    // folded cards, relation names when they show on every line, and the theme. Nothing hovered, dimmed or picked.
    const diagramImage = async (area: ImageArea, picture = false) => {
        const shownNodes = flow.getNodes().filter((n) => !n.hidden);
        const nodesOut: ImageNode[] = [];
        for (const n of shownNodes) {
            const box = boxes.map.get(n.id);
            if (n.type === 'region') {
                const width = n.width ?? n.measured?.width ?? 0;
                const height = n.height ?? n.measured?.height ?? 0;
                const d = n.data;
                nodesOut.push({ kind: 'region', x: n.position.x, y: n.position.y, width, height, connection: d.connection, meta: `${d.driver} · ${d.tables} ${d.tables === 1 ? 'table' : 'tables'}${d.hints > 0 ? ` · ${d.hints} ${d.hints === 1 ? 'hint' : 'hints'}` : ''}` });
            } else if (n.type === 'external' && box) {
                nodesOut.push({ kind: 'external', x: box.x, y: box.y, width: box.width, height: box.height, connection: n.data.connection, table: n.data.table });
            } else if (n.type === 'table' && box) {
                const d = n.data;
                const shown = new Set(d.shown);
                nodesOut.push({
                    kind: 'table',
                    x: box.x,
                    y: box.y,
                    width: box.width,
                    height: box.height,
                    name: d.table.name,
                    meta: d.models,
                    noModel: d.models.length ? null : d.table.framework ? 'framework table' : d.table.package ? 'package table' : 'no model',
                    tags: [...(d.pivot ? ['pivot'] : []), ...(d.table.package ? [d.table.package] : [])],
                    pivot: d.pivot,
                    framework: d.table.framework,
                    hints: d.hintCount,
                    change: d.change ?? null,
                    rows: d.table.columns
                        .filter((c) => shown.has(c.name))
                        .map((c) => ({ name: c.name, key: d.primary.includes(c.name) ? 'PK' : d.foreign.includes(c.name) ? 'FK' : '', type: d.casts[c.name] ? (d.casts[c.name].split('\\').pop() ?? '') : c.type, nullable: c.nullable })),
                    more: d.table.columns.length - d.shown.length,
                });
            }
        }
        const edgesOut: ImageEdge[] = [];
        for (const e of flow.getEdges()) {
            const s = boxes.map.get(e.source);
            const t = boxes.map.get(e.target);
            if (e.hidden || !e.data || !s || !t) {
                continue;
            }
            const { route: r } = e.data;
            const sourceColumn = (e.sourceHandle ?? '').split('|')[0];
            const targetColumn = (e.targetHandle ?? '').split('|')[0];
            edgesOut.push({
                kind: e.data.kind,
                many: e.data.many,
                sx: r.sourceSide === 'r' ? s.x + s.width : s.x,
                sy: rowY(s, sourceColumn),
                cx: r.channelX,
                tx: r.targetSide === 'r' ? t.x + t.width : t.x,
                ty: rowY(t, targetColumn),
                label: layers.allLabels ? { x: r.labelX, y: r.labelY, text: e.data.label, chip: e.data.relationship } : null,
            });
        }
        let bounds = boundsOf(nodesOut);
        const canvas = canvasRef.current?.getBoundingClientRect();
        if (area === 'screen' && canvas) {
            const { x, y, zoom } = flow.getViewport();
            bounds = { x: -x / zoom, y: -y / zoom, width: canvas.width / zoom, height: canvas.height / zoom };
        }
        // The theme's colors as the browser resolves them, so the file needs no stylesheet.
        const probe = document.createElement('span');
        canvasRef.current?.append(probe);
        const colors = Object.fromEntries(
            COLOR_TOKENS.map((token) => {
                probe.style.color = `var(--${token})`;
                return [token, getComputedStyle(probe).color];
            }),
        ) as Colors;
        probe.remove();
        const fonts = await fontFaces().catch(() => '');
        return { svg: diagramSvg({ nodes: nodesOut, edges: edgesOut, colors, fonts, bounds, skeleton: picture, transparent: picture }), bounds, tables: nodesOut.filter((n) => n.kind === 'table').length };
    };

    const downloadImage = (format: 'svg' | 'png', area: ImageArea) => {
        const name = `schema-${(scope === ALL ? 'all' : scope).replace(/[^A-Za-z0-9_-]/g, '-')}.${format}`;
        notify(format === 'png' ? 'Painting the diagram…' : 'Drawing the diagram…');
        diagramImage(area)
            .then(async ({ svg, bounds }) => {
                if (format === 'svg') {
                    return { blob: new Blob([svg], { type: 'image/svg+xml' }), scale: 1 };
                }
                return svgToPng(svg, bounds.width, bounds.height, Math.max(2, Math.min(window.devicePixelRatio || 1, 3)));
            })
            .then(
                ({ blob, scale }) => {
                    const url = URL.createObjectURL(blob);
                    save(url, name);
                    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
                    notify(scale < 1 ? `Large diagram saved at ${Math.round(scale * 100)}% scale; the SVG keeps every detail.` : `${format.toUpperCase()} downloaded.`, scale < 1 ? 6000 : 2600);
                },
                (e: Error) => notify(`The image could not be made: ${e.message}`),
            );
    };

    // The picture shown below PICTURE_ZOOM, painted again when what it shows changes: the layout, the kinds of lines and
    // tables shown, folded cards, notes, relation names or the theme. The picked table and its lines stay live on it.
    const zoomedOut = useStore((st) => st.transform[2] <= PICTURE_ZOOM);
    const nearZoomedOut = useStore((st) => st.transform[2] < PREPARE_ZOOM);
    const pictureKey = `${boxes.key}#${JSON.stringify(layers)}#${dark}#${scope}`;
    const [picture, setPicture] = useState<{ canvas: HTMLCanvasElement; bounds: Bounds; key: string } | null>(null);
    useEffect(() => {
        if (!nearZoomedOut || picture?.key === pictureKey || nodes.length === 0) {
            return;
        }
        let cancelled = false;
        const timer = window.setTimeout(() => {
            diagramImage('whole', true)
                .then(({ svg, bounds }) => svgToCanvas(svg, bounds.width, bounds.height, PICTURE_ZOOM * Math.max(1, window.devicePixelRatio || 1)).then((canvas) => ({ canvas, bounds })))
                .then(
                    ({ canvas, bounds }) => {
                        if (!cancelled) {
                            setPicture({ canvas, bounds, key: pictureKey });
                        }
                    },
                    () => undefined,
                );
        }, 200);
        return () => {
            cancelled = true;
            window.clearTimeout(timer);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [nearZoomedOut, pictureKey, nodes.length]);
    const showPicture = zoomedOut && picture !== null && picture.key === pictureKey;

    // The table under a point of the picture, where its card would be.
    const tableAt = (clientX: number, clientY: number): AppNode | undefined => {
        const at = flow.screenToFlowPosition({ x: clientX, y: clientY });
        for (const [id, box] of boxes.map) {
            if (at.x >= box.x && at.x <= box.x + box.width && at.y >= box.y && at.y <= box.y + box.height) {
                const node = flow.getNode(id) as AppNode | undefined;
                if (node && node.type !== 'region') {
                    return node;
                }
            }
        }
        return undefined;
    };

    // The standalone viewer: every connection in one HTML file that opens without the app, built from this page.
    const downloadHtml = () => {
        if (!downloads) {
            notify('Downloads are blocked in this preview. In your app the file downloads.');
            return;
        }
        notify('Building the standalone viewer…');
        standaloneHtml(doc, downloads, defaultConnection, changes).then(
            (html) => {
                const url = URL.createObjectURL(new Blob([html], { type: 'text/html' }));
                save(url, 'schema-viewer.html');
                window.setTimeout(() => URL.revokeObjectURL(url), 1000);
                notify('HTML page downloaded. Open it in any browser, offline.');
            },
            (e: Error) => notify(`The standalone viewer could not be built: ${e.message}`),
        );
    };


    const onAutoLayout = (next: Arrangement) => {
        setArrangement(next);
        setLayoutVersion((v) => v + 1);
        notify(`Tables arranged ${ARRANGEMENT_TEXT[next].done}.`);
    };

    const connectionOptions = [
        ...doc.connections.map((c) => ({ value: c.name, label: c.name, hint: `${c.driver} · ${c.tables.length} tables` })),
        ...(doc.connections.length > 1
            ? [{ value: ALL, label: 'All connections', hint: `${doc.connections.length} connections · ${doc.connections.reduce((n, c) => n + c.tables.length, 0)} tables` }]
            : []),
    ];

    const toggle = (key: keyof Layers) => setLayers((l) => ({ ...l, [key]: !l[key] }));

    // Keyboard shortcuts. Typing in a field only answers to Escape, handled by the field itself.
    const shortcuts = useRef<(e: KeyboardEvent) => void>(() => undefined);
    shortcuts.current = (e: KeyboardEvent) => {
        if (tourOpen) {
            return;
        }
        const target = e.target as HTMLElement | null;
        const typing = !!target && (target.isContentEditable || ['INPUT', 'SELECT', 'TEXTAREA'].includes(target.tagName));
        const mod = e.metaKey || e.ctrlKey;

        if (mod && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            searchRef.current?.focus();
            return;
        }
        if (typing || mod || e.altKey) {
            return;
        }

        const scopes = connectionOptions.map((o) => o.value);
        const step = (by: number) => switchConnection(scopes.at((scopes.indexOf(scope) + by + scopes.length) % scopes.length) ?? scope);
        const actionsByKey: Record<string, () => void> = {
            '/': () => searchRef.current?.focus(),
            '?': () => setTourOpen(true),
            s: () => setHelpOpen((o) => !o),
            Escape: () => {
                if (helpOpen) setHelpOpen(false);
                else if (sheetOpen) setSheetOpen(false);
                else if (group.length > 0) setGroup([]);
                else if (focus) endFocus();
                else if (highlight) setHighlight(null);
                else setSelected(null);
            },
            F: () => {
                if (focus) endFocus();
                else if (highlight) focusHighlight(highlight);
                else if (selected) focusOn(selected);
                else notify('Select a table, a relationship or a column first, then press Shift F to focus on it.');
            },
            c: () => setColumns(FOLDS[(FOLDS.indexOf(columns) + 1) % FOLDS.length]),
            f: () => flow.fitView({ padding: 0.08, duration: 250 }),
            '+': () => flow.zoomIn({ duration: 150 }),
            '=': () => flow.zoomIn({ duration: 150 }),
            '-': () => flow.zoomOut({ duration: 150 }),
            l: () => toggle('allLabels'),
            n: () => toggle('notes'),
            y: () => toggle('app'),
            g: () => toggle('framework'),
            p: () => toggle('package'),
            '1': () => toggle('both'),
            '2': () => toggle('relation'),
            '3': () => toggle('foreign_key'),
            '4': () => toggle('through'),
            a: () => {
                setTab('browse');
                setSheetOpen(true);
            },
            d: () => {
                setTab('details');
                setSheetOpen(true);
            },
            t: () => setTab('details'),
            h: () => {
                setTab('hints');
                setSheetOpen(true);
            },
            w: () => {
                setTab('changes');
                setSheetOpen(true);
            },
            C: () => copyLink(),
            '[': () => step(-1),
            ']': () => step(1),
        };
        const action = actionsByKey[e.key] ?? (e.key.length === 1 && !e.shiftKey ? actionsByKey[e.key.toLowerCase()] : undefined);
        if (action) {
            e.preventDefault();
            action();
        }
    };

    useEffect(() => {
        const typing = (e: KeyboardEvent) => {
            const target = e.target as HTMLElement | null;
            return !!target && (target.isContentEditable || ['INPUT', 'SELECT', 'TEXTAREA', 'BUTTON'].includes(target.tagName));
        };
        const down = (e: KeyboardEvent) => {
            if (e.key === ' ' && !typing(e)) {
                e.preventDefault();
                setSpacePan(true);
            }
        };
        const up = (e: KeyboardEvent) => e.key === ' ' && setSpacePan(false);
        const reset = () => setSpacePan(false);
        window.addEventListener('keydown', down);
        window.addEventListener('keyup', up);
        window.addEventListener('blur', reset);
        return () => {
            window.removeEventListener('keydown', down);
            window.removeEventListener('keyup', up);
            window.removeEventListener('blur', reset);
        };
    }, []);

    useEffect(() => {
        const listener = (e: KeyboardEvent) => shortcuts.current(e);
        window.addEventListener('keydown', listener);
        return () => window.removeEventListener('keydown', listener);
    }, []);

    return (
        <EdgeHover.Provider value={hover}>
            <TableActions.Provider value={tableActions}>
                <div className={`app${keysOn ? '' : ' keys-off'}${shownTables.length > DENSE ? ' is-dense' : ''}${selected ? ' has-selection' : ''}`}>
                    <EdgeMarkers />
                    <header className="toolbar">
                        <div className="toolbar__brand">
                            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" className="brand-mark">
                                <rect x="2.5" y="3.5" width="8" height="7" rx="1.5" />
                                <rect x="13.5" y="13.5" width="8" height="7" rx="1.5" />
                                <path d="M10.5 7 H14 a2 2 0 0 1 2 2 V13.5" />
                            </svg>
                            <span>Schema Viewer</span>
                            {example && <span className="toolbar__note">example data</span>}
                        </div>
                        <SearchBox schema={schema} tables={visibleTables} multiple={scopeConnections.length > 1} query={query} onQuery={setQuery} onPick={open} inputRef={searchRef} />
                        <div className="toolbar__spacer" />
                        {narrow ? (
                            <Menu
                                label="More actions"
                                trigger={
                                    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" className="icon-fill">
                                        <circle cx="5" cy="12" r="1.8" />
                                        <circle cx="12" cy="12" r="1.8" />
                                        <circle cx="19" cy="12" r="1.8" />
                                    </svg>
                                }
                                items={[{ label: 'Download…', hint: 'Diagrams, references and an offline page', onSelect: () => setDownloadOpen(true) }]}
                                groups={[{ items: [{ label: dark ? 'Light mode' : 'Dark mode', onSelect: switchTheme }, { label: 'Shortcuts', onSelect: () => setHelpOpen(true), tour: 'menu-shortcuts' }] }]}
                                open={menuOpen || tourMenu === 'phone'}
                                onOpenChange={(o) => !tourMenu && setMenuOpen(o)}
                                choice={{
                                    label: 'Connection',
                                    value: scope,
                                    options: connectionOptions,
                                    onChange: switchConnection,
                                    hint: (
                                        <span className="ui-menu__keys">
                                            <kbd className="kbd-hint">[</kbd>
                                            <kbd className="kbd-hint">]</kbd>
                                        </span>
                                    ),
                                }}
                            />
                        ) : (
                            <div className="toolbar__group" role="group" aria-label="Connection and export">
                                <Select
                                    id="connection"
                                    label="Connection"
                                    value={scope}
                                    onChange={switchConnection}
                                    options={connectionOptions}
                                    hint={
                                        <>
                                            <kbd className="kbd-hint">[</kbd>
                                            <kbd className="kbd-hint">]</kbd>
                                        </>
                                    }
                                />
                                <div className="toolbar__group" data-tour="actions">
                                    <button type="button" className="button button--with-icon" onClick={() => setDownloadOpen(true)}>
                                        <svg viewBox="0 0 16 16" width="15" height="15" aria-hidden="true" className="ui-icon">
                                            <path d="M8 2.5 V10 M4.5 6.5 L8 10 L11.5 6.5 M2.5 13.5 H13.5" />
                                        </svg>
                                        Download
                                    </button>
                                </div>
                                <Tooltip content={dark ? 'Light mode' : 'Dark mode'} side="bottom">
                                    <button type="button" className="button button--icon" aria-label={dark ? 'Switch to light mode' : 'Switch to dark mode'} onClick={switchTheme}>
                                        <ThemeIcon dark={dark} />
                                    </button>
                                </Tooltip>
                                <Tooltip content={<>Keyboard shortcuts <kbd>S</kbd></>} side="bottom">
                                    <button type="button" className="button button--with-icon" data-tour="shortcuts" aria-keyshortcuts="S" onClick={() => setHelpOpen(true)}>
                                        <svg viewBox="0 0 20 16" width="18" height="15" aria-hidden="true" className="ui-icon">
                                            <rect x="1.5" y="2" width="17" height="12" rx="2" />
                                            <path d="M5 6 H5.5 M8 6 H8.5 M11.5 6 H12 M14.5 6 H15 M5 9 H5.5 M14.5 9 H15 M7.5 11.5 H12.5" />
                                        </svg>
                                        <span className="button__text">Shortcuts</span>
                                    </button>
                                </Tooltip>
                            </div>
                        )}
                    </header>

                    <main
                        className={`main${sideBySide && panelWidth !== null ? ' has-panel-width' : ''}`}
                        ref={mainRef}
                        style={panelWidth !== null ? ({ '--panel-width': `${panelWidth}px` } as CSSProperties) : undefined}
                    >
                        <div
                            className={`canvas${panning ? ' is-panning' : ''}${showPicture ? ' is-picture' : ''}`}
                            ref={canvasRef}
                            onMouseMove={(e) => {
                                // On the picture, the pointer shows over a table, which a click picks.
                                const canvas = e.currentTarget;
                                const over = showPicture && (e.target as HTMLElement).classList.contains('react-flow__pane') && tableAt(e.clientX, e.clientY) !== undefined;
                                canvas.classList.toggle('is-over-table', over);
                            }}
                            onDoubleClick={(e) => {
                                // On the picture, a double-click focuses on the table under it, as on its card.
                                const node = showPicture && (e.target as HTMLElement).classList.contains('react-flow__pane') ? tableAt(e.clientX, e.clientY) : undefined;
                                if (node?.type === 'table') {
                                    setHighlight(null);
                                    focusOn({ connection: node.data.connection, table: node.data.table.name });
                                }
                            }}
                        >
                            <ReactFlow<AppNode, LinkEdgeType>
                                nodes={nodes}
                                edges={edges}
                                nodeTypes={nodeTypes}
                                edgeTypes={edgeTypes}
                                onNodesChange={onNodesChange}
                                onNodeClick={onNodeClick}
                                zoomOnDoubleClick={false}
                                // Double-click focuses: on a table, a line (its relationship) or, in TableNode, a column.
                                onNodeDoubleClick={(_, node) => {
                                    if (node.type === 'table') {
                                        setHighlight(null);
                                        focusOn({ connection: node.data.connection, table: node.data.table.name });
                                    }
                                }}
                                onEdgeDoubleClick={(_, edge) => {
                                    const l = links.find((x) => x.id === edge.id);
                                    const d = l ? undefined : derived.find((x) => x.id === edge.id);
                                    const connection = l?.from.connection ?? d?.connection;
                                    if (connection) {
                                        focusHighlight({ kind: 'link', connection, id: edge.id });
                                    }
                                }}
                                onEdgeMouseEnter={(_, edge) => hover.set(edge.id)}
                                onEdgeMouseLeave={() => hover.set(null)}
                                onNodeDragStart={(_, node, moving) => setDragging(new Set([node.id, ...moving.map((n) => n.id)]))}
                                onNodeDragStop={() => setDragging(null)}
                                onlyRenderVisibleElements
                                // As in dbdiagram: scrolling moves the diagram up, down and sideways, and Ctrl, Cmd or the
                                // Windows key with the wheel zooms, as does a pinch.
                                panOnScroll
                                zoomActivationKeyCode={['Control', 'Meta']}
                                onPaneClick={(event) => {
                                    // On the picture, a click picks the table under it, as on its card.
                                    const node = showPicture ? tableAt(event.clientX, event.clientY) : undefined;
                                    if (node) {
                                        onNodeClick(event, node);
                                        return;
                                    }
                                    setSelected(null);
                                    setGroup([]);
                                    setHighlight(null);
                                }}
                                onEdgeClick={(_, edge) => {
                                    const l = links.find((x) => x.id === edge.id);
                                    const d = l ? undefined : derived.find((x) => x.id === edge.id);
                                    const connection = l?.from.connection ?? d?.connection;
                                    if (connection) {
                                        highlightThing({ kind: 'link', connection, id: edge.id });
                                        if (narrow) {
                                            setSheetOpen(true);
                                        }
                                    }
                                }}
                                onMoveStart={(event) => {
                                    if (event) {
                                        userMoved.current = true;
                                    }
                                }}
                                nodesConnectable={false}
                                // As in dbdiagram: dragging the background draws a box that picks every card it touches,
                                // and Shift+click adds a card; the background moves the diagram in pan mode (the hand, or
                                // Space held) and on a phone, where a finger has no other way to move it.
                                panOnDrag={panning || narrow}
                                selectionOnDrag={!panning && !narrow}
                                selectionMode={SelectionMode.Partial}
                                multiSelectionKeyCode="Shift"
                                onSelectionEnd={() => pickGroup(flow.getNodes().filter((n) => n.selected && n.type === 'table').map((n) => n.id))}
                                // On a phone a drag moves the diagram, so a finger on a card scrolls instead of moving it.
                                nodesDraggable={!panning && !narrow}
                                edgesFocusable={false}
                                minZoom={0.02}
                                maxZoom={2}
                                proOptions={{ hideAttribution: true }}
                            >
                                {grid && <Background variant={BackgroundVariant.Dots} gap={20} size={1.2} />}
                                {showPicture && (
                                    <ViewportPortal>
                                        <DiagramPicture canvas={picture.canvas} bounds={picture.bounds} />
                                    </ViewportPortal>
                                )}
                                {/* Below 1100px the diagram needs the room more than an overview does. */}
                                {roomy && <MiniMap pannable zoomable nodeStrokeWidth={2} nodeClassName={(n) => n.type ?? ''} style={{ width: 160, height: 110 }} />}
                            </ReactFlow>
                                <div className="float-bar">
                                    <div className="float-bar__group" role="group" aria-label="Zoom and arrange" data-tour="zoom">
                                        <FloatButton label="Zoom out" keys="−" shortcut="-" onClick={() => flow.zoomOut({ duration: 150 })}>
                                            <path d="M3 8 H13" />
                                        </FloatButton>
                                        {!narrow && <ZoomLevel onZoom={(z) => flow.zoomTo(z, { duration: 200 })} />}
                                        <FloatButton label="Zoom in" keys="+" shortcut="+" onClick={() => flow.zoomIn({ duration: 150 })}>
                                            <path d="M8 3 V13 M3 8 H13" />
                                        </FloatButton>
                                        <FloatButton label="Fit the diagram" keys="F" shortcut="F" onClick={() => flow.fitView({ padding: 0.08, duration: 250 })}>
                                            <path d="M2.5 6 V2.5 H6 M10 2.5 H13.5 V6 M13.5 10 V13.5 H10 M6 13.5 H2.5 V10" />
                                        </FloatButton>
                                        {/* On a phone a drag always pans, and the layout already suits the screen. */}
                                        {!narrow && (
                                            <>
                                            <FloatButton label={panMode ? 'Pan mode on: drag anywhere to move' : 'Pan mode: drag anywhere to move'} keys="Hold Space" pressed={panMode} extra onClick={() => setPanMode((on) => !on)}>
                                                <path d="M5.5 8.5 V4 a1 1 0 0 1 2 0 V7.5 M7.5 7.5 V2.8 a1 1 0 0 1 2 0 V7.5 M9.5 7.5 V3.8 a1 1 0 0 1 2 0 V8.5 M11.5 8.5 V6 a1 1 0 0 1 2 0 V10 a4.5 4.5 0 0 1 -4.5 4.5 H8.6 a4 4 0 0 1 -3.1 -1.5 L2.7 9.6 a1.1 1.1 0 0 1 1.7 -1.4 L5.5 9.7" />
                                            </FloatButton>
                                            <ArrangeMenu onArrange={onAutoLayout} />
                                            </>
                                        )}
                                    </div>
                                    <span className="float-bar__divider" aria-hidden="true" />
                                    <div className="float-bar__group" role="group" aria-label="Show on the diagram" data-tour="layers">
                                    <ViewMenu label="Relationships" icon={<path d="M2 4.5 H6.5 V11.5 H14 M11.5 9 L14 11.5 L11.5 14" />} toggles={lineToggles} tour="relationships" forceOpen={tourOpens === 'relationships'} />
                                    <ViewMenu label="Tables" icon={<path d="M2.5 3 H13.5 V13 H2.5 Z M2.5 6.3 H13.5 M2.5 9.6 H13.5 M6.5 6.3 V13" />} toggles={tableToggles} tour="tables" forceOpen={tourOpens === 'tables'} />
                                    <ViewMenu
                                        label="Show"
                                        icon={<path d="M1.5 8 C3.5 4.5 5.6 3.2 8 3.2 C10.4 3.2 12.5 4.5 14.5 8 C12.5 11.5 10.4 12.8 8 12.8 C5.6 12.8 3.5 11.5 1.5 8 Z M8 6 A2 2 0 1 1 7.99 6 Z" />}
                                        tour="show"
                                        forceOpen={tourOpens === 'show'}
                                        toggles={showToggles}
                                        counted={false}
                                        choice={{
                                            label: 'Columns',
                                            value: columns,
                                            options: FOLDS.map((f) => ({ value: f, label: COLUMN_CHOICES[f].label, hint: COLUMN_CHOICES[f].hint })),
                                            onChange: (v) => setColumns(v as Fold),
                                            hint: <kbd className="kbd-hint">C</kbd>,
                                        }}
                                    />
                                    </div>
                                    <span className="float-bar__divider" aria-hidden="true" />
                                    <FloatButton label="Take the tour" keys="?" shortcut="?" onClick={() => setTourOpen(true)} tour="help">
                                        <path d="M5.3 5.4 a2.7 2.7 0 1 1 3.9 2.4 c-0.75 0.38 -1.2 0.95 -1.2 1.8 V10.4 M8 13.3 V13.4" />
                                    </FloatButton>
                                </div>
                            {narrow && smallScreenNote && !tourOpen && !focus && (
                                <div className="screen-note" role="status">
                                    <p>
                                        <b>Easier on a larger screen.</b> On a phone you can browse every table and its details; the diagram itself is best on a desktop or tablet.
                                    </p>
                                    <button type="button" className="button button--icon button--ghost" aria-label="Close" onClick={closeSmallScreenNote}>
                                        <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" className="ui-icon">
                                            <path d="M4 4 L12 12 M12 4 L4 12" />
                                        </svg>
                                    </button>
                                </div>
                            )}
                            {focus && (
                                <div className="focus-bar" role="group" aria-label="Focus mode">
                                    <span className="focus-bar__label">
                                        Focus on <code>{focusLabel(schema, focus, scopeConnections.length > 1)}</code>
                                    </span>
                                    <span className="segmented" role="radiogroup" aria-label="Links away">
                                        {[1, 2, 3].map((d) => (
                                            <button key={d} type="button" role="radio" aria-checked={focus.depth === d} className="segmented__option" onClick={() => setFocus((f) => f && { ...f, depth: d })}>
                                                {d} {d === 1 ? 'link' : 'links'}
                                            </button>
                                        ))}
                                    </span>
                                    <span className="focus-bar__count">{shownTables.length} tables</span>
                                    <Tooltip content={<>Show every table again <kbd>Esc</kbd></>} side="bottom">
                                        <button type="button" className="button button--small" aria-keyshortcuts="Escape" onClick={endFocus}>
                                            Leave focus mode
                                        </button>
                                    </Tooltip>
                                </div>
                            )}
                        </div>
                        {sideBySide && (
                            <div
                                className="panel-resizer"
                                role="separator"
                                aria-orientation="vertical"
                                aria-label="Resize the details panel"
                                aria-valuenow={panelWidth ?? panelDefaultWidth}
                                aria-valuemin={PANEL_MIN}
                                tabIndex={0}
                                onPointerDown={onResizeStart}
                                onDoubleClick={() => resizePanel(null)}
                                onKeyDown={(e) => {
                                    const width = panelWidth ?? document.querySelector('.panel')?.getBoundingClientRect().width ?? 360;
                                    if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                                        e.preventDefault();
                                        resizePanel(width + (e.key === 'ArrowLeft' ? 24 : -24));
                                    }
                                }}
                                title="Drag to resize, double-click to reset"
                            >
                                <span className="panel-resizer__grip" aria-hidden="true" />
                            </div>
                        )}
                        <SidePanel
                            schema={schema}
                            editor={editor ?? null}
                            focused={focus && !focus.on ? focus : null}
                            onFocus={focusOn}
                            focusedOn={focus?.on ?? null}
                            onFocusHighlight={focusHighlight}
                            onLeaveFocus={endFocus}
                            onCopyLink={copyLink}
                            changes={changes}
                            connections={scopeConnections}
                            tables={visibleTables}
                            selected={selected}
                            group={group}
                            highlight={highlight}
                            onHighlight={(h) => {
                                highlightThing(h);
                                if (narrow) {
                                    setSheetOpen(false);
                                }
                            }}
                            tab={tab}
                            onTab={(t) => {
                                setTab(t);
                                setSheetOpen(true);
                            }}
                            onOpen={(c, t) => {
                                open(c, t);
                                if (narrow) {
                                    setSheetOpen(false);
                                }
                            }}
                            sheet={narrow}
                            sheetOpen={sheetOpen}
                            onSheet={setSheetOpen}
                        />
                    </main>

                    <DownloadDialog
                        open={downloadOpen}
                        onOpenChange={setDownloadOpen}
                        connections={doc.connections.map((c) => ({ name: c.name, tables: c.tables.length }))}
                        initial={highlight?.connection ?? selected?.connection ?? (scope === ALL ? doc.connections[0].name : scope)}
                        onDownload={download}
                        onDownloadHtml={downloadHtml}
                        onDownloadImage={downloadImage}
                        shownTables={nodes.filter((n) => !n.hidden && n.type === 'table').length}
                    />
                    <ShortcutsHelp open={helpOpen} onOpenChange={setHelpOpen} keysOn={keysOn} onKeysOn={switchKeys} />
                    <Tour steps={TOUR_STEPS} open={tourOpen} onClose={endTour} onStep={onTourStep} menuSteps={narrow} />

                    {toast && (
                        <div className="toast" role="status">
                            {toast}
                        </div>
                    )}
                </div>
            </TableActions.Provider>
        </EdgeHover.Provider>
    );
}



type ImageArea = 'whole' | 'screen';

const EXTENSIONS: Record<ExportFormat, string> = { mermaid: 'mmd', dbml: 'dbml', markdown: 'md', json: 'json' };

function save(href: string, name: string) {
    const link = document.createElement('a');
    link.href = href;
    link.download = name;
    document.body.append(link);
    link.click();
    link.remove();
}

// The database schema of the current connection (tables, columns, keys, indexes, foreign keys), without the Laravel side.

const COLUMN_CHOICES: Record<Fold, { label: string; hint: string }> = {
    all: { label: 'All', hint: 'Every column of every table' },
    keys: { label: 'Keys', hint: 'Only keys and the columns relations use' },
    none: { label: 'Names', hint: 'Only table names, for the big picture' },
};

const LAYER_TOGGLES: { key: keyof Layers; label: string; swatch?: EdgeKind; hotkey: string }[] = [
    { key: 'both', label: 'FK + relation', swatch: 'both', hotkey: '1' },
    { key: 'relation', label: 'Relation only', swatch: 'relation', hotkey: '2' },
    { key: 'foreign_key', label: 'FK only', swatch: 'foreign_key', hotkey: '3' },
    { key: 'through', label: 'Through', swatch: 'through', hotkey: '4' },
    { key: 'app', label: 'Your tables', hotkey: 'Y' },
    { key: 'framework', label: 'Framework tables', hotkey: 'G' },
    { key: 'package', label: 'Package tables', hotkey: 'P' },
    { key: 'allLabels', label: 'Relation names', hotkey: 'L' },
    { key: 'notes', label: 'Notes', hotkey: 'N' },
];

function Swatch({ kind }: { kind: EdgeKind }) {
    return (
        <svg width="22" height="8" viewBox="0 0 22 8" aria-hidden="true" className={`swatch swatch--${kind}`}>
            <path d="M1 4 H21" />
        </svg>
    );
}

// The tables a relationship or column touches; the first is the one it sits on.
function seedsOf(schema: Schema, h: Highlight): TableRef[] {
    if (h.kind === 'column') {
        const out: TableRef[] = [{ connection: h.connection, table: h.table }];
        for (const l of schema.links(h.connection)) {
            const at = (e: { connection: string; table: string; columns: string[] }) => e.connection === h.connection && e.table === h.table && e.columns.includes(h.column);
            if (at(l.from)) {
                out.push({ connection: l.to.connection, table: l.to.table });
            } else if (at(l.to)) {
                out.push({ connection: l.from.connection, table: l.from.table });
            }
        }
        return out;
    }
    const l = schema.links(h.connection).find((x) => x.id === h.id);
    if (l) {
        return [
            { connection: l.from.connection, table: l.from.table },
            { connection: l.to.connection, table: l.to.table },
        ];
    }
    const d = schema.derived(h.connection).find((x) => x.id === h.id);
    return d ? [d.from, ...d.via, d.to].map((table) => ({ connection: d.connection, table })) : [{ connection: h.connection, table: '' }];
}

// What the focus bar names: the table, the relationship or the column in focus.
function focusLabel(schema: Schema, focus: TableRef & { on?: Highlight }, withConnection: boolean): string {
    const prefix = withConnection ? `${focus.connection}.` : '';
    if (focus.on?.kind === 'column') {
        return `${prefix}${focus.on.table}.${focus.on.column}`;
    }
    if (focus.on?.kind === 'link') {
        const seeds = seedsOf(schema, focus.on);
        return `${prefix}${seeds[0].table} → ${seeds[seeds.length - 1].table}`;
    }
    return `${prefix}${focus.table}`;
}

// A sun in dark mode (to switch to light), a moon in light mode.
function ThemeIcon({ dark }: { dark: boolean }) {
    return (
        <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" className="ui-icon">
            {dark ? (
                <path d="M8 5 a3 3 0 1 0 0.01 0 Z M8 1.5 V3 M8 13 V14.5 M1.5 8 H3 M13 8 H14.5 M3.4 3.4 L4.5 4.5 M11.5 11.5 L12.6 12.6 M3.4 12.6 L4.5 11.5 M11.5 4.5 L12.6 3.4" />
            ) : (
                <path d="M13.5 9.5 A5.5 5.5 0 1 1 6.5 2.5 A4.5 4.5 0 0 0 13.5 9.5 Z" />
            )}
        </svg>
    );
}

// A square icon button in the floating bar, with its name and key in a tooltip.
function FloatButton({ label, keys, shortcut, pressed, onClick, tour, extra = false, children }: { label: string; keys: string; shortcut?: string; pressed?: boolean; onClick: () => void; tour?: string; extra?: boolean; children: ReactNode }) {
    return (
        <Tooltip content={<>{label} <kbd>{keys}</kbd></>} side="top">
            <button type="button" className={`button button--icon float-bar__icon${extra ? ' float-bar__extra' : ''}`} aria-label={label} aria-pressed={pressed} aria-keyshortcuts={shortcut}
                onClick={(e) => {
                    onClick();
                    // A mouse click leaves focus on the diagram, so Space pans again instead of pressing this button.
                    if (e.detail > 0) {
                        e.currentTarget.blur();
                    }
                }}
                data-tour={tour}
            >
                <svg viewBox="0 0 16 16" width="15" height="15" aria-hidden="true" className="ui-icon">
                    {children}
                </svg>
            </button>
        </Tooltip>
    );
}

// The current zoom. Click it to type one (2–200%), Enter to apply, Escape to cancel. Its own component, so zooming
// re-renders only this.
function ZoomLevel({ onZoom }: { onZoom: (zoom: number) => void }) {
    const { zoom } = useViewport();
    const [editing, setEditing] = useState<string | null>(null);
    const percent = Math.round(zoom * 100);
    const apply = () => {
        const value = Number.parseFloat(editing ?? '');
        if (Number.isFinite(value)) {
            onZoom(Math.min(200, Math.max(2, value)) / 100);
        }
        setEditing(null);
    };
    if (editing !== null) {
        return (
            <input
                className="float-bar__zoom-input"
                aria-label="Zoom in percent"
                inputMode="numeric"
                autoFocus
                value={editing}
                onFocus={(e) => e.currentTarget.select()}
                onChange={(e) => setEditing(e.target.value.replace(/[^\d.]/g, ''))}
                onBlur={apply}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                        apply();
                    } else if (e.key === 'Escape') {
                        e.stopPropagation();
                        setEditing(null);
                    }
                }}
            />
        );
    }
    return (
        <Tooltip content="Type a zoom" side="top">
            <button type="button" className="float-bar__zoom float-bar__extra" aria-label={`Zoom ${percent}%, type another`} onClick={() => setEditing(String(percent))}>
                {percent}%
            </button>
        </Tooltip>
    );
}

// A menu of switches in the floating bar, and optionally one choice below them. It notes how many layers are hidden,
// so one is not forgotten; a menu of switches that only change how things look (counted: false) doesn't.
// tour: names the button and the open menu for the guided tour, which holds the menu open (forceOpen) for its step.
function ViewMenu({ label, icon, toggles, choice, counted = true, tour, forceOpen = false }: { label: string; icon: ReactNode; toggles: MenuToggle[]; choice?: MenuChoice; counted?: boolean; tour: string; forceOpen?: boolean }) {
    const off = counted ? toggles.filter((t) => !t.checked).length : 0;
    const [open, setOpen] = useState(false);
    return (
        <span className="float-bar__menu" data-tour={`view-${tour}`}>
        <Menu
            open={open || forceOpen}
            onOpenChange={(o) => !forceOpen && setOpen(o)}
            tour={`${tour}-menu`}
            label={label}
            triggerClass="button button--with-icon"
            trigger={
                <>
                    <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" className="ui-icon">
                        {icon}
                    </svg>
                    <span className="float-bar__label">{label}</span>
                    {off > 0 && <span className="toolbar__off">{off} off</span>}
                    <svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true" className="ui-icon float-bar__chevron">
                        <path d="M4 6 L8 10 L12 6" />
                    </svg>
                </>
            }
            items={[]}
            toggles={{ label, items: toggles }}
            afterToggles={choice}
        />
        </span>
    );
}

// What each arrangement is called and does, for the Arrange menu and the note after arranging.
const ARRANGEMENT_TEXT: Record<Arrangement, { label: string; hint: string; done: string }> = {
    'left-right': {
        label: 'Left-right',
        hint: 'Along the relationships: referenced tables to the left of the tables that point at them. Best for long chains of relationships.',
        done: 'left to right',
    },
    snowflake: {
        label: 'Snowflake',
        hint: 'The most connected tables in the middle, the tables they join around them. Best for densely connected schemas.',
        done: 'as a snowflake',
    },
    compact: {
        label: 'Compact',
        hint: 'A tight rectangle in name order. Best for schemas with few relationships.',
        done: 'compactly',
    },
};

// Arrange: picks how the tables are placed, and places them.
function ArrangeMenu({ onArrange }: { onArrange: (arrangement: Arrangement) => void }) {
    return (
        <Tooltip content="Arrange tables automatically" side="top">
            <span className="float-bar__extra" data-tour="auto-layout">
                <Menu
                    label="Arrange tables automatically"
                    triggerClass="button button--icon float-bar__icon"
                    trigger={
                        <svg viewBox="0 0 16 16" width="15" height="15" aria-hidden="true" className="ui-icon">
                            <rect x="1.5" y="2" width="5" height="4" rx="1" />
                            <rect x="9.5" y="2" width="5" height="4" rx="1" />
                            <rect x="5.5" y="10" width="5" height="4" rx="1" />
                            <path d="M4 6 V8 H12 V6 M8 8 V10" />
                        </svg>
                    }
                    itemsLabel="Arrange tables"
                    items={ARRANGEMENTS.map((a) => ({
                        label: ARRANGEMENT_TEXT[a].label,
                        hint: ARRANGEMENT_TEXT[a].hint,
                        onSelect: () => onArrange(a),
                    }))}
                />
            </span>
        </Tooltip>
    );
}

const MOD = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.userAgent) ? '⌘' : 'Ctrl';

const SHORTCUTS: [string[], string][] = [
    [['/', `${MOD} K`], 'Search'],
    [['Esc'], 'Clear the search or selection, close a panel'],
    [['F'], 'Fit the diagram to the screen'],
    [['+', '−'], 'Zoom in or out'],
    [[`${MOD} Scroll`], 'Zoom in or out at the pointer; a pinch zooms too'],
    [['Scroll'], 'Move the diagram up, down or sideways'],
    [['Space'], 'Hold to pan: drag anywhere, even over a table'],
    [['Shift click'], 'Add a table to the selection, or take it out; drag one to move them all'],
    [['Drag'], 'On the background: draw a box that selects every table it touches'],
    [['L'], 'Show relation names on every edge, or only on the selected table'],
    [['N'], 'Show notes on the cards'],
    [['1', '2', '3', '4'], 'Show or hide FK + relation, relation only, FK only, through edges'],
    [['Y'], 'Show or hide your own tables'],
    [['G'], 'Show or hide framework tables'],
    [['P'], 'Show or hide package tables, such as Passport\'s oauth_*'],
    [['A', 'D', 'H', 'W'], 'Open the Browse, Details, Hints or Changes tab'],
    [['[', ']'], 'Previous or next connection, or all of them'],
    [['Shift F'], 'Focus on the selected table, relationship or column and what is around it, or leave focus mode'],
    [['C'], 'Show every column, the key columns only, or the table names only'],
    [['Shift C'], 'Copy a link to exactly this view'],
    [['S'], 'Show this list'],
    [['?'], 'Take the tour'],
];

function ShortcutsHelp({ open, onOpenChange, keysOn, onKeysOn }: { open: boolean; onOpenChange: (open: boolean) => void; keysOn: boolean; onKeysOn: (on: boolean) => void }) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange} title="Keyboard shortcuts">
            <div className="shortcuts__switch">
                <span id="keys-label">
                    <b>Show keys next to controls</b>
                    <span className="muted">{keysOn ? 'Each button, chip and tab shows its key.' : 'The keys below work either way.'}</span>
                </span>
                <button type="button" role="switch" aria-checked={keysOn} aria-labelledby="keys-label" className="switch" onClick={() => onKeysOn(!keysOn)}>
                    <span className="switch__thumb" />
                    <span className="switch__text">{keysOn ? 'On' : 'Off'}</span>
                </button>
            </div>
            <dl className="shortcuts">
                {SHORTCUTS.map(([keys, label]) => (
                    <div key={label} className="shortcuts__row">
                        <dt>
                            {keys.map((k) => (
                                <kbd key={k}>{k}</kbd>
                            ))}
                        </dt>
                        <dd>{label}</dd>
                    </div>
                ))}
            </dl>
        </Dialog>
    );
}

// The picture of the diagram shown while zoomed out, laid exactly over where the cards and lines are.
function DiagramPicture({ canvas, bounds }: { canvas: HTMLCanvasElement; bounds: Bounds }) {
    const ref = useRef<HTMLDivElement>(null);
    useEffect(() => {
        const host = ref.current;
        if (!host) {
            return;
        }
        canvas.className = 'diagram-picture__canvas';
        host.append(canvas);
        return () => canvas.remove();
    }, [canvas]);
    return <div ref={ref} className="diagram-picture" style={{ transform: `translate(${bounds.x}px, ${bounds.y}px)`, width: bounds.width, height: bounds.height }} aria-hidden="true" />;
}
