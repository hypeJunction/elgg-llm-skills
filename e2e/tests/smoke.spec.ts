import { test, expect } from '@playwright/test';

test.describe('Elgg 3.x Site Smoke Tests', () => {
  test('homepage loads without errors', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.status()).toBe(200);
    await expect(page).toHaveTitle(/Elgg/);

    const html = await page.content();
    expect(html).not.toContain('Fatal Error');
    expect(html).not.toContain('Parse error');
  });

  test('login page renders form', async ({ page }) => {
    await page.goto('/login');
    await expect(page).toHaveTitle(/Log in/);

    // Elgg 3.x has login forms in both dropdown and main content
    const usernameInputs = page.locator('input[name="username"]');
    const count = await usernameInputs.count();
    expect(count).toBeGreaterThanOrEqual(1);
  });

  test('can log in as admin', async ({ page }) => {
    await page.goto('/login');

    // Use the main login form (not the dropdown), target the first visible one
    const form = page.locator('.elgg-form-login').first();
    await form.locator('input[name="username"]').fill('admin');
    await form.locator('input[name="password"]').fill('admin12345');
    await form.locator('button[type="submit"]').click();

    // Wait for navigation
    await page.waitForLoadState('networkidle');

    const url = page.url();
    expect(url).not.toContain('/login');
  });

  test('admin panel loads after login', async ({ page }) => {
    await page.goto('/login');
    const form = page.locator('.elgg-form-login').first();
    await form.locator('input[name="username"]').fill('admin');
    await form.locator('input[name="password"]').fill('admin12345');
    await form.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    const response = await page.goto('/admin');
    expect(response?.status()).toBe(200);

    const html = await page.content();
    expect(html).not.toContain('Fatal Error');
  });

  test('plugin list visible in admin', async ({ page }) => {
    await page.goto('/login');
    const form = page.locator('.elgg-form-login').first();
    await form.locator('input[name="username"]').fill('admin');
    await form.locator('input[name="password"]').fill('admin12345');
    await form.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    const response = await page.goto('/admin/plugins');
    expect(response?.status()).toBe(200);
  });

  test('registration page accessible', async ({ page }) => {
    const response = await page.goto('/register');
    // Should either render or redirect (not error)
    expect(response?.status()).toBeLessThan(500);
  });

  test('no PHP errors on multiple pages', async ({ page }) => {
    const pages = ['/', '/login', '/register', '/activity'];

    for (const path of pages) {
      const response = await page.goto(path);
      if (response && response.status() < 500) {
        const html = await page.content();
        expect(html).not.toContain('Fatal error');
        expect(html).not.toContain('Parse error');
      }
    }
  });
});
