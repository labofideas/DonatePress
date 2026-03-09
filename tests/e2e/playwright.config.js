const { defineConfig, devices } = require('@playwright/test');
const fs = require('fs');

const baseURL = process.env.E2E_BASE_URL || 'http://kindcart.local';
const browser = (process.env.E2E_BROWSER || 'firefox').toLowerCase();
const chromeExecutable = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

function chromiumLaunchOptions() {
  const options = {
    args: ['--disable-dev-shm-usage']
  };

  if (fs.existsSync(chromeExecutable)) {
    options.executablePath = chromeExecutable;
  }

  return options;
}

const browserProjects = {
  chromium: {
    name: 'chromium',
    use: {
      ...devices['Desktop Chrome'],
      launchOptions: chromiumLaunchOptions()
    }
  },
  firefox: {
    name: 'firefox',
    use: { ...devices['Desktop Firefox'] }
  },
  webkit: {
    name: 'webkit',
    use: { ...devices['Desktop Safari'] }
  }
};

module.exports = defineConfig({
  testDir: __dirname + '/specs',
  timeout: 45_000,
  workers: 1,
  expect: {
    timeout: 10_000
  },
  retries: process.env.CI ? 2 : 0,
  reporter: [['list'], ['html', { outputFolder: 'tests/e2e/report', open: 'never' }]],
  use: {
    baseURL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure'
  },
  projects: [browserProjects[browser] || browserProjects.firefox]
});
