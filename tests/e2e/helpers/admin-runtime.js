const path = require('path');
const { execFileSync } = require('child_process');

function shouldRunAdminUiRuntime() {
  return process.env.E2E_ADMIN_UI === '1';
}

function runAdminRuntimeHelper(action, args = []) {
  const phpBinary = process.env.E2E_ADMIN_RUNTIME_PHP || 'php';
  const helperPath = path.resolve(__dirname, 'admin-runtime-fixture.php');

  try {
    const stdout = execFileSync(phpBinary, [helperPath, action, ...args], {
      cwd: path.resolve(__dirname, '..', '..', '..'),
      env: process.env,
      encoding: 'utf8'
    }).trim();

    return JSON.parse(stdout || '{}');
  } catch (error) {
    const stdout = error.stdout ? String(error.stdout).trim() : '';
    const stderr = error.stderr ? String(error.stderr).trim() : '';
    const details = [stdout, stderr].filter(Boolean).join('\n');
    throw new Error(`Admin runtime helper failed for "${action}"${details ? `:\n${details}` : ''}`);
  }
}

module.exports = {
  runAdminRuntimeHelper,
  shouldRunAdminUiRuntime
};
