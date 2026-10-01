/// <reference types="vitest/globals" />

// Vitest config for the Fanxie Warden admin SPA.
//
// Mirrors the Vue + `@/*` alias setup from `vite.config.ts` so tests resolve
// imports identically to the production build. We do NOT pull in the HotFile
// plugin from vite.config.ts — it is dev-server only and would fight Vitest's
// lifecycle. Keep this file in sync with vite.config.ts for plugin/alias churn.

import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  test: {
    environment: 'happy-dom',
    globals: true,
    setupFiles: ['./tests/setup.ts'],
    include: [
      'src/**/*.{test,spec}.{ts,tsx}',
      'tests/**/*.{test,spec}.{ts,tsx}',
    ],
    exclude: ['node_modules', 'dist'],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'html', 'lcov'],
      include: ['src/**/*.{ts,vue}'],
      exclude: [
        'src/**/*.{test,spec}.{ts,tsx}',
        'src/**/__tests__/**',
        'src/types/**',
        'src/main.ts',
        '**/*.config.{ts,js}',
      ],
      reportsDirectory: 'coverage',
    },
  },
});
