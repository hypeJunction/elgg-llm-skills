// Mock of Elgg's `elgg/i18n` module.
//
// On Elgg 7.x the real module has a DEFAULT export only, so a module under test
// written as `import i18n from 'elgg/i18n'` receives `undefined` from a mock that
// exports named bindings alone — and every call on it throws. Both shapes are
// provided here: the default export for 7.x, the named exports for 6.x callers
// written as `import { echo } from 'elgg/i18n'`.
const translations = {};

export function echo(key, args = []) {
  let str = translations[key] || key;
  args.forEach((arg) => {
    str = str.replace('%s', arg);
  });
  return str;
}

export function addTranslation(lang, strings) {
  Object.assign(translations, strings);
}

/** Test helper: drop all registered translations between tests. */
export function __reset() {
  Object.keys(translations).forEach((k) => delete translations[k]);
}

export default { echo, addTranslation };
