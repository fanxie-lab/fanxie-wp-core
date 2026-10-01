<?php
/**
 * Static catalog of CSP presets.
 *
 * @package FanxieLab\Warden\Modules\SecurityHeaders\Csp
 */

declare( strict_types=1 );

namespace FanxieLab\Warden\Modules\SecurityHeaders\Csp;

defined( 'ABSPATH' ) || exit;

/**
 * Catalog of ready-made CSP directive fragments for common third-party stacks.
 *
 * Presets are intentionally conservative: they expose the domains needed for
 * the tool to operate but never add `'unsafe-inline'` or `'unsafe-eval'`.
 * Integrators can extend the catalog via the
 * `fanxie_warden/security_headers/csp_presets` filter.
 */
final class CspPresetLibrary {

	/**
	 * Return all presets, keyed by id.
	 *
	 * @return array<string, Preset>
	 */
	public function all(): array {
		$presets = [
			'woocommerce' => $this->woocommerce(),
			'ga-gtm'      => $this->ga_gtm(),
			'meta-pixel'  => $this->meta_pixel(),
		];

		/**
		 * Filter: fanxie_warden/security_headers/csp_presets
		 *
		 * Extend or mutate the CSP preset catalog. Handlers receive the fully
		 * built map (id → Preset) and should return the same shape.
		 *
		 * @since 0.1.0-dev
		 *
		 * @param array<string, Preset> $presets Preset catalog.
		 */
		$filtered = apply_filters( 'fanxie_warden/security_headers/csp_presets', $presets );

		if ( ! is_array( $filtered ) ) {
			return $presets;
		}

		// Drop anything that is not a Preset — protect downstream code from
		// well-meaning integrators that return raw arrays.
		$clean = [];
		foreach ( $filtered as $id => $preset ) {
			if ( $preset instanceof Preset && is_string( $id ) && '' !== $id ) {
				$clean[ $id ] = $preset;
			}
		}

		return $clean;
	}

	/**
	 * Lookup a preset by id.
	 *
	 * @param string $id Preset id.
	 */
	public function get( string $id ): ?Preset {
		$all = $this->all();
		return $all[ $id ] ?? null;
	}

	/**
	 * WooCommerce + common payment gateways preset.
	 */
	private function woocommerce(): Preset {
		return new Preset(
			'woocommerce',
			__( 'WooCommerce (Stripe, PayPal, Square)', 'fanxie-warden' ),
			[
				'script-src'  => [
					'https://js.stripe.com',
					'https://*.stripe.com',
					'https://*.paypal.com',
					'https://*.paypalobjects.com',
					'https://*.squareup.com',
					'https://*.squarecdn.com',
				],
				'connect-src' => [
					'https://*.stripe.com',
					'https://*.paypal.com',
					'https://*.squareup.com',
					'https://*.squarecdn.com',
				],
				'frame-src'   => [
					'https://js.stripe.com',
					'https://hooks.stripe.com',
					'https://*.stripe.com',
					'https://*.paypal.com',
					'https://*.squareup.com',
					'https://*.squarecdn.com',
				],
				'img-src'     => [
					'https://*.paypalobjects.com',
					'https://*.stripe.com',
					'https://*.squarecdn.com',
				],
			]
		);
	}

	/**
	 * Google Analytics + Google Tag Manager preset.
	 */
	private function ga_gtm(): Preset {
		return new Preset(
			'ga-gtm',
			__( 'Google Analytics / Tag Manager', 'fanxie-warden' ),
			[
				'script-src'  => [
					'https://*.google-analytics.com',
					'https://*.googletagmanager.com',
				],
				'connect-src' => [
					'https://*.google-analytics.com',
					'https://*.googletagmanager.com',
					'https://*.analytics.google.com',
				],
				'img-src'     => [
					'https://*.google-analytics.com',
					'https://*.googletagmanager.com',
					'https://*.google.com',
				],
			]
		);
	}

	/**
	 * Meta Pixel preset.
	 */
	private function meta_pixel(): Preset {
		return new Preset(
			'meta-pixel',
			__( 'Meta Pixel (Facebook)', 'fanxie-warden' ),
			[
				'script-src'  => [
					'https://connect.facebook.net',
				],
				'connect-src' => [
					'https://connect.facebook.net',
					'https://*.facebook.com',
				],
				'img-src'     => [
					'https://*.facebook.com',
					'https://*.fbcdn.net',
				],
			]
		);
	}
}
