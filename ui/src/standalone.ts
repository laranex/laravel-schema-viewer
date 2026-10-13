import type { StaticDownloads } from './layout';
import type { Change, SchemaDocument } from './types';

// Inflates the config and the app, then starts the app. Kept identical to HtmlExporter::LOADER.
const LOADER = `const unpack = async (id) => new Response(new Blob([Uint8Array.from(atob(document.getElementById(id).textContent.trim()), (c) => c.charCodeAt(0))]).stream().pipeThrough(new DecompressionStream('gzip'))).text();
try {
    window.__SCHEMA_VIEWER__ = JSON.parse(await unpack('schema-viewer-config'));
    await import(URL.createObjectURL(new Blob([await unpack('schema-viewer-app')], { type: 'text/javascript' })));
} catch (e) {
    document.getElementById('root').textContent = 'This page needs a current browser (Chrome, Edge, Firefox or Safari 16.4 or later): ' + e.message;
}`;

// The whole viewer as one HTML file: the same file `php artisan schema-viewer:export --format=html` writes (see
// HtmlExporter), built from what this page already has: its own script and stylesheet with the fonts inlined, the
// schema it shows, and each connection's downloads. The script and the schema are gzipped and base64 encoded, and the
// page's loader inflates them.
export async function standaloneHtml(doc: SchemaDocument, downloads: StaticDownloads, defaultConnection = 'all', changes?: Change[] | null): Promise<string> {
    const [css, js] = await Promise.all([styles(), script()]);
    const [config, app] = await Promise.all([pack(JSON.stringify({ schema: doc, downloads, defaultConnection, ...(changes !== undefined ? { changes } : {}) })), pack(js)]);

    return `<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Schema Viewer</title>
    <style>${css.replace(/<\/style/gi, '<\\/style')}</style>
    <script type="module">
${LOADER}
    </script>
</head>
<body>
    <div id="root"></div>
    <script type="application/octet-stream" id="schema-viewer-config">${config}</script>
    <script type="application/octet-stream" id="schema-viewer-app">${app}</script>
</body>
</html>
`;
}

// Text gzipped, then base64 encoded.
async function pack(text: string): Promise<string> {
    const bytes = new Uint8Array(await new Response(new Blob([text]).stream().pipeThrough(new CompressionStream('gzip'))).arrayBuffer());
    let binary = '';
    for (let i = 0; i < bytes.length; i += 0x8000) {
        binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
    }
    return btoa(binary);
}

// Base64 encoded gzip, back to text.
async function unpack(data: string): Promise<string> {
    const bytes = Uint8Array.from(atob(data.trim()), (c) => c.charCodeAt(0));
    return new Response(new Blob([bytes]).stream().pipeThrough(new DecompressionStream('gzip'))).text();
}

// The app's script: packed in a static export, otherwise loaded from the package's asset route.
async function script(): Promise<string> {
    const packed = document.getElementById('schema-viewer-app');
    if (packed) {
        return unpack(packed.textContent ?? '');
    }
    const el = document.querySelector<HTMLScriptElement>('script[type="module"][src]');
    if (!el) {
        throw new Error('The viewer script was not found on this page.');
    }
    return text(el.src);
}

// The page's @font-face rules with their WOFF2 files embedded, for a downloaded image to carry its fonts.
export async function fontFaces(): Promise<string> {
    return ((await styles()).match(/@font-face\s*\{[^}]*\}/g) ?? []).join('\n');
}

// The stylesheet with its WOFF2 fonts embedded and the WOFF fallbacks dropped, as the exporter does.
async function styles(): Promise<string> {
    const link = document.querySelector<HTMLLinkElement>('link[rel="stylesheet"]');
    if (!link) {
        return document.querySelector('head > style')?.textContent ?? '';
    }
    const css = (await text(link.href)).replace(/,\s*url\(\.\/assets\/[\w.-]+\.woff\)\s*format\("woff"\)/g, '');
    const fonts = [...new Set(css.match(/url\(\.\/assets\/[\w.-]+\.woff2\)/g) ?? [])];
    const inlined = await Promise.all(fonts.map(async (ref) => [ref, `url(${await dataUrl(new URL(ref.slice(4, -1), link.href).href)})`] as const));
    return inlined.reduce((out, [ref, data]) => out.split(ref).join(data), css);
}

async function text(url: string): Promise<string> {
    const response = await fetch(url, { credentials: 'same-origin' });
    if (!response.ok) {
        throw new Error(`Could not read ${url} (HTTP ${response.status}).`);
    }
    return response.text();
}

async function dataUrl(url: string): Promise<string> {
    const response = await fetch(url, { credentials: 'same-origin' });
    if (!response.ok) {
        throw new Error(`Could not read ${url} (HTTP ${response.status}).`);
    }
    const blob = new Blob([await response.arrayBuffer()], { type: 'font/woff2' });
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result));
        reader.onerror = () => reject(reader.error ?? new Error('Could not read a font.'));
        reader.readAsDataURL(blob);
    });
}
