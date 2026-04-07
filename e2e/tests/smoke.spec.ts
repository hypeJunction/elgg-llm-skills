import { test, expect } from '@playwright/test';

/**
 * Generic Elgg site smoke tests.
 *
 * These verify that critical routes render without PHP fatal errors.
 * They work with any Elgg 3.x/4.x site — no plugin-specific assertions.
 *
 * Usage:
 *   BASE_URL=http://localhost:8280 npx playwright test
 *
 * See: references/testing/elgg-e2e-testing.md for selector patterns and pitfalls.
 */

// Routes that should be accessible without authentication
const PUBLIC_ROUTES = [
  { path: '/', name: 'Homepage' },
  { path: '/login', name: 'Login' },
  { path: '/activity', name: 'Activity' },
  { path: '/members', name: 'Members' },
];

// Routes that require admin authentication
const ADMIN_ROUTES = [
  { path: '/admin', name: 'Admin Dashboard' },
  { path: '/admin/plugins', name: 'Admin Plugins' },
  { path: '/admin/site_settings', name: 'Admin Site Settings' },
  { path: '/admin/statistics', name: 'Admin Statistics' },
];

test.describe('Public Smoke Tests', () => {
  for (const { path, name } of PUBLIC_ROUTES) {
    test(`${name} (${path}) loads without errors`, async ({ page }) => {
      const response = await page.goto(path);
      expect(response?.status()).toBeLessThan(500);

      const html = await page.content();
      expect(html).not.toContain('Fatal Error');
      expect(html).not.toContain('Fatal error');
      expect(html).not.toContain('Parse error');
    });
  }
});

test.describe('Admin Smoke Tests', () => {
  test('can log in as admin', async ({ page }) => {
    await page.goto('/login');

    const form = page.locator('.elgg-form-login').first();
    await form.locator('input[name="username"]').fill(
      process.env.ELGG_ADMIN_USER || 'admin'
    );
    await form.locator('input[name="password"]').fill(
      process.env.ELGG_ADMIN_PASS || 'admin123'
    );
    await form.locator('[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');

    const url = page.url();
    expect(url).not.toContain('/login');
  });

  // Admin route tests login inline (no storageState dependency)
  for (const { path, name } of ADMIN_ROUTES) {
    test(`${name} (${path}) loads after login`, async ({ page }) => {
      // Login first
      await page.goto('/login');
      const form = page.locator('.elgg-form-login').first();
      await form.locator('input[name="username"]').fill(
        process.env.ELGG_ADMIN_USER || 'admin'
      );
      await form.locator('input[name="password"]').fill(
        process.env.ELGG_ADMIN_PASS || 'admin123'
      );
      await form.locator('[type="submit"]').click();
      await page.waitForLoadState('domcontentloaded');

      // Navigate to admin page
      const response = await page.goto(path);
      expect(response?.status()).toBeLessThan(500);

      const html = await page.content();
      expect(html).not.toContain('Fatal Error');
      expect(html).not.toContain('Fatal error');
      expect(html).not.toContain('Parse error');
    });
  }
});
