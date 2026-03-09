const { test, expect } = require('@playwright/test');
const { runPortalRuntimeHelper, shouldRunPortalRuntime } = require('../helpers/portal-runtime');

const PORTAL_PAGE_PATH = process.env.E2E_PORTAL_PAGE_PATH || '/donor-portal/';

test.describe('Portal Runtime', () => {
  test('requests access, authenticates with real token, and uses dashboard actions', async ({ page }) => {
    test.skip(
      !shouldRunPortalRuntime(),
      'Runs only against real portal runtime (set E2E_MOCK_API=0 and E2E_PORTAL_RUNTIME=1).'
    );

    const fixture = runPortalRuntimeHelper('seed');

    try {
      await page.goto(PORTAL_PAGE_PATH, { waitUntil: 'domcontentloaded' });

      const root = page.locator('[data-dp-portal]');
      await expect(root).toBeVisible();

      await page.locator('form[data-dp-request-link] input[name="email"]').fill(fixture.email);
      await page.locator('form[data-dp-request-link] button[type="submit"]').click();
      await expect(page.locator('[data-dp-message]')).toContainText('portal link has been sent');
      await expect(page.locator('[data-dp-stage="auth"]')).toBeVisible();

      const magic = runPortalRuntimeHelper('magic', [fixture.email]);
      await page.locator('form[data-dp-auth] input[name="token"]').fill(magic.token);
      await page.locator('form[data-dp-auth] button[type="submit"]').click();

      await expect(page.locator('[data-dp-stage="dashboard"]')).toBeVisible();
      await expect(page.locator('[data-dp-profile]')).toContainText(fixture.email);
      await expect(page.locator('[data-dp-donations]')).toContainText('DP-PORTAL-');
      await expect(page.locator('[data-dp-subscriptions]')).toContainText('DPS-PORTAL-');
      await expect(page.locator('[data-dp-annual-summary]')).toContainText(String(new Date().getFullYear()));

      await page.locator('[data-dp-profile-form] input[name="first_name"]').fill('Updated');
      await page.locator('[data-dp-profile-form] button[type="submit"]').click();
      await expect(page.locator('[data-dp-message]')).toContainText('Profile updated');
      await expect(page.locator('[data-dp-profile]')).toContainText('Updated');

      await page.locator('[data-dp-sub-action="pause"]').first().click();
      await expect(page.locator('[data-dp-message]')).toContainText('Subscription updated');
      await expect(page.locator('[data-dp-subscriptions]')).toContainText('paused');

      await page.locator('[data-dp-logout]').click();
      await expect(page.locator('[data-dp-message]')).toContainText('Logged out');
      await expect(page.locator('[data-dp-stage="request"]')).toBeVisible();
    } finally {
      runPortalRuntimeHelper('cleanup', [fixture.email]);
    }
  });
});
