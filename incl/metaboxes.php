<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * wp_kses allowlist for the metabox markup built in this file (every dynamic
 * value is escaped where the markup is assembled).
 *
 * @return array<string,array<string,bool>>
 */
if ( ! function_exists( 'lafka_metabox_allowed_html' ) ) {
	function lafka_metabox_allowed_html() {
		return array(
			'p'      => array(),
			'b'      => array(),
			'br'     => array(),
			'span'   => array(
				'class' => true,
				'id'    => true,
			),
			'div'    => array(
				'class' => true,
				'id'    => true,
			),
			'label'  => array( 'for' => true ),
			'select' => array(
				'id'    => true,
				'name'  => true,
				'class' => true,
			),
			'option' => array(
				'value'    => true,
				'selected' => true,
			),
			'input'  => array(
				'type'    => true,
				'id'      => true,
				'name'    => true,
				'value'   => true,
				'class'   => true,
				'checked' => true,
				'style'   => true,
				'data-*'  => true,
			),
			'a'      => array(
				'id'     => true,
				'class'  => true,
				'href'   => true,
				'title'  => true,
				'style'  => true,
				'data-*' => true,
			),
		);
	}
}

/**
 * Register page layout metaboxes
 */
add_action( 'add_meta_boxes', 'lafka_add_layout_metabox' );
add_action( 'save_post', 'lafka_save_layout_postdata' );

/* Adds a box to the side column on the Page edit screens */
if ( ! function_exists( 'lafka_add_layout_metabox' ) ) {

	function lafka_add_layout_metabox() {

		$posttypes = array( 'page', 'post' );
		if ( LAFKA_PLUGIN_IS_WOOCOMMERCE ) {
			$posttypes[] = 'product';
		}

		foreach ( $posttypes as $pt ) {
			add_meta_box(
				'lafka_layout',
				esc_html__( 'Page Layout Options', 'lafka-plugin' ),
				'lafka_layout_callback',
				$pt,
				'side'
			);
		}
	}

}

/* Prints the box content */
if ( ! function_exists( 'lafka_layout_callback' ) ) {

	function lafka_layout_callback( $post ) {
		// If current page is set as Blog page - don't show the options
		if ( (int) get_option( 'page_for_posts' ) === (int) $post->ID ) {
			echo esc_html__( 'Page Layout Options is disabled for this page, because the page is set as Blog page from Settings->Reading.', 'lafka-plugin' );
			return;
		}

		// If current page is set as Shop page - don't show the options
		if ( LAFKA_PLUGIN_IS_WOOCOMMERCE && (int) wc_get_page_id( 'shop' ) === (int) $post->ID ) {
			echo esc_html__( 'Page Layout Options is disabled for this page, because the page is set as Shop page.', 'lafka-plugin' );
			return;
		}

		// Use nonce for verification
		wp_nonce_field( 'lafka_save_layout_postdata', 'layout_nonce' );

		$custom = get_post_custom( $post->ID );

		// Set default values
		$values = array(
			'lafka_layout'        => 'default',
			'lafka_footer_style'  => 'default',
			'lafka_header_syle'   => '',
			'lafka_page_subtitle' => '',
		);

		if ( isset( $custom['lafka_layout'] ) && '' !== (string) $custom['lafka_layout'][0] ) {
			$values['lafka_layout'] = esc_attr( $custom['lafka_layout'][0] );
		}
		if ( isset( $custom['lafka_footer_style'] ) && '' !== (string) $custom['lafka_footer_style'][0] ) {
			$values['lafka_footer_style'] = esc_attr( $custom['lafka_footer_style'][0] );
		}
		if ( isset( $custom['lafka_header_syle'] ) && '' !== (string) $custom['lafka_header_syle'][0] ) {
			$values['lafka_header_syle'] = esc_attr( $custom['lafka_header_syle'][0] );
		}
		if ( isset( $custom['lafka_page_subtitle'] ) && '' !== (string) $custom['lafka_page_subtitle'][0] ) {
			$values['lafka_page_subtitle'] = esc_attr( $custom['lafka_page_subtitle'][0] );
		}

		// description
		$output = '<p>' . esc_html__( 'You can define layout specific options here.', 'lafka-plugin' ) . '</p>';

		// Layout
		$output .= '<p><b>' . esc_html__( 'Choose Page Layout', 'lafka-plugin' ) . '</b></p>';
		$output .= '<input id="lafka_layout_default" ' . checked( $values['lafka_layout'], 'default', false ) . ' type="radio" value="default" name="lafka_layout">';
		$output .= '<label for="lafka_layout_default">' . esc_html__( 'Default', 'lafka-plugin' ) . '</label><br>';
		$output .= '<input id="lafka_layout_fullwidth" ' . checked( $values['lafka_layout'], 'lafka_fullwidth', false ) . ' type="radio" value="lafka_fullwidth" name="lafka_layout">';
		$output .= '<label for="lafka_layout_fullwidth">' . esc_html__( 'Full-Width', 'lafka-plugin' ) . '</label><br>';
		$output .= '<input id="lafka_layout_boxed" ' . checked( $values['lafka_layout'], 'lafka_boxed', false ) . ' type="radio" value="lafka_boxed" name="lafka_layout">';
		$output .= '<label for="lafka_layout_boxed">' . esc_html__( 'Boxed', 'lafka-plugin' ) . '</label><br>';

		// Footer Style
		$output .= '<p><b>' . esc_html__( 'Footer style', 'lafka-plugin' ) . '</b></p>';
		$output .= '<input id="lafka_footer_style_default" ' . checked( $values['lafka_footer_style'], 'default', false ) . ' type="radio" value="default" name="lafka_footer_style">';
		$output .= '<label for="lafka_footer_style_default">' . esc_html__( 'Default', 'lafka-plugin' ) . '</label>&nbsp;';
		$output .= '<input id="lafka_footer_style_show" ' . checked( $values['lafka_footer_style'], 'standart', false ) . ' type="radio" value="standart" name="lafka_footer_style">';
		$output .= '<label for="lafka_footer_style_show">' . esc_html__( 'Standard', 'lafka-plugin' ) . '</label>&nbsp;';
		$output .= '<input id="lafka_footer_style_hide" ' . checked( $values['lafka_footer_style'], 'lafka-reveal-footer', false ) . ' type="radio" value="lafka-reveal-footer" name="lafka_footer_style">';
		$output .= '<label for="lafka_footer_style_hide">' . esc_html__( 'Reveal', 'lafka-plugin' ) . '</label>';

		// Header style and subtitle (posts and pages)
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->post_type, array( 'post', 'page', 'product' ), true ) ) {

			// Below is not for product
			if ( 'product' !== (string) $screen->post_type ) {
				// Header style header
				$output .= '<p><b>' . esc_html__( 'Header Style', 'lafka-plugin' ) . '</b></p>';
				$output .= '<p><label for="lafka_header_syle">';

				$output .= "<select name='lafka_header_syle'>";
				// Add a default option
				$output .= '<option';
				if ( '' === $values['lafka_header_syle'] ) {
					$output .= " selected='selected'";
				}
				$output .= " value=''>" . esc_html__( 'Normal', 'lafka-plugin' ) . '</option>';

				// Fill the select element
				$header_style_values = array(
					'lafka_transparent_header' => esc_html__( 'Transparent - Light Scheme', 'lafka-plugin' ),
					'lafka_transparent_header lafka-transparent-dark' => esc_html__( 'Transparent - Dark Scheme', 'lafka-plugin' ),
				);

				foreach ( $header_style_values as $header_style_val => $header_style_option ) {
					$output .= '<option';
					if ( $header_style_val === $values['lafka_header_syle'] ) {
						$output .= " selected='selected'";
					}
					$output .= " value='" . esc_attr( $header_style_val ) . "'>" . esc_html( $header_style_option ) . '</option>';
				}

				$output .= '</select>';

				$output .= '<p><label for="lafka_page_subtitle">' . esc_html__( 'Page Subtitle', 'lafka-plugin' ) . '</label></p>';
				$output .= '<input type="text" id="lafka_page_subtitle" name="lafka_page_subtitle" value="' . esc_attr( $values['lafka_page_subtitle'] ) . '" class="large-text" />';
			}
		}

		echo wp_kses( $output, lafka_metabox_allowed_html() );
	}

}

/* When the post is saved, saves our custom data */
if ( ! function_exists( 'lafka_save_layout_postdata' ) ) {

	function lafka_save_layout_postdata( $post_id ) {
		global $pagenow;

		// verify if this is an auto save routine.
		// If it is our form has not been submitted, so we dont want to do anything
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// verify this came from our screen and with proper authorization,
		// because save_post can be triggered at other times
		if ( ! isset( $_POST['layout_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['layout_nonce'] ) ), 'lafka_save_layout_postdata' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( 'post-new.php' === (string) $pagenow ) {
			return;
		}

		if ( isset( $_POST['lafka_layout'] ) ) {
			update_post_meta( $post_id, 'lafka_layout', sanitize_text_field( wp_unslash( $_POST['lafka_layout'] ) ) );
		}

		if ( isset( $_POST['lafka_footer_style'] ) ) {
			update_post_meta( $post_id, 'lafka_footer_style', sanitize_text_field( wp_unslash( $_POST['lafka_footer_style'] ) ) );
		}

		if ( isset( $_POST['lafka_page_subtitle'] ) ) {
			update_post_meta( $post_id, 'lafka_page_subtitle', sanitize_text_field( wp_unslash( $_POST['lafka_page_subtitle'] ) ) );
		}

		if ( isset( $_POST['lafka_header_syle'] ) ) {
			update_post_meta( $post_id, 'lafka_header_syle', sanitize_text_field( wp_unslash( $_POST['lafka_header_syle'] ) ) );
		}
	}

}

/**
 * Register metaboxes
 */
add_action( 'add_meta_boxes', 'lafka_add_page_options_metabox' );
add_action( 'save_post', 'lafka_save_page_options_postdata' );

/* Adds a box to the side column on the Page edit screens */
if ( ! function_exists( 'lafka_add_page_options_metabox' ) ) {

	function lafka_add_page_options_metabox() {

		$posttypes = array( 'page', 'post' );

		foreach ( $posttypes as $pt ) {
			add_meta_box(
				'lafka_page_options',
				esc_html__( 'Page Structure Options', 'lafka-plugin' ),
				'lafka_page_options_callback',
				$pt,
				'side'
			);
		}
	}

}

/* Prints the box content */
if ( ! function_exists( 'lafka_page_options_callback' ) ) {

	function lafka_page_options_callback( $post ) {
		// If current page is set as Blog page - don't show the options
		if ( (int) get_option( 'page_for_posts' ) === (int) $post->ID ) {
			echo esc_html__( 'Page Structure Options are disabled for this page, because the page is set as Blog page from Settings->Reading.', 'lafka-plugin' );
			return;
		}
		// If current page is set as Shop page - don't show the options
		if ( LAFKA_PLUGIN_IS_WOOCOMMERCE && (int) wc_get_page_id( 'shop' ) === (int) $post->ID ) {
			echo esc_html__( 'Page Structure Options are disabled for this page, because the page is set as Shop page.', 'lafka-plugin' );
			return;
		}

		// Use nonce for verification
		wp_nonce_field( 'lafka_save_page_options_postdata', 'page_options_nonce' );
		global $wp_registered_sidebars;

		$custom = get_post_custom( $post->ID );

		// Set default values
		$values = array(
			'lafka_top_menu'                 => 'default',
			'lafka_show_sidebar'             => 'yes',
			'lafka_sidebar_position'         => 'default',
			'lafka_show_offcanvas_sidebar'   => 'yes',
			'lafka_custom_sidebar'           => 'default',
			'lafka_custom_offcanvas_sidebar' => 'default',
		);

		if ( isset( $custom['lafka_top_menu'] ) && '' !== (string) $custom['lafka_top_menu'][0] ) {
			$values['lafka_top_menu'] = $custom['lafka_top_menu'][0];
		}
		if ( isset( $custom['lafka_show_sidebar'] ) && '' !== (string) $custom['lafka_show_sidebar'][0] ) {
			$values['lafka_show_sidebar'] = $custom['lafka_show_sidebar'][0];
		}
		if ( isset( $custom['lafka_sidebar_position'] ) && '' !== (string) $custom['lafka_sidebar_position'][0] ) {
			$values['lafka_sidebar_position'] = $custom['lafka_sidebar_position'][0];
		}
		if ( isset( $custom['lafka_show_offcanvas_sidebar'] ) && '' !== (string) $custom['lafka_show_offcanvas_sidebar'][0] ) {
			$values['lafka_show_offcanvas_sidebar'] = $custom['lafka_show_offcanvas_sidebar'][0];
		}
		if ( isset( $custom['lafka_custom_sidebar'] ) && '' !== (string) $custom['lafka_custom_sidebar'][0] ) {
			$values['lafka_custom_sidebar'] = $custom['lafka_custom_sidebar'][0];
		}
		if ( isset( $custom['lafka_custom_offcanvas_sidebar'] ) && '' !== (string) $custom['lafka_custom_offcanvas_sidebar'][0] ) {
			$values['lafka_custom_offcanvas_sidebar'] = $custom['lafka_custom_offcanvas_sidebar'][0];
		}

		// description
		$output = '<p>' . esc_html__( 'You can configure the page structure, using this options.', 'lafka-plugin' ) . '</p>';

		// Top Menu
		$choose_menu_options = lafka_get_choose_menu_options();
		$output             .= '<p><label for="lafka_top_menu"><b>' . esc_html__( 'Choose Top Menu', 'lafka-plugin' ) . '</b></label></p>';
		$output             .= "<select name='lafka_top_menu'>";
		// Add a default option
		foreach ( $choose_menu_options as $key => $val ) {
			$output .= "<option value='" . esc_attr( $key ) . "' " . esc_attr( selected( $values['lafka_top_menu'], $key, false ) ) . ' >' . esc_html( $val ) . '</option>';
		}
		$output .= '</select>';

		// Show Main sidebar
		$output .= '<p><label for="lafka_show_sidebar"><b>' . esc_html__( 'Main Sidebar', 'lafka-plugin' ) . '</b></label></p>';
		$output .= '<input id="lafka_show_sidebar_yes" ' . checked( $values['lafka_show_sidebar'], 'yes', false ) . ' type="radio" value="yes" name="lafka_show_sidebar">';
		$output .= '<label for="lafka_show_sidebar_yes">' . esc_html__( 'Show', 'lafka-plugin' ) . ' </label>&nbsp;';
		$output .= '<input id="lafka_show_sidebar_no" ' . checked( $values['lafka_show_sidebar'], 'no', false ) . ' type="radio" value="no" name="lafka_show_sidebar">';
		$output .= '<label for="lafka_show_sidebar_no">' . esc_html__( 'Hide', 'lafka-plugin' ) . ' </label>';

		// Select Main sidebar
		$output .= "<select name='lafka_custom_sidebar'>";
		// Add a default option
		$output .= '<option';
		if ( 'default' === (string) $values['lafka_custom_sidebar'] ) {
			$output .= " selected='selected'";
		}
		$output .= " value='default'>" . esc_html__( 'default', 'lafka-plugin' ) . '</option>';

		// Fill the select element with all registered sidebars
		foreach ( $wp_registered_sidebars as $sidebar_id => $sidebar ) {
			if ( 'bottom_footer_sidebar' !== (string) $sidebar_id && 'pre_header_sidebar' !== (string) $sidebar_id ) {
				$output .= '<option';
				if ( (string) $sidebar_id === (string) $values['lafka_custom_sidebar'] ) {
					$output .= " selected='selected'";
				}
				$output .= " value='" . esc_attr( $sidebar_id ) . "'>" . esc_html( $sidebar['name'] ) . '</option>';
			}
		}

		$output .= '</select>';

		// Main Sidebar Position
		$output .= '<p><label for="lafka_sidebar_position"><b>' . esc_html__( 'Main Sidebar Position', 'lafka-plugin' ) . '</b></label></p>';
		$output .= '<select name="lafka_sidebar_position">';
		$output .= '<option value="default" ' . esc_attr( selected( $values['lafka_sidebar_position'], 'default', false ) ) . ' >' . esc_html__( 'default', 'lafka-plugin' ) . '</option>';
		$output .= '<option value="lafka-left-sidebar" ' . esc_attr( selected( $values['lafka_sidebar_position'], 'lafka-left-sidebar', false ) ) . '>' . esc_html__( 'Left', 'lafka-plugin' ) . '</option>';
		$output .= '<option value="lafka-right-sidebar" ' . esc_attr( selected( $values['lafka_sidebar_position'], 'lafka-right-sidebar', false ) ) . '>' . esc_html__( 'Right', 'lafka-plugin' ) . '</option>';
		$output .= '</select>';

		// Show offcanvas sidebar
		$output .= '<p><label for="lafka_show_offcanvas_sidebar"><b>' . esc_html__( 'Off Canvas Sidebar', 'lafka-plugin' ) . '</b></label></p>';
		$output .= '<input id="lafka_show_offcanvas_sidebar_yes" ' . checked( $values['lafka_show_offcanvas_sidebar'], 'yes', false ) . ' type="radio" value="yes" name="lafka_show_offcanvas_sidebar">';
		$output .= '<label for="lafka_show_offcanvas_sidebar_yes">' . esc_html__( 'Show', 'lafka-plugin' ) . ' </label>&nbsp;';
		$output .= '<input id="lafka_show_offcanvas_sidebar_no" ' . checked( $values['lafka_show_offcanvas_sidebar'], 'no', false ) . ' type="radio" value="no" name="lafka_show_offcanvas_sidebar">';
		$output .= '<label for="lafka_show_offcanvas_sidebar_no">' . esc_html__( 'Hide', 'lafka-plugin' ) . ' </label>';

		// Select offcanvas sidebar
		$output .= "<select name='lafka_custom_offcanvas_sidebar'>";

		// Add a default option
		$output .= '<option';
		if ( 'default' === (string) $values['lafka_custom_offcanvas_sidebar'] ) {
			$output .= " selected='selected'";
		}
		$output .= " value='default'>" . esc_html__( 'default', 'lafka-plugin' ) . '</option>';

		// Fill the select element with all registered sidebars
		foreach ( $wp_registered_sidebars as $sidebar_id => $sidebar ) {
			if ( 'pre_header_sidebar' !== (string) $sidebar_id ) {
				$output .= '<option';
				if ( (string) $sidebar_id === (string) $values['lafka_custom_offcanvas_sidebar'] ) {
					$output .= " selected='selected'";
				}
				$output .= " value='" . esc_attr( $sidebar_id ) . "'>" . esc_html( $sidebar['name'] ) . '</option>';
			}
		}

		$output .= '</select>';

		echo wp_kses( $output, lafka_metabox_allowed_html() );
	}

}

/* When the post is saved, saves our custom data */
if ( ! function_exists( 'lafka_save_page_options_postdata' ) ) {

	function lafka_save_page_options_postdata( $post_id ) {
		// verify if this is an auto save routine.
		// If it is our form has not been submitted, so we dont want to do anything
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// verify this came from our screen and with proper authorization,
		// because save_post can be triggered at other times
		if ( ! isset( $_POST['page_options_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['page_options_nonce'] ) ), 'lafka_save_page_options_postdata' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['lafka_top_menu'] ) ) {
			update_post_meta( $post_id, 'lafka_top_menu', sanitize_text_field( wp_unslash( $_POST['lafka_top_menu'] ) ) );
		}
		if ( isset( $_POST['lafka_show_sidebar'] ) ) {
			update_post_meta( $post_id, 'lafka_show_sidebar', sanitize_text_field( wp_unslash( $_POST['lafka_show_sidebar'] ) ) );
		}
		if ( isset( $_POST['lafka_sidebar_position'] ) ) {
			update_post_meta( $post_id, 'lafka_sidebar_position', sanitize_text_field( wp_unslash( $_POST['lafka_sidebar_position'] ) ) );
		}
		if ( isset( $_POST['lafka_show_offcanvas_sidebar'] ) ) {
			update_post_meta( $post_id, 'lafka_show_offcanvas_sidebar', sanitize_text_field( wp_unslash( $_POST['lafka_show_offcanvas_sidebar'] ) ) );
		}
		if ( isset( $_POST['lafka_custom_sidebar'] ) ) {
			update_post_meta( $post_id, 'lafka_custom_sidebar', sanitize_text_field( wp_unslash( $_POST['lafka_custom_sidebar'] ) ) );
		}
		if ( isset( $_POST['lafka_custom_offcanvas_sidebar'] ) ) {
			update_post_meta( $post_id, 'lafka_custom_offcanvas_sidebar', sanitize_text_field( wp_unslash( $_POST['lafka_custom_offcanvas_sidebar'] ) ) );
		}
	}

}

/**
 * Register product video option for products
 */
add_action( 'add_meta_boxes', 'lafka_add_product_video_metabox' );
add_action( 'save_post', 'lafka_save_product_video_postdata' );

/* Adds a box to the side column on the Page edit screens */
if ( ! function_exists( 'lafka_add_product_video_metabox' ) ) {

	function lafka_add_product_video_metabox() {
		add_meta_box(
			'lafka_product_video',
			esc_html__( 'Product Video', 'lafka-plugin' ),
			'lafka_product_video_callback',
			'product',
			'side'
		);
	}

}

/* Prints the box content */
if ( ! function_exists( 'lafka_product_video_callback' ) ) {

	function lafka_product_video_callback( $post ) {

		// Use nonce for verification
		wp_nonce_field( 'lafka_save_product_video_postdata', 'product_video_nonce' );

		$custom = get_post_custom( $post->ID );

		// Set default values
		$values = array(
			'lafka_product_video_url' => '',
		);

		if ( isset( $custom['lafka_product_video_url'] ) && '' !== (string) $custom['lafka_product_video_url'][0] ) {
			$values['lafka_product_video_url'] = esc_attr( $custom['lafka_product_video_url'][0] );
		}

		// description
		$output = '<p>' . esc_html__( 'Product Video to be displayed on the product page (YouTube, Vimeo, Self-hosted).', 'lafka-plugin' ) . '</p>';

		// Video URL
		$output .= '<p><label for="lafka_product_video_url"><b>' . esc_html__( 'Video URL', 'lafka-plugin' ) . '</b></label></p>';
		$output .= '<input type="text" id="lafka_product_video_url" name="lafka_product_video_url" value="' . esc_attr( $values['lafka_product_video_url'] ) . '" class="large-text" />';

		echo wp_kses( $output, lafka_metabox_allowed_html() );
	}

}

/* When the post is saved, saves our custom data */
if ( ! function_exists( 'lafka_save_product_video_postdata' ) ) {

	function lafka_save_product_video_postdata( $post_id ) {
		global $pagenow;

		// verify if this is an auto save routine.
		// If it is our form has not been submitted, so we dont want to do anything
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// verify this came from our screen and with proper authorization,
		// because save_post can be triggered at other times

		if ( ! isset( $_POST['product_video_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['product_video_nonce'] ) ), 'lafka_save_product_video_postdata' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( 'post-new.php' === (string) $pagenow ) {
			return;
		}

		if ( isset( $_POST['lafka_product_video_url'] ) ) {
			update_post_meta( $post_id, 'lafka_product_video_url', esc_url_raw( wp_unslash( $_POST['lafka_product_video_url'] ) ) );
		}
	}

}

/**
 * Register product gallery type
 */
add_action( 'add_meta_boxes', 'lafka_add_product_gallery_type_metabox' );
add_action( 'save_post', 'lafka_save_product_gallery_type_postdata' );

/* Adds a box to the side column on the Page edit screens */
if ( ! function_exists( 'lafka_add_product_gallery_type_metabox' ) ) {

	function lafka_add_product_gallery_type_metabox() {
		add_meta_box(
			'lafka_product_gallery_type',
			esc_html__( 'Product Gallery Type', 'lafka-plugin' ),
			'lafka_product_gallery_type_callback',
			'product',
			'side'
		);
	}

}

/* Prints the box content */
if ( ! function_exists( 'lafka_product_gallery_type_callback' ) ) {

	function lafka_product_gallery_type_callback( $post ) {

		// Use nonce for verification
		wp_nonce_field( 'lafka_save_product_gallery_type_postdata', 'product_gallery_type_nonce' );

		$saved_value = get_post_meta( $post->ID, 'lafka_single_product_gallery_type', true );

		// Set default values
		$value = 'default';

		if ( isset( $saved_value ) && '' !== (string) $saved_value ) {
			$value = $saved_value;
		}

		$output              = '';
		$choose_menu_options = array(
			'default'       => '- ' . esc_html__( 'Use Theme Options Setting', 'lafka-plugin' ) . ' -',
			'woo_default'   => esc_html__( 'WooCommerce Default Gallery', 'lafka-plugin' ),
			'image_list'    => esc_html__( 'Image List Gallery', 'lafka-plugin' ),
			'mosaic_images' => esc_html__( 'Mosaic Images Gallery', 'lafka-plugin' ),
		);
		$output             .= '<p><label for="lafka_single_product_gallery_type"><b>' . esc_html__( 'Choose between default WooCommerce gallery and image list gallery.', 'lafka-plugin' ) . '</b></label></p>';
		$output             .= "<select name='lafka_single_product_gallery_type'>";

		// Add a default option
		foreach ( $choose_menu_options as $key => $val ) {
			$output .= "<option value='" . esc_attr( $key ) . "' " . esc_attr( selected( $value, $key, false ) ) . ' >' . esc_html( $val ) . '</option>';
		}
		$output .= '</select>';

		echo wp_kses( $output, lafka_metabox_allowed_html() );
	}

}

/* When the post is saved, saves our custom data */
if ( ! function_exists( 'lafka_save_product_gallery_type_postdata' ) ) {

	function lafka_save_product_gallery_type_postdata( $post_id ) {

		// verify if this is an auto save routine.
		// If it is our form has not been submitted, so we dont want to do anything
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// verify this came from our screen and with proper authorization,
		// because save_post can be triggered at other times

		if ( ! isset( $_POST['product_gallery_type_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['product_gallery_type_nonce'] ) ), 'lafka_save_product_gallery_type_postdata' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['lafka_single_product_gallery_type'] ) ) {
			update_post_meta( $post_id, 'lafka_single_product_gallery_type', sanitize_text_field( wp_unslash( $_POST['lafka_single_product_gallery_type'] ) ) );
		}
	}

}
