function shouldMockApi() {
  return process.env.E2E_MOCK_API !== '0';
}

async function setupDonationSubmitMock(page) {
  if (!shouldMockApi()) {
    return;
  }

  await page.route('**/wp-json/donatepress/v1/donations/submit', async (route) => {
    const body = route.request().postDataJSON() || {};
    await route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        donation_id: 9991,
        donation_number: 'DP-E2E-999',
        donation: {
          id: 9991,
          amount: Number(body.amount || 25),
          currency: body.currency || 'USD',
          gateway: body.gateway || 'stripe',
          is_recurring: Boolean(body.is_recurring),
          recurring_frequency: body.is_recurring ? body.recurring_frequency || 'monthly' : ''
        },
        payment: {
          success: true,
          provider: body.gateway || 'stripe',
          transaction_id: 'txn_e2e_123'
        }
      })
    });
  });
}

async function setupDonationSubmitPaymentFailureMock(page) {
  if (!shouldMockApi()) {
    return;
  }

  await page.route('**/wp-json/donatepress/v1/donations/submit', async (route) => {
    const body = route.request().postDataJSON() || {};
    await route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        donation_id: 9992,
        donation_number: 'DP-E2E-FAIL',
        donation: {
          id: 9992,
          amount: Number(body.amount || 25),
          currency: body.currency || 'USD',
          gateway: body.gateway || 'stripe',
          is_recurring: true,
          recurring_frequency: body.recurring_frequency || 'monthly'
        },
        payment: {
          success: false,
          provider: body.gateway || 'stripe',
          message: 'Recurring charge failed, please retry'
        }
      })
    });
  });
}

async function setupPortalMocks(page) {
  if (!shouldMockApi()) {
    return;
  }

  await page.route('**/wp-json/donatepress/v1/portal/request-link', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        message: 'If your email exists in our records, a portal link has been sent.'
      })
    });
  });

  await page.route('**/wp-json/donatepress/v1/portal/auth', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        session_token: 'dps_e2e_session_token',
        expires_in: 86400
      })
    });
  });

  await page.route('**/wp-json/donatepress/v1/portal/me', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        profile: {
          email: 'donor@example.com',
          first_name: 'Donor',
          last_name: 'Example',
          phone: '+1 555-0100',
          donation_count: 3,
          completed_volume: 155.5
        }
      })
    });
  });

  await page.route('**/wp-json/donatepress/v1/portal/profile', async (route) => {
    const body = route.request().postDataJSON() || {};
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        updated: true,
        profile: {
          email: 'donor@example.com',
          first_name: body.first_name || 'Donor',
          last_name: body.last_name || 'Example',
          phone: body.phone || ''
        }
      })
    });
  });

  await page.route('**/wp-json/donatepress/v1/portal/donations?limit=25', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        items: [
          { id: 501, donation_number: 'DP-501', amount: '55.50', currency: 'USD', status: 'completed', donated_at: '2026-03-01 10:00:00' },
          { id: 502, donation_number: 'DP-502', amount: '100.00', currency: 'USD', status: 'completed', donated_at: '2026-02-15 10:00:00' }
        ]
      })
    });
  });

  await page.route('**/wp-json/donatepress/v1/portal/subscriptions?limit=25', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        items: [
          { id: 601, subscription_number: 'DPS-601', amount: '25.00', currency: 'USD', frequency: 'monthly', status: 'active' }
        ]
      })
    });
  });

  await page.route('**/wp-json/donatepress/v1/portal/subscriptions/*/action', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        id: 601,
        action: 'pause',
        status: 'paused'
      })
    });
  });

  await page.route('**/wp-json/donatepress/v1/portal/annual-summary', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        items: [{ year: '2026', currency: 'USD', donation_count: 3, total_amount: '155.50' }]
      })
    });
  });

  await page.route('**/wp-json/donatepress/v1/portal/annual-summary/download', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        filename: 'donatepress-annual-summary-2026-03-05.csv',
        mime: 'text/csv',
        content: 'year,currency,donation_count,total_amount\n2026,USD,3,155.50'
      })
    });
  });

  await page.route('**/wp-json/donatepress/v1/portal/receipts/*/download', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        filename: 'DP-501.pdf',
        mime: 'application/pdf',
        encoding: 'base64',
        content: 'JVBERi0xLjQK'
      })
    });
  });

  await page.route('**/wp-json/donatepress/v1/portal/logout', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true })
    });
  });
}

module.exports = {
  shouldMockApi,
  setupDonationSubmitMock,
  setupDonationSubmitPaymentFailureMock,
  setupPortalMocks
};
