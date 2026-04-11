---
name: elgg-js-test-writer
description: >
  Use when writing JavaScript tests for Elgg plugins, testing AMD or ES modules,
  or setting up Vitest/Playwright for Elgg JS code.
---

# elgg-js-test-writer

> **Purpose:** Write JavaScript tests for Elgg plugin modules.
> **Usage:** `/elgg-js-test-writer <plugin-path> [--elgg-version=6.x]`

## Background

Elgg has **no built-in JS test framework**. Testing is entirely PHP-based.
Plugin JS must bring its own test setup. This skill provides that.

### JS Module System Per Version

| Elgg | System | File Extension | Load API |
|------|--------|---------------|----------|
| 2.x-5.x | RequireJS/AMD | `.js` | `elgg_require_js()`, `define()`, `require()` |
| 6.x | Native ES Modules | `.mjs` | `elgg_import_esm()`, `import`/`export` |

---

## Container Infrastructure

All JS test operations run inside Docker containers via the `node` service.

```bash
# Run Vitest (JS unit tests)
docker compose -f docker/elgg{N}/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugins/<plugin> && npm ci && npm run test:js"

# Run Vitest in watch mode
docker compose -f docker/elgg{N}/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugins/<plugin> && npm ci && npm run test:js:watch"

# Interactive shell for debugging
docker compose -f docker/elgg{N}/docker-compose.yml --profile test run --rm node bash

# Combined with Playwright (browser-level)
docker compose -f docker/elgg{N}/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugins/<plugin>/tests/playwright && npm ci && npx playwright test"
```

The `node` service uses the official Playwright Docker image (includes Node.js 20).
Plugin files are mounted at `/plugins/<plugin>` via the `PLUGINS_DIR` environment variable.

---

## Phase 1: SCAN PLUGIN JS

Inventory all JavaScript files in the plugin:

```bash
# Find all JS files
find <plugin>/views -name "*.js" -o -name "*.mjs" | sort

# Check for AMD modules (define/require pattern)
grep -rl "define(\|require(\[" <plugin>/views --include="*.js"

# Check for ES modules (import/export)
grep -rl "^import \|^export " <plugin>/views --include="*.mjs"

# Check for inline scripts in PHP views
grep -rl "<script" <plugin>/views --include="*.php"
```

For each JS file, identify:
- What it exports (functions, classes, objects)
- What it imports (dependencies)
- What DOM interactions it performs
- What Elgg APIs it uses (hooks, ajax, i18n)

---

## Phase 2: SET UP TEST FRAMEWORK

### For Elgg 6.x (ES Modules) — Use Vitest

```bash
docker compose -f docker/elgg{N}/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugins/<plugin> && npm init -y && npm install -D vitest jsdom"
```

**vitest.config.ts:**
```typescript
import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'jsdom',
    include: ['tests/js/**/*.test.{ts,js,mjs}'],
    globals: true,
  },
  resolve: {
    alias: {
      // Mock Elgg core modules that won't be available in test env
      'elgg': './tests/js/mocks/elgg.mjs',
      'elgg/Ajax': './tests/js/mocks/Ajax.mjs',
      'elgg/hooks': './tests/js/mocks/hooks.mjs',
      'elgg/i18n': './tests/js/mocks/i18n.mjs',
      'jquery': './tests/js/mocks/jquery.mjs',
    },
  },
});
```

**package.json scripts:**
```json
{
  "scripts": {
    "test:js": "vitest run",
    "test:js:watch": "vitest"
  }
}
```

### For Elgg 2.x-5.x (AMD Modules) — Use Vitest with AMD Shim

AMD modules need a shim since Vitest runs ES modules natively:

```bash
docker compose -f docker/elgg{N}/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugins/<plugin> && npm install -D vitest jsdom"
```

**tests/js/mocks/amd-shim.mjs:**
```javascript
// Minimal AMD define/require shim for testing
const modules = new Map();

export function define(name, deps, factory) {
  if (typeof name !== 'string') {
    factory = deps;
    deps = name;
    name = null;
  }
  if (!Array.isArray(deps)) {
    factory = deps;
    deps = [];
  }
  const resolved = deps.map(d => modules.get(d));
  const result = factory(...resolved);
  if (name) modules.set(name, result);
  return result;
}

export function require(deps, callback) {
  const resolved = deps.map(d => modules.get(d));
  return callback(...resolved);
}

// Pre-register core modules
modules.set('jquery', await import('./jquery.mjs').then(m => m.default));
modules.set('elgg', await import('./elgg.mjs').then(m => m.default));
```

---

## Phase 3: CREATE ELGG MOCKS

Elgg JS modules depend on the Elgg runtime. Mock the essentials:

### tests/js/mocks/elgg.mjs

```javascript
// Mock Elgg core module
export default {
  get_site_url: () => 'http://localhost:8380/',
  get_logged_in_user_guid: () => 1,
  echo: (key) => key,
  trigger_hook: (name, type, params, value) => value,
  register_hook_handler: () => {},
  security: {
    addToken: (data) => ({ ...data, __elgg_ts: '123', __elgg_token: 'abc' }),
  },
  session: {
    cookie: (name) => null,
  },
  config: {
    lastcache: Date.now(),
  },
};
```

### tests/js/mocks/Ajax.mjs

```javascript
// Mock Elgg Ajax module
export default class Ajax {
  constructor() {}

  async action(name, options = {}) {
    return { value: null, ...options.data };
  }

  async path(path, options = {}) {
    return {};
  }

  async view(view, options = {}) {
    return '<div>mock view</div>';
  }

  async form(action, options = {}) {
    return '<form>mock form</form>';
  }
}
```

### tests/js/mocks/hooks.mjs

```javascript
// Mock Elgg hooks module (6.x)
const handlers = new Map();

export function register(name, type, handler, priority = 500) {
  const key = `${name}:${type}`;
  if (!handlers.has(key)) handlers.set(key, []);
  handlers.get(key).push({ handler, priority });
}

export function trigger(name, type, params, value) {
  const key = `${name}:${type}`;
  const list = handlers.get(key) || [];
  list.sort((a, b) => a.priority - b.priority);
  for (const { handler } of list) {
    const result = handler(name, type, params, value);
    if (result !== undefined) value = result;
  }
  return value;
}

export function reset() {
  handlers.clear();
}
```

### tests/js/mocks/i18n.mjs

```javascript
// Mock Elgg i18n module
const translations = {};

export function echo(key, args = []) {
  let str = translations[key] || key;
  args.forEach((arg, i) => {
    str = str.replace(`%s`, arg);
  });
  return str;
}

export function addTranslation(lang, strings) {
  Object.assign(translations, strings);
}
```

### tests/js/mocks/jquery.mjs

```javascript
// Use jsdom's built-in document or a minimal jQuery mock
import { JSDOM } from 'jsdom';

const dom = new JSDOM('<!DOCTYPE html><html><body></body></html>');
const $ = (selector) => dom.window.document.querySelector(selector);
$.fn = {};
$.ajax = async () => ({});
$.extend = Object.assign;

export default $;
```

---

## Phase 4: WRITE TESTS

### Test categories

#### Pure Logic Tests (no DOM)

For utility functions, data transformers, validators:

```javascript
// tests/js/utils.test.mjs
import { describe, it, expect } from 'vitest';
import { formatDate, truncate } from '../../views/default/myplugin/utils.mjs';

describe('formatDate', () => {
  it('formats timestamps to readable dates', () => {
    const result = formatDate(1700000000);
    expect(result).toMatch(/\d{4}/);
  });
});

describe('truncate', () => {
  it('truncates long strings', () => {
    expect(truncate('hello world', 5)).toBe('hello...');
  });

  it('leaves short strings unchanged', () => {
    expect(truncate('hi', 5)).toBe('hi');
  });
});
```

#### Hook/Event Tests

For modules that register or trigger Elgg hooks:

```javascript
// tests/js/hooks.test.mjs
import { describe, it, expect, beforeEach } from 'vitest';
import * as hooks from '../mocks/hooks.mjs';

// Import the module under test
import { init } from '../../views/default/myplugin/init.mjs';

describe('myplugin hooks', () => {
  beforeEach(() => {
    hooks.reset();
  });

  it('registers a view hook', () => {
    init();
    const result = hooks.trigger('view', 'myplugin/widget', {}, '<div>original</div>');
    expect(result).toContain('enhanced');
  });
});
```

#### DOM Interaction Tests

For modules that manipulate the DOM:

```javascript
// tests/js/dropdown.test.mjs
import { describe, it, expect, beforeEach } from 'vitest';
import { JSDOM } from 'jsdom';

describe('dropdown', () => {
  let document;

  beforeEach(() => {
    const dom = new JSDOM(`
      <div class="elgg-menu">
        <li class="elgg-menu-item-dropdown">
          <a href="#">Menu</a>
          <ul class="elgg-child-menu" style="display:none">
            <li>Item 1</li>
          </ul>
        </li>
      </div>
    `);
    document = dom.window.document;
  });

  it('toggles child menu visibility on click', async () => {
    // Import and initialize with test document
    const { initDropdown } = await import('../../views/default/myplugin/dropdown.mjs');
    initDropdown(document);

    const trigger = document.querySelector('.elgg-menu-item-dropdown > a');
    trigger.click();

    const childMenu = document.querySelector('.elgg-child-menu');
    expect(childMenu.style.display).not.toBe('none');
  });
});
```

#### Ajax Tests

For modules that make API calls:

```javascript
// tests/js/api.test.mjs
import { describe, it, expect, vi } from 'vitest';
import Ajax from '../mocks/Ajax.mjs';

describe('wall post submission', () => {
  it('sends post data to wall/status action', async () => {
    const ajax = new Ajax();
    const spy = vi.spyOn(ajax, 'action');

    await ajax.action('wall/status', {
      data: { body: 'Hello world', container_guid: 123 },
    });

    expect(spy).toHaveBeenCalledWith('wall/status', expect.objectContaining({
      data: expect.objectContaining({ body: 'Hello world' }),
    }));
  });
});
```

---

## Phase 5: RUN TESTS

### In Docker

```bash
docker compose -f docker/elgg{N}/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugins/<plugin> && npm ci && npm run test:js"
```

### In CI (GitHub Actions)

Add to `.github/workflows/tests.yml`:

```yaml
  js-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
      - run: npm install
      - run: npm run test:js
```

### Combined with Playwright (browser-level)

For testing JS behavior in a real Elgg instance:

```bash
# Start Elgg in Docker
docker compose -f docker/elgg{N}/docker-compose.yml up -d

# Run Playwright tests inside Docker (shares network with Elgg + DB)
docker compose -f docker/elgg{N}/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugins/<plugin>/tests/playwright && npm ci && npx playwright test"
```

Playwright tests are better for:
- Form submission behavior
- AJAX interactions
- Dynamic UI updates
- Permission-dependent UI

Vitest is better for:
- Pure logic (no DOM/server needed)
- Individual module behavior
- Fast iteration (uses jsdom, no Elgg server needed — but still runs in the node container)

---

## Elgg Version Differences

### Elgg 2.x-5.x: AMD Modules

```javascript
// Module definition
define('myplugin/utils', ['elgg', 'jquery'], function(elgg, $) {
  return {
    greet: function(name) {
      return elgg.echo('greeting', [name]);
    }
  };
});

// Module usage
require(['myplugin/utils'], function(utils) {
  console.log(utils.greet('World'));
});
```

**Testing AMD:** Use the AMD shim mock, or refactor the module to be testable by extracting pure logic into separate functions.

### Elgg 6.x: ES Modules

```javascript
// views/default/myplugin/utils.mjs
import elgg from 'elgg';

export function greet(name) {
  return elgg.echo('greeting', [name]);
}
```

**Testing ESM:** Direct import in Vitest with Elgg mocks via aliases.

### Migration path for JS:

| Step | From | To |
|------|------|----|
| 2.x→5.x | AMD `define()`/`require()` | Same (but module names may change) |
| 5.x→6.x | AMD `define()`/`require()` | ES `import`/`export` + `.mjs` extension |

Key 6.x JS changes:
- `elgg_define_js()` → `elgg_register_esm()`
- `elgg_require_js()` → `elgg_import_esm()`
- `require(['module'], fn)` → `import module from 'module'`
- `define('name', [...], fn)` → `export function/class/default`

---

## File Templates

### Plugin test structure (Elgg 6.x)

```
<plugin>/
  views/default/
    myplugin/
      utils.mjs          # Module under test
      widget.mjs
  tests/
    js/
      mocks/
        elgg.mjs          # Elgg core mock
        Ajax.mjs          # Ajax mock
        hooks.mjs         # Hooks mock
        jquery.mjs        # jQuery mock
      utils.test.mjs      # Tests for utils.mjs
      widget.test.mjs     # Tests for widget.mjs
    phpunit/              # PHP tests (separate)
      ...
  vitest.config.ts
  package.json
```

### Coverage checklist

- [ ] Each exported function has at least one test
- [ ] Edge cases tested (empty input, null, undefined)
- [ ] Elgg hook interactions tested (register, trigger, return values)
- [ ] Ajax calls tested (action name, parameters, error handling)
- [ ] DOM manipulation tested (element creation, event binding, visibility)
- [ ] i18n tested (translation keys used correctly)
