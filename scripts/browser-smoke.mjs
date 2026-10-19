// Real-browser check of the viewer page as the package serves it, over the Chrome DevTools Protocol, with no
// dependency: maintainers and CI only (the package itself never needs Node.js).
//
//   VIEWER_URL=http://127.0.0.1:8000/schema-viewer node scripts/browser-smoke.mjs
//
// It reads the laranex-schema document the page carries, opens the page in headless Chrome under the page's own
// Content-Security-Policy, and checks that every table of every connection is drawn, that the Diagnostics and Changes
// tabs count what the page carries, and that the offline viewer's files load from the bundle. It fails on any uncaught
// exception, console error or warning (CSP violations included), failed request, or request outside the page's
// origin. CHROME_BIN picks the browser; SCREENSHOT=path writes a desktop screenshot.
import { spawn } from 'node:child_process';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import assert from 'node:assert/strict';

const url = new URL(process.env.VIEWER_URL || 'http://127.0.0.1:8000/schema-viewer');
const executable = process.env.CHROME_BIN || (process.platform === 'darwin' ? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' : 'google-chrome');

const html = await (await fetch(url)).text();
const data = html.match(/<script type="application\/json" id="schema-viewer-data">(.*?)<\/script>/s);
assert.ok(data, 'The page carries no schema-viewer-data block.');
const config = JSON.parse(data[1]);
assert.ok(config.document, `The page carries no document: ${config.missing ?? 'unknown reason'}`);
const tables = config.document.connections.flatMap((c) => c.tables.map((t) => t.id));

const profile = await mkdtemp(join(tmpdir(), 'schema-viewer-browser-'));
const browser = spawn(executable, ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--disable-background-networking', '--disable-component-update', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { stdio: ['ignore', 'ignore', 'pipe'] });
let socket;

try {
    const endpoint = await new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('Chrome did not start')), 20000);
        let output = '';
        browser.stderr.on('data', (chunk) => {
            output += chunk;
            const found = output.match(/DevTools listening on (ws:\/\/\S+)/);
            if (found) {
                clearTimeout(timer);
                resolve(found[1]);
            }
        });
        browser.on('error', (error) => (clearTimeout(timer), reject(error)));
    });

    socket = new WebSocket(endpoint);
    await new Promise((resolve, reject) => ((socket.onopen = resolve), (socket.onerror = reject)));

    let id = 0;
    const pending = new Map();
    const problems = [];
    const requests = [];
    socket.onmessage = ({ data: raw }) => {
        const message = JSON.parse(raw);
        if (message.id && pending.has(message.id)) {
            const { resolve, reject } = pending.get(message.id);
            pending.delete(message.id);
            message.error ? reject(new Error(message.error.message)) : resolve(message.result);
            return;
        }
        const { method, params } = message;
        if (method === 'Runtime.exceptionThrown') {
            problems.push(`exception: ${params.exceptionDetails.exception?.description ?? params.exceptionDetails.text}`);
        } else if (method === 'Runtime.consoleAPICalled' && ['error', 'warning', 'assert'].includes(params.type)) {
            problems.push(`console ${params.type}: ${params.args.map((a) => a.value ?? a.description).join(' ')}`);
        } else if (method === 'Log.entryAdded' && ['error', 'warning'].includes(params.entry.level)) {
            problems.push(`log ${params.entry.level}: ${params.entry.text} ${params.entry.url ?? ''}`);
        } else if (method === 'Network.requestWillBeSent') {
            requests.push(params.request.url);
        } else if (method === 'Network.loadingFailed' && !params.canceled) {
            problems.push(`request failed: ${params.errorText} ${params.requestId}`);
        } else if (method === 'Network.responseReceived' && params.response.status >= 400) {
            problems.push(`HTTP ${params.response.status}: ${params.response.url}`);
        }
    };
    const send = (method, params = {}, sessionId) =>
        new Promise((resolve, reject) => {
            const message = { id: ++id, method, params };
            if (sessionId) {
                message.sessionId = sessionId;
            }
            pending.set(message.id, { resolve, reject });
            socket.send(JSON.stringify(message));
        });

    const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
    const page = (method, params) => send(method, params, sessionId);
    for (const domain of ['Runtime', 'Log', 'Network', 'Page']) {
        await page(`${domain}.enable`);
    }
    await page('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await page('Page.navigate', { url: url.href });

    const evaluate = async (expression) => (await page('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true })).result.value;
    const until = async (expression, what) => {
        for (let i = 0; i < 100; i++) {
            if (await evaluate(expression)) {
                return;
            }
            await new Promise((resolve) => setTimeout(resolve, 200));
        }
        throw new Error(`Timed out waiting for ${what}`);
    };

    await until(`document.querySelector('[data-loaded="true"]') !== null`, 'the viewer to load');
    await until(`document.querySelectorAll('.react-flow__node').length >= ${tables.length}`, 'every table to be drawn');
    const drawn = await evaluate(`[...document.querySelectorAll('.react-flow__node')].map((n) => n.getAttribute('data-id'))`);
    assert.deepEqual(tables.filter((t) => !drawn.includes(t)), [], 'Tables missing from the diagram');

    const tabs = await evaluate(`[...document.querySelectorAll('[role=tab]')].map((t) => t.textContent)`);
    assert.ok(tabs.includes(`Diagnostics${config.document.diagnostics.length}`), `Diagnostics tab: ${tabs.join(', ')}`);
    if (config.changes?.length) {
        assert.ok(tabs.includes(`Changes${config.changes.length}`), `Changes tab: ${tabs.join(', ')}`);
    }

    // The offline viewer is built from the bundle's own files: same origin, allowed by the page's policy.
    const offline = await evaluate(`(async () => {
        const base = new URL('.', document.querySelector('script[type=module][src]').src);
        const statuses = await Promise.all(['offline.js', 'app.css'].map(async (f) => (await fetch(new URL(f, base))).status));
        return statuses;
    })()`);
    assert.deepEqual(offline, [200, 200], 'The offline viewer files');

    if (process.env.SCREENSHOT) {
        const { data: png } = await page('Page.captureScreenshot', { format: 'png' });
        await writeFile(process.env.SCREENSHOT, Buffer.from(png, 'base64'));
    }

    const outside = requests.filter((r) => !r.startsWith('data:') && new URL(r).origin !== url.origin);
    assert.deepEqual(outside, [], 'Requests outside the page origin');
    assert.deepEqual(problems, [], 'Errors in the browser');
    console.log(`Viewer OK: ${tables.length} tables on ${config.document.connections.length} connections, ${config.document.diagnostics.length} diagnostics, ${requests.length} requests, no errors.`);
} finally {
    socket?.close();
    browser.kill();
    await rm(profile, { recursive: true, force: true }).catch(() => {});
}
