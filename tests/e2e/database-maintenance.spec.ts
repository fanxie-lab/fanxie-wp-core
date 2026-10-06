import { execSync } from 'node:child_process';
import { test, expect } from '@playwright/test';

const SEED_ARGS =
	'comment create --comment_post_ID=1 --comment_content=fx-e2e-spam --comment_approved=spam --comment_date="2020-01-01 00:00:00"';

/**
 * Run WP-CLI against the wp-env dev site. `wp-env run` can flake on the
 * network, so prefer the running cli container directly and fall back to
 * wp-env when Docker lookup fails.
 */
function wp( args: string ): void {
	let container = '';
	try {
		container = execSync( 'docker ps --format "{{.Names}}" --filter "name=-cli-1"', { encoding: 'utf8' } )
			.split( '\n' )
			.find( ( n ) => n.endsWith( '-cli-1' ) && ! n.includes( 'tests-cli' ) ) ?? '';
	} catch {
		container = '';
	}
	const cmd = container ? `docker exec ${ container } wp ${ args }` : `npx wp-env run cli wp ${ args }`;
	execSync( cmd, { stdio: 'ignore' } );
}

test.describe( 'Fanxie Warden — Database Maintenance', () => {
	test.beforeAll( () => {
		// Seed one old spam comment on the dev site.
		wp( SEED_ARGS );
	} );

	test.beforeEach( async ( { page } ) => {
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await page.click( '#wp-submit' );
		await expect( page ).toHaveURL( /wp-admin/ );
	} );

	test( 'preview and purge spam from the Cleanup tab', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=fanxie-warden#/database-maintenance/cleanup' );

		const row = page.locator( 'tr[data-task="spam-comments"]' );
		await expect( row ).toBeVisible();

		await row.getByRole( 'button', { name: 'Preview Spam comments' } ).click();
		// Reruns may leave extra seeded rows in the sample; any one proves the preview.
		await expect( page.getByText( 'fx-e2e-spam' ).first() ).toBeVisible();

		await row.getByRole( 'checkbox' ).check();
		await page.getByRole( 'button', { name: 'Purge Selected' } ).click();
		await page.getByRole( 'button', { name: 'Delete permanently' } ).click();

		await expect( page.getByText( /Deleted \d+ rows?\./ ) ).toBeVisible();
		await expect( row ).toContainText( '—' );
	} );
} );
