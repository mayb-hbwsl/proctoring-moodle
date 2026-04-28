/**
 * Patch @timadey/proctor ESM build for production use.
 * 
 * Fixes:
 * 1. Changes delegate from "CPU" to "GPU" (CPU delegate broken in IIFE bundles)
 * 2. Updates WASM path from localhost to /local/timadey/assets/wasm
 * 
 * This runs automatically via postinstall and before each build.
 */
import { readFileSync, writeFileSync, existsSync } from 'fs';

const ESM_PATH = 'node_modules/@timadey/proctor/dist/index.esm.js';

if (!existsSync(ESM_PATH)) {
    console.log('[patch-proctor] Skipping — @timadey/proctor not installed yet.');
    process.exit(0);
}

let content = readFileSync(ESM_PATH, 'utf8');
let patched = false;

// Fix 1: CPU → GPU delegate
if (content.includes('delegate:"CPU"')) {
    content = content.replace(/delegate:"CPU"/g, 'delegate:"GPU"');
    patched = true;
    console.log('[patch-proctor] ✅ Patched delegate: CPU → GPU');
}

// Fix 2: WASM path → production Moodle path
if (content.includes('forVisionTasks("http://127.0.0.1:8080/wasm")')) {
    content = content.replace(
        'forVisionTasks("http://127.0.0.1:8080/wasm")',
        'forVisionTasks("/local/timadey/assets/wasm")'
    );
    patched = true;
    console.log('[patch-proctor] ✅ Patched WASM path → /local/timadey/assets/wasm');
}

if (patched) {
    writeFileSync(ESM_PATH, content);
    console.log('[patch-proctor] Done.');
} else {
    console.log('[patch-proctor] Already patched — no changes needed.');
}
