<?php
/**
 * P6-SEO-4 (W2-T2): Admin meta box for per-post meta description override.
 *
 * Companion to lafka_resolve_meta_description() shipped in W1-T15.
 * Stores _lafka_meta_description post meta, which the resolver reads first.
 *
 * GX3 adds, in the same box:
 *   - `_lafka_seo_title`   — per-post <title> override (tokens allowed, e.g.
 *     "{title} in {city}{sep}{name}"), read by lafka_seo_resolve_title();
 *   - `_lafka_seo_noindex` — "hide from search engines": noindex via
 *     wp_robots and dropped from the XML sitemap (incl/seo/lafka-sitemap.php).
 */
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_meta_description_register_box' ) ) {
	function lafka_meta_description_register_box() {
		$post_types = array( 'post', 'page' );
		if ( post_type_exists( 'product' ) ) {
			$post_types[] = 'product';
		}
		foreach ( $post_types as $pt ) {
			add_meta_box(
				'lafka_meta_description',
				__( 'SEO: title & description', 'lafka-plugin' ),
				'lafka_meta_description_render_box',
				$pt,
				'normal',
				'default'
			);
		}
	}
	add_action( 'add_meta_boxes', 'lafka_meta_description_register_box' );
}

if ( ! function_exists( 'lafka_meta_description_render_box' ) ) {
	function lafka_meta_description_render_box( $post ) {
		wp_nonce_field( 'lafka_meta_description_save', 'lafka_meta_description_nonce' );

		$current  = (string) get_post_meta( $post->ID, '_lafka_meta_description', true );
		$fallback = function_exists( 'lafka_resolve_meta_description' )
			? lafka_resolve_meta_description( $post )
			: '';

		// Show the fallback as placeholder only when the override is empty AND
		// the fallback didn't end up being the override itself.
		if ( $fallback === $current ) {
			$fallback = '';
		}

		$placeholder = $fallback
			? sprintf(
				/* translators: %s: auto-resolved fallback meta description preview */
				__( 'Leave empty to auto-use: %s', 'lafka-plugin' ),
				$fallback
			)
			: __( 'Leave empty to use the site tagline as fallback.', 'lafka-plugin' );

		$seo_title   = (string) get_post_meta( $post->ID, '_lafka_seo_title', true );
		$seo_noindex = '1' === (string) get_post_meta( $post->ID, '_lafka_seo_noindex', true );
		?>
		<input type="hidden" name="lafka_seo_fields" value="1">
		<p>
			<label for="lafka_seo_title_input"><strong><?php esc_html_e( 'SEO title', 'lafka-plugin' ); ?></strong></label>
			<input
				type="text"
				id="lafka_seo_title_input"
				name="lafka_seo_title"
				value="<?php echo esc_attr( $seo_title ); ?>"
				maxlength="160"
				style="width:100%;"
				placeholder="<?php esc_attr_e( 'Leave empty to use the site-wide title template', 'lafka-plugin' ); ?>"
			>
			<span class="description"><?php esc_html_e( 'Tokens: {title} {name} {city} {region} {cuisines} {sep} — [optional segments] are dropped when a token is empty. Aim for 50–60 characters.', 'lafka-plugin' ); ?></span>
		</p>
		<p><strong><?php esc_html_e( 'Meta description', 'lafka-plugin' ); ?></strong></p>
		<p class="description">
			<?php esc_html_e( 'Custom meta description for SEO. If empty, an automatic description will be used (post excerpt, WC short description, or site tagline). Recommended length: 120-160 characters.', 'lafka-plugin' ); ?>
		</p>
		<?php // The metabox wrapper already uses the id "lafka_meta_description". ?>
		<textarea
			id="lafka_meta_description_input"
			name="lafka_meta_description"
			rows="3"
			style="width:100%; font-family: inherit;"
			maxlength="320"
			placeholder="<?php echo esc_attr( $placeholder ); ?>"
		><?php echo esc_textarea( $current ); ?></textarea>
		<p class="description" style="text-align:right; margin-top:4px;">
			<span id="lafka-meta-desc-count">0</span> /
			<span style="color:#5e5e5e;">160 recommended (320 max)</span>
		</p>
		<p>
			<label>
				<input type="checkbox" name="lafka_seo_noindex" value="1" <?php checked( $seo_noindex ); ?>>
				<?php esc_html_e( 'Hide from search engines (noindex, and leave out of the sitemap) — for legacy, duplicate or utility pages.', 'lafka-plugin' ); ?>
			</label>
		</p>
		<script>
		( function () {
			var ta = document.getElementById( 'lafka_meta_description_input' );
			var ct = document.getElementById( 'lafka-meta-desc-count' );
			if ( ! ta || ! ct ) return;
			function update() {
				var n = ta.value.length;
				ct.textContent = n;
				ct.style.color = n > 160 ? '#c62828' : '#5e5e5e';
			}
			ta.addEventListener( 'input', update );
			update();
		} )();
		</script>
		<?php
	}
}

if ( ! function_exists( 'lafka_meta_description_save' ) ) {
	function lafka_meta_description_save( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['lafka_meta_description_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lafka_meta_description_nonce'] ) ), 'lafka_meta_description_save' )
		) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$value = isset( $_POST['lafka_meta_description'] )
			? sanitize_text_field( wp_unslash( $_POST['lafka_meta_description'] ) )
			: '';

		if ( '' === $value ) {
			delete_post_meta( $post_id, '_lafka_meta_description' );
		} else {
			update_post_meta( $post_id, '_lafka_meta_description', $value );
		}

		// GX3 fields — only when this box's form was submitted (the hidden
		// marker), so a save from elsewhere never wipes them.
		if ( empty( $_POST['lafka_seo_fields'] ) ) {
			return;
		}
		$title = isset( $_POST['lafka_seo_title'] )
			? sanitize_text_field( wp_unslash( $_POST['lafka_seo_title'] ) )
			: '';
		if ( '' === $title ) {
			delete_post_meta( $post_id, '_lafka_seo_title' );
		} else {
			update_post_meta( $post_id, '_lafka_seo_title', $title );
		}
		if ( ! empty( $_POST['lafka_seo_noindex'] ) ) {
			update_post_meta( $post_id, '_lafka_seo_noindex', '1' );
		} else {
			delete_post_meta( $post_id, '_lafka_seo_noindex' );
		}
	}
	add_action( 'save_post', 'lafka_meta_description_save' );
}
