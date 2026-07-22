import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [react(), tailwindcss()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    build: {
        // Compiled assets are committed to dist/ and published to
        // public/vendor/synapse on install (see SCAFFOLDING.md → Asset Delivery).
        outDir: 'dist',
        assetsDir: '',
        emptyOutDir: true,
        manifest: true,
        rollupOptions: {
            input: 'resources/js/app.tsx',
        },
    },
});
