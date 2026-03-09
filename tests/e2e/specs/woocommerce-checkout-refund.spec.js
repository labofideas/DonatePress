const { test, expect } = require('@playwright/test');
const { runWooRuntimeHelper, shouldRunWooCommerceUiRuntime } = require('../helpers/woo-runtime');

async function loginAsAdmin(page, loginUrl) {
  await page.goto(loginUrl);

  if (await page.locator('#wpadminbar').count()) {
    return;
  }

  await page.locator('#user_login').fill(process.env.E2E_WP_ADMIN_USER || '');
  await page.locator('#user_pass').fill(process.env.E2E_WP_ADMIN_PASSWORD || '');
  await page.locator('#wp-submit').click();
  await expect(page.locator('#wpadminbar')).toBeVisible();
}

function extractOrderId(url) {
  const match = String(url).match(/order-received\/(\d+)/);
  return match ? Number(match[1]) : 0;
}

test.describe('WooCommerce Checkout Runtime', () => {
  test('guest checkout syncs DonatePress donation and admin refund marks it refunded', async ({ page }) => {
    test.skip(
      !shouldRunWooCommerceUiRuntime(),
      'Runs only against real WooCommerce runtime (set E2E_MOCK_API=0 and E2E_WC_UI=1).'
    );
    test.skip(
      !process.env.E2E_WP_ADMIN_USER || !process.env.E2E_WP_ADMIN_PASSWORD,
      'Requires E2E_WP_ADMIN_USER and E2E_WP_ADMIN_PASSWORD.'
    );

    const fixture = runWooRuntimeHelper('seed');
    let orderId = 0;

    try {
      await page.goto(fixture.addToCartUrl, { waitUntil: 'domcontentloaded' });
      const checkoutLink = page.getByRole('link', { name: 'Proceed to checkout' });
      await expect(checkoutLink).toBeVisible();
      await checkoutLink.click();
      await page.waitForURL(/donatepress-e2e-checkout|checkout/, { timeout: 45_000 });

      await page.locator('#billing_first_name').fill('Woo');
      await page.locator('#billing_last_name').fill('Runtime');
      await page.locator('#billing_email').fill('woo-runtime-e2e@example.com');
      await page.locator('#billing_phone').fill('5550101');
      await page.locator('#billing_address_1').fill('123 Runtime Street');
      await page.locator('#billing_city').fill('Testville');
      await page.locator('#billing_postcode').fill('90001');

      const state = page.locator('#billing_state');
      if (await state.count()) {
        await state.selectOption({ value: 'CA' }).catch(async () => {
          await state.selectOption({ label: /California/i });
        });
      }

      const country = page.locator('#billing_country');
      if (await country.count()) {
        await country.selectOption({ value: 'US' }).catch(async () => {
          await country.selectOption({ label: /United States/i });
        });
      }

      if (await page.locator('#payment_method_cod').count()) {
        await page.locator('#payment_method_cod').check();
      } else if (await page.locator('#payment_method_bacs').count()) {
        await page.locator('#payment_method_bacs').check();
      }

      const terms = page.locator('#terms');
      if (await terms.count()) {
        await terms.check();
      }

      await page.locator('#place_order').click();
      await page.waitForURL(/order-received/, { timeout: 45_000 });
      await expect(page.getByRole('heading', { name: 'Order received' })).toBeVisible();
      await expect(page.locator('main')).toContainText('Thank you. Your order has been received.');

      orderId = extractOrderId(page.url());
      expect(orderId).toBeGreaterThan(0);

      await loginAsAdmin(page, fixture.loginUrl);
      await page.goto(fixture.adminOrderUrlTemplate.replace('%d', String(orderId)));

      const orderStatus = page.locator('#order_status');
      await expect(orderStatus).toBeVisible();
      await orderStatus.selectOption('wc-completed');
      await page.getByRole('button', { name: 'Update' }).first().click();
      await page.waitForLoadState('networkidle');

      await expect
        .poll(() => runWooRuntimeHelper('inspect', [String(orderId)]), { timeout: 45_000 })
        .toMatchObject({
          orderStatus: 'completed'
        });
      const completedSnapshot = runWooRuntimeHelper('inspect', [String(orderId)]);
      expect(completedSnapshot.donationCount).toBeGreaterThan(0);
      for (const donation of completedSnapshot.donations) {
        expect(donation.status).toBe('completed');
      }

      await page.goto(completedSnapshot.adminOrderUrl);
      await page.locator('.refund-items').click();
      await expect(page.locator('.wc-order-refund-items')).toBeVisible();
      await page.locator('#refund_amount').fill(String(fixture.amount));
      await page.locator('#refund_reason').fill('DonatePress runtime refund test');

      page.once('dialog', (dialog) => dialog.accept());
      await page.locator('.do-manual-refund').click();
      await expect(page.locator('tr.refund')).toBeVisible({ timeout: 45_000 });

      await expect
        .poll(() => runWooRuntimeHelper('inspect', [String(orderId)]), { timeout: 45_000 })
        .toMatchObject({
          totalRefunded: Number(fixture.amount)
        });
      const refundedState = runWooRuntimeHelper('inspect', [String(orderId)]);
      expect(refundedState.refundIds.length).toBeGreaterThan(0);
      expect(refundedState.totalRefunded).toBeGreaterThan(0);
      for (const donation of refundedState.donations) {
        expect(donation.status).toBe('refunded');
      }
    } finally {
      runWooRuntimeHelper('cleanup', orderId > 0 ? [String(orderId)] : []);
    }
  });
});
