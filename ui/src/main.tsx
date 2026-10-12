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

// In the package the page carries the server's URLs; without them (the prototype) the viewer shows example data.
function Root() {
    const [doc, setDoc] = useState<SchemaDocument | null>(null);
    const [error, setError] = useState<string | null>(null);

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
        // A static export carries the schema in the page itself.
        if (server.schema) {
            setDoc(server.schema);
            return;
        }
        if (!server.schemaUrl) {
            setError('This page carries no schema. Export it again with php artisan schema-viewer:export --format=html.');
            return;
        }
        fetch(server.schemaUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(async (r) => {
                if (!r.ok) {
                    const body = (await r.json().catch(() => ({}))) as { message?: string };
                    throw new Error(body.message ?? `The schema could not be read (HTTP ${r.status}).`);
                }
                return r.json() as Promise<SchemaDocument>;
            })
            .then(setDoc, (e: Error) => setError(e.message));
    }, []);

    if (error) {
        return (
            <div className="status" role="alert">
                <h1>The schema could not be read</h1>
                <p>{error}</p>
                <p className="muted">Check the database connection and the application log, then reload this page.</p>
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

    return <App doc={doc} example={!server} exportUrl={server?.exportUrl} downloads={server?.downloads} defaultConnection={server?.defaultConnection} editor={server?.editor} />;
}

createRoot(document.getElementById('root')!).render(
    <StrictMode>
        <Root />
    </StrictMode>,
);
