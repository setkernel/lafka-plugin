<?php
/**
 * Lafka_Security_Admin — admin UI for the Lafka_Security_Headers module (P2-05a).
 *
 * Adds Tools → Lafka Security so ops can flip the `enable_security_headers`
 * toggle without WP-CLI. Read-only status panel shows what's currently active.
 *
 * The toggle writes to the existing `lafka` option array (the same key
 * Lafka_Security_Headers::is_active() reads from), so this is purely a UI
 * over the existing gating logic — no engine changes.
 *
 * @package Lafka
 * @since   8.7.0
 */

// $_GET reads in this admin file are for display state (which tab is active,
// banner dismissals, etc.) — no state mutation. Form submits go through WP
// Settings API which verifies its own nonce.
// phpcs:disable WordPress.Security.NonceVerification.Recommended

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Security_Admin' ) ) {

	final class Lafka_Security_Admin {

		const PAGE_SLUG    = 'lafka-security';
		const NONCE_ACTION = 'lafka_security_save';

		/** @var Lafka_Security_Admin|null */
		private static $instance = null;

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			add_action( 'admin_menu', array( $this, 'register_menu' ) );
			add_action( 'admin_post_lafka_security_save', array( $this, 'handle_save' ) );
		}

		public function register_menu() {
			add_management_page(
				esc_html__( 'Lafka Security', 'lafka-plugin' ),
				esc_html__( 'Lafka Security', 'lafka-plugin' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_page' )
			);
		}

		/**
		 * Form-post handler. Toggles `lafka['enable_security_headers']` between
		 * 'enabled' and 'disabled' based on the submitted value.
		 */
		public function handle_save() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to change Lafka security settings.', 'lafka-plugin' ), 403 );
			}
			check_admin_referer( self::NONCE_ACTION );

			$requested = isset( $_POST['enable_security_headers'] ) ? sanitize_text_field( wp_unslash( $_POST['enable_security_headers'] ) ) : 'disabled';
			$value     = ( 'enabled' === $requested ) ? 'enabled' : 'disabled';

			// Store in dedicated `lafka_security_options` option (NOT in the main `lafka`
			// option) — the theme's options-framework `register_setting('lafka', ...)`
			// sanitize callback drops unregistered keys, so writing through `lafka` would
			// silently lose this toggle.
			$opts = get_option( Lafka_Security_Headers::OPTION_KEY, array() );
			if ( ! is_array( $opts ) ) {
				$opts = array();
			}
			$opts[ Lafka_Security_Headers::TOGGLE_OPTION_KEY ] = $value;
			update_option( Lafka_Security_Headers::OPTION_KEY, $opts );

			wp_safe_redirect(
                add_query_arg(
                    array(
						'page'    => self::PAGE_SLUG,
						'updated' => $value,
                    ),
                    admin_url( 'tools.php' ) 
                ) 
            );
			exit;
		}

		public function render_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to view this page.', 'lafka-plugin' ), 403 );
			}

			$opts    = get_option( Lafka_Security_Headers::OPTION_KEY, array() );
			$current = is_array( $opts ) && isset( $opts[ Lafka_Security_Headers::TOGGLE_OPTION_KEY ] )
				? $opts[ Lafka_Security_Headers::TOGGLE_OPTION_KEY ]
				: Lafka_Options::get( Lafka_Security_Headers::TOGGLE_OPTION_KEY, '' );
			$active  = class_exists( 'Lafka_Security_Headers' )
				&& Lafka_Security_Headers::instance()->is_active();

			$updated = isset( $_GET['updated'] ) ? sanitize_text_field( wp_unslash( $_GET['updated'] ) ) : '';
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Lafka Security', 'lafka-plugin' ); ?></h1>

				<?php if ( '' !== $updated ) : ?>
					<div class="notice notice-success is-dismissible">
						<p>
                        <?php
							echo 'enabled' === $updated
								? esc_html__( 'Security headers enabled.', 'lafka-plugin' )
								: esc_html__( 'Security headers disabled.', 'lafka-plugin' );
						?>
                        </p>
					</div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="lafka_security_save">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="enable_security_headers"><?php esc_html_e( 'Security headers', 'lafka-plugin' ); ?></label>
							</th>
							<td>
								<select id="enable_security_headers" name="enable_security_headers">
									<option value="disabled" <?php selected( 'disabled', $current ); ?>>
										<?php esc_html_e( 'Disabled', 'lafka-plugin' ); ?>
									</option>
									<option value="enabled" <?php selected( 'enabled', $current ); ?>>
										<?php esc_html_e( 'Enabled', 'lafka-plugin' ); ?>
									</option>
								</select>
								<p class="description">
									<?php esc_html_e( 'Currently:', 'lafka-plugin' ); ?>
									<strong>
                                    <?php
										echo $active
											? esc_html__( 'ACTIVE', 'lafka-plugin' )
											: esc_html__( 'INACTIVE', 'lafka-plugin' );
									?>
                                    </strong>
								</p>
							</td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'What enabling does', 'lafka-plugin' ); ?></h2>
					<ul style="list-style:disc;margin-left:20px;">
						<li><code>X-Content-Type-Options: nosniff</code> — <?php esc_html_e( 'blocks MIME-type sniffing', 'lafka-plugin' ); ?></li>
						<li><code>X-Frame-Options: SAMEORIGIN</code> — <?php esc_html_e( 'blocks clickjacking via iframe embeds (heads up: may break Stripe / payment-gateway return iframes)', 'lafka-plugin' ); ?></li>
						<li><code>Referrer-Policy: strict-origin-when-cross-origin</code> — <?php esc_html_e( 'limits Referer leakage', 'lafka-plugin' ); ?></li>
						<li><code>Permissions-Policy: interest-cohort=()</code> — <?php esc_html_e( 'opts out of FLoC tracking', 'lafka-plugin' ); ?></li>
						<li><?php esc_html_e( 'Hides the /wp-json/wp/v2/users REST endpoint (user enumeration)', 'lafka-plugin' ); ?></li>
						<li><?php esc_html_e( 'Redirects unauthenticated /?author=N probes to home', 'lafka-plugin' ); ?></li>
					</ul>

					<p>
						<?php esc_html_e( 'To roll back: change Disabled and Save.', 'lafka-plugin' ); ?>
						<?php esc_html_e( 'WP-CLI equivalent:', 'lafka-plugin' ); ?>
						<code>wp option patch insert lafka_security_options enable_security_headers enabled</code>
					</p>

					<?php submit_button( esc_html__( 'Save', 'lafka-plugin' ) ); ?>
				</form>
			</div>
			<?php
		}
	}

	if ( is_admin() ) {
		Lafka_Security_Admin::instance();
	}
}
