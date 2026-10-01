/**
 * Fanxie Warden — idle-logout watchdog (wp-admin only).
 *
 * Dependency-free, framework-free. Enqueued by the Login Protection module's
 * SessionTimeout runtime on the admin screens when per-role session timeouts are
 * enabled. It logs an inactive user out once the configured idle window elapses,
 * complementing the server-side `auth_cookie_expiration` cap.
 *
 * Config is injected via `wp_add_inline_script` as `window.fanxieWardenIdle`:
 *   - timeoutMs {number} Idle window in milliseconds (per-role).
 *   - logoutUrl {string} URL to redirect to on timeout (nonced wp_logout_url()).
 */
( function () {
	'use strict';

	var config = window.fanxieWardenIdle;

	if ( ! config ) {
		return;
	}

	// Parse rather than strict-type-check the timeout: it arrives as a real
	// number when injected via wp_json_encode, but coercing here keeps the
	// watchdog correct even if the value is ever delivered as a string.
	var timeoutMs = parseInt( config.timeoutMs, 10 );
	var logoutUrl = config.logoutUrl;

	// Bail unless we were handed a usable, positive timeout and a logout target.
	if (
		isNaN( timeoutMs ) ||
		timeoutMs <= 0 ||
		typeof logoutUrl !== 'string' ||
		logoutUrl === ''
	) {
		return;
	}

	var timer = null;

	function logout() {
		window.location.href = logoutUrl;
	}

	function resetTimer() {
		if ( timer !== null ) {
			window.clearTimeout( timer );
		}
		timer = window.setTimeout( logout, timeoutMs );
	}

	// Any of these signals means the user is still around; restart the countdown.
	var activityEvents = [ 'mousemove', 'keydown', 'click', 'scroll', 'touchstart' ];
	for ( var i = 0; i < activityEvents.length; i++ ) {
		document.addEventListener( activityEvents[ i ], resetTimer, { passive: true } );
	}

	// Arm the initial countdown on load.
	resetTimer();
} )();
