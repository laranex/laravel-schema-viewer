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
                Each card is a table. Referenced tables sit to the left of the tables that point at them. Drag cards to arrange them, scroll or pinch to zoom, and drag the background to move around.
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
                columns. On a big schema the labels show only on the selected table's lines; <b>Relation names</b> under <b>Show</b> puts them on every line (<kbd>L</kbd>).
            </p>
        ),
    },
    {
        target: ['[data-tour="zoom"]', '.react-flow__controls'],
        title: 'Zoom and arrange',
        body: (
            <p>
                Zoom in and out, fit the whole diagram on screen, and arrange the tables automatically (<kbd>Shift A</kbd>). Click the percentage to type a zoom. The hand turns on pan mode: drag anywhere, even over a table, to move around; or hold <kbd>Space</kbd>. In your app, the last button reads the database and models again (<kbd>Shift R</kbd>).
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
                <b>Framework tables</b> are Laravel's own, such as migrations, jobs, cache and sessions, plus Telescope and Pulse (<kbd>G</kbd>). <b>Package tables</b>, when your app has them, come from a known package such as
                Passport or spatie/laravel-permission (<kbd>P</kbd>). Hide them to keep only your own tables on the diagram, in the Tables list and in search.
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
                Things worth a second look (<kbd>H</kbd>): relations without a foreign key, foreign keys without an index or a relation, morph columns that store class names, and relations that could not be read.
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
        extend: '[data-tour="menu-offline"]',
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
        title: 'Keyboard shortcuts',
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
