import ELK, { type ElkNode } from 'elkjs/lib/elk-api.js';
// ELK's worker script as text: started as a Web Worker from a Blob, so it works from a static export too, and never
// parsed by the page itself unless workers are blocked.
import source from 'elkjs/lib/elk-worker.min.js?raw';

type Engine = { layout: (graph: ElkNode) => Promise<ElkNode> };

let engine: Engine | null = null;

// Lays out off the main thread, so the page stays responsive while a large schema is arranged.
function inWorker(): Engine {
    const url = URL.createObjectURL(new Blob([source], { type: 'text/javascript' }));
    let fail: (error: Error) => void = () => undefined;
    const failed = new Promise<never>((_, reject) => {
        fail = reject;
    });
    failed.catch(() => undefined);
    const elk = new ELK({
        workerFactory: () => {
            const worker = new Worker(url);
            worker.addEventListener('error', (e) => {
                e.preventDefault();
                fail(new Error('The layout worker could not start.'));
            });
            return worker;
        },
    });
    return { layout: (graph) => Promise.race([elk.layout(graph), failed]) };
}

// When a Content-Security-Policy blocks workers from blobs: the same script runs on the page, in a script element that
// carries the page's nonce, and exports a stand-in worker that lays out on this thread.
function onPage(): Engine {
    const holder = '__schemaViewerElk';
    const script = document.createElement('script');
    const nonce = document.querySelector<HTMLScriptElement>('script[nonce]')?.nonce;
    if (nonce) {
        script.nonce = nonce;
    }
    script.textContent = `window.${holder} = (function (module, exports) {\n${source}\nreturn module.exports;\n})({ exports: {} }, {});`;
    document.head.appendChild(script);
    script.remove();
    const exported = (window as unknown as Record<string, { Worker?: new () => Worker } | undefined>)[holder];
    if (!exported?.Worker) {
        throw new Error('The layout engine could not start.');
    }
    const Stand = exported.Worker;
    const elk = new ELK({ workerFactory: () => new Stand() });
    return { layout: (graph) => elk.layout(graph) };
}

export async function elkLayout(graph: ElkNode): Promise<ElkNode> {
    if (!engine) {
        try {
            engine = inWorker();
        } catch {
            engine = onPage();
        }
    }
    try {
        return await engine.layout(graph);
    } catch (e) {
        if (!(e instanceof Error) || e.message !== 'The layout worker could not start.') {
            throw e;
        }
        engine = onPage();
        return engine.layout(graph);
    }
}
