/**
 * Fanxie WP Core — idle-logout watchdog (wp-admin only).
 *
 * Dependency-free, framework-free. Enqueued by the Login Protection module's
 * SessionTimeout runtime on the admin screens when per-role session timeouts are
 * enabled. It logs an inactive user out once the configured idle window elapses,
 * complementing the server-side `auth_cookie_expiration` cap.
 *
 * Config is injected via `wp_localize_script` as `window.fanxieWpCoreIdle`:
 *   - timeoutMs {number} Idle window in milliseconds (per-role).
 *   - logoutUrl {string} URL to redirect to on timeout (nonced wp_logout_url()).
 */
( function () {
	'use strict';

	var config = window.fanxieWpCoreIdle;

	// Bail unless we were handed a usable, positive timeout and a logout target.
	if (
		! config ||
		typeof config.timeoutMs !== 'number' ||
		config.timeoutMs <= 0 ||
		typeof config.logoutUrl !== 'string' ||
		config.logoutUrl === ''
	) {
		return;
	}

	var timer = null;

	function logout() {
		window.location.href = config.logoutUrl;
	}

	function resetTimer() {
		if ( timer !== null ) {
			window.clearTimeout( timer );
		}
		timer = window.setTimeout( logout, config.timeoutMs );
	}

	// Any of these signals means the user is still around; restart the countdown.
	var activityEvents = [ 'mousemove', 'keydown', 'click', 'scroll', 'touchstart' ];
	for ( var i = 0; i < activityEvents.length; i++ ) {
		document.addEventListener( activityEvents[ i ], resetTimer, { passive: true } );
	}

	// Arm the initial countdown on load.
	resetTimer();
} )();
