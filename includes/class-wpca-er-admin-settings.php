<?php
/**
 * WooCommerce → Event registration settings.
 *
 * @package WPCA_Event_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the admin settings page under WooCommerce.
 */
class WPCA_ER_Admin_Settings {

	/**
	 * Option group for settings API.
	 */
	public const OPTION_GROUP = 'WPCA_ER_settings';

	/**
	 * Whether post content looks like it already embeds GF or our shortcode (best-effort for classic + block markup).
	 *
	 * @param string $content Post content.
	 * @return bool
	 */
	private static function page_likely_has_registration_form( $content ) {
		if ( '' === $content ) {
			return false;
		}

		$checks = array(
			'[WPCA_ER_registration',
			'[wpca_er_registration',
			'[gravityform',
			'[gravityforms',
			'wp:gravityforms/',
			'wp:gravity-forms/',
			'"gravityforms/form"',
		);

		foreach ( $checks as $needle ) {
			if ( false !== stripos( $content, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 60 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add submenu under WooCommerce.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Event registration defaults', 'wpcodeally-event-registration' ),
			__( 'Event registration', 'wpcodeally-event-registration' ),
			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			'manage_woocommerce',
			'wpcodeally-event-registration',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register options.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			WPCA_ER_Plugin::OPTION_HUB_PAGE_ID,
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			WPCA_ER_Plugin::OPTION_DEBUG,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_debug_flag' ),
				'default'           => false,
			)
		);
	}

	/**
	 * Sanitize debug checkbox (hidden 0 + checkbox 1).
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public function sanitize_debug_flag( $value ) {
		return '1' === (string) $value;
	}

	/**
	 * Render settings HTML.
	 *
	 * @return void
	 */
	public function render_page() {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$hub_id = absint( get_option( WPCA_ER_Plugin::OPTION_HUB_PAGE_ID, 0 ) );

		if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div id="message" class="updated notice is-dismissible"><p>' . esc_html__( 'Settings saved.', 'wpcodeally-event-registration' ) . '</p></div>';
		}

		if ( $hub_id ) {
			$post = get_post( $hub_id );
			if ( $post && is_string( $post->post_content ) && ! self::page_likely_has_registration_form( $post->post_content ) ) {
				echo '<div class="notice notice-warning"><p>' .
					wp_kses_post(
						__( 'The default page does not appear to contain a Gravity Form or the <code>[WPCA_ER_registration]</code> shortcode. Embed your form as usual, or add the shortcode, so customers see a registration form after checkout.', 'wpcodeally-event-registration' )
					) .
					'</p></div>';
			}
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="WPCA_ER_registration_page_id"><?php esc_html_e( 'Default registration page', 'wpcodeally-event-registration' ); ?></label>
						</th>
						<td>
							<?php
							wp_dropdown_pages(
								array(
									// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- consumed by wp_dropdown_pages().
									'name'              => WPCA_ER_Plugin::OPTION_HUB_PAGE_ID,
									'id'                => 'WPCA_ER_registration_page_id',
									// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- consumed by wp_dropdown_pages().
									'selected'          => $hub_id,
									'show_option_none'  => esc_html__( '&mdash; None &mdash;', 'wpcodeally-event-registration' ),
									'option_none_value' => '0',
								)
							);
							?>
							<p class="description">
								<?php esc_html_e( 'Optional. Used when a product has a Gravity Forms ID but no registration page on the product. Embed Gravity Forms on that page normally, or use [WPCA_ER_registration] if you want one template page.', 'wpcodeally-event-registration' ); ?>
							</p>
							<p class="description">
								<strong><?php esc_html_e( 'Per product:', 'wpcodeally-event-registration' ); ?></strong>
								<?php esc_html_e( 'Edit each event product (General tab) and choose its registration page or URL—typically a page that already contains the correct Gravity Form.', 'wpcodeally-event-registration' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Debug logging', 'wpcodeally-event-registration' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( WPCA_ER_Plugin::OPTION_DEBUG ); ?>" value="0" />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( WPCA_ER_Plugin::OPTION_DEBUG ); ?>" value="1" <?php checked( WPCA_ER_Plugin::is_debug() ); ?> />
								<?php esc_html_e( 'Write technical messages to wp-content/wpcodeally-event-registration.log (no personal data).', 'wpcodeally-event-registration' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
