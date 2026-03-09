const { test, expect } = require('@playwright/test');
const { runAdminRuntimeHelper, shouldRunAdminUiRuntime } = require('../helpers/admin-runtime');

async function loginAsAdmin(page) {
  await page.goto('/wp-login.php');

  if (await page.locator('#wpadminbar').count()) {
    return;
  }

  await page.locator('#user_login').fill(process.env.E2E_WP_ADMIN_USER || '');
  await page.locator('#user_pass').fill(process.env.E2E_WP_ADMIN_PASSWORD || '');
  await page.locator('#wp-submit').click();
  await expect(page.locator('#wpadminbar')).toBeVisible();
}

test.describe('Admin Settings Runtime', () => {
  test('saves settings, shows forms shortcode inventory, and loads setup wizard', async ({ page }) => {
    test.skip(!shouldRunAdminUiRuntime(), 'Runs only when E2E_ADMIN_UI=1.');
    test.skip(
      !process.env.E2E_WP_ADMIN_USER || !process.env.E2E_WP_ADMIN_PASSWORD,
      'Requires E2E_WP_ADMIN_USER and E2E_WP_ADMIN_PASSWORD.'
    );

    const backup = runAdminRuntimeHelper('backup');
    const orgName = `DonatePress Runtime ${Date.now()}`;
    const orgEmail = `runtime-${Date.now()}@example.com`;

    try {
      await loginAsAdmin(page);

      await page.goto('/wp-admin/admin.php?page=donatepress');
      await expect(page.getByRole('heading', { name: 'Settings & Configuration' })).toBeVisible();
      await expect(page.getByRole('tab', { name: 'Organization' })).toBeVisible();
      await expect(page.getByRole('tab', { name: 'Payments' })).toBeVisible();
      await expect(page.getByRole('tab', { name: 'v1 Product' })).toBeVisible();

      await page.locator('#dp-organization_name').fill(orgName);
      await page.locator('#dp-organization_email').fill(orgEmail);
      await page.getByRole('button', { name: 'Save Settings' }).click();
      await expect(page.locator('.notice.notice-success')).toContainText('Settings saved');
      await expect(page.locator('#dp-organization_name')).toHaveValue(orgName);
      await expect(page.locator('#dp-organization_email')).toHaveValue(orgEmail);

      await page.getByRole('tab', { name: 'Payments' }).click();
      await expect(page.locator('#dp-base_currency')).toBeVisible();

      await page.getByRole('tab', { name: 'v1 Product' }).click();
      await expect(page.locator('input[name="donatepress_settings[wc_enabled]"]')).toBeVisible();

      await page.goto('/wp-admin/admin.php?page=donatepress-forms');
      await expect(page.getByRole('heading', { name: 'Forms' })).toBeVisible();
      await expect(page.locator('table')).toContainText('Shortcode');
      await expect(page.locator('input[id^="dp-form-shortcode-"]').first()).toHaveValue(/\[donatepress_form id="\d+"\]/);

      await page.goto('/wp-admin/admin.php?page=donatepress-setup');
      await expect(page.getByRole('heading', { name: 'DonatePress Setup Wizard' })).toBeVisible();
      await expect(page.locator('#donatepress-setup-wizard-root')).toContainText('Setup Progress');
      await expect(page.locator('#donatepress-setup-wizard-root')).toContainText(/Open Settings|Manage Forms|Create First Form/);
    } finally {
      if (backup && backup.payload) {
        runAdminRuntimeHelper('restore', [backup.payload]);
      }
    }
  });
});
