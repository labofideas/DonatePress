<?php

namespace DonatePress\Services;

use DonatePress\Repositories\CampaignRepository;
use DonatePress\Repositories\FormRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seeds an opinionated first-run demo experience.
 */
class DemoImportService {
	private const OPTION_KEY = 'donatepress_demo_import_state';

	/**
	 * Return the last import snapshot.
	 *
	 * @return array<string,mixed>
	 */
	public function status(): array {
		$state = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$state['has_import'] = ! empty( $state['pages'] ) || ! empty( $state['campaign_id'] ) || ! empty( $state['menu_id'] );
		return $state;
	}

	/**
	 * Create demo content, menu links, and import metadata.
	 *
	 * @return array<string,mixed>
	 */
	public function import( string $preferred_location = '' ): array {
		global $wpdb;
		if ( ! ( $wpdb instanceof \wpdb ) ) {
			return array(
				'success' => false,
				'message' => __( 'Database connection unavailable.', 'donatepress' ),
			);
		}

		$wizard = new SetupWizardService();
		$form   = $wizard->ensure_first_form();
		if ( empty( $form['success'] ) || empty( $form['item']['id'] ) ) {
			return array(
				'success' => false,
				'message' => (string) ( $form['message'] ?? __( 'Could not create the starter donation form.', 'donatepress' ) ),
			);
		}

		$form_id             = (int) $form['item']['id'];
		$form_repository     = new FormRepository( $wpdb );
		$form_item           = $form_repository->find( $form_id );
		$campaign_repository = new CampaignRepository( $wpdb );
		$campaign            = $campaign_repository->find_by_slug( 'community-relief-fund' );

		if ( ! $campaign ) {
			$campaign_id = $campaign_repository->insert(
				array(
					'title'         => 'Community Relief Fund',
					'slug'          => 'community-relief-fund',
					'description'   => 'A starter campaign that shows donors how to support a focused fundraising goal with a progress bar and campaign story.',
					'goal_amount'   => 25000,
					'raised_amount' => 8750,
					'status'        => 'active',
					'starts_at'     => current_time( 'mysql', true ),
				)
			);

			if ( $campaign_id <= 0 ) {
				return array(
					'success' => false,
					'message' => __( 'Could not create the demo campaign.', 'donatepress' ),
				);
			}

			$campaign = $campaign_repository->find( $campaign_id );
		}

		if ( ! $campaign ) {
			return array(
				'success' => false,
				'message' => __( 'Could not load the demo campaign.', 'donatepress' ),
			);
		}

		$campaign_id = (int) $campaign['id'];
		$pages       = array(
			'overview' => $this->ensure_page(
				'donatepress-demo-home',
				'DonatePress Demo',
				$this->overview_content()
			),
			'donate'   => $this->ensure_page(
				'donatepress-demo-donate',
				'Donate',
				$this->donate_content( $form_id )
			),
			'campaign' => $this->ensure_page(
				'donatepress-demo-campaign',
				'Community Relief Campaign',
				$this->campaign_content( $campaign_id, $form_id )
			),
			'portal'   => $this->ensure_page(
				'donatepress-demo-portal',
				'Donor Portal',
				$this->portal_content()
			),
		);

		foreach ( $pages as $page ) {
			if ( $page <= 0 ) {
				return array(
					'success' => false,
					'message' => __( 'Could not create the demo pages.', 'donatepress' ),
				);
			}
		}

		$menu_status = $this->ensure_menu( $pages, $preferred_location );

		$state = array(
			'form_id'           => $form_id,
			'form_title'        => (string) ( $form_item['title'] ?? 'General Donation Form' ),
			'campaign_id'       => $campaign_id,
			'campaign_title'    => (string) ( $campaign['title'] ?? 'Community Relief Fund' ),
			'pages'             => $pages,
			'menu_id'           => (int) $menu_status['menu_id'],
			'menu_name'         => (string) $menu_status['menu_name'],
			'assigned_location' => (string) $menu_status['assigned_location'],
			'imported_at'       => current_time( 'mysql', true ),
		);

		update_option( self::OPTION_KEY, $state, false );

		return array(
			'success' => true,
			'message' => __( 'Demo content imported.', 'donatepress' ),
			'state'   => $this->status(),
		);
	}

	/**
	 * Ensure a published page exists by path.
	 */
	private function ensure_page( string $slug, string $title, string $content ): int {
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $page instanceof \WP_Post ) {
			$updated = wp_update_post(
				array(
					'ID'           => (int) $page->ID,
					'post_title'   => $title,
					'post_content' => $content,
					'post_status'  => 'publish',
				),
				true
			);

			return is_wp_error( $updated ) ? 0 : (int) $page->ID;
		}

		$created = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => $content,
			),
			true
		);

		return is_wp_error( $created ) ? 0 : (int) $created;
	}

	/**
	 * Ensure the demo nav menu exists and includes demo pages.
	 *
	 * @param array<string,int> $pages
	 * @return array<string,mixed>
	 */
	private function ensure_menu( array $pages, string $preferred_location = '' ): array {
		$menu_name = 'DonatePress Demo';
		$menu_id   = (int) wp_get_nav_menu_object( $menu_name )?->term_id;

		if ( $menu_id <= 0 ) {
			$created = wp_create_nav_menu( $menu_name );
			$menu_id = is_wp_error( $created ) ? 0 : (int) $created;
		}

		if ( $menu_id <= 0 ) {
			return array(
				'menu_id'           => 0,
				'menu_name'         => $menu_name,
				'assigned_location' => '',
			);
		}

		$existing_items = wp_get_nav_menu_items( $menu_id );
		$existing_pages = array();
		if ( is_array( $existing_items ) ) {
			foreach ( $existing_items as $item ) {
				$existing_pages[] = (int) $item->object_id;
			}
		}

		$labels = array(
			'overview' => 'Start Here',
			'donate'   => 'Donate',
			'campaign' => 'Campaign',
			'portal'   => 'Donor Portal',
		);

		foreach ( $pages as $key => $page_id ) {
			if ( in_array( (int) $page_id, $existing_pages, true ) ) {
				continue;
			}

			wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-title'     => $labels[ $key ] ?? get_the_title( $page_id ),
					'menu-item-object'    => 'page',
					'menu-item-object-id' => $page_id,
					'menu-item-type'      => 'post_type',
					'menu-item-status'    => 'publish',
				)
			);
		}

		$assigned_location = '';
		$locations         = get_nav_menu_locations();
		$registered        = get_registered_nav_menus();
		$preferred_location = sanitize_key( $preferred_location );

		if ( '' !== $preferred_location && isset( $registered[ $preferred_location ] ) ) {
			$locations[ $preferred_location ] = $menu_id;
			set_theme_mod( 'nav_menu_locations', $locations );
			$assigned_location = $preferred_location;
		}

		foreach ( array_keys( $registered ) as $location ) {
			if ( '' !== $assigned_location ) {
				break;
			}

			$current = (int) ( $locations[ $location ] ?? 0 );
			if ( $current === $menu_id ) {
				$assigned_location = $location;
				break;
			}

			if ( $current === 0 ) {
				$locations[ $location ] = $menu_id;
				set_theme_mod( 'nav_menu_locations', $locations );
				$assigned_location = $location;
				break;
			}
		}

		return array(
			'menu_id'           => $menu_id,
			'menu_name'         => $menu_name,
			'assigned_location' => $assigned_location,
		);
	}

	/**
	 * Demo overview page copy.
	 */
	private function overview_content(): string {
		return implode(
			"\n\n",
			array(
				'<!-- wp:heading {"level":1} --><h1>DonatePress Demo</h1><!-- /wp:heading -->',
				'<!-- wp:paragraph --><p>This starter page helps you explore the plugin in a realistic way. Use the menu to open a donation page, a campaign page, and the donor portal.</p><!-- /wp:paragraph -->',
				'<!-- wp:heading {"level":3} --><h3>Start Here</h3><!-- /wp:heading -->',
				'<!-- wp:list --><ul><li>Open <strong>Donate</strong> to preview the main donation flow.</li><li>Open <strong>Campaign</strong> to see storytelling plus campaign progress.</li><li>Open <strong>Donor Portal</strong> to preview the portal area for returning supporters.</li></ul><!-- /wp:list -->',
				'<!-- wp:heading {"level":3} --><h3>Replace Before Launch</h3><!-- /wp:heading -->',
				'<!-- wp:list --><ul><li>Update organization name, legal entity, and privacy URLs in DonatePress Settings.</li><li>Connect your real Stripe or PayPal credentials.</li><li>Replace the sample campaign story and page copy with your own mission.</li><li>Move the final shortcodes into your site navigation and landing pages.</li></ul><!-- /wp:list -->',
				'<!-- wp:paragraph --><p>When you are ready, replace the sample copy, adjust the form settings in DonatePress, and add these shortcodes anywhere on your own pages.</p><!-- /wp:paragraph -->',
			)
		);
	}

	/**
	 * Demo donate page copy.
	 */
	private function donate_content( int $form_id ): string {
		return implode(
			"\n\n",
			array(
				'<!-- wp:heading {"level":1} --><h1>Support Our Mission</h1><!-- /wp:heading -->',
				'<!-- wp:paragraph --><p>This page uses the main DonatePress form so you can see the donor journey on a real page, not only inside the admin area.</p><!-- /wp:paragraph -->',
				'<!-- wp:quote --><blockquote class="wp-block-quote"><p>Replace this sample appeal with your real campaign story, donor promise, and outcome statement.</p></blockquote><!-- /wp:quote -->',
				sprintf(
					'<!-- wp:shortcode -->[donatepress_form id="%d"]<!-- /wp:shortcode -->',
					$form_id
				),
			)
		);
	}

	/**
	 * Demo campaign page copy.
	 */
	private function campaign_content( int $campaign_id, int $form_id ): string {
		return implode(
			"\n\n",
			array(
				'<!-- wp:heading {"level":1} --><h1>Community Relief Campaign</h1><!-- /wp:heading -->',
				'<!-- wp:paragraph --><p>This sample campaign demonstrates how DonatePress can combine a specific fundraising goal with a dedicated donation form.</p><!-- /wp:paragraph -->',
				'<!-- wp:list --><ul><li>Set a clear funding goal.</li><li>Explain what donations will achieve.</li><li>Publish updates as the campaign progresses.</li></ul><!-- /wp:list -->',
				sprintf(
					'<!-- wp:shortcode -->[donatepress_campaign id="%d" form="%d"]<!-- /wp:shortcode -->',
					$campaign_id,
					$form_id
				),
			)
		);
	}

	/**
	 * Demo donor portal page copy.
	 */
	private function portal_content(): string {
		return implode(
			"\n\n",
			array(
				'<!-- wp:paragraph --><p>Supporters can use this page to view past gifts, download receipts, and manage recurring donations without contacting your team.</p><!-- /wp:paragraph -->',
				'<!-- wp:shortcode -->[donatepress_portal]<!-- /wp:shortcode -->',
			)
		);
	}
}
