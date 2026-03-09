const { test, expect } = require('@playwright/test');

test.describe('Security API', () => {
  test('rejects donation submit without nonce', async ({ request }) => {
    test.skip(
      process.env.E2E_MOCK_API !== '0',
      'Runs only against real API endpoints (set E2E_MOCK_API=0).'
    );

    const response = await request.post('/wp-json/donatepress/v1/donations/submit', {
      data: {
        form_id: 1,
        amount: 25,
        email: 'security-e2e@example.com',
        first_name: 'Security',
        dp_nonce: ''
      }
    });

    expect(response.status()).toBe(403);
    const body = await response.json();
    expect(body.code).toBe('invalid_nonce');
  });

  test('rejects webhook with invalid signature', async ({ request }) => {
    test.skip(
      process.env.E2E_MOCK_API !== '0',
      'Runs only against real API endpoints (set E2E_MOCK_API=0).'
    );

    const response = await request.post('/wp-json/donatepress/v1/webhooks/stripe', {
      headers: {
        'Content-Type': 'application/json',
        'stripe-signature': 't=1,v1=invalid'
      },
      data: {
        id: 'evt_security_e2e',
        type: 'payment_intent.succeeded'
      }
    });

    expect(response.status()).toBe(403);
    const body = await response.json();
    expect(body.code).toBe('invalid_signature');
  });
});
