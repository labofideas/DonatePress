<?php

namespace DonatePress\Frontend;

use DonatePress\Repositories\CampaignRepository;
use DonatePress\Repositories\CampaignUpdateRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public campaign page shortcode.
 */
class CampaignShortcode {
	/**
	 * Register shortcode/assets.
	 */
	public function register(): void {
		add_shortcode( 'donatepress_campaign', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register stylesheet.
	 */
	public function register_assets(): void {
		wp_register_style(
			'donatepress-campaign',
			DONATEPRESS_URL . 'assets/frontend/campaign.css',
			array(),
			DONATEPRESS_VERSION
		);
	}

	/**
	 * Render campaign card with updates and donate form.
	 */
	public function render( array $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'   => 0,
				'slug' => '',
				'form' => 1,
			),
			$atts,
			'donatepress_campaign'
		);

		$campaign = $this->find_campaign( (int) $atts['id'], sanitize_title( (string) $atts['slug'] ) );
		if ( ! $campaign ) {
			return '<p>' . esc_html__( 'Campaign not found.', 'donatepress' ) . '</p>';
		}

		wp_enqueue_style( 'donatepress-campaign' );
		$updates = $this->load_updates( (int) $campaign['id'] );
		$goal    = (float) ( $campaign['goal_amount'] ?? 0 );
		$raised  = (float) ( $campaign['raised_amount'] ?? 0 );
		$ratio   = $goal > 0 ? min( 100, max( 0, ( $raised / $goal ) * 100 ) ) : 0;

		$form_shortcode = new FormShortcode();
		$form_html      = $form_shortcode->render(
			array(
				'id' => (int) $atts['form'],
			)
		);

		ob_start();
		?>
		<section class="donatepress-campaign">
			<header>
				<h2><?php echo esc_html( (string) ( $campaign['title'] ?? '' ) ); ?></h2>
				<p><?php echo esc_html( (string) ( $campaign['description'] ?? '' ) ); ?></p>
			</header>
			<div class="dp-campaign-progress">
				<p><strong><?php echo esc_html__( 'Raised', 'donatepress' ); ?>:</strong> <?php echo esc_html( number_format_i18n( $raised, 2 ) ); ?></p>
				<?php if ( $goal > 0 ) : ?>
					<p><strong><?php echo esc_html__( 'Goal', 'donatepress' ); ?>:</strong> <?php echo esc_html( number_format_i18n( $goal, 2 ) ); ?></p>
					<div class="dp-progress-bar"><span style="width:<?php echo esc_attr( (string) round( $ratio, 2 ) ); ?>%"></span></div>
					<p class="dp-progress-label"><?php echo esc_html( number_format_i18n( $ratio, 1 ) ); ?>%</p>
				<?php endif; ?>
			</div>

			<div class="dp-campaign-layout">
				<div class="dp-campaign-donate">
					<h3><?php echo esc_html__( 'Support This Campaign', 'donatepress' ); ?></h3>
					<?php echo $form_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<div class="dp-campaign-updates">
					<h3><?php echo esc_html__( 'Campaign Updates', 'donatepress' ); ?></h3>
					<?php if ( empty( $updates ) ) : ?>
						<p><?php echo esc_html__( 'No updates published yet.', 'donatepress' ); ?></p>
					<?php else : ?>
						<ul>
							<?php foreach ( $updates as $update ) : ?>
								<li>
									<h4><?php echo esc_html( (string) ( $update['title'] ?? '' ) ); ?></h4>
									<p class="dp-update-meta"><?php echo esc_html( mysql2date( get_option( 'date_format' ), (string) ( $update['published_at'] ?? '' ) ) ); ?></p>
									<p><?php echo esc_html( (string) ( $update['content'] ?? '' ) ); ?></p>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			</div>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Find campaign by id or slug.
	 *
	 * @return array<string,mixed>|null
	 */
	private function find_campaign( int $id, string $slug ): ?array {
		global $wpdb;
		$repository = new CampaignRepository( $wpdb );

		if ( $id > 0 ) {
			return $repository->find( $id );
		}
		if ( '' !== $slug ) {
			return $repository->find_by_slug( $slug );
		}

		return null;
	}

	/**
	 * Load public campaign updates.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function load_updates( int $campaign_id ): array {
		global $wpdb;
		$repository = new CampaignUpdateRepository( $wpdb );
		return $repository->list_by_campaign( $campaign_id, 10, 0, 'published' );
	}
}
