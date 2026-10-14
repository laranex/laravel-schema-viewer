import type { TourStep } from './components/Tour';

// The table card nearest the middle of the screen, so the step points at something in view.
const centralTable = (): Element | null => {
    const cards = [...document.querySelectorAll('.react-flow__node-table')];
    const cx = window.innerWidth / 2;
    const cy = window.innerHeight / 2;
    return (
        cards
            .map((el) => ({ el, r: el.getBoundingClientRect() }))
            .filter(({ r }) => r.right > 0 && r.bottom > 0 && r.left < window.innerWidth && r.top < window.innerHeight)
            .sort((a, b) => Math.hypot(a.r.left + a.r.width / 2 - cx, a.r.top + a.r.height / 2 - cy) - Math.hypot(b.r.left + b.r.width / 2 - cx, b.r.top + b.r.height / 2 - cy))[0]?.el ?? null
    );
};

// A relation label wholly on screen, clear of the toolbar and the bars at the bottom; else any label in view.
const firstLabel = (): Element | null => {
    const labels = [...document.querySelectorAll('.edge-label')].map((el) => ({ el, r: el.getBoundingClientRect() }));
    const inView = labels.filter(({ r }) => r.right > 0 && r.bottom > 0 && r.left < window.innerWidth && r.top < window.innerHeight);
    const clear = inView.filter(({ r }) => r.left >= 12 && r.right <= window.innerWidth - 12 && r.top >= 80 && r.bottom <= window.innerHeight - 160);
    return (clear[0] ?? inView[0])?.el ?? null;
};

export const TOUR_STEPS: TourStep[] = [
    {
        title: 'Welcome to Schema Viewer',
        body: (
            <p>
                Every table in your database, joined with the relationships your Eloquent models declare. This tour shows each part in about a minute. Most controls have a keyboard shortcut: see them all, or show them next to each control, with <kbd>S</kbd>.
            </p>
        ),
    },
    {
        target: '.canvas',
        title: 'The diagram',
        body: (
            <p>
                Each card is a table. Referenced tables sit to the left of the tables that point at them. Drag cards to arrange them; <kbd>Shift</kbd>+click several, or drag a box around them on the background, to move them together. Scroll to move around, or hold <kbd>Space</kbd> and drag; hold <kbd>Ctrl</kbd> or <kbd>⌘</kbd> while scrolling, or pinch, to zoom.
            </p>
        ),
    },
    {
        target: ['.react-flow__node-table[data-tour-pick]', centralTable],
        closeUp: true,
        title: 'A table',
        body: (
            <ul>
                <li>The header names the table and its model; an amber number counts its hints.</li>
                <li>
                    <b>PK</b>, <b>FK</b> and <b>?</b> mark the primary key, foreign keys and nullable columns; the right side shows each type or cast.
                </li>
                <li>
                    Click a card, a column or a line for its details. Double-click one to focus on it: only what is around it stays (<kbd>Shift F</kbd>). A card's arrow folds it.
                </li>
            </ul>
        ),
    },
    {
        target: ['.edge-label[data-tour-pick]', firstLabel],
        labels: true,
        closeUp: true,
        title: 'A relationship',
        body: (
            <p>
                Each line joins the column that holds the key to the column it points at. The label names the relation methods and the kind of relationship. Crow's feet mark the many side. Hover a label for the exact
                columns. <b>Relation names</b> under <b>Show</b> hides or shows the labels on every line (<kbd>L</kbd>); off, only the selected table's lines carry them.
            </p>
        ),
    },
    {
        target: ['[data-tour="zoom"]', '.react-flow__controls'],
        title: 'Zoom and arrange',
        body: (
            <p>
                Zoom in and out, fit the whole diagram on screen, and arrange the tables automatically: left to right, as a snowflake or compactly. Click the percentage to type a zoom. The hand turns on pan mode: drag anywhere, even over a table, to move around; or hold <kbd>Space</kbd>.
            </p>
        ),
    },
    {
        target: '[data-tour="view-relationships"]',
        opens: 'relationships',
        title: 'Relationships',
        body: (
            <>
                <p>Each kind of line has its own look. Untick one to hide those lines; the button counts the hidden kinds.</p>
                <ul>
                    <li>
                        <b>FK + relation</b> (green): a foreign key, and a model relation that uses it. All is well.
                    </li>
                    <li>
                        <b>Relation only</b> (amber dashes): a relation without a foreign key, so rows can point at deleted ones.
                    </li>
                    <li>
                        <b>FK only</b> (grey): a foreign key no model declares a relation for.
                    </li>
                    <li>
                        <b>Through</b> (blue dots): a has-one-through or has-many-through relation, drawn across the table in between.
                    </li>
                </ul>
            </>
        ),
    },
    {
        target: '[data-tour="view-tables"]',
        opens: 'tables',
        title: 'Tables',
        body: (
            <p>
                Every table is one of three kinds, each with its count: <b>Your tables</b> (<kbd>Y</kbd>); <b>Framework tables</b>, Laravel's own such as migrations, jobs, cache and sessions (<kbd>G</kbd>);
                and <b>Package tables</b>, from an installed package such as Telescope, Pulse, Sanctum or Passport, or one you list in <code>package_tables</code> (<kbd>P</kbd>). A hidden kind leaves the diagram, the Browse list and search.
            </p>
        ),
    },
    {
        target: '[data-tour="view-show"]',
        opens: 'show',
        title: 'Show',
        body: (
            <ul>
                <li>
                    <b>Relation names</b> labels every line with its methods and kind (<kbd>L</kbd>). Off, only the selected table's lines carry them.
                </li>
                <li>
                    <b>Columns</b>: <b>All</b> of them, only the <b>Keys</b> and the columns relations use, or only the table <b>Names</b> for the big picture (<kbd>C</kbd>). A card's own arrow folds just that card.
                </li>
                <li>
                    <b>Grid</b> shows or hides the dots behind the diagram.
                </li>
            </ul>
        ),
    },
    {
        target: '[data-tour="search"]',
        title: 'Search',
        body: <p>Find a table by its name, a column, its model or a relation. Pick a result to jump to the table.</p>,
    },
    {
        target: ['.ui-tabs .ui-tab:nth-child(1)', '.sheet-bar__action'],
        tab: 'browse',
        title: 'Browse',
        body: (
            <p>
                Every table, relationship and column, with one filter (<kbd>A</kbd>). Pick one to see it on the diagram and in Details.
            </p>
        ),
    },
    {
        target: ['.ui-tabs .ui-tab:nth-child(2)', '.sheet-bar__main'],
        tab: 'details',
        title: 'Details',
        body: (
            <ul>
                <li>
                    What you picked (<kbd>D</kbd>): a table's columns, relations and model; a relationship's keys, foreign key and relations; or a column's type, indexes and the relationships that use it.
                </li>
                <li>Click a table, a line or a column on the diagram to pick it there.</li>
                <li>
                    <b>Focus</b> keeps only what is around it; <b>Copy link</b> copies a link to exactly this view (<kbd>Shift C</kbd>). With an editor set in the config, model and relation names open in it. Drag the panel's edge to
                    widen it.
                </li>
            </ul>
        ),
    },
    {
        target: ['.ui-tabs .ui-tab:nth-child(3)', '.sheet-bar__hints'],
        tab: 'hints',
        title: 'Hints',
        body: (
            <p>
                Things worth a second look (<kbd>H</kbd>): relations without a foreign key, foreign keys without an index or a relation, morph columns that store class names, and relations that could not be read. A hint marked <b>likely</b> is inferred from names or conventions: check it before changing anything.
            </p>
        ),
    },
    {
        target: ['.ui-tabs .ui-tab:nth-child(4)', '.sheet-bar__main'],
        tab: 'changes',
        title: 'Changes',
        body: (
            <p>
                What the last build that found a difference changed (<kbd>W</kbd>): tables, columns, indexes, foreign keys, models and relations. A build that changes nothing keeps the list as it was. A dot on a card marks a new or changed table.
            </p>
        ),
    },
    {
        target: ['#connection', '[data-tour="menu-connection"]'],
        menu: 'phone',
        title: 'Connections',
        body: (
            <p>
                Each database connection your app and its models use has its own diagram, and <b>All connections</b> puts them side by side. On one connection, a relation to a table on another shows as a dashed card you can click.
            </p>
        ),
    },
    {
        target: ['[data-tour="actions"]', '[data-tour="menu-actions"]'],
        menu: 'phone',
        title: 'Download',
        body: (
            <p>
                Download this connection's database schema (tables, columns, keys, indexes and foreign keys) as a <b>Mermaid</b> diagram for a README, <b>DBML</b> for dbdiagram.io, <b>Markdown</b> to read, or <b>JSON</b> for scripts. Or the whole viewer as one <b>HTML</b> file that opens anywhere, without your app.
            </p>
        ),
    },
    {
        target: ['[data-tour="shortcuts"]', '[data-tour="menu-shortcuts"]'],
        menu: 'phone',
        title: 'Shortcuts',
        body: (
            <p>
                Everything here has a key. Open this list any time with <kbd>S</kbd>.
            </p>
        ),
    },
    {
        target: '[data-tour="help"]',
        title: 'Come back any time',
        body: (
            <p>
                Press the <b>?</b> button in the bar, or the <kbd>?</kbd> key, to take the tour again.
            </p>
        ),
    },
];
