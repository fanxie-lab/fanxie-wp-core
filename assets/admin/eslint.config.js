// ESLint flat config (ESLint 9+) for the Fanxie WP Core admin SPA.
//
// Layered rule stack — later layers override earlier ones where they collide:
//   1. @eslint/js recommended
//   2. typescript-eslint strict-type-checked + stylistic-type-checked (project-aware)
//   3. eslint-plugin-vue flat/recommended (Vue 3)
//   4. @vue/eslint-config-typescript — Vue + TS glue
//   5. eslint-config-prettier — turn off stylistic rules that conflict with Prettier
//   6. Project-specific overrides
//
// @wordpress/eslint-plugin is NOT layered in yet. As of v22.x, it still ships an
// eslintrc-shaped export and is not flat-config native; adopting it wholesale
// here would force legacy-compat shims that fight Vue 3 + flat config. Tracked
// as a follow-up. The project-specific rules below replicate the WP rules that
// actually matter for a Vue/TS admin SPA (no-console=warn, prefer-const, etc.).

import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import vuePlugin from 'eslint-plugin-vue';
import vueTsConfig from '@vue/eslint-config-typescript';
import prettierConfig from 'eslint-config-prettier';
import vueParser from 'vue-eslint-parser';
import globals from 'globals';

export default tseslint.config(
  {
    // Global ignores — must be in its own config object with ONLY `ignores`.
    ignores: ['dist/**', 'node_modules/**', '.vite-hot', 'coverage/**'],
  },

  // 1. ESLint core recommended.
  js.configs.recommended,

  // 2. typescript-eslint strict + stylistic, type-checked.
  ...tseslint.configs.strictTypeChecked,
  ...tseslint.configs.stylisticTypeChecked,

  // 3. eslint-plugin-vue flat/recommended (Vue 3).
  ...vuePlugin.configs['flat/recommended'],

  // 4. @vue/eslint-config-typescript — array of flat configs.
  ...vueTsConfig(),

  // Language options for all TS + Vue sources.
  {
    files: ['src/**/*.{ts,vue}', '*.ts', '*.config.ts'],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'module',
      globals: {
        ...globals.browser,
        ...globals.es2022,
      },
      parser: vueParser,
      parserOptions: {
        // Nest the TS parser for <script lang="ts"> blocks and plain .ts files.
        parser: tseslint.parser,
        ecmaVersion: 2022,
        sourceType: 'module',
        extraFileExtensions: ['.vue'],
        // Project-aware parsing — required for type-checked rules.
        project: './tsconfig.json',
        tsconfigRootDir: import.meta.dirname,
      },
    },
  },

  // Node-context config files use the node tsconfig.
  {
    files: ['*.config.ts', 'vite.config.ts'],
    languageOptions: {
      globals: {
        ...globals.node,
      },
      parserOptions: {
        project: './tsconfig.node.json',
        tsconfigRootDir: import.meta.dirname,
      },
    },
  },

  // Project-specific rule overrides.
  {
    files: ['src/**/*.{ts,vue}', '*.ts', '*.config.ts'],
    rules: {
      // Strict: never silently accept `any`.
      '@typescript-eslint/no-explicit-any': 'error',

      // Toasts / buttons legitimately console.log in dev; keep as a nudge, not a block.
      'no-console': 'warn',

      'prefer-const': 'error',

      // Unhandled promises are a frequent source of real bugs in async UI code.
      '@typescript-eslint/no-floating-promises': 'error',

      // Our primitives (Toggle, Toast, Select, ...) are single-word by design.
      'vue/multi-word-component-names': 'off',

      // Allow unused args prefixed with `_` (common in emit/prop handler stubs).
      '@typescript-eslint/no-unused-vars': [
        'error',
        {
          argsIgnorePattern: '^_',
          varsIgnorePattern: '^_',
          caughtErrorsIgnorePattern: '^_',
        },
      ],
    },
  },

  // 5. Prettier — MUST be last so it wins over stylistic rules from every
  // preset above.
  prettierConfig,
);
