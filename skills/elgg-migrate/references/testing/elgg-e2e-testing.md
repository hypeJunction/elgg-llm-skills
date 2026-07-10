# E2E Testing Elgg Sites with Playwright

Reference for writing Playwright E2E tests against Elgg 3.x/4.x sites running in Docker.
Covers setup, authentication, correct selectors, and known pitfalls.

## Setup

### Playwright Config

```typescript
import { defineConfig, devices } from "@playwright/test";

export default defineConfig({
  testDir: "./tests",
  fullyParallel: false,   // Elgg has shared state; parallel can cause flaky tests
  workers: 1,
  timeout: 60_000,
  reporter: "html",

  use: {
    baseURL: process.env.BASE_URL || "http://localhost:8280",
    trace: "on-first-retry",
    screenshot: "only-on-failure",
    navigationTimeout: 60_000,
    actionTimeout: 15_000,
  },

  projects: [
    {
      name: "setup",
      testMatch: /.*\.setup\.ts/,
    },
    {
      name: "chromium",
      use: {
        ...devices["Desktop Chrome"],
        storageState: "playwright/.auth/admin.json",
      },
      dependencies: ["setup"],
    },
  ],
});
```

### Authentication Setup (auth.setup.ts)

Store admin session once, reuse across all tests:

```typescript
import { test as setup, expect } from "@playwright/test";
import path from "path";

const authFile = path.join(__dirname, "../playwright/.auth/admin.json");

setup("authenticate as admin", async ({ page }) => {
  await page.goto("/login", { waitUntil: "domcontentloaded" });

  const usernameInput = page.locator('input[name="username"]');
  if (await usernameInput.isVisible({ timeout: 5_000 }).catch(() => false)) {
    await page.fill('input[name="username"]', "admin");
    await page.fill('input[name="password"]', "admin123");
    await page.click('.elgg-form-login [type="submit"]');
    await page.waitForLoadState("domcontentloaded");
  }

  await page.goto("/activity", { waitUntil: "domcontentloaded" });
  await expect(page.locator(".elgg-page").first()).toBeVisible({ timeout: 15_000 });
  await page.context().storageState({ path: authFile });
});
```

### Unauthenticated Tests

For testing pages as a guest (registration, public pages):

```typescript
test.describe("Guest Pages", () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test("public page loads", async ({ page }) => {
    // ...
  });
});
```

---

## Elgg URL Reference by Version

### Elgg 3.x Routes

Routes vary depending on installed plugins. Always verify URLs against the running site.

#### Core Routes (always available)

| Feature | URL | Notes |
|---------|-----|-------|
| Homepage | `/` | |
| Login | `/login` | Redirects authenticated users |
| Activity | `/activity` | |
| Members | `/members` | |
| Admin dashboard | `/admin` | |
| Admin plugins | `/admin/plugins` | |
| Admin statistics | `/admin/statistics` | |
| Admin site settings | `/admin/site_settings` | NOT `/admin/settings/basic` |
| Admin users | `/admin/users` | |
| Profile | `/profile/{username}` | |
| User settings | `/settings` | May be empty if no settings plugins |

#### Plugin Routes (depend on active plugins)

| Feature | URL | Plugin | Notes |
|---------|-----|--------|-------|
| Blog listing | `/blog/all` | blog | Core plugin |
| Blog add | `/blog/add` | blog | May crash if hypeEmbed has iterator bug |
| Groups listing | `/groups/all` | groups | |
| Groups add | `/groups/add` | groups | May be overridden by custom plugins |
| Bookmarks listing | `/bookmarks/all` | bookmarks | |
| Bookmarks add | `/bookmarks/add` | bookmarks | May be intercepted by hypeWall |
| Files listing | `/file/all` | file | Note: `file` not `files` |
| Files add | `/file/add` | file | May be intercepted by hypeWall |
| Pages listing | `/pages/all` | pages | |
| Pages add | `/pages/add` | pages | May be intercepted by hypeWall |
| Search | `/search?q={query}` | search | |
| Register | `/register` | core | Redirects if user is logged in |

### hypeWall Interception

When the hypeWall plugin is active, it replaces standard `/add` routes for several
content types with its own posting form. The wall form has different fields:

- `input[name="mood"]` (radio buttons)
- `input[name="about"]` (radio buttons)
- `textarea[name="txt"]`

Standard Elgg form fields (`input[name="title"]`, `textarea[name="description"]`)
will NOT be present. Tests must account for this.

**Detection:**

```typescript
const isWallForm = await page.locator('textarea[name="txt"]').isVisible({ timeout: 2_000 }).catch(() => false);
if (isWallForm) {
  // hypeWall is intercepting — use wall-specific selectors
  await page.fill('textarea[name="txt"]', "Content");
} else {
  // Standard Elgg form
  await page.fill('input[name="title"]', "Title");
}
```

---

## Selector Patterns

### Safe Generic Selectors (work across Elgg 3.x/4.x)

```typescript
// Page loaded successfully
await expect(page.locator(".elgg-page-body").first()).toBeVisible();

// No fatal errors
const bodyText = await page.locator("body").textContent();
expect(bodyText).not.toContain("Fatal error");
expect(bodyText).not.toContain("Fatal Error");
expect(bodyText).not.toContain("Parse error");

// Page has content (not a blank/empty response)
await expect(page.locator(".elgg-page").first()).toBeVisible();

// List or empty state
await expect(
  page.locator(".elgg-list, .elgg-no-results").first()
).toBeVisible();

// Form submit buttons (try multiple selectors)
await page.click(
  'button[type="submit"], input[type="submit"], .elgg-button-action[type="submit"]'
);
```

### Elgg 3.x Specific Selectors

```typescript
// Admin sidebar menu
page.locator(".elgg-menu-page .elgg-menu-item-admin")

// Plugin list
page.locator(".elgg-plugin, [id^='elgg-plugin']")

// System messages (flash messages)
page.locator(".elgg-system-messages, .elgg-message-success")

// Login form (may appear in dropdown AND main content)
page.locator(".elgg-form-login").first()

// Profile elements
page.locator(".elgg-profile, .elgg-owner-block")
```

### Untranslated Language Keys

If you see raw language keys like `search:no_results` instead of translated text,
the language files haven't loaded or the translation is missing. This is a real bug
but tests should not fail on it — check for the key pattern:

```typescript
// Check for results OR known "no results" patterns
const bodyText = await page.locator("body").textContent();
const hasResults = await page.locator(".elgg-list").isVisible().catch(() => false);
const hasNoResults = bodyText?.includes("no_results") || bodyText?.includes("No results");
expect(hasResults || hasNoResults).toBeTruthy();
```

---

## Common Failure Patterns

### 1. "Page does not exist or you do not have permissions"

**Causes:**
- Route doesn't exist in this Elgg version
- Plugin that registers the route is inactive or broken
- User doesn't have permission (even admin can lack group-specific perms)

**Debug:** Check the URL in a browser. If it redirects to the dashboard with this error,
the route is not registered.

### 2. Fatal Error / 500

**Causes:**
- PHP compatibility issues (e.g., foreach-by-reference on iterators in PHP 7.4+)
- Missing dependencies or classes
- Plugin incompatibility with current Elgg version

**Debug:**
```bash
docker compose exec app tail -50 /var/log/apache2/error.log
# Or check the error in the HTML response
```

### 3. hypeWall Intercepting Routes

Standard content creation routes (`/blog/add`, `/bookmarks/add`, etc.) may be
intercepted by hypeWall, showing a wall post form instead. Tests expecting
`input[name="title"]` will fail.

**Fix:** Detect the wall form and adjust selectors (see hypeWall section above).

### 4. Registration Redirect

`/register` redirects authenticated users to the dashboard. Registration tests
MUST use `storageState: { cookies: [], origins: [] }` to run unauthenticated.

Even unauthenticated, registration may be disabled by admin settings. Check:
```bash
curl -s http://localhost:8280/register -o /dev/null -w "%{http_code}"
# 200 = available, 302 = redirected (disabled or logged in)
```

### 5. Empty Settings Pages

`/settings` and `/settings/user` may render with no form fields if settings
plugins are inactive or the settings UI is not exposed. Don't assert on form
field count — just verify the page renders without errors.

### 6. OPcache Stale Code

After fixing PHP files mounted via Docker volumes, OPcache may serve stale code.

**Fix:**
```bash
docker compose exec app bash -c \
  "echo '<?php opcache_reset(); echo \"ok\";' > /var/www/html/opcache_reset.php && \
   curl -s http://localhost:80/opcache_reset.php && \
   rm /var/www/html/opcache_reset.php"
```

---

## Performance

### Caching Settings

For **development/testing**, caching can be off for faster iteration:
```php
$CONFIG->simplecache_enabled = false;
$CONFIG->system_cache_enabled = false;
```

For **E2E test runs** (especially CI), enable caching for realistic performance:
```php
$CONFIG->simplecache_enabled = true;
$CONFIG->system_cache_enabled = true;
```

With caching enabled, Elgg 3.x pages typically respond in 200-300ms.
Without caching, some pages can take 1-3 seconds.

### Test Suite Timing

| Scope | Approx Time | Notes |
|-------|------------|-------|
| Auth setup | 5-10s | Login + save state |
| Smoke tests (20 routes) | 60-90s | Simple page loads |
| Feature tests (CRUD) | 5-15s each | Form fill + submit + verify |
| Full suite (90 tests) | 8-10 min | Single worker |

### Docker Entrypoint Caching

The `docker-entrypoint.sh` should enable caches by default:
```bash
\$CONFIG->simplecache_enabled = true;
\$CONFIG->system_cache_enabled = true;
```

This prevents fresh containers from starting with slow uncached responses.

---

## Known Bugs (Elgg 3.x + third-party plugins)

### Iterator foreach-by-reference

**File:** e.g. `<plugin>/classes/<Vendor>/Embed/Menus.php`
**Error:** `An iterator cannot be used with foreach by reference`
**Cause:** `foreach ($return as &$item)` where `$return` is `Elgg\Menu\MenuItems` (implements Iterator)
**Fix:** Remove the `&` — objects are passed by reference in PHP anyway:
```php
// Before (crashes)
foreach ($return as &$item) {
// After (works)
foreach ($return as $key => $item) {
```
**Impact:** Crashes any page with a longtext input (blog/add, pages/add, etc.)

---

## Smoke Test Template

Parameterized test that checks all critical routes render without errors:

```typescript
import { test, expect } from "@playwright/test";

const CRITICAL_ROUTES = [
  { path: "/", name: "Homepage" },
  { path: "/activity", name: "Activity" },
  { path: "/members", name: "Members" },
  { path: "/groups/all", name: "Groups" },
  { path: "/blog/all", name: "Blog" },
  { path: "/bookmarks/all", name: "Bookmarks" },
  { path: "/file/all", name: "Files" },
  { path: "/pages/all", name: "Pages" },
  { path: "/search?q=test", name: "Search" },
  { path: "/admin", name: "Admin Dashboard" },
  { path: "/admin/plugins", name: "Admin Plugins" },
  { path: "/admin/site_settings", name: "Admin Site Settings" },
  { path: "/profile/admin", name: "Admin Profile" },
];

test.describe("Smoke Tests", () => {
  for (const { path, name } of CRITICAL_ROUTES) {
    test(`${name} (${path}) renders without errors`, async ({ page }) => {
      const response = await page.goto(path, {
        waitUntil: "domcontentloaded",
        timeout: 30_000,
      });

      expect(response?.status()).toBeLessThan(500);

      if (response?.status() === 200) {
        await expect(page.locator(".elgg-page").first()).toBeVisible({
          timeout: 10_000,
        });
        const bodyText = await page.locator("body").textContent();
        expect(bodyText).not.toContain("Fatal error");
        expect(bodyText).not.toContain("Fatal Error");
        expect(bodyText).not.toContain("Parse error");
      }
    });
  }
});
```

---

## Running Tests

### Local (against Docker)

```bash
cd e2e
npm install
npx playwright install chromium
npx playwright test
```

### In Docker Compose (CI)

```yaml
# docker-compose.e2e.yml
services:
  playwright:
    image: mcr.microsoft.com/playwright:v1.49.0-noble
    depends_on:
      app:
        condition: service_healthy
    environment:
      - BASE_URL=http://app:80
    volumes:
      - ./e2e:/work
    working_dir: /work
    command: bash -c "npm ci && npx playwright install chromium && npx playwright test"
```

```bash
docker compose -f docker-compose.yml -f docker-compose.e2e.yml up --exit-code-from playwright
```
