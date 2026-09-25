<?php
/**
 * Lafka_Fatal_Capture — index PHP fatals that originate in Lafka code (GX1 / A5).
 *
 * WooCommerce already logs every shutdown fatal to its `fatal-errors` source
 * and then fires `woocommerce_shutdown_error`. Lafka listens to that action and
 * — only when the failing file lives inside the Lafka plugin, the Lafka parent
 * theme or its child theme — records a `critical` incident on the `php`
 * channel, so a Lafka fatal shows up on Lafka → Diagnostics, in Site Health and
 * in the daily digest. Fatals from other plugins are left to WooCommerce.
 *
 * Without WooCommerce, one register_shutdown_function() does the same scoped
 * check. No global exception handler is ever installed; entry points use
 * Lafka_Log::guard() instead.
 *
 * @package Lafka\Plugin\Observability
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Fatal_Capture' ) ) {

	/**
	 * Lafka-scoped fatal capture.
	 */
	final class Lafka_Fatal_Capture {

		/** PHP error types that end the request. */
		const FATAL_TYPES = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );

		/**
		 * Hook into WooCommerce's shutdown handler (or PHP's, without WC).
		 *
		 * @return void
		 */
		public static function register(): void {
			add_action( 'woocommerce_shutdown_error', array( __CLASS__, 'on_shutdown_error' ) );
			if ( ! ( defined( 'LAFKA_PLUGIN_IS_WOOCOMMERCE' ) && LAFKA_PLUGIN_IS_WOOCOMMERCE ) ) {
				register_shutdown_function( array( __CLASS__, 'on_php_shutdown' ) );
			}
		}

		/**
		 * Directories whose files count as "Lafka code".
		 *
		 * @return array<int,string> Absolute directory paths with a trailing slash.
		 */
		public static function roots(): array {
			$roots = array();
			if ( defined( 'LAFKA_PLUGIN_FILE' ) ) {
				$roots[] = dirname( LAFKA_PLUGIN_FILE );
			}
			$template = function_exists( 'get_template' ) ? (string) get_template() : '';
			if ( 0 === strpos( strtolower( $template ), 'lafka' ) ) {
				if ( function_exists( 'get_template_directory' ) ) {
					$roots[] = (string) get_template_directory();
				}
				if ( function_exists( 'get_stylesheet_directory' ) ) {
					$roots[] = (string) get_stylesheet_directory();
				}
			}
			if ( function_exists( 'apply_filters' ) ) {
				$filtered = apply_filters( 'lafka_fatal_capture_roots', $roots );
				if ( is_array( $filtered ) ) {
					$roots = $filtered;
				}
			}
			$out = array();
			foreach ( $roots as $root ) {
				$root = rtrim( str_replace( '\\', '/', (string) $root ), '/' );
				if ( '' !== $root ) {
					$out[] = $root . '/';
				}
			}
			return array_values( array_unique( $out ) );
		}

		/**
		 * Whether a file path is inside Lafka code.
		 *
		 * @param string                 $file  Absolute path.
		 * @param array<int,string>|null $roots Roots (default roots()).
		 * @return bool
		 */
		public static function is_lafka_file( string $file, ?array $roots = null ): bool {
			$file = str_replace( '\\', '/', $file );
			if ( '' === $file ) {
				return false;
			}
			foreach ( null === $roots ? self::roots() : $roots as $root ) {
				$root = rtrim( str_replace( '\\', '/', (string) $root ), '/' ) . '/';
				if ( '/' !== $root && 0 === strpos( $file, $root ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * `woocommerce_shutdown_error` listener.
		 *
		 * @param mixed $error error_get_last() shape: type, message, file, line.
		 * @return bool True when a Lafka incident was recorded.
		 */
		public static function on_shutdown_error( $error ): bool {
			if ( ! is_array( $error ) || empty( $error['file'] ) || ! isset( $error['type'] ) ) {
				return false;
			}
			if ( ! in_array( (int) $error['type'], self::FATAL_TYPES, true ) ) {
				return false;
			}
			if ( ! self::is_lafka_file( (string) $error['file'] ) || ! class_exists( 'Lafka_Log' ) ) {
				return false;
			}

			return Lafka_Log::critical(
				'php',
				self::summarize( (string) ( $error['message'] ?? '' ) ),
				array(
					'code' => 'php_fatal',
					'type' => self::type_name( (int) $error['type'] ),
					'file' => (string) $error['file'],
					'line' => (int) ( $error['line'] ?? 0 ),
				)
			);
		}

		/**
		 * register_shutdown_function() fallback when WooCommerce is inactive.
		 *
		 * @return void
		 */
		public static function on_php_shutdown(): void {
			$error = error_get_last();
			if ( is_array( $error ) ) {
				self::on_shutdown_error( $error );
			}
		}

		/**
		 * First line of a fatal message, with server paths made relative.
		 *
		 * @param string $message Raw message (may contain a stack trace).
		 * @return string
		 */
		public static function summarize( string $message ): string {
			$first = strtok( $message, "\n" );
			$first = false === $first ? '' : trim( $first );
			return (string) preg_replace_callback(
				'#(/[^\s:()\'"]+\.php)#',
				static function ( $m ) {
					return class_exists( 'Lafka_Log_Scrubber' ) ? Lafka_Log_Scrubber::relative_path( $m[1] ) : basename( $m[1] );
				},
				$first
			);
		}

		/**
		 * @param int $type PHP error type.
		 * @return string
		 */
		private static function type_name( int $type ): string {
			$names = array(
				E_ERROR             => 'E_ERROR',
				E_PARSE             => 'E_PARSE',
				E_CORE_ERROR        => 'E_CORE_ERROR',
				E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
				E_USER_ERROR        => 'E_USER_ERROR',
				E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
			);
			return $names[ $type ] ?? (string) $type;
		}
	}
}
