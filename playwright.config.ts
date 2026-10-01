import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for Fanxie Warden.
 *
 * End-to-end browser tests run against the `@wordpress/env` *development*
 * environment (port 8888). The `tests` env on 8889 is reserved for PHPUnit.
 *
 * Prerequisite: `npm run env:start` **must** already be running before
 * invoking `npm run test:e2e`. Playwright is deliberately *not* configured
 * to manage the webServer — wp-env is slow to boot, and coupling it to
 * Playwright's lifecycle produces flaky CI.
 *
 * See `tests/e2e/README.md` for the local workflow.
 */
const isCI = !!process.env.CI;

export default defineConfig({
	testDir: './tests/e2e',
	fullyParallel: true,
	forbidOnly: isCI,
	retries: isCI ? 1 : 0,
	workers: isCI ? 1 : undefined,
	reporter: isCI ? 'html' : 'list',
	timeout: 30_000,
	expect: {
		timeout: 5_000,
	},
	use: {
		baseURL: 'http://localhost:8888',
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
		actionTimeout: 10_000,
		navigationTimeout: 15_000,
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] },
		},
	],
	// Intentionally NO webServer block — users run `npm run env:start` first.
});
