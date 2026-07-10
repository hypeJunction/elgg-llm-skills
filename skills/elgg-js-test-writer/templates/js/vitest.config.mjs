import { defineConfig } from 'vitest/config';
import { fileURLToPath } from 'node:url';

// Absolute replacements. A relative string like './tests/js/mocks/i18n.mjs' is
// resolved against the importer rather than the project root and fails
// intermittently ("Failed to resolve import").
const mock = (p) => fileURLToPath(new URL(`./tests/js/mocks/${p}`, import.meta.url));

export default defineConfig({
  test: {
    environment: 'jsdom',
    include: ['tests/js/**/*.test.{ts,js,mjs}'],
    globals: true,
  },
  resolve: {
    // ARRAY form, MOST SPECIFIC FIRST. Vite/rollup alias matching is prefix-based
    // (`id === find || id.startsWith(find + '/')`) and takes the FIRST match, so an
    // object with a bare `'elgg'` key listed before `'elgg/Ajax'` rewrites
    // `elgg/Ajax` to `<elgg mock>/Ajax` and the import dies. The bare module is
    // matched with an exact regex so it can never swallow a submodule.
    alias: [
      { find: 'elgg/Ajax', replacement: mock('Ajax.mjs') },
      { find: 'elgg/hooks', replacement: mock('hooks.mjs') },
      { find: 'elgg/i18n', replacement: mock('i18n.mjs') },
      { find: 'jquery', replacement: mock('jquery.mjs') },
      { find: /^elgg$/, replacement: mock('elgg.mjs') },
    ],
  },
});
