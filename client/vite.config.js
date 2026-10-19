import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import { resolve } from 'node:path';

/**
 * Stable (non-hashed) file names: Silverstripe's Requirements API adds an
 * mtime-based ?m= suffix for cache busting, so no manifest is needed and
 * themes can replace the files 1:1.
 */
export default defineConfig({
  plugins: [tailwindcss()],
  build: {
    outDir: resolve(import.meta.dirname, 'dist'),
    emptyOutDir: true,
    cssCodeSplit: true,
    sourcemap: false,
    rollupOptions: {
      input: {
        egov: resolve(import.meta.dirname, 'src/main.js'),
        'egov-admin': resolve(import.meta.dirname, 'src/admin.css'),
      },
      output: {
        entryFileNames: '[name].js',
        chunkFileNames: '[name].js',
        assetFileNames: '[name][extname]',
      },
    },
  },
});
