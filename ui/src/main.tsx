import '@fontsource/ibm-plex-mono/latin-400.css';
import '@fontsource/ibm-plex-mono/latin-500.css';
import '@fontsource/ibm-plex-sans/latin-400.css';
import '@fontsource/ibm-plex-sans/latin-500.css';
import '@fontsource/ibm-plex-sans/latin-600.css';
import '@xyflow/react/dist/style.css';
import { StrictMode, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';
import type { ServerConfig } from './layout';
import './styles.css';
import type { SchemaDocument } from './types';

declare global {
    interface Window {
        __SCHEMA_VIEWER__?: ServerConfig;
    }
}

const server = window.__SCHEMA_VIEWER__;

// The page carries the schema and the downloads: the app's page from the snapshot php artisan schema-viewer:build wrote,
// a static export from the file itself. Without a page config (the prototype) the viewer shows example data.
function Root() {
    const [doc, setDoc] = useState<SchemaDocument | null>(server?.schema ?? null);
    const [error, setError] = useState<string | null>(null);
    // The app has no snapshot yet (or cannot read it): php artisan schema-viewer:build writes it.
    const missing = server?.missing ?? null;

    useEffect(() => {
        if (!server) {
            // Only the prototype build (vite build --mode prototype) carries the example data.
            if (import.meta.env.MODE === 'prototype') {
                import('./mock/schema').then((m) => setDoc(m.schema));
            } else {
                setError('Open the viewer through its Laravel route, by default /schema-viewer.');
            }
            return;
        }
        if (!server.schema && !server.missing) {
            setError('This page carries no schema. Run php artisan schema-viewer:build, or export it again with php artisan schema-viewer:export --format=html.');
        }
    }, []);

    if (missing) {
        return (
            <div className="status" role="status">
                <h1>No schema yet</h1>
                <p>{missing}</p>
                <p className="muted">
                    Run <code>php artisan schema-viewer:build</code> in your project, then reload this page. Run it again after you migrate or change a model.
                </p>
            </div>
        );
    }

    if (error) {
        return (
            <div className="status" role="alert">
                <h1>The schema could not be read</h1>
                <p>{error}</p>
                <p className="muted">Reload this page, or build the snapshot again with <code>php artisan schema-viewer:build</code>.</p>
            </div>
        );
    }

    if (!doc) {
        return (
            <div className="status" role="status">
                <span className="status__spinner" aria-hidden="true" />
                <p>Reading tables, models and relations…</p>
            </div>
        );
    }

    return <App doc={doc} example={!server} downloads={server?.downloads} defaultConnection={server?.defaultConnection} editor={server?.editor} changes={server?.changes} />;
}

createRoot(document.getElementById('root')!).render(
    <StrictMode>
        <Root />
    </StrictMode>,
);
