<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Register page layout metaboxes
 */
add_action('add_meta_boxes', 'lafka_add_layout_metabox');
add_action('save_post', 'lafka_save_layout_postdata');

/* Adds a box to the side column on the Page edit screens */
if (!function_exists('lafka_add_layout_metabox')) {

	function lafka_add_layout_metabox() {

		$posttypes = array('page', 'post', 'lafka-foodmenu');
		if (LAFKA_PLUGIN_IS_WOOCOMMERCE) {
			$posttypes[] = 'product';
		}
		if (LAFKA_PLUGIN_IS_BBPRESS) {
			$posttypes[] = 'forum';
			$posttypes[] = 'topic';
		}
		if(post_type_exists('tribe_events')) {
			$posttypes[] = 'tribe_events';
		}

		foreach ($posttypes as $pt) {
			add_meta_box(
							'lafka_layout', esc_html__('Page Layout Options', 'lafka-plugin'), 'lafka_layout_callback', $pt, 'side'
			);
		}
	}

}

/* Prints the box content */
if (!function_exists('lafka_layout_callback')) {

	function lafka_layout_callback($post) {
		// If current page is set as Blog page - don't show the options
		if ($post->ID == get_option('page_for_posts')) {
			echo esc_html__("Page Layout Options is disabled for this page, because the page is set as Blog page from Settings->Reading.", 'lafka-plugin');
			return;
		}

		// If current page is set as Shop page - don't show the options
		if (LAFKA_PLUGIN_IS_WOOCOMMERCE && $post->ID == wc_get_page_id('shop')) {
			echo esc_html__("Page Layout Options is disabled for this page, because the page is set as Shop page.", 'lafka-plugin');
			return;
		}

		// Use nonce for verification
		wp_nonce_field('lafka_save_layout_postdata', 'layout_nonce');

		$custom = get_post_custom($post->ID);

		// Set default values
		$values = array(
				'lafka_layout' => 'default',
				'lafka_top_header' => 'default',
				'lafka_footer_style' => 'default',
				'lafka_footer_size' => 'default',
				'lafka_header_size' => 'default',
				'lafka_header_syle' => '',
				'lafka_page_subtitle' => '',
				'lafka_title_background_imgid' => '',
				'lafka_title_alignment' => 'left_title'
		);

		if (isset($custom['lafka_layout']) && $custom['lafka_layout'][0] != '') {
			$values['lafka_layout'] = esc_attr($custom['lafka_layout'][0]);
		}
		if (isset($custom['lafka_top_header']) && $custom['lafka_top_header'][0] != '') {
			$values['lafka_top_header'] = esc_attr($custom['lafka_top_header'][0]);
		}
		if (isset($custom['lafka_footer_style']) && $custom['lafka_footer_style'][0] != '') {
			$values['lafka_footer_style'] = esc_attr($custom['lafka_footer_style'][0]);
		}
		if (isset($custom['lafka_footer_size']) && $custom['lafka_footer_size'][0] != '') {
			$values['lafka_footer_size'] = esc_attr($custom['lafka_footer_size'][0]);
		}
		if (isset($custom['lafka_header_size']) && $custom['lafka_header_size'][0] != '') {
			$values['lafka_header_size'] = esc_attr($custom['lafka_header_size'][0]);
		}
		if (isset($custom['lafka_header_syle']) && $custom['lafka_header_syle'][0] != '') {
			$values['lafka_header_syle'] = esc_attr($custom['lafka_header_syle'][0]);
		}
		if (isset($custom['lafka_page_subtitle']) && $custom['lafka_page_subtitle'][0] != '') {
			$values['lafka_page_subtitle'] = esc_attr($custom['lafka_page_subtitle'][0]);
		}
		if (isset($custom['lafka_title_background_imgid']) && $custom['lafka_title_background_imgid'][0] != '') {
			$values['lafka_title_background_imgid'] = esc_attr($custom['lafka_title_background_imgid'][0]);
		}
		if (isset($custom['lafka_title_alignment']) && $custom['lafka_title_alignment'][0] != '') {
			$values['lafka_title_alignment'] = esc_attr($custom['lafka_title_alignment'][0]);
		}

		// description
		$output = '<p>' . esc_html__("You can define layout specific options here.", 'lafka-plugin') . '</p>';

		// Layout
		$output .= '<p><b>' . esc_html__("Choose Page Layout", 'lafka-plugin') . '</b></p>';
		$output .= '<input id="lafka_layout_default" ' . checked($values['lafka_layout'], 'default', false) . ' type="radio" value="default" name="lafka_layout">';
		$output .= '<label for="lafka_layout_default">' . esc_html__('Default', 'lafka-plugin') . '</label><br>';
		$output .= '<input id="lafka_layout_fullwidth" ' . checked($values['lafka_layout'], 'lafka_fullwidth', false) . ' type="radio" value="lafka_fullwidth" name="lafka_layout">';
		$output .= '<label for="lafka_layout_fullwidth">' . esc_html__('Full-Width', 'lafka-plugin') . '</label><br>';
		$output .= '<input id="lafka_layout_boxed" ' . checked($values['lafka_layout'], 'lafka_boxed', false) . ' type="radio" value="lafka_boxed" name="lafka_layout">';
		$output .= '<label for="lafka_layout_boxed">' . esc_html__('Boxed', 'lafka-plugin') . '</label><br>';

		// Top Menu Bar
		$output .= '<p><b>' . esc_html__("Top Menu Bar", 'lafka-plugin') . '</b></p>';
		$output .= '<input id="lafka_top_header_default" ' . checked($values['lafka_top_header'], 'default', false) . ' type="radio" value="default" name="lafka_top_header">';
		$output .= '<label for="lafka_top_header_default">' . esc_html__('Default', 'lafka-plugin') . '</label>&nbsp;';
		$output .= '<input id="lafka_top_header_show" ' . checked($values['lafka_top_header'], 'show', false) . ' type="radio" value="show" name="lafka_top_header">';
		$output .= '<label for="lafka_top_header_show">' . esc_html__('Show', 'lafka-plugin') . '</label>&nbsp;';
		$output .= '<input id="lafka_top_header_hide" ' . checked($values['lafka_top_header'], 'hide', false) . ' type="radio" value="hide" name="lafka_top_header">';
		$output .= '<label for="lafka_top_header_hide">' . esc_html__('Hide', 'lafka-plugin') . '</label>';

		// Footer Size
		$output .= '<p><b>' . esc_html__("Footer size", 'lafka-plugin') . '</b></p>';
		$output .= '<input id="lafka_footer_size_default" ' . checked($values['lafka_footer_size'], 'default', false) . ' type="radio" value="default" name="lafka_footer_size">';
		$output .= '<label for="lafka_footer_size_default">' . esc_html__('Default', 'lafka-plugin') . '</label>&nbsp;';
		$output .= '<input id="lafka_footer_size_standard" ' . checked($values['lafka_footer_size'], 'standard', false) . ' type="radio" value="standard" name="lafka_footer_size">';
		$output .= '<label for="lafka_footer_size_standard">' . esc_html__('Standard', 'lafka-plugin') . '</label>&nbsp;';
		$output .= '<input id="lafka_footer_size_hide" ' . checked($values['lafka_footer_size'], 'lafka-stretched-footer', false) . ' type="radio" value="lafka-stretched-footer" name="lafka_footer_size">';
		$output .= '<label for="lafka_footer_size_hide">' . esc_html__('Fullwidth', 'lafka-plugin') . '</label>';

		// Footer Style
		$output .= '<p><b>' . esc_html__("Footer style", 'lafka-plugin') . '</b></p>';
		$output .= '<input id="lafka_footer_style_default" ' . checked($values['lafka_footer_style'], 'default', false) . ' type="radio" value="default" name="lafka_footer_style">';
		$output .= '<label for="lafka_footer_style_default">' . esc_html__('Default', 'lafka-plugin') . '</label>&nbsp;';
		$output .= '<input id="lafka_footer_style_show" ' . checked($values['lafka_footer_style'], 'standart', false) . ' type="radio" value="standart" name="lafka_footer_style">';
		$output .= '<label for="lafka_footer_style_show">' . esc_html__('Standard', 'lafka-plugin') . '</label>&nbsp;';
		$output .= '<input id="lafka_footer_style_hide" ' . checked($values['lafka_footer_style'], 'lafka-reveal-footer', false) . ' type="radio" value="lafka-reveal-footer" name="lafka_footer_style">';
		$output .= '<label for="lafka_footer_style_hide">' . esc_html__('Reveal', 'lafka-plugin') . '</label>';

		// Header Size
		$output .= '<p><b>' . esc_html__("Header size", 'lafka-plugin') . '</b></p>';
		$output .= '<input id="lafka_header_size_default" ' . checked($values['lafka_header_size'], 'default', false) . ' type="radio" value="default" name="lafka_header_size">';
		$output .= '<label for="lafka_header_size_default">' . esc_html__('Default', 'lafka-plugin') . '</label>&nbsp;';
		$output .= '<input id="lafka_header_size_standard" ' . checked($values['lafka_header_size'], 'standard', false) . ' type="radio" value="standard" name="lafka_header_size">';
		$output .= '<label for="lafka_header_size_standard">' . esc_html__('Standard', 'lafka-plugin') . '</label>&nbsp;';
		$output .= '<input id="lafka_header_size_hide" ' . checked($values['lafka_header_size'], 'lafka-stretched-header', false) . ' type="radio" value="lafka-stretched-header" name="lafka_header_size">';
		$output .= '<label for="lafka_header_size_hide">' . esc_html__('Fullwidth', 'lafka-plugin') . '</label>';

		// Transparent header and Title with Image Background (only on posts, pages, forum, foodmenu and topic)
		$screen = get_current_screen();
		if ($screen && in_array($screen->post_type, array('post', 'page', 'forum', 'topic', 'lafka-foodmenu', 'tribe_events', 'product'), true)) {

			// Below is not for product
			if($screen->post_type != 'product') {
				// Header style header
				$output .= '<p><b>' . esc_html__("Header Style", 'lafka-plugin') . '</b></p>';
				$output .= '<p><label for="lafka_header_syle">';

				$output .= "<select name='lafka_header_syle'>";
				// Add a default option
				$output .= "<option";
				if ($values['lafka_header_syle'] === '') {
					$output .= " selected='selected'";
				}
				$output .= " value=''>" . esc_html__('Normal', 'lafka-plugin') . "</option>";

				// Fill the select element
				$header_style_values = array(
					'lafka_transparent_header' => esc_html__('Transparent - Light Scheme', 'lafka-plugin'),
					'lafka_transparent_header lafka-transparent-dark' => esc_html__('Transparent - Dark Scheme', 'lafka-plugin')
				);

				foreach ($header_style_values as $header_style_val => $header_style_option) {
					$output .= "<option";
					if ($header_style_val === $values['lafka_header_syle']) {
						$output .= " selected='selected'";
					}
					$output .= " value='" . esc_attr($header_style_val) . "'>" . esc_html($header_style_option) . "</option>";
				}

				$output .= "</select>";

				// The image
				$image_id = get_post_meta(
					$post->ID, 'lafka_title_background_imgid', true
				);

				$add_link_style = '';
				$del_link_style = '';

				$output .= '<p class="hide-if-no-js">';
				$output .= '<span id="lafka_title_background_imgid_images" class="lafka_featured_img_holder">';

				if ( $image_id ) {
					$add_link_style = 'style="display:none"';
					$output .= wp_get_attachment_image( $image_id, 'medium' );
				} else {
					$del_link_style = 'style="display:none"';
				}

				$output .= '</span>';
				$output .= '</p>';
				$output .= '<p class="hide-if-no-js">';
				$output .= '<input id="lafka_title_background_imgid" name="lafka_title_background_imgid" type="hidden" value="' . esc_attr( $image_id ) . '" />';
				$output .= '<input type="button" value="' . esc_attr__( 'Manage Images', 'lafka-plugin' ) . '" id="upload_lafka_title_background_imgid" class="lafka_upload_image_button" data-uploader_title="' . esc_attr__( 'Select Title Background Image', 'lafka-plugin' ) . '" data-uploader_button_text="' . esc_attr__( 'Select', 'lafka-plugin' ) . '">';
				$output .= '</p>';

				$output .= '<p><label for="lafka_page_subtitle">' . esc_html__( "Page Subtitle", 'lafka-plugin' ) . '</label></p>';
				$output .= '<input type="text" id="lafka_page_subtitle" name="lafka_page_subtitle" value="' . esc_attr( $values['lafka_page_subtitle'] ) . '" class="large-text" />';
				$output .= '<p><label for="lafka_title_alignment">' . esc_html__( "Title alignment", 'lafka-plugin' ) . '</label></p>';
				$output .= '<select name="lafka_title_alignment">';
				$output .= '<option ' . ( $values['lafka_title_alignment'] == 'left_title' ? 'selected="selected"' : '' ) . ' value="left_title">Left</option>';
				$output .= '<option ' . ( $values['lafka_title_alignment'] == 'centered_title' ? 'selected="selected"' : '' ) . ' value="centered_title">Center</option>';
				$output .= '</select>';
			}
		}

		echo $output; // All dynamic data escaped
	}

}

/* When the post is saved, saves our custom data */
if (!function_exists('lafka_save_layout_postdata')) {

	function lafka_save_layout_postdata($post_id) {
		global $pagenow;

		// verify if this is an auto save routine.
		// If it is our form has not been submitted, so we dont want to do anything
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		// verify this came from our screen and with proper authorization,
		// because save_post can be triggered at other times
		if (isset($_POST['layout_nonce']) && !wp_verify_nonce($_POST['layout_nonce'], 'lafka_save_layout_postdata')) {
			return;
		}

		if (!current_user_can('edit_post', $post_id)) {
			return;
		}

		if ('post-new.php' == $pagenow) {
			return;
		}

		if (isset($_POST['lafka_layout'])) {
			update_post_meta($post_id, "lafka_layout", sanitize_text_field($_POST['lafka_layout']));
		}

		if (isset($_POST['lafka_top_header'])) {
			update_post_meta($post_id, "lafka_top_header", sanitize_text_field($_POST['lafka_top_header']));
		}

		if (isset($_POST['lafka_footer_style'])) {
			update_post_meta($post_id, "lafka_footer_style", sanitize_text_field($_POST['lafka_footer_style']));
		}

		if (isset($_POST['lafka_footer_size'])) {
			update_post_meta($post_id, "lafka_footer_size", sanitize_text_field($_POST['lafka_footer_size']));
		}

		if (isset($_POST['lafka_header_size'])) {
			update_post_meta($post_id, "lafka_header_size", sanitize_text_field($_POST['lafka_header_size']));
		}

		if (isset($_POST['lafka_page_subtitle'])) {
			update_post_meta($post_id, "lafka_page_subtitle", sanitize_text_field($_POST['lafka_page_subtitle']));
		}

		if (isset($_POST['lafka_header_syle'])) {
			update_post_meta($post_id, "lafka_header_syle", sanitize_text_field($_POST['lafka_header_syle']));
		}

		if (isset($_POST['lafka_title_background_imgid'])) {
			update_post_meta($post_id, 'lafka_title_background_imgid', sanitize_text_field($_POST['lafka_title_background_imgid']));
		}

		if (isset($_POST['lafka_title_alignment'])) {
			update_post_meta($post_id, 'lafka_title_alignment', sanitize_text_field($_POST['lafka_title_alignment']));
		}
	}

}

/**
 * Register metaboxes
 */
add_action('add_meta_boxes', 'lafka_add_page_options_metabox');
add_action('save_post', 'lafka_save_page_options_postdata');

/* Adds a box to the side column on the Page edit screens */
if (!function_exists('lafka_add_page_options_metabox')) {

	function lafka_add_page_options_metabox() {

		$posttypes = array('page', 'post', 'lafka-foodmenu', 'tribe_events');

		if (LAFKA_PLUGIN_IS_BBPRESS) {
			$posttypes[] = 'forum';
			$posttypes[] = 'topic';
		}
		if(post_type_exists('tribe_events')) {
			$posttypes[] = 'tribe_events';
		}

		foreach ($posttypes as $pt) {
			add_meta_box(
							'lafka_page_options', esc_html__('Page Structure Options', 'lafka-plugin'), 'lafka_page_options_callback', $pt, 'side'
			);
		}
	}

}

/* Prints the box content */
if (!function_exists('lafka_page_options_callback')) {

	function lafka_page_options_callback($post) {
		// If current page is set as Blog page - don't show the options
		if ($post->ID == get_option('page_for_posts')) {
			echo esc_html__("Page Structure Options are disabled for this page, because the page is set as Blog page from Settings->Reading.", 'lafka-plugin');
			return;
		}
		// If current page is set as Shop page - don't show the options
		if (LAFKA_PLUGIN_IS_WOOCOMMERCE && $post->ID == wc_get_page_id('shop')) {
			echo esc_html__("Page Structure Options are disabled for this page, because the page is set as Shop page.", 'lafka-plugin');
			return;
		}

		// Use nonce for verification
		wp_nonce_field('lafka_save_page_options_postdata', 'page_options_nonce');
		global $wp_registered_sidebars;

		$custom = get_post_custom($post->ID);

		// Set default values
		$values = array(
				'lafka_top_menu' => 'default',
				'lafka_show_title_page' => 'yes',
				'lafka_show_breadcrumb' => 'yes',
				'lafka_show_feat_image_in_post' => 'yes',
				'lafka_show_sidebar' => 'yes',
				'lafka_sidebar_position' => 'default',
				'lafka_show_footer_sidebar' => 'yes',
				'lafka_show_offcanvas_sidebar' => 'yes',
				'lafka_show_share' => 'default',
				'lafka_custom_sidebar' => 'default',
				'lafka_custom_footer_sidebar' => 'default',
				'lafka_custom_offcanvas_sidebar' => 'default'
		);

		if (isset($custom['lafka_top_menu']) && $custom['lafka_top_menu'][0] != '') {
			$values['lafka_top_menu'] = $custom['lafka_top_menu'][0];
		}
		if (isset($custom['lafka_show_title_page']) && $custom['lafka_show_title_page'][0] != '') {
			$values['lafka_show_title_page'] = $custom['lafka_show_title_page'][0];
		}
		if (isset($custom['lafka_show_breadcrumb']) && $custom['lafka_show_breadcrumb'][0] != '') {
			$values['lafka_show_breadcrumb'] = $custom['lafka_show_breadcrumb'][0];
		}
		if (isset($custom['lafka_show_feat_image_in_post']) && $custom['lafka_show_feat_image_in_post'][0] != '') {
			$values['lafka_show_feat_image_in_post'] = $custom['lafka_show_feat_image_in_post'][0];
		}
		if (isset($custom['lafka_show_sidebar']) && $custom['lafka_show_sidebar'][0] != '') {
			$values['lafka_show_sidebar'] = $custom['lafka_show_sidebar'][0];
		}
		if (isset($custom['lafka_sidebar_position']) && $custom['lafka_sidebar_position'][0] != '') {
			$values['lafka_sidebar_position'] = $custom['lafka_sidebar_position'][0];
		}
		if (isset($custom['lafka_show_footer_sidebar']) && $custom['lafka_show_footer_sidebar'][0] != '') {
			$values['lafka_show_footer_sidebar'] = $custom['lafka_show_footer_sidebar'][0];
		}
		if (isset($custom['lafka_show_offcanvas_sidebar']) && $custom['lafka_show_offcanvas_sidebar'][0] != '') {
			$values['lafka_show_offcanvas_sidebar'] = $custom['lafka_show_offcanvas_sidebar'][0];
		}
		if (isset($custom['lafka_show_share']) && $custom['lafka_show_share'][0] != '') {
			$values['lafka_show_share'] = $custom['lafka_show_share'][0];
		}
		if (isset($custom['lafka_custom_sidebar']) && $custom['lafka_custom_sidebar'][0] != '') {
			$values['lafka_custom_sidebar'] = $custom['lafka_custom_sidebar'][0];
		}
		if (isset($custom['lafka_custom_footer_sidebar']) && $custom['lafka_custom_footer_sidebar'][0] != '') {
			$values['lafka_custom_footer_sidebar'] = $custom['lafka_custom_footer_sidebar'][0];
		}
		if (isset($custom['lafka_custom_offcanvas_sidebar']) && $custom['lafka_custom_offcanvas_sidebar'][0] != '') {
			$values['lafka_custom_offcanvas_sidebar'] = $custom['lafka_custom_offcanvas_sidebar'][0];
		}

		// description
		$output = '<p>' . esc_html__("You can configure the page structure, using this options.", 'lafka-plugin') . '</p>';

		// Top Menu
		$choose_menu_options = lafka_get_choose_menu_options();
		$output .= '<p><label for="lafka_top_menu"><b>' . esc_html__("Choose Top Menu", 'lafka-plugin') . '</b></label></p>';
		$output .= "<select name='lafka_top_menu'>";
		// Add a default option
		foreach ($choose_menu_options as $key => $val) {
			$output .= "<option value='" . esc_attr($key) . "' " . esc_attr(selected($values['lafka_top_menu'], $key, false)) . " >" . esc_html($val) . "</option>";
		}
		$output .= "</select>";

		// Show title
		$output .= '<p><label for="lafka_show_title_page"><b>' . esc_html__("Show Title", 'lafka-plugin') . '</b></label></p>';
		$output .= '<input id="lafka_show_title_page_yes" ' . checked($values['lafka_show_title_page'], 'yes', false) . ' type="radio" value="yes" name="lafka_show_title_page">';
		$output .= '<label for="lafka_show_title_page_yes">Yes </label>&nbsp;';
		$output .= '<input id="lafka_show_title_page_no" ' . checked($values['lafka_show_title_page'], 'no', false) . ' type="radio" value="no" name="lafka_show_title_page">';
		$output .= '<label for="lafka_show_title_page_no">No</label>';

		// Show breadcrumb
		$output .= '<p><label for="lafka_show_breadcrumb"><b>' . esc_html__("Show Breadcrumb", 'lafka-plugin') . '</b></label></p>';
		$output .= "<input id='lafka_show_breadcrumb_yes' " . checked($values['lafka_show_breadcrumb'], 'yes', false) . " type='radio' value='yes' name='lafka_show_breadcrumb'>";
		$output .= '<label for="lafka_show_breadcrumb_yes">Yes </label>&nbsp;';
		$output .= '<input id="lafka_show_breadcrumb_no" ' . checked($values['lafka_show_breadcrumb'], 'no', false) . ' type="radio" value="no" name="lafka_show_breadcrumb">';
		$output .= '<label for="lafka_show_breadcrumb_no">No</label>';

		// Show featured image inside post in single post view
		$screen = get_current_screen();
		if ($screen && in_array($screen->post_type, array('post'), true)) {
			$output .= '<p><label for="lafka_show_feat_image_in_post"><b>' . esc_html__( "Featured Image in Single Post View", 'lafka-plugin' ) . '</b></label></p>';
			$output .= '<input id="lafka_show_feat_image_in_post_yes" ' . checked( $values['lafka_show_feat_image_in_post'], 'yes', false ) . ' type="radio" value="yes" name="lafka_show_feat_image_in_post">';
			$output .= '<label for="lafka_show_feat_image_in_post_yes">Yes </label>&nbsp;';
			$output .= '<input id="lafka_show_feat_image_in_post_no" ' . checked( $values['lafka_show_feat_image_in_post'], 'no', false ) . ' type="radio" value="no" name="lafka_show_feat_image_in_post">';
			$output .= '<label for="lafka_show_feat_image_in_post_no">No</label>';
		}

		// Show share
		$output .= '<p><label for="lafka_show_share"><b>' . esc_html__("Show Social Share Links", 'lafka-plugin') . '</b></label></p>';
		$output .= '<input id="lafka_show_share_default" ' . checked($values['lafka_show_share'], 'default', false) . ' type="radio" value="default" name="lafka_show_share">';
		$output .= '<label for="lafka_show_share_default">' . esc_html__('Default', 'lafka-plugin') . '</label>&nbsp;';
		$output .= '<input id="lafka_show_share_yes" ' . checked($values['lafka_show_share'], 'yes', false) . ' type="radio" value="yes" name="lafka_show_share">';
		$output .= '<label for="lafka_show_share_yes">Yes </label>&nbsp;';
		$output .= '<input id="lafka_show_share_no" ' . checked($values['lafka_show_share'], 'no', false) . ' type="radio" value="no" name="lafka_show_share">';
		$output .= '<label for="lafka_show_share_no">No</label>';

		// Show Main sidebar
		$output .= '<p><label for="lafka_show_sidebar"><b>' . esc_html__("Main Sidebar", 'lafka-plugin') . '</b></label></p>';
		$output .= '<input id="lafka_show_sidebar_yes" ' . checked($values['lafka_show_sidebar'], 'yes', false) . ' type="radio" value="yes" name="lafka_show_sidebar">';
		$output .= '<label for="lafka_show_sidebar_yes">Show </label>&nbsp;';
		$output .= '<input id="lafka_show_sidebar_no" ' . checked($values['lafka_show_sidebar'], 'no', false) . ' type="radio" value="no" name="lafka_show_sidebar">';
		$output .= '<label for="lafka_show_sidebar_no">Hide </label>';

		// Select Main sidebar
		$output .= "<select name='lafka_custom_sidebar'>";
		// Add a default option
		$output .= "<option";
		if ($values['lafka_custom_sidebar'] == "default") {
			$output .= " selected='selected'";
		}
		$output .= " value='default'>" . esc_html__('default', 'lafka-plugin') . "</option>";

		// Fill the select element with all registered sidebars
		foreach ($wp_registered_sidebars as $sidebar_id => $sidebar) {
			if ($sidebar_id != 'bottom_footer_sidebar' && $sidebar_id != 'pre_header_sidebar') {
				$output .= "<option";
				if ($sidebar_id == $values['lafka_custom_sidebar']) {
					$output .= " selected='selected'";
				}
				$output .= " value='" . esc_attr($sidebar_id) . "'>" . esc_html($sidebar['name']) . "</option>";
			}
		}

		$output .= "</select>";

		// Main Sidebar Position
		$output .= '<p><label for="lafka_sidebar_position"><b>' . esc_html__("Main Sidebar Position", 'lafka-plugin') . '</b></label></p>';
		$output .= '<select name="lafka_sidebar_position">';
		$output .= '<option value="default" '.esc_attr(selected($values['lafka_sidebar_position'], 'default', false)).' >' . esc_html__("default", 'lafka-plugin') . '</option>';
		$output .= '<option value="lafka-left-sidebar" '.esc_attr(selected($values['lafka_sidebar_position'], 'lafka-left-sidebar', false)).'>' . esc_html__("Left", 'lafka-plugin') . '</option>';
		$output .= '<option value="lafka-right-sidebar" '.esc_attr(selected($values['lafka_sidebar_position'], 'lafka-right-sidebar', false)).'>' . esc_html__("Right", 'lafka-plugin') . '</option>';
		$output .= '</select>';

		// Show offcanvas sidebar
		$output .= '<p><label for="lafka_show_offcanvas_sidebar"><b>' . esc_html__("Off Canvas Sidebar", 'lafka-plugin') . '</b></label></p>';
		$output .= '<input id="lafka_show_offcanvas_sidebar_yes" ' . checked($values['lafka_show_offcanvas_sidebar'], 'yes', false) . ' type="radio" value="yes" name="lafka_show_offcanvas_sidebar">';
		$output .= '<label for="lafka_show_offcanvas_sidebar_yes">Show </label>&nbsp;';
		$output .= '<input id="lafka_show_offcanvas_sidebar_no" ' . checked($values['lafka_show_offcanvas_sidebar'], 'no', false) . ' type="radio" value="no" name="lafka_show_offcanvas_sidebar">';
		$output .= '<label for="lafka_show_offcanvas_sidebar_no">Hide </label>';

		// Select offcanvas sidebar
		$output .= "<select name='lafka_custom_offcanvas_sidebar'>";

		// Add a default option
		$output .= "<option";
		if ($values['lafka_custom_offcanvas_sidebar'] == "default") {
			$output .= " selected='selected'";
		}
		$output .= " value='default'>" . esc_html__('default', 'lafka-plugin') . "</option>";

		// Fill the select element with all registered sidebars
		foreach ($wp_registered_sidebars as $sidebar_id => $sidebar) {
			if ($sidebar_id != 'pre_header_sidebar') {
				$output .= "<option";
				if ($sidebar_id == $values['lafka_custom_offcanvas_sidebar']) {
					$output .= " selected='selected'";
				}
				$output .= " value='" . esc_attr($sidebar_id) . "'>" . esc_html($sidebar['name']) . "</option>";
			}
		}

		$output .= "</select>";

		// Show footer sidebar
		$output .= '<p><label for="lafka_show_footer_sidebar"><b>' . esc_html__("Footer Sidebar", 'lafka-plugin') . '</b></label></p>';
		$output .= '<input id="lafka_show_footer_sidebar_yes" ' . checked($values['lafka_show_footer_sidebar'], 'yes', false) . ' type="radio" value="yes" name="lafka_show_footer_sidebar">';
		$output .= '<label for="lafka_show_footer_sidebar_yes">Show </label>&nbsp;';
		$output .= '<input id="lafka_show_footer_sidebar_no" ' . checked($values['lafka_show_footer_sidebar'], 'no', false) . ' type="radio" value="no" name="lafka_show_footer_sidebar">';
		$output .= '<label for="lafka_show_footer_sidebar_no">Hide </label>';

		// Select footer sidebar
		$output .= "<select name='lafka_custom_footer_sidebar'>";

		// Add a default option
		$output .= "<option";
		if ($values['lafka_custom_footer_sidebar'] == "default") {
			$output .= " selected='selected'";
		}
		$output .= " value='default'>" . esc_html__('default', 'lafka-plugin') . "</option>";

		// Fill the select element with all registered sidebars
		foreach ($wp_registered_sidebars as $sidebar_id => $sidebar) {
			if ($sidebar_id != 'pre_header_sidebar') {
				$output .= "<option";
				if ($sidebar_id == $values['lafka_custom_footer_sidebar']) {
					$output .= " selected='selected'";
				}
				$output .= " value='" . esc_attr($sidebar_id) . "'>" . esc_html($sidebar['name']) . "</option>";
			}
		}

		$output .= "</select>";

		echo $output; // All dynamic data escaped
	}

}

/* When the post is saved, saves our custom data */
if (!function_exists('lafka_save_page_options_postdata')) {

	function lafka_save_page_options_postdata($post_id) {
		// verify if this is an auto save routine.
		// If it is our form has not been submitted, so we dont want to do anything
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		// verify this came from our screen and with proper authorization,
		// because save_post can be triggered at other times
		if (isset($_POST['page_options_nonce']) && !wp_verify_nonce($_POST['page_options_nonce'], 'lafka_save_page_options_postdata')) {
			return;
		}

		if (!current_user_can('edit_post', $post_id)) {
			return;
		}

		if (isset($_POST['lafka_top_menu'])) {
			update_post_meta($post_id, "lafka_top_menu", sanitize_text_field($_POST['lafka_top_menu']));
		}
		if (isset($_POST['lafka_show_title_page'])) {
			update_post_meta($post_id, "lafka_show_title_page", sanitize_text_field($_POST['lafka_show_title_page']));
		}
		if (isset($_POST['lafka_show_breadcrumb'])) {
			update_post_meta($post_id, "lafka_show_breadcrumb", sanitize_text_field($_POST['lafka_show_breadcrumb']));
		}
		if (isset($_POST['lafka_show_feat_image_in_post'])) {
			update_post_meta($post_id, "lafka_show_feat_image_in_post", sanitize_text_field($_POST['lafka_show_feat_image_in_post']));
		}
		if (isset($_POST['lafka_show_sidebar'])) {
			update_post_meta($post_id, "lafka_show_sidebar", sanitize_text_field($_POST['lafka_show_sidebar']));
		}
		if (isset($_POST['lafka_sidebar_position'])) {
			update_post_meta($post_id, "lafka_sidebar_position", sanitize_text_field($_POST['lafka_sidebar_position']));
		}
		if (isset($_POST['lafka_show_footer_sidebar'])) {
			update_post_meta($post_id, "lafka_show_footer_sidebar", sanitize_text_field($_POST['lafka_show_footer_sidebar']));
		}
		if (isset($_POST['lafka_show_offcanvas_sidebar'])) {
			update_post_meta($post_id, "lafka_show_offcanvas_sidebar", sanitize_text_field($_POST['lafka_show_offcanvas_sidebar']));
		}
		if (isset($_POST['lafka_show_share'])) {
			update_post_meta($post_id, "lafka_show_share", sanitize_text_field($_POST['lafka_show_share']));
		}
		if (isset($_POST['lafka_custom_sidebar'])) {
			update_post_meta($post_id, "lafka_custom_sidebar", sanitize_text_field($_POST['lafka_custom_sidebar']));
		}
		if (isset($_POST['lafka_custom_footer_sidebar'])) {
			update_post_meta($post_id, "lafka_custom_footer_sidebar", sanitize_text_field($_POST['lafka_custom_footer_sidebar']));
		}
		if (isset($_POST['lafka_custom_offcanvas_sidebar'])) {
			update_post_meta($post_id, "lafka_custom_offcanvas_sidebar", sanitize_text_field($_POST['lafka_custom_offcanvas_sidebar']));
		}
	}

}

// If Revolution slider is active add the meta box
if (LAFKA_PLUGIN_IS_REVOLUTION) {
	add_action('add_meta_boxes', 'lafka_add_revolution_slider_metabox');
	add_action('save_post', 'lafka_save_revolution_slider_postdata');
}

/* Adds a box to the side column on the Post, Page and Foodmenu edit screens */
if (!function_exists('lafka_add_revolution_slider_metabox')) {

	function lafka_add_revolution_slider_metabox() {
		add_meta_box(
						'lafka_revolution_slider', esc_html__('Revolution Slider', 'lafka-plugin'), 'lafka_revolution_slider_callback', 'page', 'side'
		);

		add_meta_box(
						'lafka_revolution_slider', esc_html__('Revolution Slider', 'lafka-plugin'), 'lafka_revolution_slider_callback', 'post', 'side'
		);

		add_meta_box(
						'lafka_revolution_slider', esc_html__('Revolution Slider', 'lafka-plugin'), 'lafka_revolution_slider_callback', 'lafka-foodmenu', 'side'
		);

		add_meta_box(
						'lafka_revolution_slider', esc_html__('Revolution Slider', 'lafka-plugin'), 'lafka_revolution_slider_callback', 'tribe_events', 'side'
		);
	}

}

/* Prints the box content */
if (!function_exists('lafka_revolution_slider_callback')) {

	function lafka_revolution_slider_callback($post) {

		// If current page is set as Blog page - don't show the options
		if ($post->ID == get_option('page_for_posts')) {
			echo esc_html__("Revolution slider is disabled for this page, because the page is set as Blog page from Settings->Reading.", 'lafka-plugin');
			return;
		}

		// If current page is set as Shop page - don't show the options
		if (LAFKA_PLUGIN_IS_WOOCOMMERCE && $post->ID == wc_get_page_id('shop')) {
			echo esc_html__("Revolution slider is disabled for this page, because the page is set as Shop page.", 'lafka-plugin');
			return;
		}

		// Use nonce for verification
		wp_nonce_field('lafka_save_revolution_slider_postdata', 'lafka_revolution_slider');

		$custom = get_post_custom($post->ID);

		if (isset($custom['lafka_rev_slider'])) {
			$val = $custom['lafka_rev_slider'][0];
		} else {
			$val = "none";
		}

		if (isset($custom['lafka_rev_slider_before_header']) && $custom['lafka_rev_slider_before_header'][0] != '') {
			$val_before_header = esc_attr($custom['lafka_rev_slider_before_header'][0]);
		} else {
			$val_before_header = 0;
		}

		// description
		$output = '<p>' . esc_html__("You can choose a Revolution slider to be attached. It will show up on the top of this page/post.", 'lafka-plugin') . '</p>';

		// select
		$output .= '<p><label for="lafka_rev_slider"><b>' . esc_html__("Select slider", 'lafka-plugin') . '</b></label></p>';
		$output .= "<select name='lafka_rev_slider'>";

		// Add a default option
		$output .= "<option";
		if ($val == "none") {
			$output .= " selected='selected'";
		}
		$output .= " value='none'>" . esc_html__('none', 'lafka-plugin') . "</option>";

		// Get defined revolution slides
		$slider = new RevSlider();
		$arrSliders = $slider->getArrSlidersShort();

		// Fill the select element with all registered slides
		foreach ($arrSliders as $id => $title) {
			$output .= "<option";
			if ($id == $val)
				$output .= " selected='selected'";
			$output .= " value='" . esc_attr($id) . "'>" . esc_html($title) . "</option>";
		}

		$output .= "</select>";
		$screen = get_current_screen();
		// only for pages
		if ($screen && in_array($screen->post_type, array('page'), true)) {
			// place before header
			$output .= '<p><label for="lafka_rev_slider_before_header">';
			$output .= "<input type='checkbox' id='lafka_rev_slider_before_header' name='lafka_rev_slider_before_header' value='1' " . checked(esc_attr($val_before_header), 1, false) . "><b>" . esc_html__("Place before header", 'lafka-plugin') . "</b></label></p>";
		}
		echo $output; // All dynamic data escaped
	}

}

/* When the post is saved, saves our custom data */
if (!function_exists('lafka_save_revolution_slider_postdata')) {

	function lafka_save_revolution_slider_postdata($post_id) {
		// verify if this is an auto save routine.
		// If it is our form has not been submitted, so we dont want to do anything
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		// verify this came from our screen and with proper authorization,
		// because save_post can be triggered at other times
		if (isset($_POST['lafka_revolution_slider']) && !wp_verify_nonce($_POST['lafka_revolution_slider'], 'lafka_save_revolution_slider_postdata')) {
			return;
		}

		if (!current_user_can('edit_post', $post_id)) {
			return;
		}

		if (isset($_POST['lafka_rev_slider'])) {
			update_post_meta($post_id, "lafka_rev_slider", sanitize_text_field($_POST['lafka_rev_slider']));
		}

		if (isset($_POST['lafka_rev_slider_before_header']) && $_POST['lafka_rev_slider_before_header']) {
			update_post_meta($post_id, "lafka_rev_slider_before_header", 1);
		} else {
			update_post_meta($post_id, "lafka_rev_slider_before_header", 0);
		}
	}

}

/**
 * Register video background metaboxes
 */
add_action('add_meta_boxes', 'lafka_add_video_bckgr_metabox');
add_action('save_post', 'lafka_save_video_bckgr_postdata');

/* Adds a box to the side column on the Page edit screens */
if (!function_exists('lafka_add_video_bckgr_metabox')) {

	function lafka_add_video_bckgr_metabox() {

		$posttypes = array('page', 'post', 'lafka-foodmenu', 'tribe_events');
		if (LAFKA_PLUGIN_IS_WOOCOMMERCE) {
			$posttypes[] = 'product';
		}
		if (LAFKA_PLUGIN_IS_BBPRESS) {
			$posttypes[] = 'forum';
			$posttypes[] = 'topic';
		}

		foreach ($posttypes as $pt) {
			add_meta_box(
							'lafka_video_bckgr', esc_html__('Video Background', 'lafka-plugin'), 'lafka_video_bckgr_callback', $pt, 'side'
			);
		}
	}

}

/* Prints the box content */
if (!function_exists('lafka_video_bckgr_callback')) {

	function lafka_video_bckgr_callback($post) {
		// If current page is set as Blog page - don't show the options
		if ($post->ID == get_option('page_for_posts')) {
			echo esc_html__("Video Background options are disabled for this page, because the page is set as Blog page from Settings->Reading.", 'lafka-plugin');
			return;
		}

		// If current page is set as Shop page - don't show the options
		if (LAFKA_PLUGIN_IS_WOOCOMMERCE && $post->ID == wc_get_page_id('shop')) {
			echo esc_html__("Video Background options are disabled for this page, because the page is set as Shop page.", 'lafka-plugin');
			return;
		}


		// Use nonce for verification
		wp_nonce_field('lafka_save_video_bckgr_postdata', 'video_bckgr_nonce');

		$custom = get_post_custom($post->ID);

		// Set default values
		$values = array(
				'lafka_video_bckgr_url' => '',
				'lafka_video_bckgr_start' => '',
				'lafka_video_bckgr_end' => '',
				'lafka_video_bckgr_loop' => 1,
				'lafka_video_bckgr_mute' => 1
		);

		if (isset($custom['lafka_video_bckgr_url']) && $custom['lafka_video_bckgr_url'][0] != '') {
			$values['lafka_video_bckgr_url'] = esc_attr($custom['lafka_video_bckgr_url'][0]);
		}
		if (isset($custom['lafka_video_bckgr_start']) && $custom['lafka_video_bckgr_start'][0] != '') {
			$values['lafka_video_bckgr_start'] = esc_attr($custom['lafka_video_bckgr_start'][0]);
		}
		if (isset($custom['lafka_video_bckgr_end']) && $custom['lafka_video_bckgr_end'][0] != '') {
			$values['lafka_video_bckgr_end'] = esc_attr($custom['lafka_video_bckgr_end'][0]);
		}
		if (isset($custom['lafka_video_bckgr_loop']) && $custom['lafka_video_bckgr_loop'][0] != '') {
			$values['lafka_video_bckgr_loop'] = esc_attr($custom['lafka_video_bckgr_loop'][0]);
		}
		if (isset($custom['lafka_video_bckgr_mute']) && $custom['lafka_video_bckgr_mute'][0] != '') {
			$values['lafka_video_bckgr_mute'] = esc_attr($custom['lafka_video_bckgr_mute'][0]);
		}

		// description
		$output = '<p>' . esc_html__("Define the video background options for this page/post.", 'lafka-plugin') . '</p>';

		// Video URL
		$output .= '<p><label for="lafka_video_bckgr_url"><b>' . esc_html__("YouTube video URL", 'lafka-plugin') . '</b></label></p>';
		$output .= '<input type="text" id="lafka_video_bckgr_url" name="lafka_video_bckgr_url" value="' . esc_attr($values['lafka_video_bckgr_url']) . '" class="large-text" />';

		// Start time
		$output .= '<p><label for="lafka_video_bckgr_start"><b>' . esc_html__("Start time in seconds", 'lafka-plugin') . '</b></label></p>';
		$output .= '<input type="text" id="lafka_video_bckgr_start" name="lafka_video_bckgr_start" value="' . esc_attr($values['lafka_video_bckgr_start']) . '" size="8" />';

		// End time
		$output .= '<p><label for="lafka_video_bckgr_end"><b>' . esc_html__("End time in seconds", 'lafka-plugin') . '</b></label></p>';
		$output .= '<input type="text" id="lafka_video_bckgr_end" name="lafka_video_bckgr_end" value="' . esc_attr($values['lafka_video_bckgr_end']) . '" size="8" />';

		// Loop
		$output .= '<p><label for="lafka_video_bckgr_loop">';
		$output .= "<input type='checkbox' id='lafka_video_bckgr_loop' name='lafka_video_bckgr_loop' value='1' " . checked(esc_attr($values['lafka_video_bckgr_loop']), 1, false) . "><b>" . esc_html__("Loop", 'lafka-plugin') . "</b></label></p>";

		// Mute
		$output .= '<p><label for="lafka_video_bckgr_mute">';
		$output .= "<input type='checkbox' id='lafka_video_bckgr_mute' name='lafka_video_bckgr_mute' value='1' " . checked(esc_attr($values['lafka_video_bckgr_mute']), 1, false) . "><b>" . esc_html__("Mute", 'lafka-plugin') . "</b></label></p>";


		echo $output; // All dynamic data escaped
	}

}

/* When the post is saved, saves our custom data */
if (!function_exists('lafka_save_video_bckgr_postdata')) {

	function lafka_save_video_bckgr_postdata($post_id) {
		global $pagenow;

		// verify if this is an auto save routine.
		// If it is our form has not been submitted, so we dont want to do anything
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		// verify this came from our screen and with proper authorization,
		// because save_post can be triggered at other times

		if (isset($_POST['video_bckgr_nonce']) && !wp_verify_nonce($_POST['video_bckgr_nonce'], 'lafka_save_video_bckgr_postdata')) {
			return;
		}

		if (!current_user_can('edit_post', $post_id)) {
			return;
		}

		if ('post-new.php' == $pagenow) {
			return;
		}

		if (isset($_POST['lafka_video_bckgr_url'])) {
			update_post_meta($post_id, "lafka_video_bckgr_url", esc_url($_POST['lafka_video_bckgr_url']));
		}
		if (isset($_POST['lafka_video_bckgr_start'])) {
			update_post_meta($post_id, "lafka_video_bckgr_start", sanitize_text_field($_POST['lafka_video_bckgr_start']));
		}
		if (isset($_POST['lafka_video_bckgr_end'])) {
			update_post_meta($post_id, "lafka_video_bckgr_end", sanitize_text_field($_POST['lafka_video_bckgr_end']));
		}
		if (isset($_POST['lafka_video_bckgr_loop']) && $_POST['lafka_video_bckgr_loop']) {
			update_post_meta($post_id, "lafka_video_bckgr_loop", 1);
		} else {
			update_post_meta($post_id, "lafka_video_bckgr_loop", 0);
		}
		if (isset($_POST['lafka_video_bckgr_mute']) && $_POST['lafka_video_bckgr_mute']) {
			update_post_meta($post_id, "lafka_video_bckgr_mute", 1);
		} else {
			update_post_meta($post_id, "lafka_video_bckgr_mute", 0);
		}
	}

}

/**
 * Foodmenu CPT metaboxes
 */
add_action('add_meta_boxes', 'lafka_add_foodmenu_metabox');
add_action('save_post', 'lafka_save_foodmenu_postdata');

/* Adds the custom fields for lafka-foodmenu CPT */
if (!function_exists('lafka_add_foodmenu_metabox')) {

	function lafka_add_foodmenu_metabox() {
		add_meta_box(
						'lafka_foodmenu_details', esc_html__('Menu Entry Fields', 'lafka-plugin'), 'lafka_foodmenu_callback', 'lafka-foodmenu', 'normal', 'high'
		);
	}

}

/* Prints the foodmenu content */
if (!function_exists('lafka_foodmenu_callback')) {

	function lafka_foodmenu_callback($post) {
		// Use nonce for verification
		wp_nonce_field('lafka_save_foodmenu_postdata', 'lafka_foodmenu_nonce');

		if ( defined( 'LAFKA_PLUGIN_IS_WOOCOMMERCE' ) && LAFKA_PLUGIN_IS_WOOCOMMERCE ) {
			$currency = get_woocommerce_currency();
			echo '<h4>' . esc_html__( 'The currency for all price fields will be the one set up in WooCommerce', 'lafka-plugin' ) . '</h4>';
		} else {
			$currency = lafka_get_option('foodmenu_currency');
			echo '<h4>' . esc_html__( 'The currency for all price fields will be the one set up in Theme Options -> Restaurant Menu', 'lafka-plugin' ) . '</h4>';
		}

		echo '<div><label for="lafka_item_single_price" class="lafka-admin-option-label">';
		esc_html_e( 'Item Price', 'lafka-plugin' );
		echo '</label> ';
		echo '<input type="text" id="lafka_item_single_price" name="lafka_item_single_price" value="' . esc_attr( get_post_meta( $post->ID, 'lafka_item_single_price', true ) ) . '" class="small-text" /> ' . esc_html( $currency ) . '</div>';

		echo '<div><label for="lafka_item_weight" class="lafka-admin-option-label">';
		esc_html_e('Item Weight', 'lafka-plugin');
		echo '</label> ';
		echo '<input type="text" id="lafka_item_weight" name="lafka_item_weight" value="' . esc_attr(get_post_meta($post->ID, 'lafka_item_weight', true)) . '" class="small-text" />';
		echo ' <label for="lafka_item_weight_unit" >';
		esc_html_e('Units (g)', 'lafka-plugin');
		echo '</label> ';
		echo '<input type="text" id="lafka_item_weight_unit" name="lafka_item_weight_unit" value="' . esc_attr(get_post_meta($post->ID, 'lafka_item_weight_unit', true)) . '" class="small-text" /></div>';

		echo '<h4>' . esc_html__('Fill up to three option->price pairs. E.g. Small -> +5, Big -> 10', 'lafka-plugin') . '</h4>';

		for ( $i = 1; $i <= 3; $i ++ ) {
			echo '<div>';
			echo '<label for="lafka_item_size' . $i . '">';
			esc_html_e( 'Option', 'lafka-plugin' );
			echo '</label> ';
			echo '<input type="text" id="lafka_item_size1" name="lafka_item_size' . $i . '" value="' . esc_attr( get_post_meta( $post->ID, 'lafka_item_size' . $i, true ) ) . '" class="regular-text" />';
			echo ' <label for="lafka_item_price' . $i . '">';
			esc_html_e( 'Price', 'lafka-plugin' );
			echo '</label> ';
			echo '<input type="text" id="lafka_item_price' . $i . '" name="lafka_item_price' . $i . '" value="' . esc_attr( get_post_meta( $post->ID, 'lafka_item_price' . $i, true ) ) . '" class="small-text" /> ' . esc_html( $currency );
			echo '</div>';
		}

		echo '<br>';

		echo '<label for="lafka_ingredients">';
		esc_html_e('Ingredients', 'lafka-plugin');
		echo '</label> ';
		echo '<div><input type="text" id="lafka_ingredients" name="lafka_ingredients" value="' . esc_attr(get_post_meta($post->ID, 'lafka_ingredients', true)) . '" class="regular-text" /></div>';

		echo '<label for="lafka_allergens">';
		esc_html_e('Allergens', 'lafka-plugin');
		echo '</label> ';
		echo '<div><input type="text" id="lafka_allergens" name="lafka_allergens" value="' . esc_attr(get_post_meta($post->ID, 'lafka_allergens', true)) . '" class="regular-text" /></div>';

		if ( class_exists( 'Lafka_Nutrition_Config' ) ) {
			echo '<h4>' . esc_html__( 'Nutrition Information:', 'lafka-plugin' ) . '</h4>';
			echo '<div class="lafka-menu-nutrition-admin">';
			foreach ( Lafka_Nutrition_Config::$nutrition_meta_fields as $field_name => $field_data ) {
				echo '<div><label class="lafka-admin-option-label" for="' . esc_attr( $field_name ) . '">';
				echo esc_html( $field_data['label'] );
				echo '</label> ';
				echo '<input type="text" id="' . esc_attr( $field_name ) . '" name="' . esc_attr( $field_name ) . '" value="' . esc_attr( get_post_meta( $post->ID, $field_name, true ) ) . '" class="small-text"  /></div>';
			}
			echo '</div>';
		}

		echo '<h4>' . esc_html__('Menu Entry External Links:', 'lafka-plugin') . '</h4>';
		echo '<label for="lafka_ext_link_button_title">';
		esc_html_e('First Button Title', 'lafka-plugin');
		echo '</label> ';
		echo '<div><input type="text" id="lafka_ext_link_button_title" name="lafka_ext_link_button_title" value="' . esc_attr(get_post_meta($post->ID, 'lafka_ext_link_button_title', true)) . '" class="regular-text" /></div>';

		echo '<label for="lafka_ext_link_url">';
		esc_html_e('First Button Url', 'lafka-plugin');
		echo '</label> ';
		echo '<div><input type="text" id="lafka_ext_link_url" name="lafka_ext_link_url" value="' . esc_attr(get_post_meta($post->ID, 'lafka_ext_link_url', true)) . '" class="regular-text" /></div>';

		echo '<h4>' . esc_html__('Short Description', 'lafka-plugin') . '</h4>';
		wp_editor(wp_kses_post(get_post_meta($post->ID, 'lafka_add_description', true)), 'lafkaadddescription', $settings = array('textarea_name' => 'lafka_add_description', 'textarea_rows' => 5));
	}

}

/* When the foodmenu is saved, saves our custom data */
if (!function_exists('lafka_save_foodmenu_postdata')) {

	function lafka_save_foodmenu_postdata($post_id) {

		// Check if our nonce is set.
		if (!isset($_POST['lafka_foodmenu_nonce'])) {
			return;
		}

		// Verify that the nonce is valid.
		if (!wp_verify_nonce($_POST['lafka_foodmenu_nonce'], 'lafka_save_foodmenu_postdata')) {
			return;
		}

		// If this is an autosave, our form has not been submitted, so we don't want to do anything.
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		// Check the user's permissions.
		if (isset($_POST['post_type']) && 'page' === $_POST['post_type']) {

			if (!current_user_can('edit_post', $post_id)) {
				return;
			}
		} else {

			if (!current_user_can('edit_posts', $post_id)) {
				return;
			}
		}

		/* OK, it's safe for us to save the data now. */
		// Make sure that it is set.
		if (!isset($_POST['lafka_item_single_price'], $_POST['lafka_item_size1'], $_POST['lafka_item_price1'], $_POST['lafka_item_size2'], $_POST['lafka_item_price2'], $_POST['lafka_item_size3'], $_POST['lafka_item_price3'], $_POST['lafka_ingredients'], $_POST['lafka_allergens'], $_POST['lafka_ext_link_button_title'], $_POST['lafka_ext_link_url'], $_POST['lafka_add_description'])) {
			return;
		}

		update_post_meta($post_id, 'lafka_item_single_price', sanitize_text_field($_POST['lafka_item_single_price']));
		update_post_meta($post_id, 'lafka_item_weight', sanitize_text_field($_POST['lafka_item_weight']));
		update_post_meta($post_id, 'lafka_item_weight_unit', sanitize_text_field($_POST['lafka_item_weight_unit']));
		for ( $i = 1; $i <= 3; $i ++ ) {
			update_post_meta( $post_id, 'lafka_item_size'.$i, sanitize_text_field( $_POST['lafka_item_size'.$i] ) );
			update_post_meta( $post_id, 'lafka_item_price'.$i, sanitize_text_field( $_POST['lafka_item_price'.$i] ) );
		}
		update_post_meta($post_id, 'lafka_ingredients', sanitize_text_field($_POST['lafka_ingredients']));
		update_post_meta($post_id, 'lafka_allergens', sanitize_text_field($_POST['lafka_allergens']));
		if ( class_exists( 'Lafka_Nutrition_Config' ) ) {
			foreach ( Lafka_Nutrition_Config::$nutrition_meta_fields as $field_name => $field_data ) {
				if ( is_numeric( $_POST[ $field_name ] ) ) {
					update_post_meta( $post_id, $field_name, sanitize_text_field( $_POST[ $field_name ] ) );
				} else {
					update_post_meta( $post_id, $field_name, '' );
				}
			}
		}
		update_post_meta($post_id, 'lafka_ext_link_button_title', sanitize_text_field($_POST['lafka_ext_link_button_title']));
		update_post_meta($post_id, 'lafka_ext_link_url', esc_url($_POST['lafka_ext_link_url']));
		update_post_meta($post_id, 'lafka_add_description', wp_kses_post($_POST['lafka_add_description']));
	}

}

/**
 * Register additional featured images metaboxes (5)
 */
add_action('add_meta_boxes', 'lafka_add_additonal_featured_meta');
add_action('save_post', 'lafka_save_additonal_featured_meta_postdata');

/* Adds a box to the side column on the Page/Post/Foodmenu edit screens */
if (!function_exists('lafka_add_additonal_featured_meta')) {

	function lafka_add_additonal_featured_meta() {
		$post_types_array = array('page', 'post', 'lafka-foodmenu', 'tribe_events');

		for ($i = 2; $i <= 6; $i++) {
			foreach ($post_types_array as $post_type) {
				add_meta_box(
								'lafka_featured_' . $i, esc_html__('Featured Image', 'lafka-plugin') . ' ' . $i, 'lafka_additonal_featured_meta_callback', $post_type, 'side', 'default', array('num' => $i)
				);
			}
		}
	}

}

/* Prints the box content */
if (!function_exists('lafka_additonal_featured_meta_callback')) {

	function lafka_additonal_featured_meta_callback($post, $args) {
		// Use nonce for verification
		wp_nonce_field('lafka_save_additonal_featured_meta_postdata', 'lafka_featuredmeta');

		$num = esc_attr($args['args']['num']);

		$image_id = get_post_meta(
						$post->ID, 'lafka_featured_imgid_' . $num, true
		);

		$add_link_style = '';
		$del_link_style = '';

		$output = '<p class="hide-if-no-js">';
		$output .= '<span id="lafka_featured_imgid_' . esc_attr($num) . '_images" class="lafka_featured_img_holder">';

		if ($image_id) {
			$add_link_style = 'style="display:none"';
			$output .= wp_get_attachment_image($image_id, 'medium');
		} else {
			$del_link_style = 'style="display:none"';
		}

		$output .= '</span>';
		$output .= '</p>';

		$output .= '<p class="hide-if-no-js">';
		$output .= '<input id="lafka_featured_imgid_' . esc_attr($num) . '" name="lafka_featured_imgid_' . esc_attr($num) . '" type="hidden" value="' . esc_attr($image_id) . '" />';

		// delete link
		$output .= '<a id="delete_lafka_featured_imgid_' . esc_attr($num) . '" ' . wp_kses_data($del_link_style) . ' class="lafka_delete_image_button" href="#" title="' . esc_attr__('Remove featured image', 'lafka-plugin') . ' ' . esc_attr($num) . '">' . esc_html__('Remove featured image', 'lafka-plugin') . ' ' . esc_attr($num) . '</a>';

		// add link
		$output .= '<a id="upload_lafka_featured_imgid_' . esc_attr($num) . '" ' . wp_kses_data($add_link_style) . ' data-uploader_title="' . esc_attr__('Select Featured Image', 'lafka-plugin') . ' ' . esc_attr($num) . '" data-uploader_button_text="' . esc_attr__('Set Featured Image', 'lafka-plugin') . ' ' . esc_attr($num) . '" class="lafka_upload_image_button is_upload_link" href="#" title="' . esc_attr__('Set featured image', 'lafka-plugin') . ' ' . esc_attr($num) . '">' . esc_html__('Set featured image', 'lafka-plugin') . ' ' . esc_attr($num) . '</a>';


		$output .= '</p>';

		echo $output; // All dynamic data escaped
	}

}

/* When the post is saved, saves our custom data */
if (!function_exists('lafka_save_additonal_featured_meta_postdata')) {

	function lafka_save_additonal_featured_meta_postdata($post_id) {
		// verify if this is an auto save routine.
		// If it is our form has not been submitted, so we dont want to do anything
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		// verify this came from our screen and with proper authorization,
		// because save_post can be triggered at other times

		if (isset($_POST['lafka_featuredmeta']) && !wp_verify_nonce($_POST['lafka_featuredmeta'], 'lafka_save_additonal_featured_meta_postdata')) {
			return;
		}

		if (!current_user_can('edit_post', $post_id)) {
			return;
		}

		foreach ($_POST as $key => $value) {
			if (strstr($key, 'lafka_featured_imgid_')) {
				update_post_meta($post_id, sanitize_key($key), sanitize_text_field($value));
			}
		}
	}

}

/**
 * Register Foodmenu enable Cloud Zoom metabox
 */
add_action('add_meta_boxes', 'lafka_add_foodmenu_cz_metabox');
add_action('save_post', 'lafka_save_foodmenu_cz_postdata');

if (!function_exists('lafka_add_foodmenu_cz_metabox')) {

	function lafka_add_foodmenu_cz_metabox() {
		add_meta_box(
						'lafka_foodmenu_cz', esc_html__('Menu Entry Options', 'lafka-plugin'), 'lafka_foodmenu_cz_callback', 'lafka-foodmenu', 'side', 'low'
		);
	}

}

/* Prints the box content */
if (!function_exists('lafka_foodmenu_cz_callback')) {

	function lafka_foodmenu_cz_callback($post) {

		// Use nonce for verification
		wp_nonce_field('lafka_save_foodmenu_cz_postdata', 'foodmenu_cz_nonce');

		$custom = get_post_custom($post->ID);

		// Set default
		$lafka_prtfl_custom_content = 0;
		$lafka_prtfl_gallery = 'flex';

		if (isset($custom['lafka_prtfl_custom_content']) && $custom['lafka_prtfl_custom_content'][0]) {
			$lafka_prtfl_custom_content = $custom['lafka_prtfl_custom_content'][0];
		}
		if (isset($custom['lafka_prtfl_gallery']) && $custom['lafka_prtfl_gallery'][0]) {
			$lafka_prtfl_gallery = $custom['lafka_prtfl_gallery'][0];
		}

		$output = '<p><b>' . esc_html__('Custom Content:', 'lafka-plugin') . '</b></p>';

		$output .= '<p><label for="lafka_prtfl_custom_content">';
		$output .= "<input type='checkbox' id='lafka_prtfl_custom_content' name='lafka_prtfl_custom_content' value='1' " .
						checked(esc_attr($lafka_prtfl_custom_content), 1, false) . ">" .
						esc_html__("Don't use the menu entry gallery and all fields. Use only the content.", 'lafka-plugin') . "</label></p>";

		$output .= '<p><b>' . esc_html__('Menu entry gallery will appear as:', 'lafka-plugin') . '</b></p>';

		$output .= '<div><input id="lafka_prtfl_gallery_flex" ' . checked($lafka_prtfl_gallery, 'flex', false) . ' type="radio" value="flex" name="lafka_prtfl_gallery">';
		$output .= '<label for="lafka_prtfl_gallery_flex">' . esc_html__('Flex Slider', 'lafka-plugin') . '</label></div>';
		$output .= '<div><input id="lafka_prtfl_gallery_cloud" ' . checked($lafka_prtfl_gallery, 'cloud', false) . ' type="radio" value="cloud" name="lafka_prtfl_gallery">';
		$output .= '<label for="lafka_prtfl_gallery_cloud">' . esc_html__('Cloud Zoom', 'lafka-plugin') . '</label></div>';
		$output .= '<div><input id="lafka_prtfl_gallery_list" ' . checked($lafka_prtfl_gallery, 'list', false) . ' type="radio" value="list" name="lafka_prtfl_gallery">';
		$output .= '<label for="lafka_prtfl_gallery_list">' . esc_html__('Image List', 'lafka-plugin') . '</label></div>';

		echo $output; // All dynamic data escaped
	}

}

/* When the post is saved, saves our custom data */
if (!function_exists('lafka_save_foodmenu_cz_postdata')) {

	function lafka_save_foodmenu_cz_postdata($post_id) {
		// verify if this is an auto save routine.
		// If it is our form has not been submitted, so we dont want to do anything
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		// verify this came from our screen and with proper authorization,
		// because save_post can be triggered at other times

		if (isset($_POST['foodmenu_cz_nonce']) && !wp_verify_nonce($_POST['foodmenu_cz_nonce'], 'lafka_save_foodmenu_cz_postdata')) {
			return;
		}

		// Check the user's permissions.
		if (isset($_POST['post_type']) && 'page' === $_POST['post_type']) {

			if (!current_user_can('edit_post', $post_id)) {
				return;
			}
		} else {

			if (!current_user_can('edit_posts', $post_id)) {
				return;
			}
		}

		if (isset($_POST['lafka_prtfl_custom_content']) && $_POST['lafka_prtfl_custom_content']) {
			update_post_meta($post_id, "lafka_prtfl_custom_content", 1);
		} else {
			update_post_meta($post_id, "lafka_prtfl_custom_content", 0);
		}

		// It is checkbox - if is in the post - is set, if not - is not set
		if (isset($_POST['lafka_prtfl_gallery'])) {
			update_post_meta($post_id, "lafka_prtfl_gallery", sanitize_text_field($_POST['lafka_prtfl_gallery']));
		}
	}

}

/**
 * Register product video option for products
 */
add_action('add_meta_boxes', 'lafka_add_product_video_metabox');
add_action('save_post', 'lafka_save_product_video_postdata');

/* Adds a box to the side column on the Page edit screens */
if (!function_exists('lafka_add_product_video_metabox')) {

	function lafka_add_product_video_metabox() {
		add_meta_box(
			'lafka_product_video', esc_html__('Product Video', 'lafka-plugin'), 'lafka_product_video_callback', 'product', 'side'
		);
	}

}

/* Prints the box content */
if (!function_exists('lafka_product_video_callback')) {

	function lafka_product_video_callback($post) {

		// Use nonce for verification
		wp_nonce_field('lafka_save_product_video_postdata', 'product_video_nonce');

		$custom = get_post_custom($post->ID);

		// Set default values
		$values = array(
			'lafka_product_video_url' => ''
		);

		if (isset($custom['lafka_product_video_url']) && $custom['lafka_product_video_url'][0] != '') {
			$values['lafka_product_video_url'] = esc_attr($custom['lafka_product_video_url'][0]);
		}

		// description
		$output = '<p>' . esc_html__("Product Video to be displayed on the product page (YouTube, Vimeo, Self-hosted).", 'lafka-plugin') . '</p>';

		// Video URL
		$output .= '<p><label for="lafka_product_video_url"><b>' . esc_html__("Video URL", 'lafka-plugin') . '</b></label></p>';
		$output .= '<input type="text" id="lafka_product_video_url" name="lafka_product_video_url" value="' . esc_attr($values['lafka_product_video_url']) . '" class="large-text" />';

		echo $output; // All dynamic data escaped
	}

}

/* When the post is saved, saves our custom data */
if (!function_exists('lafka_save_product_video_postdata')) {

	function lafka_save_product_video_postdata($post_id) {
		global $pagenow;

		// verify if this is an auto save routine.
		// If it is our form has not been submitted, so we dont want to do anything
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		// verify this came from our screen and with proper authorization,
		// because save_post can be triggered at other times

		if (isset($_POST['product_video_nonce']) && !wp_verify_nonce($_POST['product_video_nonce'], 'lafka_save_product_video_postdata')) {
			return;
		}

		if (!current_user_can('edit_post', $post_id)) {
			return;
		}

		if ('post-new.php' == $pagenow) {
			return;
		}

		if (isset($_POST['lafka_product_video_url'])) {
			update_post_meta($post_id, "lafka_product_video_url", esc_url($_POST['lafka_product_video_url']));
		}
	}

}

/**
 * Register product gallery type
 */
add_action('add_meta_boxes', 'lafka_add_product_gallery_type_metabox');
add_action('save_post', 'lafka_save_product_gallery_type_postdata');

/* Adds a box to the side column on the Page edit screens */
if (!function_exists('lafka_add_product_gallery_type_metabox')) {

	function lafka_add_product_gallery_type_metabox() {
		add_meta_box(
			'lafka_product_gallery_type', esc_html__('Product Gallery Type', 'lafka-plugin'), 'lafka_product_gallery_type_callback', 'product', 'side'
		);
	}

}

/* Prints the box content */
if (!function_exists('lafka_product_gallery_type_callback')) {

	function lafka_product_gallery_type_callback($post) {

		// Use nonce for verification
		wp_nonce_field('lafka_save_product_gallery_type_postdata', 'product_gallery_type_nonce');

		$saved_value = get_post_meta($post->ID, 'lafka_single_product_gallery_type', true);

        // Set default values
		$value = 'default';

		if (isset($saved_value) && $saved_value != '') {
			$value = $saved_value;
		}

		$output = '';
		$choose_menu_options = array(
		        'default' => '- '. esc_html__("Use Theme Options Setting", 'lafka-plugin') . ' -',
		        'woo_default' => esc_html__("WooCommerce Default Gallery", 'lafka-plugin'),
		        'image_list' => esc_html__("Image List Gallery", 'lafka-plugin'),
		        'mosaic_images' => esc_html__("Mosaic Images Gallery", 'lafka-plugin')
		);
		$output .= '<p><label for="lafka_single_product_gallery_type"><b>' . esc_html__("Choose between default WooCommerce gallery and image list gallery.", 'lafka-plugin') . '</b></label></p>';
		$output .= "<select name='lafka_single_product_gallery_type'>";

		// Add a default option
		foreach ($choose_menu_options as $key => $val) {
			$output .= "<option value='" . esc_attr($key) . "' " . esc_attr(selected($value, $key, false)) . " >" . esc_html($val) . "</option>";
		}
		$output .= "</select>";

		echo $output;
	}

}

/* When the post is saved, saves our custom data */
if (!function_exists('lafka_save_product_gallery_type_postdata')) {

	function lafka_save_product_gallery_type_postdata($post_id) {

		// verify if this is an auto save routine.
		// If it is our form has not been submitted, so we dont want to do anything
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		// verify this came from our screen and with proper authorization,
		// because save_post can be triggered at other times

		if (isset($_POST['product_gallery_type_nonce']) && !wp_verify_nonce($_POST['product_gallery_type_nonce'], 'lafka_save_product_gallery_type_postdata')) {
			return;
		}

		if (!current_user_can('edit_post', $post_id)) {
			return;
		}

		if (isset($_POST['lafka_single_product_gallery_type'])) {
			update_post_meta($post_id, "lafka_single_product_gallery_type", sanitize_text_field($_POST['lafka_single_product_gallery_type']));
		}
	}

}