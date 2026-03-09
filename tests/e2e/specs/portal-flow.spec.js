const { test, expect } = require('@playwright/test');
const { setupPortalMocks } = require('../helpers/api-mocks');

const PORTAL_PAGE_PATH = process.env.E2E_PORTAL_PAGE_PATH || '/donor-portal/';

async function ensurePortalFixture(page) {
  const root = page.locator('[data-dp-portal]');
  if (await root.count()) {
    return;
  }

  await page.setContent(
    [
      '<div class="donatepress-portal" data-dp-portal data-rest-base="/wp-json/donatepress/v1/portal" data-dp-nonce="e2e_nonce">',
      '  <div class="dp-portal-card" data-dp-stage="request">',
      '    <form data-dp-request-link>',
      '      <input type="email" name="email" required />',
      '      <button type="submit">Send Link</button>',
      '    </form>',
      '  </div>',
      '  <div class="dp-portal-card" data-dp-stage="auth">',
      '    <form data-dp-auth>',
      '      <input type="text" name="token" required />',
      '      <button type="submit">Sign In</button>',
      '    </form>',
      '  </div>',
      '  <div class="dp-portal-card" data-dp-stage="dashboard" hidden>',
      '    <div data-dp-profile></div>',
      '    <div data-dp-donations></div>',
      '    <div data-dp-subscriptions></div>',
      '    <div data-dp-annual-summary></div>',
      '    <button type="button" data-dp-logout>Logout</button>',
      '  </div>',
      '  <p data-dp-message></p>',
      '</div>'
    ].join('\n')
  );
  await page.addScriptTag({ path: 'assets/frontend/portal.js' });
}

test.describe('Donor Portal', () => {
  test('authenticates and renders dashboard panels', async ({ page }) => {
    await setupPortalMocks(page);
    await page.goto(PORTAL_PAGE_PATH);
    await ensurePortalFixture(page);

    const root = page.locator('[data-dp-portal]');
    await expect(root).toBeVisible();

    await page.locator('form[data-dp-request-link] input[name="email"]').fill('donor@example.com');
    await page.locator('form[data-dp-request-link] button[type="submit"]').click();
    await expect(page.locator('[data-dp-message]')).toContainText('portal link has been sent');

    await page.locator('form[data-dp-auth] input[name="token"]').fill('token_from_email');
    await page.locator('form[data-dp-auth] button[type="submit"]').click();
    await expect(page.locator('[data-dp-stage="dashboard"]')).toBeVisible();

    await expect(page.locator('[data-dp-profile]')).toContainText('donor@example.com');
    await expect(page.locator('[data-dp-donations]')).toContainText('DP-501');
    await expect(page.locator('[data-dp-subscriptions]')).toContainText('DPS-601');
    await expect(page.locator('[data-dp-annual-summary]')).toContainText('2026');

    await page.locator('[data-dp-profile-form] input[name="first_name"]').fill('Updated');
    await page.locator('[data-dp-profile-form] button[type="submit"]').click();
    await expect(page.locator('[data-dp-message]')).toContainText('Profile updated');
  });

  test('auto-authenticates when opened from emailed token link', async ({ page }) => {
    await setupPortalMocks(page);
    await page.goto(PORTAL_PAGE_PATH + '?dp_token=token_from_email');
    await ensurePortalFixture(page);

    await expect(page.locator('[data-dp-stage="dashboard"]')).toBeVisible();
    await expect(page.locator('[data-dp-profile]')).toContainText('donor@example.com');

    await expect
      .poll(async () => page.evaluate(() => window.location.search))
      .toBe('');
  });

  test('supports subscription action and logout', async ({ page }) => {
    await setupPortalMocks(page);
    await page.goto(PORTAL_PAGE_PATH);
    await ensurePortalFixture(page);

    await page.locator('form[data-dp-request-link] input[name="email"]').fill('donor@example.com');
    await page.locator('form[data-dp-request-link] button[type="submit"]').click();
    await page.locator('form[data-dp-auth] input[name="token"]').fill('token_from_email');
    await page.locator('form[data-dp-auth] button[type="submit"]').click();
    await expect(page.locator('[data-dp-stage="dashboard"]')).toBeVisible();

    await page.locator('[data-dp-sub-action="pause"]').first().click();
    await expect(page.locator('[data-dp-message]')).toContainText('Subscription updated');

    await page.locator('[data-dp-logout]').click();
    await expect(page.locator('[data-dp-message]')).toContainText('Logged out');
    await expect(page.locator('[data-dp-stage="request"]')).toBeVisible();
  });
});
