<?php

namespace DonatePress\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central settings access helper.
 */
class SettingsService {
	/**
	 * Option key.
	 */
	private const OPTION_KEY = 'donatepress_settings';

	/**
	 * Return full settings array.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		$settings = get_option( self::OPTION_KEY, array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Read one setting.
	 *
	 * @param mixed $default Default value.
	 * @return mixed
	 */
	public function get( string $key, $default = '' ) {
		$settings = $this->all();
		return $settings[ $key ] ?? $default;
	}
}
