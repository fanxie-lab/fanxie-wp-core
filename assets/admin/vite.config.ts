import { existsSync, unlinkSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, URL } from 'node:url';
import type { AddressInfo } from 'node:net';
import { defineConfig, type Plugin, type ViteDevServer } from 'vite';
import vue from '@vitejs/plugin-vue';

// Vite build config.
//
// The PHP side enqueues these exact paths (relative to the plugin root):
//   assets/admin/dist/admin.js
//   assets/admin/dist/admin.css
//
// Do NOT rename the entry/asset outputs without coordinating with the PHP agent
// that wires wp_enqueue_script / wp_enqueue_style.
//
// Dev-mode hot-file contract (Laravel-Vite style):
// while `vite` is running, we write the dev server URL to `assets/admin/.vite-hot`.
// PHP watches for that file: when it exists, SettingsPage enqueues modules from
// the Vite dev server (HMR inside real WP admin). Cleaned up on shutdown.

const HOT_FILE = path.resolve(fileURLToPath(new URL('.', import.meta.url)), '.vite-hot');

/**
 * Silently remove the hot-file marker. ENOENT is ignored — the file may
 * already be gone (racey shutdown paths, prior crash, etc.).
 */
function removeHotFile(): void {
  try {
    if (existsSync(HOT_FILE)) {
      unlinkSync(HOT_FILE);
    }
  } catch (err) {
    const code = (err as NodeJS.ErrnoException).code;
    if (code !== 'ENOENT') {
      // eslint-disable-next-line no-console
      console.warn('[vite:hot-file] could not remove marker:', err);
    }
  }
}

/**
 * HotFilePlugin — writes `.vite-hot` while the dev server is listening and
 * deletes it on any shutdown path. The PHP side reads this file as its ONLY
 * dev-mode signal, so cleanup correctness matters more than anything else
 * this plugin does.
 */
function HotFilePlugin(): Plugin {
  let signalHandlersRegistered = false;
  let writtenUrl: string | null = null;

  const writeHotFile = (url: string): void => {
    writtenUrl = url;
    writeFileSync(HOT_FILE, url, { encoding: 'utf8' });
  };

  const registerSignalHandlers = (): void => {
    if (signalHandlersRegistered) return;
    signalHandlersRegistered = true;

    const cleanup = (): void => removeHotFile();
    // Named handlers so we don't double-bind across Vite restarts.
    const onSigint = (): void => {
      cleanup();
      process.exit(130);
    };
    const onSigterm = (): void => {
      cleanup();
      process.exit(143);
    };
    process.once('SIGINT', onSigint);
    process.once('SIGTERM', onSigterm);
    process.once('exit', cleanup);
  };

  return {
    name: 'fanxie-wp-core:hot-file',
    apply: 'serve',

    configureServer(server: ViteDevServer) {
      registerSignalHandlers();

      const httpServer = server.httpServer;
      if (!httpServer) return;

      httpServer.once('listening', () => {
        const address = httpServer.address() as AddressInfo | string | null;
        if (!address || typeof address === 'string') return;

        const protocol = server.config.server.https ? 'https' : 'http';
        const host = server.config.server.host === true ? '0.0.0.0' : (server.config.server.host ?? 'localhost');
        const url = `${protocol}://${host}:${address.port}`;
        writeHotFile(url);
      });

      httpServer.once('close', () => removeHotFile());
    },

    buildStart() {
      // Idempotent safety net: if `listening` already fired and wrote a URL,
      // make sure the file still exists (e.g., something external deleted it).
      if (writtenUrl && !existsSync(HOT_FILE)) {
        writeHotFile(writtenUrl);
      }
    },

    closeBundle() {
      removeHotFile();
    },
  };
}

export default defineConfig({
  plugins: [vue(), HotFilePlugin()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    host: 'localhost',
    port: 5173,
    strictPort: true,
    cors: true,
    // Ensures Vite emits absolute URLs for HMR assets (images, workers, etc.)
    // that point back at the dev server itself, not at the consumer page
    // (which lives at http://localhost:8888/wp-admin/... in integrated mode).
    origin: 'http://localhost:5173',
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    manifest: false,
    sourcemap: true,
    cssCodeSplit: false,
    rollupOptions: {
      input: 'src/main.ts',
      output: {
        entryFileNames: 'admin.js',
        chunkFileNames: 'admin-[name].js',
        assetFileNames: (assetInfo) => {
          if (assetInfo.name?.endsWith('.css')) {
            return 'admin.css';
          }
          return 'assets/[name][extname]';
        },
      },
    },
  },
});
