// Serves the built viewer (../dist) the way ViewerController does, with the schema of a fixture inlined:
// /?fixture=blog (the default), multi, large or missing (no snapshot yet). Plain node:http, no dependencies.
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { extname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';
import { fixtures } from './fixtures/fixtures.mjs';

const dist = process.env.SCHEMA_VIEWER_DIST ?? fileURLToPath(new URL('../../dist/', import.meta.url));
const port = Number(process.env.PORT ?? 4179);
const types = { '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.woff2': 'font/woff2', '.woff': 'font/woff' };
const safe = { '<': '\\u003c', '>': '\\u003e', '&': '\\u0026', '\u2028': '\\u2028', '\u2029': '\\u2029' };

function page(fixture) {
    const config = { defaultConnection: 'all', editor: null, missing: null };
    if (fixture === 'missing') {
        config.missing = 'No schema snapshot at [schema-viewer.json] yet. Run php artisan schema-viewer:build.';
    } else {
        const snapshot = (fixtures[fixture] ?? fixtures.blog)();
        config.schema = snapshot.document;
        config.downloads = snapshot.downloads;
        if (snapshot.changes !== undefined) {
            config.changes = snapshot.changes;
        }
    }
    const json = JSON.stringify(config).replace(/[<>&\u2028\u2029]/g, (c) => safe[c]);
    return `<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Schema Viewer</title>
    <link rel="stylesheet" href="/app.css">
    <script>window.__SCHEMA_VIEWER__ = ${json};</script>
    <script type="module" src="/app.js"></script>
</head>
<body>
    <div id="root"></div>
</body>
</html>`;
}

createServer(async (request, response) => {
    const url = new URL(request.url ?? '/', `http://localhost:${port}`);
    if (url.pathname === '/') {
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
        response.end(page(url.searchParams.get('fixture') ?? 'blog'));
        return;
    }
    const file = normalize(join(dist, decodeURIComponent(url.pathname)));
    if (!file.startsWith(dist) || !types[extname(file)]) {
        response.writeHead(404).end();
        return;
    }
    try {
        const body = await readFile(file);
        response.writeHead(200, { 'Content-Type': types[extname(file)] });
        response.end(body);
    } catch {
        response.writeHead(404).end();
    }
}).listen(port, () => console.log(`Schema viewer test server on http://localhost:${port}`));
