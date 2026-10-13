import { useEffect, useState } from 'react';
import { Dialog, Select } from './ui';

export type ExportFormat = 'mermaid' | 'dbml' | 'markdown' | 'json';

export const EXPORTS: { format: ExportFormat; label: string; hint: string }[] = [
    { format: 'mermaid', label: 'Mermaid', hint: 'An ER diagram for READMEs and pull requests' },
    { format: 'dbml', label: 'DBML', hint: 'For dbdiagram.io and other DBML tools' },
    { format: 'markdown', label: 'Markdown', hint: 'A readable reference of every table' },
    { format: 'json', label: 'JSON', hint: 'The tables as data, for scripts and tools' },
];

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    connections: { name: string; tables: number }[];
    // The connection the dialog opens on: the picked table's, or the one in view.
    initial: string;
    onDownload: (format: ExportFormat, connection: string) => void;
    onDownloadHtml: () => void;
};

// Downloads: one connection's database schema in a format, or the whole viewer as one offline HTML page.
export function DownloadDialog({ open, onOpenChange, connections, initial, onDownload, onDownloadHtml }: Props) {
    const [connection, setConnection] = useState(initial);
    useEffect(() => {
        if (open) {
            setConnection(initial);
        }
    }, [open, initial]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange} title="Download">
            <div className="downloads" data-tour="download-dialog">
                {connections.length > 1 && (
                    <section className="downloads__connection-select">
                        <h3 className="downloads__heading">Connection</h3>
                        <Select
                            id="download-connection"
                            label="Connection"
                            value={connection}
                            onChange={setConnection}
                            options={connections.map((c) => ({ value: c.name, label: c.name, hint: `${c.tables} tables` }))}
                        />
                    </section>
                )}
                <section>
                    <h3 className="downloads__heading">
                        Database schema of <code>{connection}</code>
                    </h3>
                    <div className="downloads__grid">
                        {EXPORTS.map((e) => (
                            <button key={e.format} type="button" className="downloads__item" onClick={() => onDownload(e.format, connection)}>
                                <b>{e.label}</b>
                                <span className="muted">{e.hint}</span>
                            </button>
                        ))}
                    </div>
                </section>
                <section>
                    <h3 className="downloads__heading">Use it offline</h3>
                    <button type="button" className="downloads__item downloads__item--wide" onClick={onDownloadHtml}>
                        <b>This page as HTML</b>
                        <span className="muted">Opens in any browser, offline, with every connection</span>
                    </button>
                </section>
            </div>
        </Dialog>
    );
}
