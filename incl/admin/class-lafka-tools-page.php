<?php
/**
 * Lafka_Tools_Page — the "Lafka → Tools" screen (NX1-05).
 *
 * Operator-facing surface for the config bundle (Lafka_Config_Bundle):
 *
 *   - Export: a nonce-protected, manage_woocommerce-gated download of the
 *     versioned JSON bundle (streamed via admin-post.php with proper headers).
 *   - Import: upload a bundle, see a per-section create/update/skip DRY-RUN diff
 *     table, then explicitly confirm to apply. Import is create/update only and
 *     never deletes. The uploaded bundle is held in a short-lived per-user
 *     transient between the preview and the confirm, so the operator never
 *     re-uploads and no large payload rides a hidden field.
 *
 * All real work is delegated to Lafka_Config_Bundle; this class is view +
 * nonce + capability + headers only.
 *
 * @package Lafka\Plugin\Admin
 * @since   9.36.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Tools_Page' ) ) {

	final class Lafka_Tools_Page {

		const PARENT_SLUG = 'lafka-modules';
		const MENU_SLUG   = 'lafka-tools';
		const CAPABILITY  = 'manage_woocommerce';

		const EXPORT_ACTION = 'lafka_config_export';
		const IMPORT_ACTION = 'lafka_config_import';
		const APPLY_ACTION  = 'lafka_config_apply';

		/** Per-user transient holding the uploaded bundle between preview + apply. */
		const PENDING_TRANSIENT = 'lafka_config_pending_';

		/** @var Lafka_Tools_Page|null */
		private static $instance = null;

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			add_action( 'admin_menu', array( $this, 'register_menu' ) );
			add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'handle_export' ) );
			add_action( 'admin_post_' . self::IMPORT_ACTION, array( $this, 'handle_import_preview' ) );
			add_action( 'admin_post_' . self::APPLY_ACTION, array( $this, 'handle_import_apply' ) );
		}

		/**
		 * Register the "Tools" submenu under the top-level Lafka menu.
		 */
		public function register_menu() {
			add_submenu_page(
				self::PARENT_SLUG,
				esc_html__( 'Lafka Tools', 'lafka-plugin' ),
				esc_html__( 'Tools', 'lafka-plugin' ),
				self::CAPABILITY,
				self::MENU_SLUG,
				array( $this, 'render_page' )
			);
		}

		// ─── Export ──────────────────────────────────────────────────────────

		/**
		 * Stream the config bundle as a JSON file download.
		 */
		public function handle_export() {
			if ( ! current_user_can( self::CAPABILITY ) ) {
				wp_die( esc_html__( 'You do not have permission to export Lafka configuration.', 'lafka-plugin' ), 403 );
			}
			check_admin_referer( self::EXPORT_ACTION );

			$json     = Lafka_Config_Bundle::export_json();
			$host     = wp_parse_url( home_url(), PHP_URL_HOST );
			$host     = is_string( $host ) ? preg_replace( '/[^a-z0-9\-]/i', '-', $host ) : 'site';
			$filename = 'lafka-config-' . $host . '-' . gmdate( 'Ymd-His' ) . '.json';

			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'Content-Length: ' . strlen( $json ) );

			// The payload is our own generated JSON; echo verbatim so the file is
			// byte-for-byte the bundle (escaping would corrupt it).
			echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON file download body, not HTML.
			exit;
		}

		// ─── Import: preview (dry-run) ───────────────────────────────────────

		/**
		 * Handle the uploaded bundle: run a dry-run, stash it, redirect to the
		 * preview.
		 */
		public function handle_import_preview() {
			if ( ! current_user_can( self::CAPABILITY ) ) {
				wp_die( esc_html__( 'You do not have permission to import Lafka configuration.', 'lafka-plugin' ), 403 );
			}
			check_admin_referer( self::IMPORT_ACTION );

			$json = $this->read_uploaded_bundle();
			if ( null === $json ) {
				$this->redirect_to_page( array( 'lafka_import' => 'nofile' ) );
			}

			$report = Lafka_Config_Bundle::import_json( $json, true );

			set_transient(
				self::PENDING_TRANSIENT . get_current_user_id(),
				array(
					'json'   => $json,
					'report' => $report,
				),
				15 * MINUTE_IN_SECONDS
			);

			$this->redirect_to_page( array( 'lafka_import' => 'preview' ) );
		}

		/**
		 * Read + validate the uploaded bundle file, returning its raw JSON or
		 * null when nothing usable was uploaded.
		 *
		 * @return string|null
		 */
		private function read_uploaded_bundle() {
			// The only caller (handle_import_preview) verifies the nonce with
			// check_admin_referer() before delegating here, so these $_FILES
			// reads are already CSRF-guarded; the sniff can't see across methods.
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			if ( empty( $_FILES['lafka_config_file']['tmp_name'] ) ) {
				return null;
			}
			$error = isset( $_FILES['lafka_config_file']['error'] ) ? (int) $_FILES['lafka_config_file']['error'] : UPLOAD_ERR_NO_FILE;
			if ( UPLOAD_ERR_OK !== $error ) {
				return null;
			}
			$tmp = sanitize_text_field( wp_unslash( $_FILES['lafka_config_file']['tmp_name'] ) );
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
				return null;
			}
			$contents = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a just-uploaded local temp file.
			if ( false === $contents || '' === $contents ) {
				return null;
			}
			return $contents;
		}

		// ─── Import: apply (confirmed) ───────────────────────────────────────

		/**
		 * Apply the previously-previewed bundle for real.
		 */
		public function handle_import_apply() {
			if ( ! current_user_can( self::CAPABILITY ) ) {
				wp_die( esc_html__( 'You do not have permission to import Lafka configuration.', 'lafka-plugin' ), 403 );
			}
			check_admin_referer( self::APPLY_ACTION );

			$key     = self::PENDING_TRANSIENT . get_current_user_id();
			$pending = get_transient( $key );
			if ( ! is_array( $pending ) || empty( $pending['json'] ) ) {
				$this->redirect_to_page( array( 'lafka_import' => 'expired' ) );
			}

			$report = Lafka_Config_Bundle::import_json( (string) $pending['json'], false );
			delete_transient( $key );

			set_transient(
				self::PENDING_TRANSIENT . 'result_' . get_current_user_id(),
				$report,
				5 * MINUTE_IN_SECONDS
			);

			$this->redirect_to_page( array( 'lafka_import' => $report['ok'] ? 'done' : 'failed' ) );
		}

		// ─── Rendering ───────────────────────────────────────────────────────

		/**
		 * Render the Tools screen: export card + import (upload / preview / result).
		 */
		public function render_page() {
			if ( ! current_user_can( self::CAPABILITY ) ) {
				wp_die( esc_html__( 'You do not have permission to view this page.', 'lafka-plugin' ), 403 );
			}

			echo '<div class="wrap lafka-tools">';
			echo '<h1>' . esc_html__( 'Lafka Tools', 'lafka-plugin' ) . '</h1>';
			echo '<p class="description">' . esc_html__( 'Move a configured Lafka install between sites. Export packages your settings, menu structure metadata, branches and delivery zones into a portable JSON bundle; import merges a bundle into this site (it only creates or updates — it never deletes).', 'lafka-plugin' ) . '</p>';

			$this->render_styles();
			$this->render_export_card();
			$this->render_import_card();

			echo '</div>';
		}

		/**
		 * Export card: a nonce-protected download button + the excluded manifest.
		 */
		private function render_export_card() {
			echo '<div class="lafka-tools-card">';
			echo '<h2>' . esc_html__( 'Export configuration', 'lafka-plugin' ) . '</h2>';
			echo '<p>' . esc_html__( 'Download this site\'s Lafka configuration as a JSON bundle.', 'lafka-plugin' ) . '</p>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::EXPORT_ACTION ) . '">';
			wp_nonce_field( self::EXPORT_ACTION );
			echo '<button type="submit" class="button button-primary">' . esc_html__( 'Download bundle', 'lafka-plugin' ) . '</button>';
			echo '</form>';

			echo '<p class="lafka-tools-note">' . esc_html__( 'Not included (configure manually on the destination):', 'lafka-plugin' ) . '</p>';
			echo '<ul class="lafka-tools-excluded">';
			foreach ( Lafka_Config_Bundle::excluded_notes() as $note ) {
				echo '<li>' . esc_html( $note ) . '</li>';
			}
			echo '</ul>';
			echo '</div>';
		}

		/**
		 * Import card: upload form + (when previewing) a diff table + confirm.
		 */
		private function render_import_card() {
			// $_GET['lafka_import'] is display-state only; the state-changing paths
			// (preview/apply) each verify their own nonce via check_admin_referer().
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$state = isset( $_GET['lafka_import'] ) ? sanitize_key( wp_unslash( $_GET['lafka_import'] ) ) : '';

			echo '<div class="lafka-tools-card">';
			echo '<h2>' . esc_html__( 'Import configuration', 'lafka-plugin' ) . '</h2>';

			$this->render_import_notice( $state );

			if ( 'preview' === $state ) {
				$this->render_preview();
				echo '</div>';
				return;
			}

			echo '<p>' . esc_html__( 'Upload a Lafka bundle to preview the changes before applying. You will see exactly what would be created or updated in each section.', 'lafka-plugin' ) . '</p>';
			echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::IMPORT_ACTION ) . '">';
			wp_nonce_field( self::IMPORT_ACTION );
			echo '<input type="file" name="lafka_config_file" accept="application/json,.json" required> ';
			echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Preview import', 'lafka-plugin' ) . '</button>';
			echo '</form>';
			echo '</div>';
		}

		/**
		 * Render the dry-run diff table + the explicit apply/cancel controls.
		 */
		private function render_preview() {
			$pending = get_transient( self::PENDING_TRANSIENT . get_current_user_id() );
			if ( ! is_array( $pending ) || empty( $pending['report'] ) ) {
				echo '<p>' . esc_html__( 'The import preview expired. Please upload the bundle again.', 'lafka-plugin' ) . '</p>';
				return;
			}
			$report = $pending['report'];

			echo '<p>' . esc_html__( 'Preview (nothing has been changed yet). Review the per-section diff, then apply.', 'lafka-plugin' ) . '</p>';

			echo '<table class="widefat striped lafka-tools-diff"><thead><tr>';
			echo '<th>' . esc_html__( 'Section', 'lafka-plugin' ) . '</th>';
			echo '<th>' . esc_html__( 'Create', 'lafka-plugin' ) . '</th>';
			echo '<th>' . esc_html__( 'Update', 'lafka-plugin' ) . '</th>';
			echo '<th>' . esc_html__( 'Skip', 'lafka-plugin' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $report['sections'] as $id => $counts ) {
				echo '<tr>';
				echo '<td>' . esc_html( $id ) . '</td>';
				echo '<td>' . esc_html( (string) $counts['created'] ) . '</td>';
				echo '<td>' . esc_html( (string) $counts['updated'] ) . '</td>';
				echo '<td>' . esc_html( (string) $counts['skipped'] ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';

			$this->render_messages( $report['warnings'], 'warning' );
			$this->render_messages( $report['errors'], 'error' );

			echo '<p class="lafka-tools-actions">';
			if ( ! empty( $report['ok'] ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
				echo '<input type="hidden" name="action" value="' . esc_attr( self::APPLY_ACTION ) . '">';
				wp_nonce_field( self::APPLY_ACTION );
				echo '<button type="submit" class="button button-primary">' . esc_html__( 'Apply changes', 'lafka-plugin' ) . '</button>';
				echo '</form> ';
			} else {
				echo '<span class="lafka-tools-blocked">' . esc_html__( 'This bundle has errors and cannot be applied. Fix the source export and try again.', 'lafka-plugin' ) . '</span> ';
			}
			echo '<a class="button button-link" href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) . '">' . esc_html__( 'Cancel', 'lafka-plugin' ) . '</a>';
			echo '</p>';
		}

		/**
		 * Result / status notices after a preview / apply round-trip.
		 *
		 * @param string $state The lafka_import GET flag.
		 */
		private function render_import_notice( $state ) {
			if ( 'nofile' === $state ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'No bundle file was uploaded.', 'lafka-plugin' ) . '</p></div>';
				return;
			}
			if ( 'expired' === $state ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'The import session expired before it was applied. Please upload the bundle again.', 'lafka-plugin' ) . '</p></div>';
				return;
			}
			if ( 'done' !== $state && 'failed' !== $state ) {
				return;
			}

			$report = get_transient( self::PENDING_TRANSIENT . 'result_' . get_current_user_id() );
			delete_transient( self::PENDING_TRANSIENT . 'result_' . get_current_user_id() );

			if ( 'failed' === $state ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Import failed. No partial section was applied.', 'lafka-plugin' ) . '</p></div>';
				if ( is_array( $report ) && ! empty( $report['errors'] ) ) {
					$this->render_messages( $report['errors'], 'error' );
				}
				return;
			}

			$created = 0;
			$updated = 0;
			if ( is_array( $report ) && ! empty( $report['sections'] ) ) {
				foreach ( $report['sections'] as $counts ) {
					$created += (int) $counts['created'];
					$updated += (int) $counts['updated'];
				}
			}
			echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(
				/* translators: 1: number created, 2: number updated. */
				esc_html__( 'Import complete: %1$d created, %2$d updated.', 'lafka-plugin' ),
				(int) $created,
				(int) $updated
			) . '</p></div>';
		}

		/**
		 * Render a list of messages as an inline notice block.
		 *
		 * @param array<int,string> $messages Messages.
		 * @param string            $tone     'warning' | 'error'.
		 */
		private function render_messages( $messages, $tone ) {
			if ( empty( $messages ) || ! is_array( $messages ) ) {
				return;
			}
			$class = 'error' === $tone ? 'notice-error' : 'notice-warning';
			echo '<div class="notice ' . esc_attr( $class ) . ' inline"><ul style="list-style:disc;margin-left:1.4em">';
			foreach ( $messages as $message ) {
				echo '<li>' . esc_html( (string) $message ) . '</li>';
			}
			echo '</ul></div>';
		}

		/**
		 * Redirect back to the Tools page with query args.
		 *
		 * @param array<string,string> $args Extra query args.
		 */
		private function redirect_to_page( array $args ) {
			$args = array_merge( array( 'page' => self::MENU_SLUG ), $args );
			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		/**
		 * Minimal inline admin CSS (admin-only, rarely visited).
		 */
		private function render_styles() {
			echo '<style>
				.lafka-tools-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 18px;margin:16px 0;max-width:820px;}
				.lafka-tools-card h2{margin-top:0;}
				.lafka-tools-note{margin:14px 0 4px;font-weight:600;color:#50575e;}
				.lafka-tools-excluded{margin:0 0 0 1.2em;color:#646970;font-size:13px;list-style:disc;}
				.lafka-tools-diff{max-width:520px;margin:12px 0;}
				.lafka-tools-actions{margin-top:14px;}
				.lafka-tools-blocked{color:#8a2424;font-weight:600;}
			</style>';
		}
	}

	if ( function_exists( 'is_admin' ) && is_admin() ) {
		Lafka_Tools_Page::instance();
	}
}
