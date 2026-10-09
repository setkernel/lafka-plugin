<?php
/**
 * Installable app (PWA): the web app manifest, a precached offline page, the
 * settings the service worker needs and the "Add to home screen" card.
 *
 * One service worker serves the site. The theme owns the file and its URL
 * (`lafka_service_worker_url()`, shared with Web Push); this class hands it a
 * configuration through the `lafka_service_worker_config` filter and registers
 * it from the storefront script, so the plugin stays theme-agnostic: with no
 * theme worker there is no registration and no card, while the manifest and the
 * offline page still work.
 *
 * Endpoints use a query flag, like the worker, so no rewrite rule is needed and
 * nothing is written to disk:
 *   /?lafka_manifest=1   the manifest (application/manifest+json)
 *   /?lafka_offline=1    the offline page the worker precaches
 *
 * @package Lafka\Plugin\Pwa
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Pwa' ) ) {

	/**
	 * Installable app.
	 */
	final class Lafka_Pwa {

		/** Query flag of the manifest. */
		const QUERY_MANIFEST = 'lafka_manifest';

		/** Query flag of the offline page. */
		const QUERY_OFFLINE = 'lafka_offline';

		/** Marker on the start URL; the Insights module counts it as the "pwa" source. */
		const START_MARKER = 'pwa';

		/**
		 * Whether the module is on (default on: the manifest and the offline
		 * page are harmless).
		 *
		 * @return bool
		 */
		public static function enabled(): bool {
			return 'yes' === get_option( 'lafka_pwa_enabled', 'yes' );
		}

		/**
		 * Whether the "Add to home screen" card is on (its own setting, default off).
		 *
		 * @return bool
		 */
		public static function prompt_enabled(): bool {
			return 'yes' === get_option( 'lafka_pwa_install_prompt', 'no' );
		}

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'init', array( __CLASS__, 'serve' ), 1 );
			add_action( 'send_headers', array( __CLASS__, 'session_header' ) );
			add_action( 'wp_head', array( __CLASS__, 'head' ), 2 );
			add_filter( 'lafka_service_worker_config', array( __CLASS__, 'worker_config' ) );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 5 );
			add_action( 'wp_footer', array( __CLASS__, 'card' ), 20 );
		}

		/**
		 * The service worker URL: the theme's, or none.
		 *
		 * @return string
		 */
		public static function worker_url(): string {
			$url = function_exists( 'lafka_service_worker_url' ) ? lafka_service_worker_url() : '';
			/**
			 * Filter the service worker URL the app registers. Empty = none
			 * (no registration, no install card).
			 *
			 * @since 10.4.0
			 * @param string $url Worker URL.
			 */
			return (string) apply_filters( 'lafka_pwa_service_worker_url', $url );
		}

		/**
		 * The worker's scope: the site's path.
		 *
		 * @return string
		 */
		public static function worker_scope(): string {
			return function_exists( 'lafka_service_worker_scope' ) ? lafka_service_worker_scope() : self::home_path();
		}

		/**
		 * Whether the site can be installed: a site icon (Chrome needs 192 and
		 * 512 pixel icons) and a worker to register.
		 *
		 * @return bool
		 */
		public static function installable(): bool {
			return has_site_icon() && '' !== self::worker_url();
		}

		/**
		 * The path of the site root ("/" or "/subdir/").
		 *
		 * @return string
		 */
		private static function home_path(): string {
			$path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			return '' === $path ? '/' : $path;
		}

		/**
		 * The menu page URL (the app's start page), kept on this site.
		 *
		 * @return string
		 */
		public static function menu_url(): string {
			$url = function_exists( 'lafka_get_menu_url' ) ? lafka_get_menu_url() : home_url( '/' );
			if ( wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) {
				$url = home_url( '/' );
			}
			return $url;
		}

		/**
		 * The restaurant's name.
		 *
		 * @return string
		 */
		private static function name(): string {
			$info = function_exists( 'lafka_get_restaurant_info' ) ? lafka_get_restaurant_info() : array();
			$name = trim( (string) ( $info['name'] ?? '' ) );
			return '' !== $name ? $name : html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		/**
		 * The name under the home screen icon (12 characters or fewer).
		 *
		 * @return string
		 */
		private static function short_name(): string {
			$set = trim( (string) get_option( 'lafka_pwa_short_name', '' ) );
			if ( '' !== $set ) {
				return mb_substr( $set, 0, 12 );
			}
			$name = self::name();
			if ( mb_strlen( $name ) <= 12 ) {
				return $name;
			}
			$short = '';
			foreach ( explode( ' ', $name ) as $word ) {
				$next = '' === $short ? $word : $short . ' ' . $word;
				if ( mb_strlen( $next ) > 12 ) {
					break;
				}
				$short = $next;
			}
			return '' !== $short ? $short : mb_substr( $name, 0, 12 );
		}

		/**
		 * The colours of the app: the theme's active design through a filter,
		 * so the plugin names no design colour.
		 *
		 * @return array{theme_color:string,background_color:string,text_color:string}
		 */
		public static function colors(): array {
			$colors = array(
				'theme_color'      => '#ffffff',
				'background_color' => '#ffffff',
				'text_color'       => '#1f1f1f',
			);
			/**
			 * Filter the app colours (hex values): the toolbar colour, the
			 * splash and offline page background, and the offline page text.
			 * The Lafka theme supplies the active preset's.
			 *
			 * @since 10.4.0
			 * @param array<string,string> $colors theme_color, background_color, text_color.
			 */
			$filtered = (array) apply_filters( 'lafka_pwa_colors', $colors );
			foreach ( $colors as $key => $default ) {
				$value          = isset( $filtered[ $key ] ) ? sanitize_hex_color( (string) $filtered[ $key ] ) : null;
				$colors[ $key ] = $value ? $value : $default;
			}
			return $colors;
		}

		/**
		 * The offline page URL.
		 *
		 * @return string
		 */
		public static function offline_url(): string {
			return add_query_arg( self::QUERY_OFFLINE, '1', home_url( '/' ) );
		}

		/**
		 * The manifest URL.
		 *
		 * @return string
		 */
		public static function manifest_url(): string {
			return add_query_arg( self::QUERY_MANIFEST, '1', home_url( '/' ) );
		}

		/**
		 * Icons for the manifest, from the WordPress site icon.
		 *
		 * @return array<int,array<string,string>>
		 */
		private static function icons(): array {
			$icons = array();
			foreach ( array( 192, 512 ) as $size ) {
				$url = (string) get_site_icon_url( $size );
				if ( '' === $url ) {
					continue;
				}
				$type    = wp_check_filetype( (string) wp_parse_url( $url, PHP_URL_PATH ) );
				$icons[] = array(
					'src'     => $url,
					'sizes'   => $size . 'x' . $size,
					'type'    => $type['type'] ? $type['type'] : 'image/png',
					'purpose' => 'any',
				);
			}
			/**
			 * A 512 pixel icon drawn for masking (the artwork inside the central
			 * 80% circle). Empty = none; Android then crops the "any" icon.
			 *
			 * @since 10.4.0
			 * @param string $url Maskable icon URL.
			 */
			$maskable = (string) apply_filters( 'lafka_pwa_maskable_icon_url', '' );
			if ( '' !== $maskable ) {
				$type    = wp_check_filetype( (string) wp_parse_url( $maskable, PHP_URL_PATH ) );
				$icons[] = array(
					'src'     => $maskable,
					'sizes'   => '512x512',
					'type'    => $type['type'] ? $type['type'] : 'image/png',
					'purpose' => 'maskable',
				);
			}
			return $icons;
		}

		/**
		 * The manifest document.
		 *
		 * @return array<string,mixed>
		 */
		public static function manifest(): array {
			$colors   = self::colors();
			$manifest = array(
				'id'               => self::home_path(),
				'name'             => self::name(),
				'short_name'       => self::short_name(),
				'description'      => wp_strip_all_tags( (string) get_bloginfo( 'description' ) ),
				'lang'             => (string) get_bloginfo( 'language' ),
				'start_url'        => add_query_arg( 'source', self::START_MARKER, self::menu_url() ),
				'scope'            => self::home_path(),
				'display'          => 'standalone',
				'theme_color'      => $colors['theme_color'],
				'background_color' => $colors['background_color'],
				'categories'       => array( 'food', 'shopping' ),
				'icons'            => self::icons(),
			);
			if ( '' === $manifest['description'] ) {
				unset( $manifest['description'] );
			}
			/**
			 * Filter the web app manifest.
			 *
			 * @since 10.4.0
			 * @param array<string,mixed> $manifest Manifest.
			 */
			return (array) apply_filters( 'lafka_pwa_manifest', $manifest );
		}

		/**
		 * Answer the manifest and offline page requests, then stop.
		 *
		 * @return void
		 */
		public static function serve(): void {
			// Public, read-only documents selected by a query flag; nothing is changed.
			if ( '1' === filter_input( INPUT_GET, self::QUERY_MANIFEST, FILTER_SANITIZE_NUMBER_INT ) ) {
				nocache_headers();
				header( 'Content-Type: application/manifest+json; charset=utf-8' );
				header( 'X-Robots-Tag: noindex' );
				echo lafka_pwa_json( self::manifest() );
				exit;
			}
			if ( '1' === filter_input( INPUT_GET, self::QUERY_OFFLINE, FILTER_SANITIZE_NUMBER_INT ) ) {
				nocache_headers();
				header( 'Content-Type: text/html; charset=utf-8' );
				header( 'X-Robots-Tag: noindex' );
				self::render_offline();
				exit;
			}
		}

		/**
		 * Mark pages served to a visitor with a session (signed in, or with a
		 * cart): the worker never keeps a copy of those.
		 *
		 * @return void
		 */
		public static function session_header(): void {
			if ( is_admin() || headers_sent() ) {
				return;
			}
			$session = is_user_logged_in();
			foreach ( array_keys( $_COOKIE ) as $name ) {
				if ( 0 === strpos( (string) $name, 'wp_woocommerce_session_' ) || 'woocommerce_items_in_cart' === $name || 'woocommerce_cart_hash' === $name ) {
					$session = true;
					break;
				}
			}
			if ( $session ) {
				header( 'X-Lafka-Session: 1' );
			}
		}

		/**
		 * The manifest link, the toolbar colour and the iOS title.
		 *
		 * @return void
		 */
		public static function head(): void {
			if ( ! has_site_icon() ) {
				return;
			}
			$colors = self::colors();
			printf( "<link rel=\"manifest\" href=\"%s\" crossorigin=\"use-credentials\">\n", esc_url( self::manifest_url() ) );
			printf( "<meta name=\"theme-color\" content=\"%s\">\n", esc_attr( $colors['theme_color'] ) );
			printf( "<meta name=\"apple-mobile-web-app-title\" content=\"%s\">\n", esc_attr( self::short_name() ) );
		}

		/**
		 * Settings for the service worker (merged into the theme's worker file).
		 *
		 * @param mixed $config Config so far.
		 * @return array<string,mixed>
		 */
		public static function worker_config( $config ): array {
			$config = is_array( $config ) ? $config : array();
			$never  = array();
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$path = function_exists( 'wc_get_page_permalink' ) ? (string) wp_parse_url( wc_get_page_permalink( $page ), PHP_URL_PATH ) : '';
				if ( '' !== $path && '/' !== $path ) {
					$never[] = trailingslashit( $path );
				}
			}
			$offline = (string) wp_parse_url( self::offline_url(), PHP_URL_PATH ) . '?' . self::QUERY_OFFLINE . '=1';
			return array_merge(
				$config,
				array(
					'version'    => defined( 'LAFKA_PLUGIN_VERSION' ) ? LAFKA_PLUGIN_VERSION : '0',
					'pwa'        => true,
					'menuPath'   => trailingslashit( (string) wp_parse_url( self::menu_url(), PHP_URL_PATH ) ),
					'offlineUrl' => $offline,
					'never'      => array_values( array_unique( $never ) ),
				)
			);
		}

		/**
		 * Whether the visitor is on a page where the card must not appear.
		 *
		 * @return bool
		 */
		private static function blocked_page(): bool {
			if ( self::ordered_page() ) {
				return false;
			}
			return ( function_exists( 'is_cart' ) && is_cart() )
				|| ( function_exists( 'is_checkout' ) && is_checkout() )
				|| ( function_exists( 'is_account_page' ) && is_account_page() )
				|| ( function_exists( 'is_product' ) && is_product() );
		}

		/**
		 * Whether this is the order confirmation (the best moment to ask).
		 *
		 * @return bool
		 */
		private static function ordered_page(): bool {
			return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' );
		}

		/**
		 * The script and the card's structure.
		 *
		 * @return void
		 */
		public static function enqueue(): void {
			$sw = self::worker_url();
			if ( is_admin() || '' === $sw ) {
				return;
			}
			$rel = function_exists( 'lafka_plugin_script_path' ) ? lafka_plugin_script_path( 'assets/js/lafka-pwa.min.js' ) : 'assets/js/lafka-pwa.min.js';
			wp_enqueue_script(
				'lafka-pwa',
				plugins_url( $rel, LAFKA_PLUGIN_FILE ),
				array(),
				lafka_plugin_asset_version( $rel ),
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
			$prompt = self::prompt_enabled() && has_site_icon() && ! self::blocked_page();
			/**
			 * Days the card stays away after a visitor dismisses it.
			 *
			 * @since 10.4.0
			 * @param int $days Days.
			 */
			$snooze = (int) apply_filters( 'lafka_pwa_snooze_days', 30 );
			wp_add_inline_script(
				'lafka-pwa',
				'window.lafkaPwa=' . wp_json_encode(
					array(
						'swUrl'    => $sw,
						'scope'    => self::worker_scope(),
						'menuPath' => trailingslashit( (string) wp_parse_url( self::menu_url(), PHP_URL_PATH ) ),
						'prompt'   => $prompt,
						'visits'   => max( 1, (int) get_option( 'lafka_pwa_install_visits', 2 ) ),
						'snooze'   => max( 1, $snooze ),
						'ordered'  => self::ordered_page(),
					)
				) . ';',
				'before'
			);
			if ( $prompt ) {
				wp_enqueue_style( 'lafka-pwa', plugins_url( 'assets/css/lafka-pwa.css', LAFKA_PLUGIN_FILE ), array(), lafka_plugin_asset_version( 'assets/css/lafka-pwa.css' ) );
			}
		}

		/**
		 * The "Add to home screen" card (hidden until the script decides).
		 *
		 * @return void
		 */
		public static function card(): void {
			if ( is_admin() || ! self::prompt_enabled() || ! has_site_icon() || self::blocked_page() || '' === self::worker_url() ) {
				return;
			}
			$name = self::name();
			$copy = array(
				/* translators: %s: restaurant name. */
				'title'   => sprintf( __( 'Add %s to your home screen', 'lafka-plugin' ), $name ),
				'text'    => __( 'Open the menu in one tap, like an app.', 'lafka-plugin' ),
				'install' => __( 'Add to home screen', 'lafka-plugin' ),
				'later'   => __( 'Not now', 'lafka-plugin' ),
				'ios'     => __( 'Tap the Share icon, then choose "Add to Home Screen".', 'lafka-plugin' ),
				'gotit'   => __( 'Got it', 'lafka-plugin' ),
				'close'   => __( 'Close', 'lafka-plugin' ),
			);
			/**
			 * Filter the install card's wording (keys: title, text, install,
			 * later, ios, gotit, close).
			 *
			 * @since 10.4.0
			 * @param array<string,string> $copy Wording.
			 */
			$copy = (array) apply_filters( 'lafka_pwa_install_copy', $copy );
			?>
<aside class="lafka-install-card" role="complementary" aria-labelledby="lafka-install-title" hidden>
	<div class="lafka-install-card__inner lafka-card lafka-card--float">
		<div class="lafka-install-card__body">
			<p class="lafka-install-card__title" id="lafka-install-title"><?php echo esc_html( (string) ( $copy['title'] ?? '' ) ); ?></p>
			<p class="lafka-install-card__text" data-lafka-install-android><?php echo esc_html( (string) ( $copy['text'] ?? '' ) ); ?></p>
			<p class="lafka-install-card__text" data-lafka-install-ios hidden>
				<?php echo esc_html( (string) ( $copy['ios'] ?? '' ) ); ?>
				<svg class="lafka-install-card__share" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3v12M8 7l4-4 4 4M6 11H5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-8a1 1 0 0 0-1-1h-1"/></svg>
			</p>
			<div class="lafka-install-card__actions">
				<button type="button" class="lafka-install-card__install lafka-btn lafka-btn--primary lafka-btn--sm" data-lafka-install-android><?php echo esc_html( (string) ( $copy['install'] ?? '' ) ); ?></button>
				<button type="button" class="lafka-install-card__later lafka-btn lafka-btn--quiet lafka-btn--sm" data-lafka-install-android><?php echo esc_html( (string) ( $copy['later'] ?? '' ) ); ?></button>
				<button type="button" class="lafka-install-card__later lafka-btn lafka-btn--primary lafka-btn--sm" data-lafka-install-ios hidden><?php echo esc_html( (string) ( $copy['gotit'] ?? '' ) ); ?></button>
			</div>
		</div>
		<button type="button" class="lafka-install-card__close" aria-label="<?php echo esc_attr( (string) ( $copy['close'] ?? '' ) ); ?>">&times;</button>
	</div>
</aside>
			<?php
		}

		/**
		 * The offline page: the restaurant's name, a tap-to-call phone and the
		 * hours, in the app colours. Inline styles only, so it needs nothing
		 * else to show.
		 *
		 * @return void
		 */
		private static function render_offline(): void {
			$info          = function_exists( 'lafka_get_restaurant_info' ) ? lafka_get_restaurant_info() : array();
			$lafka_offline = array(
				'name'   => self::name(),
				'colors' => self::colors(),
				'status' => class_exists( 'Lafka_Order_Hours' ) ? Lafka_Order_Hours::client_status() : null,
				'hours'  => (array) ( $info['hours'] ?? array() ),
				'tel'    => (string) ( $info['phone_tel'] ?? '' ),
				'phone'  => (string) ( $info['phone_display'] ?? '' ),
				'menu'   => self::menu_url(),
				'lang'   => (string) get_bloginfo( 'language' ),
			);
			include __DIR__ . '/offline-page.php';
		}
	}
}
