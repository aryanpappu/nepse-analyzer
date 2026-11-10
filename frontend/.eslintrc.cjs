/* eslint-env node */

/*
 * ESLint 8 (flat config arrived in 9), matching the plugins already in
 * devDependencies. `npm run lint` was in package.json from the start but no
 * config file existed, so it has never actually run.
 */
module.exports = {
  root: true,
  env: { browser: true, es2020: true },
  extends: [
    'eslint:recommended',
    'plugin:@typescript-eslint/recommended',
    'plugin:react-hooks/recommended',
  ],
  parser: '@typescript-eslint/parser',
  parserOptions: { ecmaVersion: 'latest', sourceType: 'module' },
  plugins: ['@typescript-eslint', 'react-refresh'],
  ignorePatterns: ['dist', 'node_modules', '.eslintrc.cjs', 'vite.config.ts', '*.config.js'],
  rules: {
    'react-refresh/only-export-components': ['warn', { allowConstantExport: true }],
    // tsc already reports genuinely unused code; this rule's value here is
    // catching an unused *parameter*, and `_`-prefixed ones are intentional.
    '@typescript-eslint/no-unused-vars': ['warn', {
      argsIgnorePattern: '^_',
      varsIgnorePattern: '^_',
    }],
  },
  overrides: [
    { files: ['playwright.config.ts', 'tests/**/*.ts'], env: { node: true } },
    {
      /*
       * A context file exporting its provider component alongside its `use…`
       * hook is the idiomatic React pattern, and splitting them to satisfy a
       * hot-reload heuristic would make the code worse. Same for a chart module
       * that exports its shared config next to the component. Correctness rules
       * still apply to these files — only the HMR hint is off.
       */
      files: ['src/lib/*.tsx', 'src/components/PriceChart.tsx'],
      rules: { 'react-refresh/only-export-components': 'off' },
    },
  ],
}
