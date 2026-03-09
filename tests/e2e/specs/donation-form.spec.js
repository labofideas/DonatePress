const { test, expect } = require('@playwright/test');
const { setupDonationSubmitMock, setupDonationSubmitPaymentFailureMock } = require('../helpers/api-mocks');

const DONATION_PAGE_PATH = process.env.E2E_DONATION_PAGE_PATH || '/donate/';

async function fillDonationForm(form, values) {
  const isMultiStep = (await form.getAttribute('data-dp-multistep')) === '1';

  const amountInput = form.locator('input[name="amount"]');
  await expect(amountInput).toBeVisible();
  await amountInput.fill(String(values.amount));

  if (!isMultiStep) {
    await form.locator('input[name="first_name"]').fill(values.firstName);
    await form.locator('input[name="last_name"]').fill(values.lastName);
    await form.locator('input[name="email"]').fill(values.email);
  } else {
    await form.locator('[data-dp-next]').click();
    await expect(form.locator('input[name="first_name"]')).toBeVisible();
    await form.locator('input[name="first_name"]').fill(values.firstName);
    await form.locator('input[name="last_name"]').fill(values.lastName);
    await form.locator('input[name="email"]').fill(values.email);
    await form.locator('[data-dp-next]').click();
  }

  if (values.recurring) {
    await expect(form.locator('input[name="is_recurring"]')).toBeVisible();
    await form.locator('input[name="is_recurring"]').check();
    await form.locator('select[name="recurring_frequency"]').selectOption(values.recurringFrequency || 'monthly');
  }

  const submitButton = form.locator('[data-dp-submit], button[type="submit"]');
  await expect(submitButton).toBeVisible();
  await submitButton.click();
}

test.describe('Donation Form', () => {
  test('submits one-time donation', async ({ page }) => {
    await setupDonationSubmitMock(page);
    await page.goto(DONATION_PAGE_PATH);

    const form = page.locator('[data-dp-form]');
    await expect(form).toBeVisible();

    await fillDonationForm(form, {
      amount: 15,
      firstName: 'One',
      lastName: 'Time',
      email: 'onetime@example.com',
      recurring: false
    });

    await expect(form.locator('[data-dp-message]')).toContainText('Donation created');
  });

  test('submits donation and shows success message', async ({ page }) => {
    await setupDonationSubmitMock(page);
    await page.goto(DONATION_PAGE_PATH);

    const form = page.locator('[data-dp-form]');
    await expect(form).toBeVisible();

    await fillDonationForm(form, {
      amount: 25,
      firstName: 'E2E',
      lastName: 'Donor',
      email: 'donor@example.com',
      recurring: true,
      recurringFrequency: 'monthly'
    });

    await expect(form.locator('[data-dp-message]')).toContainText('Donation created');
  });

  test('shows recurring payment failure message when gateway start fails', async ({ page }) => {
    await setupDonationSubmitPaymentFailureMock(page);
    await page.goto(DONATION_PAGE_PATH);

    const form = page.locator('[data-dp-form]');
    await expect(form).toBeVisible();

    await fillDonationForm(form, {
      amount: 30,
      firstName: 'Retry',
      lastName: 'Failure',
      email: 'retry-failure@example.com',
      recurring: true,
      recurringFrequency: 'monthly'
    });

    await expect(form.locator('[data-dp-message]')).toContainText('Recurring charge failed, please retry');
  });
});
