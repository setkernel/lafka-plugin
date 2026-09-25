<?php
/**
 * GX3: IndexNow — tell Bing and the other IndexNow engines (which feed
 * ChatGPT search and Copilot) within minutes when menu URLs change, instead
 * of waiting for the next crawl.
 *
 *   - Key: 32 hex chars, generated once (`lafka_seo_indexnow_key`) and served
 *     at /{key}.txt as the ownership proof.
 *   - Triggers: publish / update of products, pages and posts (not ones
 *     marked noindex), product price / stock changes, category edits.
 *   - Debounced + batched: URLs collect in `lafka_seo_indexnow_queue`; one
 *     Action Scheduler job (`lafka_indexnow_flush`, WP-Cron fallback) sends
 *     them in a single POST a couple of minutes after the last change.
 *   - Never pings from a non-production environment
 *     (wp_get_environment_type() !== 'production') or a site that
 *     discourages search engines — a staging copy must not announce URLs.
 *
 * Toggle: WooCommerce → Settings → Restaurant → Search & AI → IndexNow
 * (`lafka_seo_indexnow_enabled`, default OFF — Site Health suggests it).
 *
 * @package Lafka\Plugin\SEO
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

/** Queue option (list of URLs awaiting the next flush). */
const LAFKA_INDEXNOW_QUEUE = 'lafka_seo_indexnow_queue';

/** IndexNow accepts at most 10,000 URLs per request. */
const LAFKA_INDEXNOW_BATCH = 10000;

if ( ! function_exists( 'lafka_indexnow_enabled' ) ) {
	/**
	 * Whether the operator turned IndexNow on.
	 *
	 * @return bool
	 */
	function lafka_indexnow_enabled(): bool {
		$on = function_exists( 'lafka_seo_is_on' ) && lafka_seo_is_on( 'lafka_seo_indexnow_enabled' );
		/**
		 * Filter whether IndexNow is enabled.
		 *
		 * @since 10.2.0
		 * @param bool $on Option value.
		 */
		return (bool) apply_filters( 'lafka_indexnow_enabled', $on );
	}
}

if ( ! function_exists( 'lafka_indexnow_can_ping' ) ) {
	/**
	 * Enabled AND on production AND the site is public.
	 *
	 * @return bool
	 */
	function lafka_indexnow_can_ping(): bool {
		$env = function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'production';
		$ok  = lafka_indexnow_enabled() && 'production' === $env && '0' !== (string) get_option( 'blog_public', '1' );
		/**
		 * Filter whether IndexNow may send pings on this request.
		 *
		 * @since 10.2.0
		 * @param bool   $ok  Computed gate.
		 * @param string $env Environment type.
		 */
		return (bool) apply_filters( 'lafka_indexnow_can_ping', $ok, $env );
	}
}

if ( ! function_exists( 'lafka_indexnow_key' ) ) {
	/**
	 * The site's IndexNow key, generated on first use.
	 *
	 * @return string 32 lowercase hex characters.
	 */
	function lafka_indexnow_key(): string {
		$key = (string) get_option( 'lafka_seo_indexnow_key', '' );
		if ( 1 === preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			return $key;
		}
		$key = bin2hex( random_bytes( 16 ) );
		update_option( 'lafka_seo_indexnow_key', $key, false );
		return $key;
	}
}

if ( ! function_exists( 'lafka_indexnow_key_url' ) ) {
	/**
	 * Public URL of the key file.
	 *
	 * @return string
	 */
	function lafka_indexnow_key_url(): string {
		return trailingslashit( home_url( '/' ) ) . lafka_indexnow_key() . '.txt';
	}
}

if ( ! function_exists( 'lafka_indexnow_queue_url' ) ) {
	/**
	 * Queue a URL for the next batched ping and (re)arm the debounced flush.
	 *
	 * @param string $url Absolute URL on this site.
	 * @return bool Whether the URL was queued.
	 */
	function lafka_indexnow_queue_url( string $url ): bool {
		if ( '' === $url || ! lafka_indexnow_can_ping() ) {
			return false;
		}
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( '' === $host || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== strtolower( $host ) ) {
			return false;
		}
		$queue = get_option( LAFKA_INDEXNOW_QUEUE, array() );
		$queue = is_array( $queue ) ? $queue : array();
		if ( ! in_array( $url, $queue, true ) ) {
			$queue[] = $url;
			$queue   = array_slice( $queue, -LAFKA_INDEXNOW_BATCH );
			update_option( LAFKA_INDEXNOW_QUEUE, $queue, false );
		}
		lafka_indexnow_schedule_flush();
		return true;
	}
}

if ( ! function_exists( 'lafka_indexnow_schedule_flush' ) ) {
	/**
	 * Arm one flush job (Action Scheduler when available, else WP-Cron).
	 * Already-armed ⇒ no-op, so a burst of edits becomes one request.
	 *
	 * @return void
	 */
	function lafka_indexnow_schedule_flush() {
		/**
		 * Seconds to wait after the first change before pinging (debounce).
		 *
		 * @since 10.2.0
		 * @param int $delay Default 120.
		 */
		$delay = max( 0, (int) apply_filters( 'lafka_indexnow_delay', 120 ) );
		if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_has_scheduled_action' ) ) {
			if ( ! as_has_scheduled_action( 'lafka_indexnow_flush', array(), 'lafka' ) ) {
				as_schedule_single_action( time() + $delay, 'lafka_indexnow_flush', array(), 'lafka' );
			}
			return;
		}
		if ( ! wp_next_scheduled( 'lafka_indexnow_flush' ) ) {
			wp_schedule_single_event( time() + $delay, 'lafka_indexnow_flush' );
		}
	}
}

if ( ! function_exists( 'lafka_indexnow_flush' ) ) {
	/**
	 * Send the queued URLs (Action Scheduler / cron callback).
	 *
	 * @return array{sent:int,code:int} Summary (also stored as `lafka_seo_indexnow_last`).
	 */
	function lafka_indexnow_flush(): array {
		$queue = get_option( LAFKA_INDEXNOW_QUEUE, array() );
		$queue = is_array( $queue ) ? array_values( array_unique( array_filter( array_map( 'strval', $queue ) ) ) ) : array();
		if ( empty( $queue ) || ! lafka_indexnow_can_ping() ) {
			return array(
				'sent' => 0,
				'code' => 0,
			);
		}
		update_option( LAFKA_INDEXNOW_QUEUE, array(), false );

		/**
		 * IndexNow endpoint (api.indexnow.org shares with Bing, Yandex, Seznam …).
		 *
		 * @since 10.2.0
		 * @param string $endpoint Endpoint URL.
		 */
		$endpoint = (string) apply_filters( 'lafka_indexnow_endpoint', 'https://api.indexnow.org/indexnow' );
		$host     = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$sent     = 0;
		$code     = 0;
		$retry    = array();

		foreach ( array_chunk( $queue, LAFKA_INDEXNOW_BATCH ) as $batch ) {
			$response = wp_remote_post(
				$endpoint,
				array(
					'timeout' => 15,
					'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
					'body'    => wp_json_encode(
						array(
							'host'        => $host,
							'key'         => lafka_indexnow_key(),
							'keyLocation' => lafka_indexnow_key_url(),
							'urlList'     => array_values( $batch ),
						)
					),
				)
			);
			$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 300 ) {
				$sent += count( $batch );
			} elseif ( 0 === $code || 429 === $code || $code >= 500 ) {
				// Transient failure: keep the URLs for the next change's flush.
				$retry = array_merge( $retry, $batch );
			}
		}

		if ( ! empty( $retry ) ) {
			$pending = get_option( LAFKA_INDEXNOW_QUEUE, array() );
			$pending = is_array( $pending ) ? $pending : array();
			update_option( LAFKA_INDEXNOW_QUEUE, array_slice( array_values( array_unique( array_merge( $retry, $pending ) ) ), -LAFKA_INDEXNOW_BATCH ), false );
		}

		$summary = array(
			'sent' => $sent,
			'code' => $code,
		);
		update_option( 'lafka_seo_indexnow_last', $summary + array( 'time' => time() ), false );
		return $summary;
	}
}

if ( ! function_exists( 'lafka_indexnow_post_types' ) ) {
	/**
	 * Post types whose URLs are announced.
	 *
	 * @return list<string>
	 */
	function lafka_indexnow_post_types(): array {
		return (array) apply_filters( 'lafka_indexnow_post_types', array( 'product', 'page', 'post' ) );
	}
}

if ( ! function_exists( 'lafka_indexnow_on_transition' ) ) {
	/**
	 * `transition_post_status`: announce published / updated content.
	 *
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 * @param object $post       WP_Post.
	 * @return void
	 */
	function lafka_indexnow_on_transition( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || ! is_object( $post ) || ! isset( $post->ID, $post->post_type ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, lafka_indexnow_post_types(), true ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post->ID ) ) {
			return;
		}
		if ( '1' === (string) get_post_meta( (int) $post->ID, '_lafka_seo_noindex', true ) ) {
			return;
		}
		lafka_indexnow_queue_url( (string) get_permalink( $post ) );
	}
}

if ( ! function_exists( 'lafka_indexnow_on_product_change' ) ) {
	/**
	 * Price / stock changes (incl. variations → their parent product).
	 *
	 * @param int|object $product_or_id Product id or WC_Product.
	 * @return void
	 */
	function lafka_indexnow_on_product_change( $product_or_id ) {
		$id = is_object( $product_or_id ) && method_exists( $product_or_id, 'get_id' ) ? (int) $product_or_id->get_id() : (int) $product_or_id;
		if ( $id <= 0 ) {
			return;
		}
		$parent = (int) wp_get_post_parent_id( $id );
		if ( $parent > 0 ) {
			$id = $parent;
		}
		if ( 'publish' !== get_post_status( $id ) ) {
			return;
		}
		lafka_indexnow_queue_url( (string) get_permalink( $id ) );
	}
}

if ( ! function_exists( 'lafka_indexnow_on_props_updated' ) ) {
	/**
	 * `woocommerce_product_object_updated_props`: only price props count.
	 *
	 * @param object            $product WC_Product.
	 * @param array<int,string> $props   Updated props.
	 * @return void
	 */
	function lafka_indexnow_on_props_updated( $product, $props ) {
		if ( array_intersect( (array) $props, array( 'regular_price', 'sale_price', 'price', 'stock_status' ) ) ) {
			lafka_indexnow_on_product_change( $product );
		}
	}
}

if ( ! function_exists( 'lafka_indexnow_on_term_edit' ) ) {
	/**
	 * Category edits (description / FAQ changes the landing page).
	 *
	 * @param int $term_id Term id.
	 * @return void
	 */
	function lafka_indexnow_on_term_edit( $term_id ) {
		$link = get_term_link( (int) $term_id, 'product_cat' );
		if ( ! is_wp_error( $link ) ) {
			lafka_indexnow_queue_url( (string) $link );
		}
	}
}

if ( ! function_exists( 'lafka_indexnow_register_rewrites' ) ) {
	/**
	 * /{32-hex}.txt → key file.
	 *
	 * @return void
	 */
	function lafka_indexnow_register_rewrites() {
		add_rewrite_rule( '^([a-f0-9]{32})\.txt$', 'index.php?lafka_indexnow_key=$matches[1]', 'top' );
	}
}

if ( ! function_exists( 'lafka_indexnow_query_vars' ) ) {
	/**
	 * @param array<int,string> $vars Query vars.
	 * @return array<int,string>
	 */
	function lafka_indexnow_query_vars( $vars ) {
		$vars[] = 'lafka_indexnow_key';
		return $vars;
	}
}

if ( ! function_exists( 'lafka_indexnow_serve_key' ) ) {
	/**
	 * `template_redirect`: serve the key file (only the real key, only while
	 * IndexNow is enabled; anything else falls through to the normal 404).
	 *
	 * @return void
	 */
	function lafka_indexnow_serve_key() {
		$asked = (string) get_query_var( 'lafka_indexnow_key' );
		if ( '' === $asked ) {
			return;
		}
		$key = (string) get_option( 'lafka_seo_indexnow_key', '' );
		if ( ! lafka_indexnow_enabled() || '' === $key || ! hash_equals( $key, $asked ) ) {
			global $wp_query;
			if ( is_object( $wp_query ) && method_exists( $wp_query, 'set_404' ) ) {
				$wp_query->set_404();
			}
			status_header( 404 );
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo esc_html( $key );
		exit;
	}
}

if ( ! function_exists( 'lafka_indexnow_ensure_key' ) ) {
	/**
	 * Generate the key as soon as IndexNow is switched on, so the key file
	 * exists before the first ping.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $value     New value.
	 * @return void
	 */
	function lafka_indexnow_ensure_key( $old_value, $value ) {
		if ( 'yes' === $value ) {
			lafka_indexnow_key();
		}
	}
}

if ( ! function_exists( 'lafka_indexnow_register_module' ) ) {
	/**
	 * List IndexNow on Lafka → Modules (same option as the settings toggle).
	 *
	 * @return void
	 */
	function lafka_indexnow_register_module() {
		if ( ! class_exists( 'Lafka_Module' ) || ! class_exists( 'Lafka_Module_Registry' ) ) {
			return;
		}
		Lafka_Module_Registry::register(
			new Lafka_Module(
				array(
					'id'              => 'indexnow',
					'label'           => esc_html__( 'IndexNow', 'lafka-plugin' ),
					'description'     => esc_html__( 'Tell Bing and other IndexNow search engines within minutes when menu items, prices or pages change. Production sites only.', 'lafka-plugin' ),
					'category'        => 'seo',
					'storage'         => 'option',
					'default_enabled' => false,
					'get_enabled'     => static function () {
						return lafka_seo_is_on( 'lafka_seo_indexnow_enabled' );
					},
					'set_enabled'     => static function ( bool $enabled ) {
						update_option( 'lafka_seo_indexnow_enabled', $enabled ? 'yes' : 'no' );
					},
					'settings_path'   => 'admin.php?page=wc-settings&tab=lafka_restaurant&section=search',
					'docs_slug'       => 'indexnow',
				)
			)
		);
	}
}

add_action( 'lafka_register_modules', 'lafka_indexnow_register_module' );
add_action( 'init', 'lafka_indexnow_register_rewrites' );
add_filter( 'query_vars', 'lafka_indexnow_query_vars' );
add_action( 'template_redirect', 'lafka_indexnow_serve_key', 0 );
add_action( 'lafka_indexnow_flush', 'lafka_indexnow_flush' );
add_action( 'update_option_lafka_seo_indexnow_enabled', 'lafka_indexnow_ensure_key', 10, 2 );
add_action( 'transition_post_status', 'lafka_indexnow_on_transition', 20, 3 );
add_action( 'woocommerce_product_set_stock_status', 'lafka_indexnow_on_product_change' );
add_action( 'woocommerce_variation_set_stock_status', 'lafka_indexnow_on_product_change' );
add_action( 'woocommerce_product_object_updated_props', 'lafka_indexnow_on_props_updated', 10, 2 );
add_action( 'edited_product_cat', 'lafka_indexnow_on_term_edit' );
