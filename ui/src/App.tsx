import {
    Background,
    BackgroundVariant,
    ControlButton,
    Controls,
    MiniMap,
    ReactFlow,
    ReactFlowProvider,
    useNodesInitialized,
    useNodesState,
    useReactFlow,
    type Node,
    type NodeMouseHandler,
} from '@xyflow/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { EdgeMarkers, LinkEdgeView, type EdgeKind, type LinkEdgeData, type LinkEdgeType } from './components/LinkEdge';
import { SearchBox } from './components/SearchBox';
import { Tour, type TourStep } from './components/Tour';
import { TOUR_STEPS } from './tourSteps';
import { Dialog, Menu, Select, Tooltip, TooltipProvider } from './components/ui';
import { SidePanel } from './components/SidePanel';
import { ExternalNodeView, RegionNodeView, TableNodeView, type ExternalNodeType, type RegionNodeType, type TableNodeType } from './components/TableNode';
import { linkKind, NODE_WIDTH, nodeHeight, nodeId, relationKind, Schema, shortClass } from './graph';
import { boxWidth, route, type Box } from './routing';
import { layoutConnections, type StaticDownloads } from './layout';
import { standaloneHtml } from './standalone';
import type { SchemaDocument, Table } from './types';

type AppNode = TableNodeType | ExternalNodeType | RegionNodeType;

// A table on the diagram: which connection it is on, and its name.
type TableRef = { connection: string; table: string };

// The connection picker's value for every connection at once.
const ALL = '*';

type Layers = Record<EdgeKind, boolean> & { framework: boolean; allLabels: boolean };

const nodeTypes = { table: TableNodeView, external: ExternalNodeView, region: RegionNodeView };
const edgeTypes = { link: LinkEdgeView };

const EXTERNAL_HEIGHT = 76;
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

export default function App({ doc, example = false, exportUrl, downloads }: { doc: SchemaDocument; example?: boolean; exportUrl?: string; downloads?: StaticDownloads }) {
    return (
        <ReactFlowProvider>
            <TooltipProvider>
                <Viewer doc={doc} example={example} exportUrl={exportUrl} downloads={downloads} />
            </TooltipProvider>
        </ReactFlowProvider>
    );
}

function Viewer({ doc, example, exportUrl, downloads }: { doc: SchemaDocument; example: boolean; exportUrl?: string; downloads?: StaticDownloads }) {
    const schema = useMemo(() => new Schema(doc), [doc]);
    const flow = useReactFlow<AppNode, LinkEdgeType>();

    // One connection, or ALL to put every connection on the diagram: the start when there is more than one.
    const [scope, setScope] = useState(doc.connections.length > 1 ? ALL : doc.connections[0].name);
    const [selected, setSelected] = useState<TableRef | null>(null);
    const [pending, setPending] = useState<TableRef | null>(null);
    const scopeConnections = useMemo(() => (scope === ALL ? doc.connections.map((c) => c.name) : [scope]), [doc, scope]);
    const selectedId = selected ? nodeId(selected.connection, selected.table) : null;
    // The connection the panel and the per-connection downloads talk about: the selected table's, else the one in view.
    const connection = selected?.connection ?? scopeConnections[0];
    const [tab, setTab] = useState<'table' | 'hints'>('table');
    const [query, setQuery] = useState('');
    const [layers, setLayers] = useState<Layers>({ both: true, relation: true, foreign_key: true, through: true, framework: true, allLabels: true });
    const [nodes, setNodes, onNodesChange] = useNodesState<AppNode>([]);
    const [toast, setToast] = useState<string | null>(null);
    const [layoutVersion, setLayoutVersion] = useState(0);
    const [needsFit, setNeedsFit] = useState(false);
    const [sheetOpen, setSheetOpen] = useState(false);
    const narrow = useNarrow();
    const roomy = useMedia('(min-width: 1100px)');
    const [hovered, setHovered] = useState<string | null>(null);
    const searchRef = useRef<HTMLInputElement>(null);
    const [helpOpen, setHelpOpen] = useState(false);
    // Whether each control shows its shortcut key beside it. Off by default; remembered per browser.
    const [keysOn, setKeysOn] = useState(() => {
        try {
            return localStorage.getItem('schema-viewer.shortcut-hints') === 'on';
        } catch {
            return false;
        }
    });
    const switchKeys = (on: boolean) => {
        setKeysOn(on);
        try {
            localStorage.setItem('schema-viewer.shortcut-hints', on ? 'on' : 'off');
        } catch {
            // Storage blocked: the choice lasts until reload.
        }
    };
    const [tourOpen, setTourOpen] = useState(false);
    const [menuOpen, setMenuOpen] = useState(false);
    // While a tour step explains part of the phone menu, the tour keeps the menu open.
    const [tourMenu, setTourMenu] = useState<TourStep['menu']>(undefined);
    const onTourStep = useCallback((step: TourStep | null) => setTourMenu(step?.menu), []);
    const [toured, setToured] = useState(() => {
        try {
            return localStorage.getItem('schema-viewer.toured') === '1';
        } catch {
            return true;
        }
    });
    const endTour = useCallback(() => {
        setTourOpen(false);
        setToured(true);
        try {
            localStorage.setItem('schema-viewer.toured', '1');
        } catch {
            // Storage blocked: the button keeps its first-visit pulse, nothing else changes.
        }
    }, []);
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

    // Phones start on the selected table at a readable size; larger screens see the whole diagram.
    const fitRef = useRef(() => undefined as unknown);
    fitRef.current = () => {
        const target = narrow && selectedId ? [{ id: selectedId }] : undefined;
        return flow.fitView({ padding: narrow ? 0.25 : 0.08, nodes: target, maxZoom: narrow ? 1.1 : 1 });
    };

    // The canvas can change size after the first fit (a phone's browser bars settling, a rotated screen):
    // fit again until the person pans or zooms themselves.
    const userMoved = useRef(false);
    const canvasRef = useRef<HTMLDivElement>(null);
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

    const notify = useCallback((message: string) => {
        setToast(message);
        window.clearTimeout(toastTimer.current);
        toastTimer.current = window.setTimeout(() => setToast(null), 2600);
    }, []);

    // Every table in view, with its connection.
    const visibleTables = useMemo(
        () => scopeConnections.flatMap((c) => schema.tables(c).filter((t) => layers.framework || !t.framework).map((table) => ({ connection: c, table }))),
        [schema, scopeConnections, layers.framework],
    );
    const tablesById = useMemo(() => new Map(visibleTables.map((e) => [nodeId(e.connection, e.table.name), e])), [visibleTables]);

    // The links and through links in view: each once, even when it joins two connections in view.
    const links = useMemo(() => [...new Map(scopeConnections.flatMap((c) => schema.links(c)).map((l) => [l.id, l])).values()], [schema, scopeConnections]);
    const derived = useMemo(() => scopeConnections.flatMap((c) => schema.derived(c)), [schema, scopeConnections]);

    // Tables on a connection out of view that the relations in view point at.
    const externals = useMemo(() => {
        const out = new Map<string, TableRef>();
        links.forEach((l) => {
            if (!scopeConnections.includes(l.to.connection)) {
                out.set(nodeId(l.to.connection, l.to.table), { connection: l.to.connection, table: l.to.table });
            }
        });
        return out;
    }, [links, scopeConnections]);

    // Place the nodes automatically; with every connection in view, each connection gets its own region.
    useEffect(() => {
        let cancelled = false;
        const ids = new Set([...tablesById.keys(), ...externals.keys()]);
        const sizes: Record<string, number> = {};
        tablesById.forEach((e, id) => (sizes[id] = nodeHeight(e.table)));
        externals.forEach((_, id) => (sizes[id] = EXTERNAL_HEIGHT));

        const place = async () => {
            const { positions, regions } = await layoutConnections(schema, scopeConnections, ids, sizes);
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
                            tables: visibleTables.filter((e) => e.connection === r.connection).length,
                            hints: schema.hints(r.connection).length,
                        },
                    }),
                ),
                ...visibleTables.map(
                    ({ connection: c, table: t }): TableNodeType => ({
                        id: nodeId(c, t.name),
                        type: 'table',
                        position: positions[nodeId(c, t.name)] ?? { x: 0, y: 0 },
                        data: { connection: c, table: t, models: [], pivot: false, hintCount: 0, dim: false, active: false, primary: [], foreign: [], linked: [], casts: {} },
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
    }, [schema, scopeConnections, visibleTables, tablesById, externals, layoutVersion]);

    const neighbors = useMemo(() => (selected ? schema.neighbors(selected.connection, selected.table, 1) : null), [schema, selected]);
    const pivots = useMemo(() => new Set(scopeConnections.flatMap((c) => [...schema.pivotTables(c)].map((t) => nodeId(c, t)))), [schema, scopeConnections]);

    const isDim = useCallback(
        (id: string) => {
            const entry = tablesById.get(id);
            return query.trim() !== '' && (!entry || !schema.matches(entry.connection, entry.table, query));
        },
        [schema, tablesById, query],
    );

    // What each node shows for the current selection and search.
    const decorate = useCallback(
        (n: AppNode): AppNode => {
            if (n.type === 'region') {
                return n;
            }
            if (n.type === 'external') {
                return { ...n, data: { ...n.data, dim: isDim(n.id) } };
            }
            const t = n.data.table;
            const c = n.data.connection;
            const models = schema.modelsFor(c, t.name);
            const at = (end: { connection: string; table: string }, ref: TableRef | null) => ref !== null && end.connection === ref.connection && end.table === ref.table;
            const touching = selected ? schema.links(selected.connection).filter((l) => at(l.from, selected) || at(l.to, selected)) : [];
            const self = { connection: c, table: t.name };
            const linked = touching.flatMap((l) => [...(at(l.from, self) ? l.from.columns : []), ...(at(l.to, self) ? l.to.columns : [])]);
            return {
                ...n,
                selected: n.id === selectedId,
                data: {
                    ...n.data,
                    models: models.map((m) => shortClass(m.class)),
                    pivot: pivots.has(n.id),
                    hintCount: schema.hints(c, t.name).length,
                    dim: isDim(n.id),
                    active: neighbors !== null && neighbors.has(n.id) && n.id !== selectedId,
                    primary: t.indexes.find((i) => i.primary)?.columns ?? [],
                    foreign: t.foreignKeys.flatMap((f) => f.columns),
                    linked,
                    casts: Object.assign({}, ...models.map((m) => m.casts)),
                },
            };
        },
        [schema, selected, selectedId, neighbors, pivots, isDim],
    );
    const decorateRef = useRef(decorate);
    decorateRef.current = decorate;

    useEffect(() => {
        setNodes((current) => current.map(decorate));
    }, [decorate, setNodes]);

    const positionOf = useCallback((id: string) => nodes.find((n) => n.id === id), [nodes]);

    const edges = useMemo((): LinkEdgeType[] => {
        const visible = (id: string) => {
            const n = positionOf(id);
            return n && !n.hidden ? n : undefined;
        };
        const touches = (a: string, b: string) => selectedId !== null && (a === selectedId || b === selectedId);
        const firstColumn = (c: string, name: string) => schema.table(c, name)?.columns[0]?.name ?? 'id';

        type Spec = { id: string; source: string; target: string; sourceColumn: string; targetColumn: string; data: Omit<LinkEdgeData, 'route'> };
        const specs: Spec[] = [];

        for (const l of links) {
            const kind: EdgeKind = l.state;
            const source = nodeId(l.from.connection, l.from.table);
            const target = nodeId(l.to.connection, l.to.table);
            if (!layers[kind] || !visible(source) || !visible(target)) {
                continue;
            }
            const names = [...new Set(l.relations.map((r) => r.split('::')[1]))];
            const touch = touches(source, target);
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
                    showLabel: layers.allLabels || touch || hovered === l.id,
                    emphasized: touch || hovered === l.id,
                    dim: isDim(source) || isDim(target) || (selected !== null && !touch),
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
                const touch = touches(source, target);
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
                        showLabel: layers.allLabels || touch || hovered === d.id,
                        emphasized: touch || hovered === d.id,
                        dim: isDim(source) || isDim(target) || (selected !== null && !touch),
                    },
                });
            }
        }

        const boxes = new Map<string, Box>();
        nodes.forEach((n) => {
            if (n.hidden || n.type === 'region') {
                return;
            }
            boxes.set(n.id, {
                id: n.id,
                x: n.position.x,
                y: n.position.y,
                width: n.measured?.width ?? boxWidth(n.type),
                height: n.measured?.height ?? (n.type === 'table' ? nodeHeight(n.data.table) : EXTERNAL_HEIGHT),
                rows: n.type === 'table' ? n.data.table.columns.map((c) => c.name) : ['id'],
            });
        });
        const routes = route(
            boxes,
            specs.map((s) => ({ id: s.id, source: s.source, target: s.target, sourceColumn: s.sourceColumn, targetColumn: s.targetColumn, labelled: s.data.showLabel })),
        );

        return specs.flatMap((s): LinkEdgeType[] => {
            const r = routes.get(s.id);
            if (!r) {
                return [];
            }
            return [
                {
                    id: s.id,
                    type: 'link',
                    source: s.source,
                    target: s.target,
                    sourceHandle: `${s.sourceColumn}|s${r.sourceSide}`,
                    targetHandle: `${s.targetColumn}|t${r.targetSide}`,
                    zIndex: s.data.emphasized ? 10 : 0,
                    data: { ...s.data, route: r },
                },
            ];
        });
    }, [schema, links, derived, layers, selectedId, hovered, isDim, positionOf, nodes]);

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
            setTab('table');
            if (!scopeConnections.includes(conn)) {
                setScope(conn);
                setSelected(null);
                setPending({ connection: conn, table });
                return;
            }
            setSelected({ connection: conn, table });
            centerOn(nodeId(conn, table));
        },
        [scopeConnections, centerOn],
    );

    const onNodeClick: NodeMouseHandler<AppNode> = useCallback(
        (_, node: Node) => {
            if (node.type === 'external') {
                const d = node.data as { connection: string; table: string };
                open(d.connection, d.table);
                return;
            }
            if (node.type === 'region') {
                return;
            }
            const d = node.data as { connection: string; table: Table };
            setSelected({ connection: d.connection, table: d.table.name });
            setTab('table');
        },
        [open],
    );

    const switchConnection = (name: string) => {
        setScope(name);
        setSelected(null);
        setPending(null);
    };

    // The server builds the file, so a large schema never has to fit in the clipboard or the page's memory. A static
    // export has no server: it carries the files, and JSON is the connection itself, as the server writes it.
    const download = (format: ExportFormat) => {
        if (downloads) {
            const content = format === 'json' ? `${JSON.stringify(doc.connections.find((c) => c.name === connection), null, 4)}\n` : downloads[connection]?.[format];
            if (content === undefined) {
                notify('This export has no such file. Export it again to include it.');
                return;
            }
            const url = URL.createObjectURL(new Blob([content], { type: format === 'json' ? 'application/json' : 'text/plain' }));
            save(url, `schema-${connection.replace(/[^A-Za-z0-9_-]/g, '-')}.${EXTENSIONS[format]}`);
            window.setTimeout(() => URL.revokeObjectURL(url), 1000);
            return;
        }
        if (!exportUrl) {
            notify('Downloads are blocked in this preview. In your app the file downloads.');
            return;
        }
        save(`${exportUrl}?${new URLSearchParams({ format, connection })}`, '');
    };

    // The standalone viewer: every connection in one HTML file that opens without the app, built from this page.
    const downloadHtml = () => {
        if (!exportUrl && !downloads) {
            notify('Downloads are blocked in this preview. In your app the file downloads.');
            return;
        }
        notify('Building the standalone viewer…');
        standaloneHtml(doc, downloads, exportUrl).then(
            (html) => {
                const url = URL.createObjectURL(new Blob([html], { type: 'text/html' }));
                save(url, 'schema-viewer.html');
                window.setTimeout(() => URL.revokeObjectURL(url), 1000);
                notify('Standalone viewer downloaded.');
            },
            (e: Error) => notify(`The standalone viewer could not be built: ${e.message}`),
        );
    };

    const htmlItem = { label: 'Standalone viewer (HTML)', hint: 'Every connection in one file that opens without your app', onSelect: downloadHtml };

    const onAutoLayout = () => {
        setLayoutVersion((v) => v + 1);
        notify('Tables arranged automatically.');
    };

    const connectionOptions = [
        ...doc.connections.map((c) => ({ value: c.name, label: c.name, hint: `${c.driver} · ${c.tables.length} tables` })),
        ...(doc.connections.length > 1
            ? [{ value: ALL, label: 'All connections', hint: `${doc.connections.length} connections · ${doc.connections.reduce((n, c) => n + c.tables.length, 0)} tables` }]
            : []),
    ];

    const downloadHeading = (
        <span className="ui-menu__heading">
            Download <code>{connection}</code> connection
        </span>
    );

    const actions = [
        ...EXPORTS.map((e) => ({ label: e.label, hint: e.hint, tour: undefined, run: () => download(e.format) })),
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
                else setSelected(null);
            },
            f: () => flow.fitView({ padding: 0.08, duration: 250 }),
            '+': () => flow.zoomIn({ duration: 150 }),
            '=': () => flow.zoomIn({ duration: 150 }),
            '-': () => flow.zoomOut({ duration: 150 }),
            l: () => toggle('allLabels'),
            g: () => toggle('framework'),
            '1': () => toggle('both'),
            '2': () => toggle('relation'),
            '3': () => toggle('foreign_key'),
            '4': () => toggle('through'),
            t: () => setTab('table'),
            h: () => {
                setTab('hints');
                setSheetOpen(true);
            },
            A: () => onAutoLayout(),
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
        const listener = (e: KeyboardEvent) => shortcuts.current(e);
        window.addEventListener('keydown', listener);
        return () => window.removeEventListener('keydown', listener);
    }, []);
    const hintCount = scopeConnections.reduce((n, c) => n + schema.hints(c).length, 0);

    return (
        <div className={`app${keysOn ? '' : ' keys-off'}`}>
            <EdgeMarkers />
            <header className="toolbar">
                <div className="toolbar__brand">
                    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" className="brand-mark">
                        <rect x="2.5" y="3.5" width="8" height="7" rx="1.5" />
                        <rect x="13.5" y="13.5" width="8" height="7" rx="1.5" />
                        <path d="M10.5 7 H14 a2 2 0 0 1 2 2 V13.5" />
                    </svg>
                    <span>Schema Viewer</span>
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
                        itemsLabel={downloadHeading}
                        items={actions.map((a) => ({ label: a.label, hint: a.hint, onSelect: a.run, tour: a.tour }))}
                        extraItems={[htmlItem, { label: 'Keyboard shortcuts', onSelect: () => setHelpOpen(true), tour: 'menu-shortcuts' }]}
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
                        toggles={{
                            label: 'Show',
                            items: LAYER_TOGGLES.map((t) => ({
                                label: t.label,
                                checked: layers[t.key],
                                onChange: () => toggle(t.key),
                                icon: t.swatch ? <Swatch kind={t.swatch} /> : undefined,
                                hotkey: t.hotkey,
                            })),
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
                            <Menu
                                label="Download"
                                triggerClass="button button--with-icon"
                                trigger={
                                    <>
                                        <svg viewBox="0 0 16 16" width="15" height="15" aria-hidden="true" className="ui-icon">
                                            <path d="M8 2.5 V10 M4.5 6.5 L8 10 L11.5 6.5 M2.5 13.5 H13.5" />
                                        </svg>
                                        Download
                                    </>
                                }
                                itemsLabel={downloadHeading}
                                items={EXPORTS.map((e) => ({ label: e.label, hint: e.hint, onSelect: () => download(e.format) }))}
                                extraItems={[htmlItem]}
                            />
                        </div>
                        <Tooltip content={<>Keyboard shortcuts <kbd>S</kbd></>} side="bottom">
                            <button type="button" className="button button--with-icon" data-tour="shortcuts" aria-keyshortcuts="S" onClick={() => setHelpOpen(true)}>
                                <svg viewBox="0 0 20 16" width="18" height="15" aria-hidden="true" className="ui-icon">
                                    <rect x="1.5" y="2" width="17" height="12" rx="2" />
                                    <path d="M5 6 H5.5 M8 6 H8.5 M11.5 6 H12 M14.5 6 H15 M5 9 H5.5 M14.5 9 H15 M7.5 11.5 H12.5" />
                                </svg>
                                Keyboard shortcuts
                            </button>
                        </Tooltip>
                    </div>
                )}
            </header>

            {!narrow && (
                <div className="layers" role="group" aria-label="Show on the diagram" data-tour="layers">
                    {LAYER_TOGGLES.map((t) => (
                        <LayerToggle key={t.key} on={layers[t.key]} onClick={() => toggle(t.key)} swatch={t.swatch} label={t.label} hotkey={t.hotkey} divider={t.key === 'framework'} />
                    ))}
                    <span className="layers__note">
                        {scopeConnections.length > 1 ? `${scopeConnections.length} connections · ` : ''}
                        {visibleTables.length} tables · {hintCount} {hintCount === 1 ? 'hint' : 'hints'}
                        {example ? ' · example data' : ''}
                    </span>
                </div>
            )}

            <main className="main">
                <div className="canvas" ref={canvasRef}>
                    <ReactFlow<AppNode, LinkEdgeType>
                        nodes={nodes}
                        edges={edges}
                        nodeTypes={nodeTypes}
                        edgeTypes={edgeTypes}
                        onNodesChange={onNodesChange}
                        onNodeClick={onNodeClick}
                        onEdgeMouseEnter={(_, edge) => setHovered(edge.id)}
                        onEdgeMouseLeave={() => setHovered(null)}
                        onPaneClick={() => setSelected(null)}
                        onMoveStart={(event) => {
                            if (event) {
                                userMoved.current = true;
                            }
                        }}
                        nodesConnectable={false}
                        edgesFocusable={false}
                        minZoom={0.08}
                        maxZoom={2}
                        proOptions={{ hideAttribution: true }}
                    >
                        <Background variant={BackgroundVariant.Dots} gap={20} size={1.2} />
                        <Controls showInteractive={false} showZoom={false} showFitView={false}>
                            <Tooltip content={<>Zoom in <kbd>+</kbd></>} side="right">
                                <ControlButton onClick={() => flow.zoomIn({ duration: 150 })} aria-label="Zoom in" aria-keyshortcuts="+">
                                    <svg viewBox="0 0 16 16" aria-hidden="true" className="ui-icon">
                                        <path d="M8 3 V13 M3 8 H13" />
                                    </svg>
                                </ControlButton>
                            </Tooltip>
                            <Tooltip content={<>Zoom out <kbd>−</kbd></>} side="right">
                                <ControlButton onClick={() => flow.zoomOut({ duration: 150 })} aria-label="Zoom out" aria-keyshortcuts="-">
                                    <svg viewBox="0 0 16 16" aria-hidden="true" className="ui-icon">
                                        <path d="M3 8 H13" />
                                    </svg>
                                </ControlButton>
                            </Tooltip>
                            <Tooltip content={<>Fit the diagram <kbd>F</kbd></>} side="right">
                                <ControlButton onClick={() => flow.fitView({ padding: 0.08, duration: 250 })} aria-label="Fit the diagram" aria-keyshortcuts="F">
                                    <svg viewBox="0 0 16 16" aria-hidden="true" className="ui-icon">
                                        <path d="M2.5 6 V2.5 H6 M10 2.5 H13.5 V6 M13.5 10 V13.5 H10 M6 13.5 H2.5 V10" />
                                    </svg>
                                </ControlButton>
                            </Tooltip>
                            <Tooltip content={<>Auto layout <kbd>Shift A</kbd></>} side="right">
                                <ControlButton onClick={onAutoLayout} aria-label="Arrange tables automatically" aria-keyshortcuts="Shift+A" data-tour="auto-layout">
                                    <svg viewBox="0 0 16 16" aria-hidden="true" className="ui-icon">
                                        <rect x="1.5" y="2" width="5" height="4" rx="1" />
                                        <rect x="9.5" y="2" width="5" height="4" rx="1" />
                                        <rect x="5.5" y="10" width="5" height="4" rx="1" />
                                        <path d="M4 6 V8 H12 V6 M8 8 V10" />
                                    </svg>
                                </ControlButton>
                            </Tooltip>
                        </Controls>
                        {/* Below 1100px the diagram needs the room more than an overview does. */}
                        {roomy && <MiniMap pannable zoomable nodeStrokeWidth={2} nodeClassName={(n) => n.type ?? ''} style={{ width: 160, height: 110 }} />}
                    </ReactFlow>
                    <Tooltip content={<>Take the tour <kbd>?</kbd></>} side="left">
                        <button type="button" className={`tour-fab${toured ? '' : ' is-new'}`} aria-label="Take the tour" aria-keyshortcuts="?" onClick={() => setTourOpen(true)}>
                            ?
                        </button>
                    </Tooltip>
                </div>
                <SidePanel
                    schema={schema}
                    connections={scopeConnections}
                    selected={selected}
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

            <ShortcutsHelp open={helpOpen} onOpenChange={setHelpOpen} keysOn={keysOn} onKeysOn={switchKeys} />
            <Tour steps={TOUR_STEPS} open={tourOpen} onClose={endTour} onStep={onTourStep} menuSteps={narrow} />

            {toast && (
                <div className="toast" role="status">
                    {toast}
                </div>
            )}

        </div>
    );
}


type ExportFormat = 'mermaid' | 'dbml' | 'markdown' | 'json';

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
const EXPORTS: { format: ExportFormat; label: string; hint: string }[] = [
    { format: 'mermaid', label: 'Mermaid', hint: 'An ER diagram for READMEs and pull requests' },
    { format: 'dbml', label: 'DBML', hint: 'For dbdiagram.io and other DBML tools' },
    { format: 'markdown', label: 'Markdown', hint: 'A readable reference of every table' },
    { format: 'json', label: 'JSON', hint: 'The tables as data, for scripts and tools' },
];

const LAYER_TOGGLES: { key: keyof Layers; label: string; swatch?: EdgeKind; hotkey: string }[] = [
    { key: 'both', label: 'FK + relation', swatch: 'both', hotkey: '1' },
    { key: 'relation', label: 'Relation only', swatch: 'relation', hotkey: '2' },
    { key: 'foreign_key', label: 'FK only', swatch: 'foreign_key', hotkey: '3' },
    { key: 'through', label: 'Through', swatch: 'through', hotkey: '4' },
    { key: 'framework', label: 'Framework tables', hotkey: 'G' },
    { key: 'allLabels', label: 'Relation names', hotkey: 'L' },
];

function Swatch({ kind }: { kind: EdgeKind }) {
    return (
        <svg width="22" height="8" viewBox="0 0 22 8" aria-hidden="true" className={`swatch swatch--${kind}`}>
            <path d="M1 4 H21" />
        </svg>
    );
}

function LayerToggle({ on, onClick, label, swatch, hotkey, divider }: { on: boolean; onClick: () => void; label: string; swatch?: EdgeKind; hotkey: string; divider?: boolean }) {
    return (
        <>
            {divider && <span className="layers__divider" aria-hidden="true" />}
            <button type="button" className="chip-toggle" aria-pressed={on} onClick={onClick} aria-keyshortcuts={hotkey}>
                {swatch ? <Swatch kind={swatch} /> : <span className="check" aria-hidden="true" />}
                {label}
                <kbd className="kbd-hint">{hotkey}</kbd>
            </button>
        </>
    );
}

const MOD = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.userAgent) ? '⌘' : 'Ctrl';

const SHORTCUTS: [string[], string][] = [
    [['/', `${MOD} K`], 'Search'],
    [['Esc'], 'Clear the search or selection, close a panel'],
    [['F'], 'Fit the diagram to the screen'],
    [['+', '−'], 'Zoom in or out'],
    [['L'], 'Show relation names on every edge, or only on the selected table'],
    [['1', '2', '3', '4'], 'Show or hide FK + relation, relation only, FK only, through edges'],
    [['G'], 'Show or hide framework tables'],
    [['T', 'H'], 'Open the Table or Hints tab'],
    [['[', ']'], 'Previous or next connection, or all of them'],
    [['Shift A'], 'Arrange tables automatically'],
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
