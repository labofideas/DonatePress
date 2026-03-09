<?php

namespace DonatePress\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gutenberg block registration for DonatePress form embed.
 */
class FormBlock {
	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Register editor script and block type.
	 */
	public function register_block(): void {
		$handle = 'donatepress-form-block-editor';

		wp_register_script(
			$handle,
			DONATEPRESS_URL . 'assets/blocks/form-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor' ),
			DONATEPRESS_VERSION,
			true
		);

		register_block_type(
			'donatepress/form',
			array(
				'api_version'     => 2,
				'editor_script'   => $handle,
				'render_callback' => array( $this, 'render' ),
				'attributes'      => array(
					'id'        => array( 'type' => 'number', 'default' => 1 ),
					'amount'    => array( 'type' => 'number', 'default' => 25 ),
					'currency'  => array( 'type' => 'string', 'default' => 'USD' ),
					'gateway'   => array( 'type' => 'string', 'default' => 'stripe' ),
					'recurring' => array( 'type' => 'boolean', 'default' => false ),
				),
				'supports'        => array(
					'html' => false,
				),
			)
		);
	}

	/**
	 * Server-side renderer delegates to shortcode renderer.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public function render( array $attributes ): string {
		$shortcode = new FormShortcode();
		$shortcode->register_assets();

		$atts = array(
			'id'        => (int) ( $attributes['id'] ?? 1 ),
			'amount'    => (float) ( $attributes['amount'] ?? 25 ),
			'currency'  => sanitize_text_field( (string) ( $attributes['currency'] ?? 'USD' ) ),
			'gateway'   => sanitize_key( (string) ( $attributes['gateway'] ?? 'stripe' ) ),
			'recurring' => ! empty( $attributes['recurring'] ) ? 'true' : 'false',
		);

		return $shortcode->render( $atts );
	}
}

