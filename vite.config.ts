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
        // Compiled assets are committed to dist/ and inlined into the dashboard
        // by Synapse::css()/js() — never published to the host app's public
        // directory, so there is nothing to re-publish on upgrade. Filenames are
        // stable (no hashes) because they're read directly off disk.
        outDir: 'dist',
        assetsDir: '',
        emptyOutDir: true,
        cssCodeSplit: false,
        rollupOptions: {
            input: 'resources/js/app.tsx',
            output: {
                entryFileNames: 'app.js',
                chunkFileNames: 'app-[name].js',
                assetFileNames: 'app.[ext]',
            },
        },
    },
});
