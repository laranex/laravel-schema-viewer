// The pages the browser tests open, by the ?fixture= of the URL. blog.json is a real snapshot of the shared blog schema
// (tests/Feature/UiFixtureTest.php writes it); the others are built from it or generated.
import { readFileSync } from 'node:fs';

const blog = () => JSON.parse(readFileSync(new URL('./blog.json', import.meta.url), 'utf8'));

// The blog plus a second connection with tables of its own.
function multi() {
    const snapshot = blog();
    const column = (name, type = 'bigint') => ({ name, type, typeName: type, nullable: false, default: null, autoIncrement: name === 'id', comment: null });
    const table = (name, columns) => ({ name, comment: null, framework: false, package: null, columns, indexes: [{ name: `${name}_pkey`, columns: ['id'], unique: true, primary: true }], foreignKeys: [] });
    snapshot.document.connections.push({
        name: 'analytics',
        driver: 'pgsql',
        tables: [table('events', [column('id'), column('name', 'varchar(255)'), column('user_id')]), table('page_views', [column('id'), column('event_id'), column('path', 'text')])],
    });
    snapshot.downloads.analytics = { mermaid: 'erDiagram\n', dbml: 'Table events {\n}\n', markdown: '# Database schema: analytics (pgsql)\n' };
    return snapshot;
}

// 250 tables in chains of ten, each pointing at the one before it.
function large() {
    const tables = [];
    for (let i = 0; i < 250; i++) {
        const name = `table_${String(i).padStart(3, '0')}`;
        const parent = i % 10 === 0 ? null : `table_${String(i - 1).padStart(3, '0')}`;
        const columns = [
            { name: 'id', type: 'integer', typeName: 'integer', nullable: false, default: null, autoIncrement: true, comment: null },
            { name: 'name', type: 'varchar', typeName: 'varchar', nullable: false, default: null, autoIncrement: false, comment: null },
        ];
        if (parent) {
            columns.push({ name: 'parent_id', type: 'integer', typeName: 'integer', nullable: true, default: null, autoIncrement: false, comment: null });
        }
        tables.push({
            name,
            comment: null,
            framework: false,
            package: null,
            columns,
            indexes: [{ name: 'primary', columns: ['id'], unique: true, primary: true }],
            foreignKeys: parent ? [{ name: `${name}_parent_id_foreign`, columns: ['parent_id'], foreignTable: parent, foreignColumns: ['id'], onUpdate: 'no action', onDelete: 'no action' }] : [],
        });
    }
    const links = tables.flatMap((t) =>
        t.foreignKeys.map((f) => ({ id: `l_${t.name}`, from: { connection: 'testing', table: t.name, columns: ['parent_id'] }, to: { connection: 'testing', table: f.foreignTable, columns: ['id'] }, foreignKey: f.name, relations: [], state: 'foreign_key', morph: false })),
    );
    return {
        format: 2,
        document: { version: 1, connections: [{ name: 'testing', driver: 'sqlite', tables }], models: [], relations: [], unresolved: [], links, derivedLinks: [], hints: [] },
        downloads: { testing: { mermaid: 'erDiagram\n', dbml: '', markdown: '# Database schema: testing (sqlite)\n' } },
    };
}

export const fixtures = { blog, multi, large };
