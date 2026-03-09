<?php

namespace DonatePress\Frontend;

use DonatePress\Repositories\FormRepository;
use DonatePress\Security\NonceManager;
use DonatePress\Services\SetupWizardService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FormShortcode {
	public function register(): void {
		add_shortcode( 'donatepress_form', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	public function register_assets(): void {
		wp_register_style(
			'donatepress-form',
			DONATEPRESS_URL . 'assets/frontend/form.css',
			array(),
			DONATEPRESS_VERSION
		);

		wp_register_script(
			'donatepress-form',
			DONATEPRESS_URL . 'assets/frontend/form.js',
			array(),
			DONATEPRESS_VERSION,
			true
		);
	}

	public function render( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'       => 1,
				'amount'   => 25,
				'currency' => 'USD',
				'gateway'  => 'stripe',
				'recurring' => 'false',
			),
			$atts,
			'donatepress_form'
		);

		$form_id = (int) $atts['id'];
		$form    = $this->load_form( $form_id );

		wp_enqueue_style( 'donatepress-form' );
		wp_enqueue_script( 'donatepress-form' );

		if ( ! $form ) {
			$form = $this->resolve_fallback_form();
		}
		if ( $form ) {
			$form_id = (int) ( $form['id'] ?? $form_id );
		}
		if ( ! $form || $form_id <= 0 ) {
			return '<p class="donatepress-form-error">' . esc_html__( 'No active DonatePress form is available yet.', 'donatepress' ) . '</p>';
		}

		$config  = $this->resolve_form_config( $form, $atts );
		$nonce_manager = new NonceManager();
		$nonce = $nonce_manager->create( $form_id );

		$rest_url = esc_url_raw( rest_url( 'donatepress/v1/donations/submit' ) );

		ob_start();
		?>
		<form
			class="donatepress-form"
			data-dp-form
			data-rest-url="<?php echo esc_url( $rest_url ); ?>"
			data-dp-multistep="<?php echo ! empty( $config['multi_step'] ) ? '1' : '0'; ?>"
			data-dp-fee-percent="<?php echo esc_attr( (string) $config['fee_recovery_percent'] ); ?>"
		>
			<input type="hidden" name="form_id" value="<?php echo esc_attr( (string) $form_id ); ?>" />
			<input type="hidden" name="currency" value="<?php echo esc_attr( strtoupper( (string) $config['currency'] ) ); ?>" />
			<input type="hidden" name="gateway" value="<?php echo esc_attr( sanitize_key( (string) $config['gateway'] ) ); ?>" />
			<input type="hidden" name="dp_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
			<?php if ( ! empty( $config['multi_step'] ) ) : ?>
				<input type="hidden" name="dp_form_step" value="1" data-dp-form-step />
			<?php endif; ?>

			<div class="dp-step" data-dp-step="1">
				<?php if ( ! empty( $config['amount_presets'] ) ) : ?>
					<div class="dp-field">
						<label><?php echo esc_html__( 'Choose an amount', 'donatepress' ); ?></label>
						<div class="dp-amount-presets" data-dp-amount-presets>
							<?php foreach ( $config['amount_presets'] as $preset ) : ?>
								<button type="button" class="dp-preset" data-dp-amount="<?php echo esc_attr( (string) $preset ); ?>">
									<?php echo esc_html( strtoupper( (string) $config['currency'] ) . ' ' . number_format_i18n( (float) $preset, 2 ) ); ?>
								</button>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $config['allow_custom_amount'] ) ) : ?>
					<div class="dp-field">
						<label><?php echo esc_html__( 'Amount', 'donatepress' ); ?></label>
						<input type="number" step="0.01" min="1" name="amount" value="<?php echo esc_attr( (string) $config['amount'] ); ?>" required data-dp-amount-input />
					</div>
				<?php else : ?>
					<input type="hidden" name="amount" value="<?php echo esc_attr( (string) $config['amount'] ); ?>" data-dp-amount-input />
					<p class="dp-static-amount"><?php echo esc_html( strtoupper( (string) $config['currency'] ) . ' ' . number_format_i18n( (float) $config['amount'], 2 ) ); ?></p>
				<?php endif; ?>
			</div>

			<div class="dp-step" data-dp-step="2" <?php echo ! empty( $config['multi_step'] ) ? 'hidden' : ''; ?>>
				<div class="dp-field"><label><?php echo esc_html__( 'First Name', 'donatepress' ); ?></label><input type="text" name="first_name" required /></div>
				<div class="dp-field"><label><?php echo esc_html__( 'Last Name', 'donatepress' ); ?></label><input type="text" name="last_name" /></div>
				<div class="dp-field"><label><?php echo esc_html__( 'Email', 'donatepress' ); ?></label><input type="email" name="email" required /></div>
			</div>

			<input type="hidden" name="captcha_token" value="" data-dp-captcha-token />

			<div class="dp-step" data-dp-step="3" <?php echo ! empty( $config['multi_step'] ) ? 'hidden' : ''; ?>>
				<?php if ( ! empty( $config['allow_recurring'] ) ) : ?>
					<div class="dp-field">
						<label>
							<input type="checkbox" name="is_recurring" value="1" <?php checked( ! empty( $config['recurring_default'] ) ); ?> />
							<?php echo esc_html__( 'Make this a recurring gift', 'donatepress' ); ?>
						</label>
					</div>
					<div class="dp-field">
						<label><?php echo esc_html__( 'Recurring Frequency', 'donatepress' ); ?></label>
						<select name="recurring_frequency">
							<option value="monthly"><?php echo esc_html__( 'Monthly', 'donatepress' ); ?></option>
							<option value="annual"><?php echo esc_html__( 'Annual', 'donatepress' ); ?></option>
						</select>
					</div>
				<?php else : ?>
					<input type="hidden" name="is_recurring" value="0" />
					<input type="hidden" name="recurring_frequency" value="monthly" />
				<?php endif; ?>

				<?php if ( ! empty( $config['enable_fee_recovery'] ) ) : ?>
					<div class="dp-field">
						<label>
							<input type="checkbox" name="fee_recovery" value="1" data-dp-fee-recovery />
							<?php echo esc_html__( 'Cover processing fees', 'donatepress' ); ?>
						</label>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $config['enable_tribute'] ) ) : ?>
					<div class="dp-field"><label><?php echo esc_html__( 'Tribute Honoree Name', 'donatepress' ); ?></label><input type="text" name="tribute_name" /></div>
					<div class="dp-field"><label><?php echo esc_html__( 'Tribute Message', 'donatepress' ); ?></label><textarea name="tribute_message" rows="2"></textarea></div>
				<?php endif; ?>

				<?php foreach ( $config['custom_fields'] as $field ) : ?>
					<?php $this->render_custom_field( $field ); ?>
				<?php endforeach; ?>

				<div class="dp-field" aria-hidden="true" style="position:absolute;left:-9999px;opacity:0;pointer-events:none;"><label>Website</label><input type="text" name="hp_name" tabindex="-1" autocomplete="off" /></div>
				<div class="dp-field"><label><?php echo esc_html__( 'Comment', 'donatepress' ); ?></label><textarea name="comment" rows="3"></textarea></div>

				<?php if ( ! empty( $config['require_consent'] ) ) : ?>
					<div class="dp-field">
						<label>
							<input type="checkbox" name="consent" value="1" required />
							<?php echo esc_html__( 'I agree to the privacy policy and terms for this donation.', 'donatepress' ); ?>
						</label>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $config['multi_step'] ) ) : ?>
				<div class="dp-actions-row">
					<button type="button" class="dp-secondary" data-dp-prev hidden><?php echo esc_html__( 'Back', 'donatepress' ); ?></button>
					<button type="button" class="dp-secondary" data-dp-next><?php echo esc_html__( 'Next', 'donatepress' ); ?></button>
					<button type="submit" data-dp-submit hidden><?php echo esc_html__( 'Donate Now', 'donatepress' ); ?></button>
				</div>
			<?php else : ?>
				<button type="submit"><?php echo esc_html__( 'Donate Now', 'donatepress' ); ?></button>
			<?php endif; ?>
			<div class="dp-payment-panel" data-dp-payment-panel hidden>
				<h3 data-dp-payment-title><?php echo esc_html__( 'Complete your payment', 'donatepress' ); ?></h3>
				<p data-dp-payment-note><?php echo esc_html__( 'Your donation has been started. Finish the payment step below to confirm it.', 'donatepress' ); ?></p>
				<div data-dp-payment-content></div>
			</div>
			<p class="dp-message" data-dp-message aria-live="polite"></p>
		</form>
		<?php
		$html = (string) ob_get_clean();

		/**
		 * Filters rendered form HTML.
		 *
		 * @param string              $html Form HTML.
		 * @param array<string,mixed> $atts Shortcode attributes.
		 */
		return (string) apply_filters( 'donatepress_render_form_html', $html, $atts );
	}

	private function load_form( int $id ): ?array {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return null;
		}

		$repository = new FormRepository( $wpdb );
		return $repository->find( $id );
	}

	private function resolve_fallback_form(): ?array {
		global $wpdb;
		if ( ! ( $wpdb instanceof \wpdb ) ) {
			return null;
		}

		$repository = new FormRepository( $wpdb );
		$form       = $repository->find_first_active();
		if ( $form ) {
			return $form;
		}

		$wizard = new SetupWizardService();
		$result = $wizard->ensure_first_form();
		return ! empty( $result['success'] ) && ! empty( $result['item'] ) && is_array( $result['item'] )
			? $result['item']
			: null;
	}

	/**
	 * Merge DB config with shortcode attributes.
	 *
	 * @param array<string,mixed>|null $form
	 * @param array<string,mixed>      $atts
	 * @return array<string,mixed>
	 */
	private function resolve_form_config( ?array $form, array $atts ): array {
		$raw_config = array();
		if ( is_array( $form ) && isset( $form['config'] ) ) {
			$decoded = json_decode( (string) $form['config'], true );
			if ( is_array( $decoded ) ) {
				$raw_config = $decoded;
			}
		}

		$amount_presets = array_map(
			'floatval',
			array_values( array_filter( (array) ( $raw_config['amount_presets'] ?? array( 25, 50, 100 ) ), static fn( $v ): bool => (float) $v > 0 ) )
		);
		if ( empty( $amount_presets ) ) {
			$amount_presets = array( (float) ( $atts['amount'] ?? 25 ) );
		}

		$custom_fields = (array) ( $raw_config['custom_fields'] ?? array() );
		$sanitized_custom_fields = array();
		foreach ( $custom_fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$key = sanitize_key( (string) ( $field['key'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}
			$type = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
			if ( ! in_array( $type, array( 'text', 'email', 'number', 'textarea', 'checkbox', 'select' ), true ) ) {
				$type = 'text';
			}
			$sanitized_custom_fields[] = array(
				'key'      => $key,
				'label'    => sanitize_text_field( (string) ( $field['label'] ?? ucfirst( $key ) ) ),
				'type'     => $type,
				'required' => ! empty( $field['required'] ),
				'options'  => array_values( array_map( 'sanitize_text_field', (array) ( $field['options'] ?? array() ) ) ),
			);
		}

		return array(
			'amount'               => isset( $raw_config['default_amount'] ) ? (float) $raw_config['default_amount'] : (float) ( $form['default_amount'] ?? $atts['amount'] ?? 25 ),
			'currency'             => strtoupper( (string) ( $form['currency'] ?? $atts['currency'] ?? 'USD' ) ),
			'gateway'              => sanitize_key( (string) ( $form['gateway'] ?? $atts['gateway'] ?? 'stripe' ) ),
			'multi_step'           => ! empty( $raw_config['multi_step'] ),
			'amount_presets'       => $amount_presets,
			'allow_custom_amount'  => ! array_key_exists( 'allow_custom_amount', $raw_config ) || ! empty( $raw_config['allow_custom_amount'] ),
			'allow_recurring'      => ! array_key_exists( 'allow_recurring', $raw_config ) || ! empty( $raw_config['allow_recurring'] ),
			'recurring_default'    => 'true' === strtolower( (string) $atts['recurring'] ),
			'enable_fee_recovery'  => ! empty( $raw_config['enable_fee_recovery'] ),
			'fee_recovery_percent' => max( 0, (float) ( $raw_config['fee_recovery_percent'] ?? 5 ) ),
			'enable_tribute'       => ! empty( $raw_config['enable_tribute'] ),
			'require_consent'      => ! empty( $raw_config['require_consent'] ),
			'custom_fields'        => $sanitized_custom_fields,
		);
	}

	/**
	 * Render one configurable custom field.
	 *
	 * @param array<string,mixed> $field
	 */
	private function render_custom_field( array $field ): void {
		$key      = sanitize_key( (string) ( $field['key'] ?? '' ) );
		$label    = sanitize_text_field( (string) ( $field['label'] ?? $key ) );
		$type     = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
		$required = ! empty( $field['required'] ) ? 'required' : '';
		$name     = 'cf_' . $key;
		if ( '' === $key ) {
			return;
		}
		?>
		<div class="dp-field">
			<label><?php echo esc_html( $label ); ?></label>
			<?php if ( 'textarea' === $type ) : ?>
				<textarea name="<?php echo esc_attr( $name ); ?>" rows="2" <?php echo esc_attr( $required ); ?>></textarea>
			<?php elseif ( 'checkbox' === $type ) : ?>
				<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" /> <?php echo esc_html( $label ); ?></label>
			<?php elseif ( 'select' === $type ) : ?>
				<select name="<?php echo esc_attr( $name ); ?>" <?php echo esc_attr( $required ); ?>>
					<option value=""><?php echo esc_html__( 'Select an option', 'donatepress' ); ?></option>
					<?php foreach ( (array) ( $field['options'] ?? array() ) as $option ) : ?>
						<option value="<?php echo esc_attr( (string) $option ); ?>"><?php echo esc_html( (string) $option ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php else : ?>
				<input type="<?php echo esc_attr( in_array( $type, array( 'email', 'number' ), true ) ? $type : 'text' ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php echo esc_attr( $required ); ?> />
			<?php endif; ?>
		</div>
		<?php
	}
}
