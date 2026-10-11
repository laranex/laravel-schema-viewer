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

const firstLabel = (): Element | null =>
    [...document.querySelectorAll('.edge-label')].find((el) => {
        const r = el.getBoundingClientRect();
        return r.right > 0 && r.bottom > 0 && r.left < window.innerWidth && r.top < window.innerHeight;
    }) ?? null;

export const TOUR_STEPS: TourStep[] = [
    {
        title: 'Welcome to Schema Viewer',
        body: <p>Every table in your database, joined with the relationships your Eloquent models declare. This tour shows each part in about a minute.</p>,
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
        target: centralTable,
        title: 'A table',
        body: (
            <ul>
                <li>The header shows the table and the model that uses it.</li>
                <li>
                    <b>PK</b> marks the primary key, <b>FK</b> a foreign key, and <b>?</b> a nullable column.
                </li>
                <li>The right column shows the type, or the cast when the model casts it.</li>
                <li>An amber number counts the hints for this table. Click the card to see everything about it.</li>
            </ul>
        ),
    },
    {
        target: firstLabel,
        title: 'A relationship',
        body: (
            <p>
                Each line joins the column that holds the key to the column it points at. The label names the relation methods and the kind of relationship. Crow's feet mark the many side. Hover a label for the exact
                columns.
            </p>
        ),
    },
    {
        target: '.react-flow__controls',
        title: 'Zoom and arrange',
        body: (
            <p>
                Zoom in and out, fit the whole diagram on screen, and arrange the tables automatically with the last button (<kbd>Shift A</kbd>).
            </p>
        ),
    },
    {
        target: '[data-tour="search"]',
        title: 'Search',
        body: <p>Find a table by its name, a column, its model or a relation. Pick a result to jump to the table.</p>,
    },
    {
        target: ['.ui-tabs', '.sheet-bar__main'],
        title: 'Details',
        body: <p>The selected table's columns, relations, the relations that point at it, indexes and model settings: casts, fillable, hidden, scopes and observers.</p>,
    },
    {
        target: ['.ui-tabs .ui-tab:nth-child(2)', '.sheet-bar__hints'],
        title: 'Hints',
        body: <p>Things worth a second look: relations without a foreign key, foreign keys without an index or a relation, morph columns that store class names, and relations that could not be read.</p>,
    },
    {
        target: ['[data-tour="layers"]', '[data-tour="menu-show"]'],
        menu: 'phone',
        title: 'What the lines mean',
        body: (
            <ul>
                <li>
                    <b>Green</b>: a foreign key that a relation also uses.
                </li>
                <li>
                    <b>Amber dashes</b>: a relation without a foreign key, so rows can point at deleted ones.
                </li>
                <li>
                    <b>Grey</b>: a foreign key no model declares.
                </li>
                <li>
                    <b>Blue dots</b>: a through relation.
                </li>
            </ul>
        ),
    },
    {
        target: ['#connection', '[data-tour="menu-connection"]'],
        menu: 'phone',
        title: 'Connections',
        body: <p>Each database connection your app and its models use has its own diagram. A relation to a table on another connection shows as a dashed card you can click.</p>,
    },
    {
        target: ['[data-tour="actions"]', '[data-tour="menu-actions"]'],
        menu: 'phone',
        title: 'Export',
        body: (
            <ul>
                <li>
                    <b>Copy Mermaid</b>: this connection as a Mermaid diagram, for a README or a pull request.
                </li>
                <li>
                    <b>Copy JSON</b>: everything the viewer knows, every connection, for scripts and AI agents.
                </li>
            </ul>
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
        target: '.tour-fab',
        title: 'Come back any time',
        body: (
            <p>
                Press this button or <kbd>?</kbd> to take the tour again.
            </p>
        ),
    },
];
