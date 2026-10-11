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
import { ExternalNodeView, TableNodeView, type ExternalNodeType, type TableNodeType } from './components/TableNode';
import { toJson, toMermaid } from './export';
import { linkKind, NODE_WIDTH, nodeHeight, nodeId, relationKind, Schema, shortClass } from './graph';
import { boxWidth, route, type Box } from './routing';
import { autoLayout } from './layout';
import type { SchemaDocument } from './types';

type AppNode = TableNodeType | ExternalNodeType;

type Layers = Record<EdgeKind, boolean> & { framework: boolean; allLabels: boolean };

const nodeTypes = { table: TableNodeView, external: ExternalNodeView };
const edgeTypes = { link: LinkEdgeView };

const EXTERNAL_HEIGHT = 76;
const NARROW = '(max-width: 760px)';

function useNarrow() {
    const [narrow, setNarrow] = useState(() => window.matchMedia(NARROW).matches);
    useEffect(() => {
        const query = window.matchMedia(NARROW);
        const update = () => setNarrow(query.matches);
        query.addEventListener('change', update);
        return () => query.removeEventListener('change', update);
    }, []);
    return narrow;
}

export default function App({ doc, example = false }: { doc: SchemaDocument; example?: boolean }) {
    return (
        <ReactFlowProvider>
            <TooltipProvider>
                <Viewer doc={doc} example={example} />
            </TooltipProvider>
        </ReactFlowProvider>
    );
}

function Viewer({ doc, example }: { doc: SchemaDocument; example: boolean }) {
    const schema = useMemo(() => new Schema(doc), [doc]);
    const flow = useReactFlow<AppNode, LinkEdgeType>();

    const [connection, setConnection] = useState(doc.connections[0].name);
    const [selected, setSelected] = useState<string | null>(null);
    const [pending, setPending] = useState<string | null>(null);
    const [tab, setTab] = useState<'table' | 'hints'>('table');
    const [query, setQuery] = useState('');
    const [layers, setLayers] = useState<Layers>({ both: true, relation: true, foreign_key: true, through: true, framework: true, allLabels: true });
    const [nodes, setNodes, onNodesChange] = useNodesState<AppNode>([]);
    const [toast, setToast] = useState<string | null>(null);
    const [copyText, setCopyText] = useState<{ title: string; text: string } | null>(null);
    const [layoutVersion, setLayoutVersion] = useState(0);
    const [needsFit, setNeedsFit] = useState(false);
    const [sheetOpen, setSheetOpen] = useState(false);
    const narrow = useNarrow();
    const [hovered, setHovered] = useState<string | null>(null);
    const searchRef = useRef<HTMLInputElement>(null);
    const [helpOpen, setHelpOpen] = useState(false);
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
        const target = narrow && selected ? [{ id: nodeId(connection, selected) }] : undefined;
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

    const tables = schema.tables(connection);
    const visibleTables = useMemo(() => tables.filter((t) => layers.framework || !t.framework), [tables, layers.framework]);

    // Tables on another connection that this connection's relations point at.
    const externals = useMemo(() => {
        const out = new Map<string, { connection: string; table: string }>();
        schema.links(connection).forEach((l) => {
            if (l.to.connection !== connection) {
                out.set(nodeId(l.to.connection, l.to.table), { connection: l.to.connection, table: l.to.table });
            }
        });
        return out;
    }, [schema, connection]);

    // Place the nodes: the saved layout when there is one, else an automatic layout.
    useEffect(() => {
        let cancelled = false;
        const ids = new Set([...visibleTables.map((t) => nodeId(connection, t.name)), ...externals.keys()]);
        const sizes: Record<string, number> = {};
        visibleTables.forEach((t) => (sizes[nodeId(connection, t.name)] = nodeHeight(t)));
        externals.forEach((_, id) => (sizes[id] = EXTERNAL_HEIGHT));

        const place = async () => {
            const positions = await autoLayout(schema, connection, ids, sizes);
            if (cancelled) {
                return;
            }
            const next: AppNode[] = [
                ...visibleTables.map(
                    (t): TableNodeType => ({
                        id: nodeId(connection, t.name),
                        type: 'table',
                        position: positions[nodeId(connection, t.name)] ?? { x: 0, y: 0 },
                        data: { table: t, models: [], pivot: false, hintCount: 0, dim: false, active: false, primary: [], foreign: [], linked: [], casts: {} },
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
    }, [schema, connection, visibleTables, externals, layoutVersion]);

    const neighbors = useMemo(() => (selected ? schema.neighbors(connection, selected, 1) : null), [schema, connection, selected]);
    const pivots = useMemo(() => schema.pivotTables(connection), [schema, connection]);

    const isDim = useCallback(
        (id: string) => {
            const name = id.startsWith(`${connection}.`) ? id.slice(connection.length + 1) : null;
            const table = name ? schema.table(connection, name) : undefined;
            return query.trim() !== '' && (!table || !schema.matches(connection, table, query));
        },
        [schema, connection, query],
    );

    // What each node shows for the current selection and search.
    const decorate = useCallback(
        (n: AppNode): AppNode => {
            if (n.type === 'external') {
                return { ...n, data: { ...n.data, dim: isDim(n.id) } };
            }
            const t = n.data.table;
            const models = schema.modelsFor(connection, t.name);
            const touching = schema
                .links(connection)
                .filter((l) => selected && ((l.from.connection === connection && l.from.table === selected) || (l.to.connection === connection && l.to.table === selected)));
            const linked = touching.flatMap((l) => [...(l.from.table === t.name ? l.from.columns : []), ...(l.to.table === t.name ? l.to.columns : [])]);
            return {
                ...n,
                selected: t.name === selected,
                data: {
                    ...n.data,
                    models: models.map((m) => shortClass(m.class)),
                    pivot: pivots.has(t.name),
                    hintCount: schema.hints(connection, t.name).length,
                    dim: isDim(n.id),
                    active: neighbors !== null && neighbors.has(n.id) && t.name !== selected,
                    primary: t.indexes.find((i) => i.primary)?.columns ?? [],
                    foreign: t.foreignKeys.flatMap((f) => f.columns),
                    linked,
                    casts: Object.assign({}, ...models.map((m) => m.casts)),
                },
            };
        },
        [schema, connection, selected, neighbors, pivots, isDim],
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
        const touches = (a: string, b: string) => selected !== null && (a === nodeId(connection, selected) || b === nodeId(connection, selected));
        const firstColumn = (name: string) => schema.table(connection, name)?.columns[0]?.name ?? 'id';

        type Spec = { id: string; source: string; target: string; sourceColumn: string; targetColumn: string; data: Omit<LinkEdgeData, 'route'> };
        const specs: Spec[] = [];

        for (const l of schema.links(connection)) {
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
            for (const d of schema.derived(connection)) {
                const source = nodeId(connection, d.from);
                const target = nodeId(connection, d.to);
                if (!visible(source) || !visible(target)) {
                    continue;
                }
                const touch = touches(source, target);
                specs.push({
                    id: d.id,
                    source,
                    target,
                    sourceColumn: firstColumn(d.from),
                    targetColumn: firstColumn(d.to),
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
            if (n.hidden) {
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
    }, [schema, connection, layers, selected, hovered, isDim, positionOf, nodes]);

    const centerOn = useCallback(
        (name: string) => {
            const n = flow.getNode(nodeId(connection, name));
            if (n) {
                const h = n.measured?.height ?? 200;
                flow.setCenter(n.position.x + NODE_WIDTH / 2, n.position.y + h / 2, { zoom: Math.max(flow.getZoom(), 0.9), duration: 400 });
            }
        },
        [flow, connection],
    );

    // After switching connection from an external node, select the table once it is placed.
    useEffect(() => {
        if (pending && nodes.some((n) => n.id === nodeId(connection, pending))) {
            setSelected(pending);
            setPending(null);
            window.setTimeout(() => centerOn(pending), 350);
        }
    }, [pending, nodes, connection, centerOn]);

    const open = useCallback(
        (conn: string, table: string) => {
            setTab('table');
            if (conn !== connection) {
                setConnection(conn);
                setSelected(null);
                setPending(table);
                return;
            }
            setSelected(table);
            centerOn(table);
        },
        [connection, centerOn],
    );

    const onNodeClick: NodeMouseHandler<AppNode> = useCallback(
        (_, node: Node) => {
            if (node.type === 'external') {
                const d = node.data as { connection: string; table: string };
                open(d.connection, d.table);
                return;
            }
            setSelected((node.data as { table: { name: string } }).table.name);
            setTab('table');
        },
        [open],
    );

    const switchConnection = (name: string) => {
        setConnection(name);
        setSelected(null);
        setPending(null);
    };

    const copy = async (title: string, text: string) => {
        try {
            await navigator.clipboard.writeText(text);
            notify(`${title} copied.`);
        } catch {
            setCopyText({ title, text });
        }
    };

    const onAutoLayout = () => {
        setLayoutVersion((v) => v + 1);
        notify('Tables arranged automatically.');
    };

    const connectionOptions = doc.connections.map((c) => ({ value: c.name, label: c.name, hint: `${c.driver} · ${c.tables.length} tables` }));

    const actions = [
        { label: 'Copy Mermaid', shortcut: undefined, tour: undefined, run: () => copy('Mermaid diagram', toMermaid(schema, connection)) },
        { label: 'Copy JSON', shortcut: undefined, tour: undefined, run: () => copy('Schema JSON', toJson(schema)) },
        { label: 'Keyboard shortcuts', shortcut: narrow ? undefined : 'S', tour: 'menu-shortcuts', run: () => setHelpOpen(true) },
        { label: 'Take the tour', shortcut: narrow ? undefined : '?', tour: undefined, run: () => setTourOpen(true) },
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

        const connections = doc.connections.map((c) => c.name);
        const step = (by: number) => switchConnection(connections.at((connections.indexOf(connection) + by + connections.length) % connections.length) ?? connection);
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
    const hintCount = schema.hints(connection).length;

    return (
        <div className="app">
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
                <SearchBox schema={schema} connection={connection} tables={visibleTables} query={query} onQuery={setQuery} onPick={(table) => open(connection, table)} inputRef={searchRef} />
                <div className="toolbar__spacer" />
                {!narrow && <Select id="connection" label="Connection" value={connection} onChange={switchConnection} options={connectionOptions} />}
                <Menu
                    label="More actions"
                    trigger={
                        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" className="icon-fill">
                            <circle cx="5" cy="12" r="1.8" />
                            <circle cx="12" cy="12" r="1.8" />
                            <circle cx="19" cy="12" r="1.8" />
                        </svg>
                    }
                    items={actions.map((a) => ({ label: a.label, shortcut: a.shortcut, onSelect: a.run, tour: a.tour }))}
                    open={menuOpen || tourMenu === 'always' || (narrow && tourMenu === 'phone')}
                    onOpenChange={(o) => !tourMenu && setMenuOpen(o)}
                    choice={narrow ? { label: 'Connection', value: connection, options: connectionOptions, onChange: switchConnection } : undefined}
                    toggles={
                        narrow
                            ? {
                                  label: 'Show',
                                  items: LAYER_TOGGLES.map((t) => ({
                                      label: t.label,
                                      checked: layers[t.key],
                                      onChange: () => toggle(t.key),
                                      icon: t.swatch ? <Swatch kind={t.swatch} /> : undefined,
                                  })),
                              }
                            : undefined
                    }
                />
            </header>

            {!narrow && (
                <div className="layers" role="group" aria-label="Show on the diagram" data-tour="layers">
                    {LAYER_TOGGLES.map((t) => (
                        <LayerToggle key={t.key} on={layers[t.key]} onClick={() => toggle(t.key)} swatch={t.swatch} label={t.label} divider={t.key === 'framework'} />
                    ))}
                    <span className="layers__note">
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
                        minZoom={0.2}
                        maxZoom={2}
                        proOptions={{ hideAttribution: true }}
                    >
                        <Background variant={BackgroundVariant.Dots} gap={20} size={1.2} />
                        <Controls showInteractive={false}>
                            <Tooltip content={<>Auto layout <kbd>Shift A</kbd></>} side="right">
                                <ControlButton onClick={onAutoLayout} aria-label="Arrange tables automatically" data-tour="auto-layout">
                                    <svg viewBox="0 0 16 16" aria-hidden="true" className="ui-icon">
                                        <rect x="1.5" y="2" width="5" height="4" rx="1" />
                                        <rect x="9.5" y="2" width="5" height="4" rx="1" />
                                        <rect x="5.5" y="10" width="5" height="4" rx="1" />
                                        <path d="M4 6 V8 H12 V6 M8 8 V10" />
                                    </svg>
                                </ControlButton>
                            </Tooltip>
                        </Controls>
                        {!narrow && <MiniMap pannable zoomable nodeStrokeWidth={2} />}
                    </ReactFlow>
                    <Tooltip content={<>Take the tour <kbd>?</kbd></>} side="left">
                        <button type="button" className={`tour-fab${toured ? '' : ' is-new'}`} aria-label="Take the tour" aria-keyshortcuts="?" onClick={() => setTourOpen(true)}>
                            ?
                        </button>
                    </Tooltip>
                </div>
                <SidePanel
                    schema={schema}
                    connection={connection}
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

            <ShortcutsHelp open={helpOpen} onOpenChange={setHelpOpen} />
            <Tour steps={TOUR_STEPS} open={tourOpen} onClose={endTour} onStep={onTourStep} menuSteps={narrow} />

            {toast && (
                <div className="toast" role="status">
                    {toast}
                </div>
            )}

            <Dialog open={copyText !== null} onOpenChange={(o) => !o && setCopyText(null)} title={copyText?.title ?? ''} description="Copying is blocked here. Select the text and copy it." wide>
                <textarea id="copy-text" className="ui-textarea" readOnly value={copyText?.text ?? ''} onFocus={(e) => e.currentTarget.select()} autoFocus />
            </Dialog>
        </div>
    );
}


const LAYER_TOGGLES: { key: keyof Layers; label: string; swatch?: EdgeKind }[] = [
    { key: 'both', label: 'FK + relation', swatch: 'both' },
    { key: 'relation', label: 'Relation only', swatch: 'relation' },
    { key: 'foreign_key', label: 'FK only', swatch: 'foreign_key' },
    { key: 'through', label: 'Through', swatch: 'through' },
    { key: 'framework', label: 'Framework tables' },
    { key: 'allLabels', label: 'Relation names' },
];

function Swatch({ kind }: { kind: EdgeKind }) {
    return (
        <svg width="22" height="8" viewBox="0 0 22 8" aria-hidden="true" className={`swatch swatch--${kind}`}>
            <path d="M1 4 H21" />
        </svg>
    );
}

function LayerToggle({ on, onClick, label, swatch, divider }: { on: boolean; onClick: () => void; label: string; swatch?: EdgeKind; divider?: boolean }) {
    return (
        <>
            {divider && <span className="layers__divider" aria-hidden="true" />}
            <button type="button" className="chip-toggle" aria-pressed={on} onClick={onClick}>
                {swatch ? <Swatch kind={swatch} /> : <span className="check" aria-hidden="true" />}
                {label}
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
    [['[', ']'], 'Previous or next connection'],
    [['Shift A'], 'Arrange tables automatically'],
    [['S'], 'Show this list'],
    [['?'], 'Take the tour'],
];

function ShortcutsHelp({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange} title="Keyboard shortcuts">
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
