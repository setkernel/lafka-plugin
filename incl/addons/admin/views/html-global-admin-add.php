<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap woocommerce">
	<div class="icon32 icon32-posts-product" id="icon-woocommerce"><br/></div>

    <h2><?php esc_html_e( 'Add/Edit Lafka Global Add-on', 'lafka-plugin' ) ?></h2><br/>

	<form method="POST" action="">
		<table class="form-table global-addons-form meta-box-sortables">
			<tr>
				<th>
					<label for="addon-reference"><?php esc_html_e( 'Global Add-on Reference', 'lafka-plugin' ); ?></label>
				</th>
				<td>
					<input type="text" name="addon-reference" id="addon-reference" style="width:50%;" value="<?php echo esc_attr( $reference ); ?>" />
					<p class="description"><?php esc_html_e( 'Give this global add-on a reference/name to make it recognisable.', 'lafka-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th>
					<label for="addon-priority"><?php esc_html_e( 'Priority', 'lafka-plugin' ); ?></label>
				</th>
				<td>
					<input type="text" name="addon-priority" id="addon-priority" style="width:50%;" value="<?php echo esc_attr( $priority ); ?>" />
					<p class="description"><?php esc_html_e( 'Give this global addon a priority - this will determine the order in which multiple groups of addons get displayed on the frontend. Per-product add-ons will always have priority 10.', 'lafka-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th>
					<label for="addon-objects"><?php esc_html_e( 'Applied to...', 'lafka-plugin' ); ?></label>
				</th>
				<td>
					<select id="addon-objects" name="addon-objects[]" multiple="multiple" style="width:50%;" data-placeholder="<?php esc_html_e('Choose some options&hellip;', 'lafka-plugin'); ?>" class="chosen_select wc-enhanced-select">
						<option value="0" <?php selected( in_array( '0', $objects ), true ); ?>><?php esc_html_e( 'All Products', 'lafka-plugin' ); ?></option>
						<optgroup label="<?php esc_html_e( 'Product category notifications', 'lafka-plugin' ); ?>">
							<?php
								$terms = get_terms( 'product_cat', array( 'hide_empty' => 0 ) );

								foreach ( $terms as $term ) {
									echo '<option value="' . $term->term_id . '" ' . selected( in_array( $term->term_id, $objects ), true, false ) . '>' . esc_html__( 'Category:', 'lafka-plugin' ) . ' ' . $term->name . '</option>';
								}
							?>
						</optgroup>
						<?php do_action( 'lafka_product_addons_global_edit_objects', $objects ); ?>
					</select>
					<p class="description"><?php esc_html_e( 'Choose categories which should show these addons (or apply to all products).', 'lafka-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th>
					<label for="addon-objects"><?php esc_html_e( 'Add-ons', 'lafka-plugin' ); ?></label>
				</th>
				<td id="poststuff" class="postbox">
					<?php
						$exists = false;
					    $attribute_taxonomies = wc_get_attribute_taxonomies();
						include( dirname( __FILE__ ) . '/html-addon-panel.php' );
					?>
				</td>
			</tr>
		</table>
		<p class="submit">
			<input type="hidden" name="edit_id" value="<?php if ( ! empty( $edit_id ) ) echo $edit_id; ?>" />
			<input type="submit" name="submit" id="submit" class="button button-primary" value="<?php esc_html_e( 'Save Global Add-on', 'lafka-plugin' ); ?>">
		</p>
	</form>
</div>
<script type="text/javascript">
	// Toggle function
	function openclose() {
		jQuery('.wc-metabox').toggleClass( 'closed' ).toggleClass( 'open' );
	}
	// Open and close the Product Add-On metaboxes
	jQuery('.wc-metaboxes-wrapper').on('click', '.wc-metabox h3', function(event){
		// If the user clicks on some form input inside the h3, like a select list (for variations), the box should not be toggled
		if (jQuery(event.target).filter(':input, option').length) {
			return;
		}

		jQuery(this).next('.wc-metabox-content').toggle();
		openclose();
		})
	.on('click', '.expand_all', function(){
		jQuery(this).closest('.wc-metaboxes-wrapper').find('.wc-metabox > table').show();
		openclose();
		return false;
	})
	.on('click', '.close_all', function(){
		jQuery(this).closest('.wc-metaboxes-wrapper').find('.wc-metabox > table').hide();
		openclose();
		return false;
	});
	jQuery('.wc-metabox.closed').each(function(){
		jQuery(this).find('.wc-metabox-content').hide();
	});
</script>
