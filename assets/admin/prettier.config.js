// Prettier config for the Fanxie WP Core admin SPA.
// Kept intentionally minimal — any style rule that also exists in ESLint is
// disabled there via eslint-config-prettier so Prettier owns formatting.

/** @type {import('prettier').Config} */
export default {
  semi: true,
  singleQuote: true,
  trailingComma: 'all',
  printWidth: 80,
  tabWidth: 2,
  arrowParens: 'always',
  endOfLine: 'lf',
  vueIndentScriptAndStyle: false,
};
