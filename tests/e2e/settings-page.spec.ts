import { test, expect } from '@playwright/test';

test.describe('Fanxie Warden — settings page', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/wp-login.php');
		await page.fill('#user_login', 'admin');
		await page.fill('#user_pass', 'password');
		await page.click('#wp-submit');
		await expect(page).toHaveURL(/wp-admin/);
	});

	test('plugin is active, settings page renders, Vue app mounts', async ({ page }) => {
		await page.goto('/wp-admin/options-general.php?page=fanxie-warden');

		// Sidebar nav should be present (Vue app mounted).
		await expect(page.locator('#fanxie-warden-admin')).toBeVisible();
		await expect(page.getByRole('navigation', { name: /modules/i })).toBeVisible();

		// Section headings from the new IA.
		await expect(page.getByRole('heading', { name: /security/i })).toBeVisible();
		await expect(page.getByRole('heading', { name: /performance/i })).toBeVisible();
		await expect(page.getByRole('heading', { name: /maintenance/i })).toBeVisible();
	});
});
