import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
  build: {
    outDir: 'dist-moodle',
    emptyOutDir: false, // Don't wipe - we have patched wasm/ files in here
    lib: {
      entry: resolve(__dirname, 'moodle-bridge.js'),
      name: 'TimadeyProctorMoodle',
      fileName: 'moodle-proctor-bundle',
      formats: ['iife'], // immediately invoked function expression, best for direct script tag insertion
    },
  },
  define: {
    // Some libraries might expect process.env.NODE_ENV
    'process.env.NODE_ENV': JSON.stringify('production'),
  }
});
