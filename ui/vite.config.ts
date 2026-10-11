import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [react()],
    base: './',
    build: {
        outDir: '../dist',
        emptyOutDir: true,
        rollupOptions: {
            output: {
                entryFileNames: 'app.js',
                // The stylesheet keeps a fixed name for the page; fonts go under assets/ with a content hash.
                assetFileNames: (asset) => (asset.names.some((name) => name.endsWith('.css')) ? 'app[extname]' : 'assets/[name]-[hash][extname]'),
                inlineDynamicImports: true,
            },
        },
    },
});
