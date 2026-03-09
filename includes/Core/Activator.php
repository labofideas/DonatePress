<?php

namespace DonatePress\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles activation/deactivation lifecycle actions.
 */
class Activator {
	private const RETRY_CRON_HOOK = 'donatepress_retry_subscriptions_cron';

	/**
	 * Plugin activation callback.
	 */
	public static function activate(): void {
		add_option( 'donatepress_version', DONATEPRESS_VERSION );
		add_option( 'donatepress_setup_completed', 0 );

		self::create_tables();
		self::maybe_seed_defaults();
		self::schedule_events();
	}

	/**
	 * Create required plugin tables.
	 */
	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$donations_table = $wpdb->prefix . 'dp_donations';
		$donors_table    = $wpdb->prefix . 'dp_donors';
		$forms_table     = $wpdb->prefix . 'dp_forms';
		$campaigns_table = $wpdb->prefix . 'dp_campaigns';
		$subs_table      = $wpdb->prefix . 'dp_subscriptions';
		$portal_tokens_table = $wpdb->prefix . 'dp_portal_tokens';
		$webhook_events_table = $wpdb->prefix . 'dp_webhook_events';
		$campaign_updates_table = $wpdb->prefix . 'dp_campaign_updates';
		$audit_logs_table = $wpdb->prefix . 'dp_audit_logs';

		$donations_sql = "CREATE TABLE {$donations_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			donation_number VARCHAR(40) NOT NULL,
			subscription_id BIGINT UNSIGNED NULL,
			donor_id BIGINT UNSIGNED NULL,
			form_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
			campaign_id BIGINT UNSIGNED NULL,
			donor_email VARCHAR(255) NOT NULL,
			donor_first_name VARCHAR(100) NOT NULL,
			donor_last_name VARCHAR(100) DEFAULT '',
			amount DECIMAL(13,4) NOT NULL,
			currency CHAR(3) NOT NULL DEFAULT 'USD',
			gateway VARCHAR(50) NOT NULL DEFAULT 'stripe',
			gateway_transaction_id VARCHAR(255) NULL,
			is_recurring TINYINT(1) NOT NULL DEFAULT 0,
			recurring_frequency VARCHAR(20) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			donor_comment TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			donated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY donation_number (donation_number),
			KEY donor_id (donor_id),
			KEY donor_email (donor_email),
			KEY subscription_id (subscription_id),
			KEY form_id (form_id),
			KEY campaign_id (campaign_id),
			KEY is_recurring (is_recurring),
			KEY status (status),
			KEY donated_at (donated_at),
			KEY gateway_transaction_id (gateway_transaction_id),
			UNIQUE KEY gateway_transaction_unique (gateway, gateway_transaction_id)
		) {$charset};";

		$subs_sql = "CREATE TABLE {$subs_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			subscription_number VARCHAR(40) NOT NULL,
			initial_donation_id BIGINT UNSIGNED NOT NULL,
			donor_id BIGINT UNSIGNED NULL,
			form_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
			campaign_id BIGINT UNSIGNED NULL,
			gateway VARCHAR(50) NOT NULL DEFAULT 'stripe',
			gateway_subscription_id VARCHAR(255) NULL,
			amount DECIMAL(13,4) NOT NULL,
			currency CHAR(3) NOT NULL DEFAULT 'USD',
			frequency VARCHAR(20) NOT NULL DEFAULT 'monthly',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			failure_count INT UNSIGNED NOT NULL DEFAULT 0,
			max_retries INT UNSIGNED NOT NULL DEFAULT 3,
			next_payment_at DATETIME NULL,
			last_payment_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY subscription_number (subscription_number),
			KEY initial_donation_id (initial_donation_id),
			KEY donor_id (donor_id),
			KEY form_id (form_id),
			KEY campaign_id (campaign_id),
			KEY gateway (gateway),
			KEY status (status),
			KEY next_payment_at (next_payment_at),
			UNIQUE KEY gateway_subscription_unique (gateway, gateway_subscription_id)
		) {$charset};";

		$portal_tokens_sql = "CREATE TABLE {$portal_tokens_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			donor_email VARCHAR(255) NOT NULL,
			token_hash CHAR(64) NOT NULL,
			ip_address VARCHAR(45) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			used_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY donor_email (donor_email),
			KEY expires_at (expires_at),
			KEY used_at (used_at),
			UNIQUE KEY token_hash (token_hash)
		) {$charset};";

		$webhook_events_sql = "CREATE TABLE {$webhook_events_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			gateway VARCHAR(50) NOT NULL,
			event_id VARCHAR(191) NOT NULL,
			payload_hash CHAR(64) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'processing',
			received_at DATETIME NOT NULL,
			processed_at DATETIME NULL,
			response_message TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY gateway_event_unique (gateway, event_id),
			KEY payload_hash (payload_hash),
			KEY status (status),
			KEY received_at (received_at),
			KEY processed_at (processed_at)
		) {$charset};";

		$donors_sql = "CREATE TABLE {$donors_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_user_id BIGINT UNSIGNED NULL,
			email VARCHAR(255) NOT NULL,
			first_name VARCHAR(100) NOT NULL,
			last_name VARCHAR(100) DEFAULT '',
			phone VARCHAR(50) DEFAULT '',
			total_donated DECIMAL(13,4) NOT NULL DEFAULT 0.0000,
			donation_count INT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			tags LONGTEXT NULL,
			notes LONGTEXT NULL,
			blocked_at DATETIME NULL,
			anonymized_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY wp_user_id (wp_user_id),
			KEY status (status),
			KEY blocked_at (blocked_at),
			KEY anonymized_at (anonymized_at),
			KEY total_donated (total_donated)
		) {$charset};";

		$forms_sql = "CREATE TABLE {$forms_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) NOT NULL,
			slug VARCHAR(255) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			default_amount DECIMAL(13,4) NOT NULL DEFAULT 25.0000,
			currency CHAR(3) NOT NULL DEFAULT 'USD',
			gateway VARCHAR(50) NOT NULL DEFAULT 'stripe',
			config LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY status (status)
		) {$charset};";

		$campaigns_sql = "CREATE TABLE {$campaigns_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(255) NOT NULL,
			slug VARCHAR(255) NOT NULL,
			description LONGTEXT NULL,
			goal_amount DECIMAL(13,4) NULL,
			raised_amount DECIMAL(13,4) NOT NULL DEFAULT 0.0000,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			starts_at DATETIME NULL,
			ends_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY status (status),
			KEY starts_at (starts_at),
			KEY ends_at (ends_at)
		) {$charset};";

		$campaign_updates_sql = "CREATE TABLE {$campaign_updates_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT UNSIGNED NOT NULL,
			title VARCHAR(255) NOT NULL,
			content LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'published',
			published_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY campaign_id (campaign_id),
			KEY status (status),
			KEY published_at (published_at)
		) {$charset};";

		$audit_logs_sql = "CREATE TABLE {$audit_logs_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event VARCHAR(120) NOT NULL,
			actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			ip_address VARCHAR(45) NOT NULL DEFAULT '',
			context LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY event (event),
			KEY actor_id (actor_id),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $donors_sql );
		dbDelta( $forms_sql );
		dbDelta( $campaigns_sql );
		dbDelta( $campaign_updates_sql );
		dbDelta( $audit_logs_sql );
		dbDelta( $subs_sql );
		dbDelta( $portal_tokens_sql );
		dbDelta( $webhook_events_sql );
		dbDelta( $donations_sql );

		self::maybe_add_foreign_keys( $donations_table, $subs_table, $donors_table, $forms_table, $campaigns_table, $campaign_updates_table );
	}

	/**
	 * Add relational integrity constraints where possible.
	 */
	private static function maybe_add_foreign_keys( string $donations_table, string $subs_table, string $donors_table, string $forms_table, string $campaigns_table, string $campaign_updates_table ): void {
		global $wpdb;

		$constraints = array(
			array(
				'table'      => $donations_table,
				'name'       => 'dp_fk_donations_donor',
				'statement'  => "ALTER TABLE {$donations_table} ADD CONSTRAINT dp_fk_donations_donor FOREIGN KEY (donor_id) REFERENCES {$donors_table}(id) ON DELETE SET NULL ON UPDATE CASCADE",
			),
			array(
				'table'      => $donations_table,
				'name'       => 'dp_fk_donations_form',
				'statement'  => "ALTER TABLE {$donations_table} ADD CONSTRAINT dp_fk_donations_form FOREIGN KEY (form_id) REFERENCES {$forms_table}(id) ON DELETE RESTRICT ON UPDATE CASCADE",
			),
			array(
				'table'      => $donations_table,
				'name'       => 'dp_fk_donations_campaign',
				'statement'  => "ALTER TABLE {$donations_table} ADD CONSTRAINT dp_fk_donations_campaign FOREIGN KEY (campaign_id) REFERENCES {$campaigns_table}(id) ON DELETE SET NULL ON UPDATE CASCADE",
			),
			array(
				'table'      => $subs_table,
				'name'       => 'dp_fk_subs_initial_donation',
				'statement'  => "ALTER TABLE {$subs_table} ADD CONSTRAINT dp_fk_subs_initial_donation FOREIGN KEY (initial_donation_id) REFERENCES {$donations_table}(id) ON DELETE RESTRICT ON UPDATE CASCADE",
			),
			array(
				'table'      => $subs_table,
				'name'       => 'dp_fk_subs_donor',
				'statement'  => "ALTER TABLE {$subs_table} ADD CONSTRAINT dp_fk_subs_donor FOREIGN KEY (donor_id) REFERENCES {$donors_table}(id) ON DELETE SET NULL ON UPDATE CASCADE",
			),
			array(
				'table'      => $subs_table,
				'name'       => 'dp_fk_subs_form',
				'statement'  => "ALTER TABLE {$subs_table} ADD CONSTRAINT dp_fk_subs_form FOREIGN KEY (form_id) REFERENCES {$forms_table}(id) ON DELETE RESTRICT ON UPDATE CASCADE",
			),
			array(
				'table'      => $subs_table,
				'name'       => 'dp_fk_subs_campaign',
				'statement'  => "ALTER TABLE {$subs_table} ADD CONSTRAINT dp_fk_subs_campaign FOREIGN KEY (campaign_id) REFERENCES {$campaigns_table}(id) ON DELETE SET NULL ON UPDATE CASCADE",
			),
			array(
				'table'      => $campaign_updates_table,
				'name'       => 'dp_fk_campaign_updates_campaign',
				'statement'  => "ALTER TABLE {$campaign_updates_table} ADD CONSTRAINT dp_fk_campaign_updates_campaign FOREIGN KEY (campaign_id) REFERENCES {$campaigns_table}(id) ON DELETE CASCADE ON UPDATE CASCADE",
			),
		);

		foreach ( $constraints as $constraint ) {
			if ( self::foreign_key_exists( (string) $constraint['table'], (string) $constraint['name'] ) ) {
				continue;
			}
			$wpdb->query( (string) $constraint['statement'] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		}
	}

	/**
	 * Detect whether a foreign key already exists.
	 */
	private static function foreign_key_exists( string $table, string $constraint_name ): bool {
		global $wpdb;

		$schema = defined( 'DB_NAME' ) ? DB_NAME : '';
		if ( '' === $schema ) {
			return false;
		}

		$query = $wpdb->prepare(
			'SELECT COUNT(*)
			FROM information_schema.TABLE_CONSTRAINTS
			WHERE TABLE_SCHEMA = %s
			AND TABLE_NAME = %s
			AND CONSTRAINT_NAME = %s
			AND CONSTRAINT_TYPE = %s',
			$schema,
			$table,
			$constraint_name,
			'FOREIGN KEY'
		);

		return (int) $wpdb->get_var( $query ) > 0;
	}

	/**
	 * Seed settings defaults for first install.
	 */
	private static function maybe_seed_defaults(): void {
		$defaults = array(
			'organization_name'          => get_bloginfo( 'name' ),
			'organization_email'         => get_bloginfo( 'admin_email' ),
			'organization_phone'         => '',
			'organization_address'       => '',
			'organization_tax_id'        => '',
			'receipt_legal_entity_name'  => get_bloginfo( 'name' ),
			'tax_disclaimer_text'        => 'Please consult your local tax advisor regarding deduction eligibility.',
			'privacy_policy_url'         => '',
			'terms_url'                  => '',
			'receipt_footer_text'        => '',
			'base_currency'              => 'USD',
			'supported_countries'        => array( 'US' ),
			'wc_enabled'                 => 1,
			'enable_captcha'             => 0,
			'recurring_frequencies'      => array( 'monthly', 'annual' ),
			'live_mode_enabled'          => 0,
			'stripe_publishable_key'     => '',
			'stripe_secret_key'          => '',
			'stripe_webhook_secret'      => '',
			'paypal_client_id'           => '',
			'paypal_secret'              => '',
			'paypal_webhook_id'          => '',
			'statement_descriptor'       => '',
		);

		$defaults = apply_filters( 'donatepress_settings_defaults', $defaults );

		if ( ! get_option( 'donatepress_settings' ) ) {
			add_option( 'donatepress_settings', $defaults );
		}
	}

	/**
	 * Plugin deactivation callback.
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( self::RETRY_CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::RETRY_CRON_HOOK );
		}
	}

	/**
	 * Schedule recurring maintenance jobs.
	 */
	private static function schedule_events(): void {
		if ( ! wp_next_scheduled( self::RETRY_CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::RETRY_CRON_HOOK );
		}
	}
}
