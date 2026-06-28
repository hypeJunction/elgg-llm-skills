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

## Skill layout (no shared docker stack)

This skill does not ship its own docker infrastructure. It assumes the
plugin under test already has a per-plugin test stack at
`<plugin>/docker/docker-compose.yml` — scaffolded by the
`elgg-test-writer` skill (which copies templates into the plugin repo).

Every docker command below is run **from the plugin root** and
references `docker/docker-compose.yml` relative to that root. If the
plugin does not yet have a `docker/` directory, run the deterministic
bootstrap script from the `elgg-test-writer` skill first:

```bash
<path-to-elgg-test-writer>/bin/scaffold-docker.sh
```

The script infers `PLUGIN_ID` and the Elgg major version from the
plugin's `composer.json` and writes the full docker stack under
`<plugin>/docker/`. See the `elgg-test-writer` SKILL.md for details.

Each plugin ends up with its own isolated stack (own containers,
volumes, network, and ports scoped to `${PLUGIN_ID}-elgg{N}`) — nothing
is shared between plugins, and this skill never touches anything
outside the plugin repository.

## Container Infrastructure

All JS test operations run inside Docker containers via the `node` service.

```bash
# Run Vitest (JS unit tests)
docker compose -f docker/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugin && npm ci && npm run test:js"

# Run Vitest in watch mode
docker compose -f docker/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugin && npm ci && npm run test:js:watch"

# Interactive shell for debugging
docker compose -f docker/docker-compose.yml --profile test run --rm node bash

# Combined with Playwright (browser-level)
docker compose -f docker/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugin/tests/playwright && npm ci && npx playwright test"
```

The `node` service uses the official Playwright Docker image (includes Node.js 20).
The plugin's source directory is mounted at `/plugin` inside the node container via the per-plugin `docker/docker-compose.yml`.

---

## Phase 1: SCAN PLUGIN FOR TEST TARGETS

Read three layers together — views, CSS, and JS — to understand what behaviors to test.

### 1a. JavaScript inventory

```bash
# Find all JS/MJS files
find <plugin>/views -name "*.js" -o -name "*.mjs" | sort

# AMD modules (define/require pattern)
grep -rl "define(\|require(\[" <plugin>/views --include="*.js"

# ES modules (import/export)
grep -rl "^import \|^export " <plugin>/views --include="*.mjs"

# Inline scripts in PHP views
grep -rl "<script" <plugin>/views --include="*.php"
```

For each JS file, note:
- What it **exports** (functions, classes, objects)
- What **DOM selectors** it binds to (classes, data-* attributes, element types)
- What **state transitions** it drives (CSS class adds/removes, show/hide, disabled)
- What **Elgg APIs** it calls (hooks, ajax actions, i18n keys)
- What **third-party libs** it wraps (Select2, Dropzone, Parsley, etc.)
- What **events** it fires or listens to (custom events, Elgg hooks)

### 1b. CSS state inventory

```bash
find <plugin>/views -name "*.css" -o -name "*.less" | sort
```

For each stylesheet, extract:
- **State classes** that JS toggles (`has-errors`, `success`, `loading`, `hidden`, `active`)
- **Data-driven rules** (attribute selectors like `[data-dz-*]`, `[data-src]`)
- **Transition/animation** rules (what visual feedback exists)

These classes are the observable test signals in Playwright.

### 1c. PHP view anatomy

```bash
find <plugin>/views/default -name "*.php" | sort
```

For each view file, identify:
- What **HTML structure** it renders (forms, inputs, containers, menus)
- What **data-* attributes** it sets (JS reads these for config)
- What **inline `<script>` tags** bootstrap JS modules
- What **Elgg view helpers** it uses (`elgg_view_form`, `elgg_view_menu`, `elgg_view_field`, `elgg_view_module`)

### 1d. Build the test target map

Before writing any test, produce a table:

| File | What it does | Observable signals |
|------|-------------|-------------------|
| `views/default/myplugin/form.php` | Renders upload form with `data-max-size` | Form element with `data-max-size` attr |
| `views/default/myplugin/upload.js` | Initializes Dropzone on `.elgg-dropzone` | `.elgg-dropzone-success` class after upload |
| `views/default/myplugin/styles.css` | `.has-errors` turns label orange | Label color computed style |

This map drives Phase 4 (Vitest) and Phase 5 (Playwright) test targets.

---

## Phase 2: SET UP TEST FRAMEWORK

### For Elgg 6.x (ES Modules) — Use Vitest

```bash
docker compose -f docker/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugin && npm init -y && npm install -D vitest jsdom"
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
docker compose -f docker/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugin && npm install -D vitest jsdom"
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
docker compose -f docker/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugin && npm ci && npm run test:js"
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

```bash
# Start Elgg in Docker
docker compose -f docker/docker-compose.yml up -d

# Run Playwright tests inside Docker (shares network with Elgg + DB)
docker compose -f docker/docker-compose.yml --profile test run --rm node sh -c \
  "cd /plugin/tests/playwright && npm ci && npx playwright test"
```

**Decision guide — Vitest vs Playwright:**

| Use Vitest for | Use Playwright for |
|---------------|--------------------|
| Pure functions, data transforms | AJAX form submissions hitting real Elgg actions |
| Hook registration/triggering | CSS state class transitions after user interaction |
| Individual module logic | Third-party library integration (Dropzone, Select2, Parsley) |
| Fast iteration (no Elgg server) | Permission-dependent UI (logged in vs. out) |
| AMD/ESM import wiring | Multi-step workflows (upload → progress → success) |

---

## Phase 5b: PLAYWRIGHT TESTS — BEHAVIORAL PATTERNS

**Setup:** `tests/playwright/playwright.config.ts`

```typescript
import { defineConfig } from '@playwright/test';

export default defineConfig({
  use: {
    baseURL: 'http://elgg:8080',  // Elgg container hostname in Docker network
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  testDir: './tests',
  timeout: 30_000,
});
```

**Shared login helper:** `tests/playwright/helpers/login.ts`

```typescript
import { Page } from '@playwright/test';

export async function loginAsAdmin(page: Page) {
  await page.goto('/login');
  await page.fill('input[name="username"]', 'admin');
  await page.fill('input[name="password"]', 'password');
  await page.click('[type=submit]');
  await page.waitForURL(/\/dashboard/);
}
```

---

### Pattern 0: Console / pageerror smoke gate (MANDATORY for every page type)

This is the single most important JS test and the one most often missing or inert.
HTTP-status gates (curl, render golden-master) return **200 even when JS throws** —
an Elgg 7 importmap specifier that fails to resolve, or a `jQuery is not defined`,
aborts the module in the browser while the page still serves. A smoke spec that only
asserts the page loaded (or that never reads the console) catches none of it.

Every page type (home, listing, profile, profile-edit, group, admin, each form) gets
one spec that captures **both** `console` errors and uncaught `pageerror`, waits for
deferred modules to run, and asserts the real-error set is empty against an explicit
allow-list of known-benign external noise.

```typescript
// tests/playwright/tests/smoke.spec.ts
import { test, expect } from '@playwright/test';

// Known-benign noise NOT caused by the migration (external tags, PWA-manifest-under-
// basic-auth, blocked third-party requests). Keep this list short and justified.
const IGNORE = [
  /www\.googletagmanager\.com/, /web-share/, /www-widgetapi/, /youtube/,
  /1Password/, /ERR_BLOCKED_BY_CLIENT/, /manifest\.json.*40[13]/, /net::ERR_/,
];

for (const path of ['/', '/activity', '/members', '/groups/all', '/blog/all']) {
  test(`no console/page errors on ${path}`, async ({ page }) => {
    const errors: string[] = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));        // uncaught throws / module-resolution

    await page.goto(path);
    await page.waitForTimeout(1500);                            // let deferred ES modules execute

    const real = errors.filter((e) => !IGNORE.some((re) => re.test(e)));
    expect(real, `console/page errors on ${path}:\n${real.join('\n')}`).toEqual([]);
  });
}
```

Gotchas that make this gate silently pass when it shouldn't:
- **Forgetting `pageerror`.** "Failed to resolve module specifier …" arrives as a
  `pageerror`, not always a `console` message. Capture both.
- **No wait.** ES modules are deferred; assert after a `waitForTimeout`/`waitForLoadState`
  or the page reports clean before any module ran.
- **Over-broad allow-list.** A regex like `/.*/` or `/elgg/` neuters the gate. Each
  entry must name a specific external/benign source.
- **Walled-garden sites** 404 most content anonymously — log in (helper below) so the
  authenticated page types are actually exercised.

---

### Pattern A: State class transitions

When JS adds/removes CSS classes in response to events, test the class directly.

```typescript
// tests/playwright/tests/validation.spec.ts
import { test, expect } from '@playwright/test';
import { loginAsAdmin } from '../helpers/login';

test('shows inline error when required field is empty', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/path/to/form-page');

  // Submit without filling required field
  await page.click('[type=submit]');

  // Field row should have error class
  const field = page.locator('.elgg-field').filter({ has: page.locator('[name="title"]') });
  await expect(field).toHaveClass(/elgg-field-has-errors/);

  // Error message list should be visible
  await expect(field.locator('.elgg-field-feedback')).toBeVisible();
  await expect(field.locator('.elgg-field-feedback li')).toContainText(['required']);
});

test('clears error class when field is corrected', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/path/to/form-page');

  await page.click('[type=submit]');
  const field = page.locator('.elgg-field').filter({ has: page.locator('[name="title"]') });
  await expect(field).toHaveClass(/elgg-field-has-errors/);

  await page.fill('[name="title"]', 'Valid title');
  await page.locator('[name="title"]').blur();
  await expect(field).not.toHaveClass(/elgg-field-has-errors/);
});
```

---

### Pattern B: AJAX form submission lifecycle

For plugins that intercept form submit with JS (e.g. hypeajax-style forms):

```typescript
// tests/playwright/tests/ajax-form.spec.ts
import { test, expect } from '@playwright/test';

test('submit button is disabled during AJAX request', async ({ page }) => {
  await page.goto('/path/to/ajax-form');

  // Intercept the action to delay response
  await page.route('**/action/myplugin/save', async (route) => {
    await new Promise(r => setTimeout(r, 500));
    await route.fulfill({ json: { status: 0, value: {} } });
  });

  const btn = page.locator('[type=submit]');
  await page.fill('[name="body"]', 'Test content');
  await btn.click();

  // Button should be disabled mid-flight
  await expect(btn).toBeDisabled();

  // After response, button re-enables
  await expect(btn).toBeEnabled({ timeout: 2000 });
});

test('success callback runs and updates UI', async ({ page }) => {
  await page.goto('/path/to/ajax-form');

  await page.route('**/action/myplugin/save', (route) =>
    route.fulfill({ json: { status: 0, value: { guid: 42 } } })
  );

  await page.fill('[name="body"]', 'Hello world');
  await page.click('[type=submit]');

  // Expect success feedback (class, message, or redirect)
  await expect(page.locator('.elgg-system-messages')).toContainText('saved');
});

test('error response shows system error message', async ({ page }) => {
  await page.goto('/path/to/ajax-form');

  await page.route('**/action/myplugin/save', (route) =>
    route.fulfill({ json: { status: -1, messages: { errors: ['Permission denied'] } } })
  );

  await page.fill('[name="body"]', 'Hello');
  await page.click('[type=submit]');

  await expect(page.locator('.elgg-system-messages')).toContainText('Permission denied');
});
```

---

### Pattern C: File upload with progress

For plugins using Dropzone or similar:

```typescript
// tests/playwright/tests/file-upload.spec.ts
import { test, expect } from '@playwright/test';
import path from 'path';

test('file upload shows progress then success class', async ({ page }) => {
  await page.goto('/path/to/upload-page');

  // Set input file (works even with hidden inputs when Dropzone is active)
  const dropzone = page.locator('.elgg-dropzone');
  await expect(dropzone).toBeVisible();

  // Use setInputFiles on the hidden fallback input
  await page.setInputFiles('input[type=file]', path.join(__dirname, '../fixtures/test-image.jpg'));

  // Progress bar should appear
  const preview = page.locator('.dz-preview').first();
  await expect(preview).toBeVisible();

  // Wait for success class (Dropzone adds this after server confirms)
  await expect(preview).toHaveClass(/elgg-dropzone-success/, { timeout: 10_000 });

  // File name should appear in preview
  await expect(preview.locator('.dz-filename')).toContainText('test-image');
});

test('failed upload shows error class and message', async ({ page }) => {
  await page.route('**/action/dropzone/**', (route) =>
    route.fulfill({ status: 500, body: 'Server error' })
  );

  await page.goto('/path/to/upload-page');
  await page.setInputFiles('input[type=file]', path.join(__dirname, '../fixtures/test-image.jpg'));

  const preview = page.locator('.dz-preview').first();
  await expect(preview).toHaveClass(/elgg-dropzone-error/, { timeout: 5_000 });
  await expect(preview.locator('.dz-error-message')).toBeVisible();
});

test('remove button deletes the file', async ({ page }) => {
  await page.goto('/path/to/upload-page');
  await page.setInputFiles('input[type=file]', path.join(__dirname, '../fixtures/test-image.jpg'));

  const preview = page.locator('.dz-preview').first();
  await expect(preview).toHaveClass(/elgg-dropzone-success/, { timeout: 10_000 });

  // Click remove
  await preview.locator('.dz-remove').click();
  await expect(preview).not.toBeAttached();
});
```

---

### Pattern D: Dynamic content loading (placeholders / lazy views)

For plugins that render `data-src` placeholders and load content via AJAX:

```typescript
// tests/playwright/tests/placeholder.spec.ts
import { test, expect } from '@playwright/test';

test('placeholder loads deferred view content', async ({ page }) => {
  await page.goto('/path/to/page-with-placeholder');

  // Initially shows a loading spinner or nothing
  const placeholder = page.locator('[data-src*="_deferred/"]').first();
  await expect(placeholder).toBeVisible();

  // Wait for content to load (JS replaces placeholder with real HTML)
  await expect(placeholder).not.toHaveAttribute('data-src', { timeout: 5_000 });

  // Actual content should be present
  await expect(page.locator('.myplugin-widget-content')).toBeVisible();
});

test('deferred view does not make duplicate requests', async ({ page }) => {
  const requests: string[] = [];
  page.on('request', (req) => {
    if (req.url().includes('_deferred')) requests.push(req.url());
  });

  await page.goto('/path/to/page-with-placeholder');
  await page.waitForTimeout(2000);

  // Count unique deferred URLs — should load each only once
  const unique = new Set(requests);
  expect(requests.length).toBe(unique.size);
});
```

---

### Pattern E: Autocomplete / select2 inputs

For plugins that enhance `<select>` elements with Select2 or similar:

```typescript
// tests/playwright/tests/autocomplete.spec.ts
import { test, expect } from '@playwright/test';

test('typing in autocomplete fetches and shows results', async ({ page }) => {
  await page.goto('/path/to/form-with-autocomplete');

  // Click the Select2 container to open
  await page.click('.select2-container');

  // Type in the search field that Select2 creates
  await page.fill('.select2-search__field', 'admin');

  // Mock or wait for AJAX results
  await expect(page.locator('.select2-results__option')).toHaveCount({ minimum: 1 }, { timeout: 3_000 });

  // Select first result
  await page.locator('.select2-results__option').first().click();

  // The underlying <select> should have the value set
  const selectValue = await page.locator('select[name="user_guid"]').inputValue();
  expect(selectValue).not.toBe('');
});

test('selected item renders with icon if provided', async ({ page }) => {
  await page.route('**/path/to/autocomplete/source*', (route) =>
    route.fulfill({
      json: [{ id: 1, text: 'Admin User', icon: '/path/to/avatar.jpg' }]
    })
  );

  await page.goto('/path/to/form-with-autocomplete');
  await page.click('.select2-container');
  await page.fill('.select2-search__field', 'Ad');
  await page.locator('.select2-results__option').first().click();

  // Rendered selection should contain an image
  await expect(page.locator('.select2-selection .select-img')).toBeVisible();
});
```

---

### Pattern F: Toggle visibility / show-hide UI

For plugins with toggler buttons (attachments, collapsible panels, drawers):

```typescript
// tests/playwright/tests/toggle.spec.ts
import { test, expect } from '@playwright/test';

test('attachments panel is hidden by default', async ({ page }) => {
  await page.goto('/path/to/post-form');
  await expect(page.locator('.attachments-form')).toBeHidden();
});

test('toggler button shows the attachments panel', async ({ page }) => {
  await page.goto('/path/to/post-form');
  await page.click('.attachments-toggler');
  await expect(page.locator('.attachments-form')).toBeVisible();
});

test('form reset hides the attachments panel again', async ({ page }) => {
  await page.goto('/path/to/post-form');
  await page.click('.attachments-toggler');
  await expect(page.locator('.attachments-form')).toBeVisible();

  await page.click('[type=reset]');
  await expect(page.locator('.attachments-form')).toBeHidden();
});
```

---

### Pattern G: Permission-dependent UI

For plugins that show/hide UI based on login state or capabilities:

```typescript
// tests/playwright/tests/permissions.spec.ts
import { test, expect } from '@playwright/test';
import { loginAsAdmin } from '../helpers/login';

test('action buttons hidden for guests', async ({ page }) => {
  // No login — guest session
  await page.goto('/path/to/content');
  await expect(page.locator('.elgg-menu-entity .elgg-menu-item-edit')).not.toBeVisible();
});

test('edit button visible for content owner', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/path/to/content');
  await expect(page.locator('.elgg-menu-entity .elgg-menu-item-edit')).toBeVisible();
});
```

---

### Pattern H: Elgg system messages

Every AJAX action in Elgg surfaces feedback through `elgg.system_message()` / `elgg.register_error()`. Test these for any action-based workflow:

```typescript
// Check for success system message
await expect(page.locator('.elgg-system-messages .elgg-message-success')).toBeVisible({ timeout: 5_000 });

// Check for error system message
await expect(page.locator('.elgg-system-messages .elgg-message-error')).toBeVisible({ timeout: 5_000 });
```

---

### Playwright test file structure

```
<plugin>/
  tests/
    playwright/
      playwright.config.ts
      package.json          # { "devDependencies": { "@playwright/test": "^1.x" } }
      helpers/
        login.ts            # loginAsAdmin(), loginAsUser()
      fixtures/
        test-image.jpg      # Small test file for upload tests
        test-doc.pdf
      tests/
        validation.spec.ts  # Form validation state classes
        ajax-form.spec.ts   # AJAX submit lifecycle
        file-upload.spec.ts # Dropzone/upload flows
        toggle.spec.ts      # Show/hide UI panels
        permissions.spec.ts # Guest vs owner visibility
```

### Playwright test coverage checklist

- [ ] Every CSS state class that JS toggles is tested (success, error, loading, active, hidden)
- [ ] Every AJAX action has: pending state, success path, error path
- [ ] Every toggler has: initial state, after click, after reset
- [ ] File upload has: progress, success class, error class, removal
- [ ] Autocomplete has: typing triggers results, selection updates hidden input
- [ ] Permission-gated UI tested as both guest and authenticated user
- [ ] System messages tested for all action outcomes
- [ ] `data-*` driven initialization tested (element has attribute → JS activates)

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

**Vitest (unit):**
- [ ] Each exported function has at least one test
- [ ] Edge cases tested (empty input, null, undefined)
- [ ] Elgg hook interactions tested (register, trigger, return values)
- [ ] Ajax calls tested (action name, parameters, error handling)
- [ ] DOM manipulation tested (element creation, event binding, visibility)
- [ ] i18n tested (translation keys used correctly)

**Playwright (behavioral):**
- [ ] Every CSS state class that JS toggles is tested (see Phase 5b checklist)
- [ ] Every AJAX action: pending → success → error
- [ ] Every toggle/show-hide: initial state, after click, after reset
- [ ] File upload flows: progress, success, error, removal
- [ ] Permission-gated UI: guest vs authenticated
- [ ] System messages verified for all action outcomes
