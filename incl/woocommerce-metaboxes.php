<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Register second image field for WooCommerce categories
 * to be used in the category header
 */

// Categories
add_action( 'product_cat_add_form_fields', 'lafka_woocommerce_custom_cat_fields_add', 11, 2 );
add_action( 'product_cat_edit_form_fields', 'lafka_woocommerce_custom_cat_fields_edit', 11, 2 );
add_action( 'created_term', 'lafka_woocommerce_custom_cat_fields_save', 10, 4 );
add_action( 'edit_term', 'lafka_woocommerce_custom_cat_fields_save', 10, 4 );

// Tags
add_action( 'product_tag_add_form_fields', 'lafka_woocommerce_custom_cat_fields_add', 11, 2 );
add_action( 'product_tag_edit_form_fields', 'lafka_woocommerce_custom_cat_fields_edit', 11, 2 );

if ( ! function_exists( 'lafka_woocommerce_custom_cat_fields_add' ) ) {
	function lafka_woocommerce_custom_cat_fields_add() {
		?>
		<div class="form-field lafka-term-header-img-wrap">
			<label><?php echo esc_html__( 'Title background image', 'lafka-plugin' ); ?></label>
			<div id="lafka_term_header_img" style="float: left; margin-right: 10px;"><img
						src="<?php echo esc_url( wc_placeholder_img_src() ); ?>" width="60px" height="60px"/></div>
			<div style="line-height: 60px;">
				<input type="hidden" id="lafka_term_header_img_id" name="lafka_term_header_img_id"/>
				<button type="button"
						class="lafka_term_header_img_upload_image_button button"><?php echo esc_html__( 'Upload/Add image', 'lafka-plugin' ); ?></button>
				<button type="button"
						class="lafka_term_header_img_remove_image_button button"><?php echo esc_html__( 'Remove image', 'lafka-plugin' ); ?></button>
			</div>
			<?php ob_start(); ?>
			<script>
				// Only show the "remove image" button when needed
				if (!jQuery('#lafka_term_header_img_id').val()) {
					jQuery('.lafka_term_header_img_remove_image_button').hide();
				}

				// Uploading files
				var lafka_term_header_img_file_frame;

				jQuery(document).on('click', '.lafka_term_header_img_upload_image_button', function (event) {

					event.preventDefault();

					// If the media frame already exists, reopen it.
					if (lafka_term_header_img_file_frame) {
						lafka_term_header_img_file_frame.open();
						return;
					}

					// Create the media frame.
					lafka_term_header_img_file_frame = wp.media.frames.downloadable_file = wp.media({
						title: '<?php echo esc_html__( 'Choose an image', 'lafka' ); ?>',
						button: {
							text: '<?php echo esc_html__( 'Use image', 'lafka' ); ?>'
						},
						multiple: false
					});

					// When an image is selected, run a callback.
					lafka_term_header_img_file_frame.on('select', function () {
						var attachment = lafka_term_header_img_file_frame.state().get('selection').first().toJSON();

						jQuery('#lafka_term_header_img_id').val(attachment.id);
						jQuery('#lafka_term_header_img').find('img').attr('src', attachment.sizes.thumbnail.url);
						jQuery('.lafka_term_header_img_remove_image_button').show();
					});

					// Finally, open the modal.
					lafka_term_header_img_file_frame.open();
				});

				jQuery(document).on('click', '.lafka_term_header_img_remove_image_button', function () {
					jQuery('#lafka_term_header_img').find('img').attr('src', '<?php echo esc_js( wc_placeholder_img_src() ); ?>');
					jQuery('#lafka_term_header_img_id').val('');
					jQuery('.lafka_term_header_img_remove_image_button').hide();
					return false;
				});

				jQuery(document).ajaxComplete(function (event, request, options) {
					if (request && 4 === request.readyState && 200 === request.status
						&& options.data && 0 <= options.data.indexOf('action=add-tag')) {

						var res = wpAjax.parseAjaxResponse(request.responseXML, 'ajax-response');
						if (!res || res.errors) {
							return;
						}
						// Clear Thumbnail fields on submit
						jQuery('#lafka_term_header_img').find('img').attr('src', '<?php echo esc_js( wc_placeholder_img_src() ); ?>');
						jQuery('#lafka_term_header_img_id').val('');
						jQuery('.lafka_term_header_img_remove_image_button').hide();
						return;
					}
				});

			</script>
			<?php $js_handle_header_img_on_cat_add = ob_get_clean(); ?>
			<?php wp_add_inline_script( 'lafka-back', lafka_strip_script_tag_from_js_block( $js_handle_header_img_on_cat_add ) ); ?>
			<div class="clear"></div>
		</div>

		<div class="form-field lafka-term-header-style-wrap">
			<label for="lafka_term_header_style"><?php esc_html_e( 'Header Style', 'lafka-plugin' ); ?></label>
			<select id="lafka_term_header_style" name="lafka_term_header_style">
				<option value="" selected="selected"><?php esc_html_e( 'Normal', 'lafka-plugin' ); ?></option>
				<option value="lafka_transparent_header"><?php esc_html_e( 'Transparent - Light Scheme', 'lafka-plugin' ); ?></option>
				<option value="lafka_transparent_header lafka-transparent-dark"><?php esc_html_e( 'Transparent - Dark Scheme', 'lafka-plugin' ); ?></option>
			</select>
		</div>

		<div class="form-field lafka-term-header-subtitle-wrap">
			<label for="lafka_term_header_subtitle"><?php esc_html_e( 'Subtitle', 'lafka-plugin' ); ?></label>
			<input type="text" class="large-text" value="" name="lafka_term_header_subtitle"
					id="lafka_term_header_subtitle">
		</div>

		<div class="form-field lafka-term-header-alignment-wrap">
			<label for="lafka_term_header_alignment"><?php esc_html_e( 'Title alignment', 'lafka-plugin' ); ?></label>
			<select name="lafka_term_header_alignment">
				<option value="left_title" selected="selected"><?php esc_html_e( 'Left', 'lafka-plugin' ); ?></option>
				<option value="centered_title"><?php esc_html_e( 'Center', 'lafka-plugin' ); ?></option>
			</select>
		</div>
		<?php
	}
}

if ( ! function_exists( 'lafka_woocommerce_custom_cat_fields_edit' ) ) {
	function lafka_woocommerce_custom_cat_fields_edit( $term ) {

		$thumbnail_id     = absint( get_term_meta( $term->term_id, 'lafka_term_header_img_id', true ) );
		$header_style     = get_term_meta( $term->term_id, 'lafka_term_header_style', true );
		$subtitle         = get_term_meta( $term->term_id, 'lafka_term_header_subtitle', true );
		$header_alignment = get_term_meta( $term->term_id, 'lafka_term_header_alignment', true );

		$header_style_values = array(
			''                         => __( 'Normal', 'lafka-plugin' ),
			'lafka_transparent_header' => __( 'Transparent - Light Scheme', 'lafka-plugin' ),
			'lafka_transparent_header lafka-transparent-dark' => __( 'Transparent - Dark Scheme', 'lafka-plugin' ),
		);

		$header_alignment_values = array(
			'left_title'     => __( 'Left', 'lafka-plugin' ),
			'centered_title' => __( 'Center', 'lafka-plugin' ),
		);

		if ( $thumbnail_id ) {
			$image = wp_get_attachment_thumb_url( $thumbnail_id );
		} else {
			$image = wc_placeholder_img_src();
		}
		?>
		<tr class="form-field">
			<th scope="row" valign="top"><label><?php echo esc_html__( 'Title background image', 'lafka-plugin' ); ?></label>
			</th>
			<td>
				<div id="lafka_term_header_img" style="float: left; margin-right: 10px;"><img
							src="<?php echo esc_url( $image ); ?>" width="60px" height="60px"/></div>
				<div style="line-height: 60px;">
					<input type="hidden" id="lafka_term_header_img_id" name="lafka_term_header_img_id"
							value="<?php echo esc_attr( $thumbnail_id ); ?>"/>
					<button type="button"
							class="lafka_term_header_img_upload_image_button button"><?php echo esc_html__( 'Upload/Add image', 'lafka-plugin' ); ?></button>
					<button type="button"
							class="lafka_term_header_img_remove_image_button button"><?php echo esc_html__( 'Remove image', 'lafka-plugin' ); ?></button>
				</div>
				<?php ob_start(); ?>
				<script>

					// Only show the "remove image" button when needed
					if ('0' === jQuery('#lafka_term_header_img_id').val()) {
						jQuery('.lafka_term_header_img_remove_image_button').hide();
					}

					// Uploading files
					var lafka_term_header_img_file_frame;

					jQuery(document).on('click', '.lafka_term_header_img_upload_image_button', function (event) {

						event.preventDefault();

						// If the media frame already exists, reopen it.
						if (lafka_term_header_img_file_frame) {
							lafka_term_header_img_file_frame.open();
							return;
						}

						// Create the media frame.
						lafka_term_header_img_file_frame = wp.media.frames.downloadable_file = wp.media({
							title: '<?php echo esc_html__( 'Choose an image', 'lafka' ); ?>',
							button: {
								text: '<?php echo esc_html__( 'Use image', 'lafka' ); ?>'
							},
							multiple: false
						});

						// When an image is selected, run a callback.
						lafka_term_header_img_file_frame.on('select', function () {
							var attachment = lafka_term_header_img_file_frame.state().get('selection').first().toJSON();

							jQuery('#lafka_term_header_img_id').val(attachment.id);
							jQuery('#lafka_term_header_img').find('img').attr('src', attachment.sizes.thumbnail.url);
							jQuery('.lafka_term_header_img_remove_image_button').show();
						});

						// Finally, open the modal.
						lafka_term_header_img_file_frame.open();
					});

					jQuery(document).on('click', '.lafka_term_header_img_remove_image_button', function () {
						jQuery('#lafka_term_header_img').find('img').attr('src', '<?php echo esc_js( wc_placeholder_img_src() ); ?>');
						jQuery('#lafka_term_header_img_id').val('');
						jQuery('.lafka_term_header_img_remove_image_button').hide();
						return false;
					});

				</script>
				<?php $js_handle_header_img_on_cat_edit = ob_get_clean(); ?>
				<?php wp_add_inline_script( 'lafka-back', lafka_strip_script_tag_from_js_block( $js_handle_header_img_on_cat_edit ) ); ?>
				<div class="clear"></div>
			</td>
		</tr>

		<tr class="form-field">
			<th scope="row" valign="top"><label
						for="lafka_term_header_style"><?php esc_html_e( 'Header Style', 'lafka-plugin' ); ?></label></th>
			<td>
				<div class="form-field lafka-term-header-style-wrap">
					<select id="lafka_term_header_style" name="lafka_term_header_style">
						<?php foreach ( $header_style_values as $key => $value ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php echo( $key == $header_style ? 'selected="selected"' : '' ); ?> ><?php echo esc_html( $value ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</td>
		</tr>

		<tr class="form-field">
			<th scope="row" valign="top"><label
						for="lafka_term_header_subtitle"><?php esc_html_e( 'Subtitle', 'lafka-plugin' ); ?></label>
			</th>
			<td>
				<div class="form-field lafka-term-header-subtitle-wrap">
					<input type="text" class="large-text"
							value="<?php echo( $subtitle ? esc_html( $subtitle ) : '' ); ?>"
							name="lafka_term_header_subtitle"
							id="lafka_term_header_subtitle">
				</div>
			</td>
		</tr>

		<tr class="form-field">
			<th scope="row" valign="top"><label
						for="lafka_term_header_alignment"><?php esc_html_e( 'Title alignment', 'lafka-plugin' ); ?></label>
			</th>
			<td>
				<div class="form-field lafka-term-header-alignment-wrap">
					<select name="lafka_term_header_alignment">
						<?php foreach ( $header_alignment_values as $key => $value ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php echo( $key == $header_alignment ? 'selected="selected"' : '' ); ?> ><?php echo esc_html( $value ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</td>
		</tr>
		<?php
	}
}

if ( ! function_exists( 'lafka_woocommerce_custom_cat_fields_save' ) ) {
	function lafka_woocommerce_custom_cat_fields_save( $term_id, $tt_id = '', $taxonomy = '' ) {

		if ( isset( $_POST['lafka_term_header_img_id'] ) && in_array( $taxonomy, array( 'product_cat', 'product_tag' ) ) ) {
			update_term_meta( $term_id, 'lafka_term_header_img_id', absint( $_POST['lafka_term_header_img_id'] ) );
		}

		if ( isset( $_POST['lafka_term_header_style'] ) && in_array( $taxonomy, array( 'product_cat', 'product_tag' ) ) ) {
			update_term_meta( $term_id, 'lafka_term_header_style', sanitize_text_field( wp_unslash( $_POST['lafka_term_header_style'] ) ) );
		}

		if ( isset( $_POST['lafka_term_header_subtitle'] ) && in_array( $taxonomy, array( 'product_cat', 'product_tag' ) ) ) {
			update_term_meta( $term_id, 'lafka_term_header_subtitle', sanitize_text_field( wp_unslash( $_POST['lafka_term_header_subtitle'] ) ) );
		}

		if ( isset( $_POST['lafka_term_header_alignment'] ) && in_array( $taxonomy, array( 'product_cat', 'product_tag' ) ) ) {
			update_term_meta( $term_id, 'lafka_term_header_alignment', sanitize_text_field( wp_unslash( $_POST['lafka_term_header_alignment'] ) ) );
		}
	}
}
