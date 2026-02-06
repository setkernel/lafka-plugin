<?php defined( 'ABSPATH' ) || exit; ?>
<?php
/**
 * Map all Lafka shortcodes to VC
 */
if (!function_exists('lafka_integrateWithVC')) {

	function lafka_integrateWithVC() {

		$current_user_email = '';
		if (is_user_logged_in()) {
			$the_logged_user = wp_get_current_user();
			if ($the_logged_user instanceof WP_User) {
				$current_user_email = $the_logged_user->user_email;
			}
		}

		$althem_icon = plugins_url('assets/image/VC_logo_alth.png', dirname(__FILE__));
		$latest_projects_columns_values = array(1, 2, 3, 4, 5, 6);
		$banner_alignment_styles = array(
			esc_html__('Center-Center', 'lafka-plugin') => 'banner-center-center',
			esc_html__('Top-Left', 'lafka-plugin') => 'banner-top-left',
			esc_html__('Top-Center', 'lafka-plugin') => 'banner-top-center',
			esc_html__('Top-Right', 'lafka-plugin') => 'banner-top-right',
			esc_html__('Center-Left', 'lafka-plugin') => 'banner-center-left',
			esc_html__('Center-Right', 'lafka-plugin') => 'banner-center-right',
			esc_html__('Bottom-Left', 'lafka-plugin') => 'banner-bottom-left',
			esc_html__('Bottom-Center', 'lafka-plugin') => 'banner-bottom-center',
			esc_html__('Bottom-Right', 'lafka-plugin') => 'banner-bottom-right',
		);

		// Map lafka_counter
		if (defined('WPB_VC_VERSION')) {
			require_once vc_path_dir('CONFIG_DIR', 'content/vc-icon-element.php');

			$icon_params = vc_map_integrate_shortcode(vc_icon_element_params(), 'i_', '', array(
				// we need only type, icon_fontawesome, icon_.., NOT etc
				'include_only_regex' => '/^(type|icon_\w*)/',
			), array(
				'element' => 'add_icon',
				'value' => 'true',
			));

			$params = array_merge(array(
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Text before counter', 'lafka-plugin'),
					'param_name' => 'txt_before_counter',
					'value' => '',
					'description' => esc_html__('Enter text to be shown before counter.', 'lafka-plugin'),
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Counting number', 'lafka-plugin'),
					'param_name' => 'count_number',
					'value' => '10',
					'description' => esc_html__('Enter the number to count to.', 'lafka-plugin'),
					'admin_label' => true
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Text after counter', 'lafka-plugin'),
					'param_name' => 'txt_after_counter',
					'value' => '',
					'description' => esc_html__('Enter text to be shown after counter.', 'lafka-plugin'),
				),
				array(
					'type' => 'checkbox',
					'param_name' => 'add_icon',
					'heading' => esc_html__('Add icon?', 'lafka-plugin'),
					'description' => esc_html__('Add icon to the counter.', 'lafka-plugin'),
				)), $icon_params, array(
				array(
					'type' => 'colorpicker',
					'heading' => esc_html__('Icon color', 'lafka-plugin'),
					'param_name' => 'i_custom_color',
					'value' => '',
					'description' => esc_html__('Select icon color.', 'lafka-plugin'),
					'dependency' => array(
						'element' => 'add_icon',
						'value' => 'true'
					)
				),
				array(
					'type' => 'dropdown',
					'param_name' => 'counter_style',
					'value' => array(
						'H1' => 'h1',
						'H2' => 'h2',
						'H3' => 'h3',
						'H4' => 'h4',
						'H5' => 'h5',
						'H6' => 'h6',
						'Paragraph' => 'paragraph'
					),
					'std' => 'h4',
					'heading' => esc_html__('Counter style', 'lafka-plugin'),
					'description' => esc_html__('Select counter style.', 'lafka-plugin'),
				),
				array(
					'type' => 'dropdown',
					'param_name' => 'counter_alignment',
					'value' => array(
						esc_html__('Left', 'lafka-plugin') => 'lafka-counter-left',
						esc_html__('Centered', 'lafka-plugin') => 'lafka-counter-centered',
						esc_html__('Right', 'lafka-plugin') => 'lafka-counter-right',
					),
					'std' => 'lafka-counter-left',
					'heading' => esc_html__('Counter alignment', 'lafka-plugin'),
					'description' => esc_html__('Select counter alignment style.', 'lafka-plugin'),
				),
				array(
					'type' => 'colorpicker',
					'heading' => esc_html__('Text color', 'lafka-plugin'),
					'param_name' => 'text_color',
					'value' => '',
					'description' => esc_html__('Choose color for the counter text.', 'lafka-plugin'),
				),
			));

			// Remove Icon library admin label
			unset($params[4]['admin_label']);

			vc_map(array(
					'name' => esc_html__('Counter', 'lafka-plugin'),
					'base' => 'lafka_counter',
					'icon' => $althem_icon,
					'description' => esc_html__('Configure counter', 'lafka-plugin'),
					'category' => esc_html__('Lafka Shortcodes', 'lafka-plugin'),
					'params' => $params,
				)
			);
		}

		// Map lafka_typed
		vc_map(array(
				'name' => esc_html__('Typed', 'lafka-plugin'),
				'base' => 'lafka_typed',
				'icon' => $althem_icon,
				'description' => esc_html__('Animated typing', 'lafka-plugin'),
				'category' => esc_html__('Lafka Shortcodes', 'lafka-plugin'),
				'params' => array(
					array(
						'type' => 'textfield',
						'heading' => esc_html__('Text before typing rotator', 'lafka-plugin'),
						'param_name' => 'txt_before_typed',
						'value' => '',
						'description' => esc_html__('Enter text to be shown before typing rotator.', 'lafka-plugin'),
					),
					array(
						'type' => 'textfield',
						'heading' => esc_html__('Rotating strings', 'lafka-plugin'),
						'param_name' => 'rotating_strings',
						'value' => 'One,Two,Tree',
						'description' => esc_html__('Enter strings to be rotated, separated by comma, (e.g. One,Two,Tree). Please only use letters and numbers. No special characters. If it’s necessary to use special characters, please use HTML Entities instead (e.g. “&amp;amp;” instead of “&”)', 'lafka-plugin'),
						'admin_label' => true
					),
					array(
						'type' => 'textfield',
						'heading' => esc_html__('Text after typing rotator', 'lafka-plugin'),
						'param_name' => 'txt_after_typed',
						'value' => '',
						'description' => esc_html__('Enter text to be shown after typing rotator.', 'lafka-plugin'),
					),
					array(
						'type' => 'dropdown',
						'param_name' => 'typed_style',
						'value' => array(
							'H1' => 'h1',
							'H2' => 'h2',
							'H3' => 'h3',
							'H4' => 'h4',
							'H5' => 'h5',
							'H6' => 'h6',
							'Paragraph' => 'paragraph'
						),
						'std' => 'h4',
						'heading' => esc_html__('Typed style', 'lafka-plugin'),
						'description' => esc_html__('Select style.', 'lafka-plugin'),
					),
					array(
						'type' => 'dropdown',
						'param_name' => 'typed_alignment',
						'value' => array(
							esc_html__('Left', 'lafka-plugin') => 'lafka-typed-left',
							esc_html__('Centered', 'lafka-plugin') => 'lafka-typed-centered',
							esc_html__('Right', 'lafka-plugin') => 'lafka-typed-right',
						),
						'std' => 'lafka-typed-left',
						'heading' => esc_html__('Alignment', 'lafka-plugin'),
						'description' => esc_html__('Select alignment style.', 'lafka-plugin'),
					),
					array(
						'type' => 'colorpicker',
						'heading' => esc_html__('Static text color', 'lafka-plugin'),
						'param_name' => 'static_text_color',
						'value' => '',
						'description' => esc_html__('Choose color for the static text.', 'lafka-plugin'),
					),
					array(
						'type' => 'colorpicker',
						'heading' => esc_html__('Typed text color', 'lafka-plugin'),
						'param_name' => 'typed_text_color',
						'value' => '',
						'description' => esc_html__('Choose color for the typed text.', 'lafka-plugin'),
					),
					array(
						'type' => 'checkbox',
						'heading' => esc_html__('Loop', 'lafka-plugin'),
						'param_name' => 'loop',
						'value' => array(esc_html__('Start from beginning after the last string.', 'lafka-plugin') => 'yes'),
						'std' => 'yes'
					),
					array(
						'type' => 'css_editor',
						'heading' => esc_html__('CSS box', 'lafka-plugin'),
						'param_name' => 'css',
						'group' => esc_html__('Design Options', 'lafka-plugin'),
					),
				),)
		);

		// Map lafka_content_slider shortcode
		vc_map(array(
			'name' => esc_html__('Content Slider', 'lafka-plugin'),
			'base' => 'lafka_content_slider',
			'icon' => $althem_icon,
			'is_container' => true,
			'show_settings_on_create' => false,
			'as_parent' => array(
				'only' => 'vc_tta_section',
			),
			'category' => esc_html__('Lafka Shortcodes', 'lafka-plugin'),
			'description' => esc_html__('Slide any content', 'lafka-plugin'),
			'params' => array(
				array(
					'type' => 'textfield',
					'param_name' => 'title',
					'heading' => esc_html__('Widget title', 'lafka-plugin'),
					'description' => esc_html__('Enter text used as widget title (Note: located above content element).', 'lafka-plugin'),
				),
				array(
					'type' => 'hidden',
					'param_name' => 'no_fill_content_area',
					'std' => true,
				),
				array(
					'type' => 'dropdown',
					'param_name' => 'autoplay',
					'value' => array(
						esc_html__('None', 'lafka-plugin') => 'none',
						'1' => '1',
						'2' => '2',
						'3' => '3',
						'4' => '4',
						'5' => '5',
						'10' => '10',
						'20' => '20',
						'30' => '30',
						'40' => '40',
						'50' => '50',
						'60' => '60',
					),
					'std' => 'none',
					'heading' => esc_html__('Autoplay', 'lafka-plugin'),
					'description' => esc_html__('Select auto rotate for pageable in seconds (Note: disabled by default).', 'lafka-plugin'),
				),
				array(
					'type' => 'checkbox',
					'heading' => esc_html__('Full-Height slider', 'lafka-plugin'),
					'param_name' => 'full_height',
					'value' => array(esc_html__('Display slider in full height', 'lafka-plugin') => 'yes'),
				),
				array(
					'type' => 'checkbox',
					'heading' => esc_html__('Prev / Next navigation', 'lafka-plugin'),
					'param_name' => 'navigation',
					'value' => array(esc_html__('Enable Prev / Next navigation', 'lafka-plugin') => 'yes'),
					'std' => 'yes'
				),
				array(
					'type' => 'dropdown',
					'param_name' => 'navigation_color',
					'value' => array(
						esc_html__('Light', 'lafka-plugin') => 'lafka_content_slider_light_nav',
						esc_html__('Dark', 'lafka-plugin') => 'lafka_content_slider_dark_nav',
					),
					'std' => 'lafka_content_slider_light_nav',
					'heading' => esc_html__('Navigation Color', 'lafka-plugin'),
					'description' => esc_html__('Choose light or dark navigation, depending on the background.', 'lafka-plugin'),
				),
				array(
					'type' => 'checkbox',
					'heading' => esc_html__('Pause On Hover', 'lafka-plugin'),
					'param_name' => 'pause_on_hover',
					'value' => array(esc_html__('Should the slider pause on hover', 'lafka-plugin') => 'yes'),
					'std' => 'yes'
				),
				array(
					'type' => 'checkbox',
					'heading' => esc_html__('Pagination', 'lafka-plugin'),
					'param_name' => 'pagination',
					'value' => array(esc_html__('Enable pagination', 'lafka-plugin') => 'yes'),
				),
				array(
					'type' => 'dropdown',
					'param_name' => 'pagination_type',
					'value' => array(
						esc_html__('Bullets', 'lafka-plugin') => 'lafka-pagination-bullets',
						esc_html__('Numbers', 'lafka-plugin') => 'lafka-pagination-numbers'
					),
					'std' => 'lafka-pagination-bullets',
					'heading' => esc_html__('Pagination Type', 'lafka-plugin'),
					'description' => esc_html__('Select pagination type.', 'lafka-plugin'),
					'dependency' => array(
						'element' => 'pagination',
						'value' => 'yes'
					)
				),
				array(
					'type' => 'dropdown',
					'param_name' => 'transition',
					'value' => array(
						esc_html__('Fade', 'lafka-plugin') => 'fade',
						esc_html__('Slide', 'lafka-plugin') => 'slide',
						esc_html__('Slide-Flip', 'lafka-plugin') => 'slide-flip',
					),
					'std' => 'fade',
					'heading' => esc_html__('Transition', 'lafka-plugin'),
					'description' => esc_html__('Select transition effect.', 'lafka-plugin'),
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Extra class name', 'lafka-plugin'),
					'param_name' => 'el_class',
					'description' => esc_html__('If you wish to style particular content element differently, then use this field to add a class name and then lafka to it in your css file.', 'lafka-plugin') . '<br/><br/>' . esc_html__('NOTE: If video backgrounds are used in slides content, the loop slides option would be automatically disabled for compatibility reasons.', 'lafka-plugin'),
				),
				array(
					'type' => 'css_editor',
					'heading' => esc_html__('CSS box', 'lafka-plugin'),
					'param_name' => 'css',
					'group' => esc_html__('Design Options', 'lafka-plugin'),
				),
			),
			'js_view' => 'VcBackendTtaPageableView',
			'custom_markup' => '
<div class="vc_tta-container vc_tta-o-non-responsive" data-vc-action="collapse">
	<div class="vc_general vc_tta vc_tta-tabs vc_tta-pageable vc_tta-color-backend-tabs-white vc_tta-style-flat vc_tta-shape-rounded vc_tta-spacing-1 vc_tta-tabs-position-top vc_tta-controls-align-left">
		<div class="vc_tta-tabs-container">'
			                   . '<ul class="vc_tta-tabs-list">'
			                   . '<li class="vc_tta-tab" data-vc-tab data-vc-target-model-id="{{ model_id }}" data-element_type="vc_tta_section"><a href="javascript:;" data-vc-tabs data-vc-container=".vc_tta" data-vc-target="[data-model-id=\'{{ model_id }}\']" data-vc-target-model-id="{{ model_id }}"><span class="vc_tta-title-text">{{ section_title }}</span></a></li>'
			                   . '</ul>
		</div>
		<div class="vc_tta-panels vc_clearfix {{container-class}}">
		  {{ content }}
		</div>
	</div>
</div>',
			'default_content' => '
[vc_tta_section title="' . sprintf('%s %d', esc_html__('Section', 'lafka-plugin'), 1) . '"][/vc_tta_section]
[vc_tta_section title="' . sprintf('%s %d', esc_html__('Section', 'lafka-plugin'), 2) . '"][/vc_tta_section]
	',
			'admin_enqueue_js' => array(
				vc_asset_url('lib/vc_tabs/vc-tabs.min.js'),
			),
		));

// Map lafkablogposts shortcode
		vc_map(array(
			'name' => esc_html__('Blog Posts', 'lafka-plugin'),
			'base' => 'lafkablogposts',
			'icon' => $althem_icon,
			'description' => esc_html__('Output Blog posts with customizable Blog style', 'lafka-plugin'),
			'category' => esc_html__('Lafka Shortcodes', 'lafka-plugin'),
			'params' => array(
				array(
					'type' => 'dropdown',
					'heading' => esc_html__('Blog style', 'lafka-plugin'),
					'param_name' => 'blog_style',
					'value' => array(
						esc_html__('Standard', 'lafka-plugin') => '',
						esc_html__('Masonry Tiles', 'lafka-plugin') => 'lafka_blog_masonry',
						esc_html__('Mozaic', 'lafka-plugin') => 'lafka_blog_masonry lafka-mozaic',
					),
					'description' => esc_html__('Choose how the posts will appear.', 'lafka-plugin')
				),
				array(
					'type' => 'dropdown',
					'heading' => esc_html__('Sorting direction', 'lafka-plugin'),
					'param_name' => 'date_sort',
					'value' => array(
						esc_html__('WordPress Default', 'lafka-plugin') => 'default',
						esc_html__('Ascending', 'lafka-plugin') => 'ASC',
						esc_html__('Descending', 'lafka-plugin') => 'DESC'
					),
					'description' => esc_html__('Choose the date sorting direction.', 'lafka-plugin')
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Posts per page', 'lafka-plugin'),
					'param_name' => 'number_of_posts',
					'value' => '',
					'description' => esc_html__('Enter the number of posts displayed per page. Leave blank for default.', 'lafka-plugin'),
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Offset', 'lafka-plugin'),
					'param_name' => 'offset',
					'value' => '',
					'description' => esc_html__('Set number of posts to be skipped.', 'lafka-plugin'),
				)
			)
		));

		// Map lafka_latest_posts shortcode
		// Define filters to be able to use the Taxonomies search autocomplete field
		add_filter('vc_autocomplete_lafka_latest_posts_taxonomies_callback', 'lafka_latest_posts_category_field_search', 10, 1);
		add_filter('vc_autocomplete_lafka_latest_posts_taxonomies_render', 'vc_autocomplete_taxonomies_field_render', 10, 1);
		vc_map(array(
			'name' => esc_html__('Latest Posts', 'lafka-plugin'),
			'base' => 'lafka_latest_posts',
			'icon' => $althem_icon,
			'description' => esc_html__('Show Latest Posts', 'lafka-plugin'),
			'category' => esc_html__('Lafka Shortcodes', 'lafka-plugin'),
			'params' => array(
				array(
					'type' => 'dropdown',
					'heading' => esc_html__('Layout', 'lafka-plugin'),
					'value' => array(
						esc_html__('Grid', 'lafka-plugin') => 'grid',
						esc_html__('Carousel', 'lafka-plugin') => 'carousel',
					),
					'param_name' => 'layout',
					'admin_label' => true
				),
				array(
					'type' => 'dropdown',
					'heading' => esc_html__('Columns', 'lafka-plugin'),
					'value' => $latest_projects_columns_values,
					'param_name' => 'columns',
					'description' => esc_html__('Number of columns', 'lafka-plugin'),
					'admin_label' => true,
					'dependency' => array(
						'element' => 'layout',
						'value' => array('grid', 'carousel')
					)
				),
				array(
					'type' => 'autocomplete',
					'heading' => esc_html__('Filter By Category', 'lafka-plugin'),
					'param_name' => 'taxonomies',
					'settings' => array(
						'multiple' => true,
						'min_length' => 1,
						'groups' => false,
						// In UI show results grouped by groups, default false
						'unique_values' => true,
						// In UI show results except selected. NB! You should manually check values in backend, default false
						'display_inline' => true,
						// In UI show results inline view, default false (each value in own line)
						'delay' => 500,
						// delay for search. default 500
						'auto_focus' => true,
						// auto focus input, default true
					),
					'param_holder_class' => 'vc_not-for-custom',
					'description' => esc_html__('Enter category names.', 'lafka-plugin'),
					'admin_label' => true
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Number of posts', 'lafka-plugin'),
					'param_name' => 'number_of_posts',
					'value' => '4',
					'description' => esc_html__('Enter the number of posts to be displayed.', 'lafka-plugin'),
					'admin_label' => true
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Offset', 'lafka-plugin'),
					'param_name' => 'offset',
					'value' => '',
					'description' => esc_html__('Set number of posts to be skipped.', 'lafka-plugin'),
				),
				array(
					'type' => 'dropdown',
					'heading' => esc_html__('Sorting direction', 'lafka-plugin'),
					'param_name' => 'date_sort',
					'value' => array(
						esc_html__('WordPress Default', 'lafka-plugin') => 'default',
						esc_html__('Ascending', 'lafka-plugin') => 'ASC',
						esc_html__('Descending', 'lafka-plugin') => 'DESC'
					),
					'description' => esc_html__('Choose the date sorting direction.', 'lafka-plugin'),
					'admin_label' => true
				),
				array(
					'type' => 'css_editor',
					'heading' => esc_html__('CSS box', 'lafka-plugin'),
					'param_name' => 'css',
					'group' => esc_html__('Design Options', 'lafka-plugin'),
				),
			)
		));

		// Map lafka_banner shortcode
		vc_map(array(
			'name' => esc_html__('Banner', 'lafka-plugin'),
			'base' => 'lafka_banner',
			'icon' => $althem_icon,
			'description' => esc_html__('Output configurable banner', 'lafka-plugin'),
			'category' => esc_html__('Lafka Shortcodes', 'lafka-plugin'),
			'params' => array(
				array(
					'type' => 'dropdown',
					'heading' => esc_html__('Alignment', 'lafka-plugin'),
					'value' => $banner_alignment_styles,
					'param_name' => 'alignment',
					'description' => esc_html__('Choose alginment style for the banner.', 'lafka-plugin'),
				),
				array(
					'type' => 'dropdown',
					'heading' => esc_html__('Icon library', 'lafka-plugin'),
					'value' => array(
						esc_html__('Font Awesome 5', 'lafka-plugin') => 'fontawesome',
						esc_html__('Open Iconic', 'lafka-plugin') => 'openiconic',
						esc_html__('Typicons', 'lafka-plugin') => 'typicons',
						esc_html__('Entypo', 'lafka-plugin') => 'entypo',
						esc_html__('Linecons', 'lafka-plugin') => 'linecons',
						esc_html__('Elegant Icons Font', 'lafka-plugin') => 'etline',
						esc_html__('Fast Food Icons', 'lafka-plugin') => 'flaticon',
					),
					'admin_label' => true,
					'param_name' => 'type',
					'description' => esc_html__('Select icon library.', 'lafka-plugin'),
				),
				array(
					'type' => 'iconpicker',
					'heading' => esc_html__('Icon', 'lafka-plugin'),
					'param_name' => 'icon_fontawesome',
					'value' => '', // default value to backend editor admin_label
					'settings' => array(
						'emptyIcon' => true, // default true, display an "EMPTY" icon?
						'type' => 'fontawesome',
						'iconsPerPage' => 4000, // default 100, how many icons per/page to display, we use (big number) to display all icons in single page
					),
					'dependency' => array(
						'element' => 'type',
						'value' => 'fontawesome',
					),
					'description' => esc_html__('Select icon from library.', 'lafka-plugin'),
				),
				array(
					'type' => 'iconpicker',
					'heading' => esc_html__('Icon', 'lafka-plugin'),
					'param_name' => 'icon_openiconic',
					'value' => '', // default value to backend editor admin_label
					'settings' => array(
						'emptyIcon' => true, // default true, display an "EMPTY" icon?
						'type' => 'openiconic',
						'iconsPerPage' => 4000, // default 100, how many icons per/page to display
					),
					'dependency' => array(
						'element' => 'type',
						'value' => 'openiconic',
					),
					'description' => esc_html__('Select icon from library.', 'lafka-plugin'),
				),
				array(
					'type' => 'iconpicker',
					'heading' => esc_html__('Icon', 'lafka-plugin'),
					'param_name' => 'icon_typicons',
					'value' => '', // default value to backend editor admin_label
					'settings' => array(
						'emptyIcon' => true, // default true, display an "EMPTY" icon?
						'type' => 'typicons',
						'iconsPerPage' => 4000, // default 100, how many icons per/page to display
					),
					'dependency' => array(
						'element' => 'type',
						'value' => 'typicons',
					),
					'description' => esc_html__('Select icon from library.', 'lafka-plugin'),
				),
				array(
					'type' => 'iconpicker',
					'heading' => esc_html__('Icon', 'lafka-plugin'),
					'param_name' => 'icon_entypo',
					'value' => '', // default value to backend editor admin_label
					'settings' => array(
						'emptyIcon' => true, // default true, display an "EMPTY" icon?
						'type' => 'entypo',
						'iconsPerPage' => 4000, // default 100, how many icons per/page to display
					),
					'dependency' => array(
						'element' => 'type',
						'value' => 'entypo',
					),
				),
				array(
					'type' => 'iconpicker',
					'heading' => esc_html__('Icon', 'lafka-plugin'),
					'param_name' => 'icon_linecons',
					'value' => '', // default value to backend editor admin_label
					'settings' => array(
						'emptyIcon' => true, // default true, display an "EMPTY" icon?
						'type' => 'linecons',
						'iconsPerPage' => 4000, // default 100, how many icons per/page to display
					),
					'dependency' => array(
						'element' => 'type',
						'value' => 'linecons',
					),
					'description' => esc_html__('Select icon from library.', 'lafka-plugin'),
				),
				array(
					'type' => 'iconpicker',
					'heading' => esc_html__('Icon', 'lafka-plugin'),
					'param_name' => 'icon_etline',
					'value' => '', // default value to backend editor admin_label
					'settings' => array(
						'emptyIcon' => true,
						'type' => 'etline',
						'iconsPerPage' => 100,
						// default 100, how many icons per/page to display, we use (big number) to display all icons in single page
					),
					'dependency' => array(
						'element' => 'type',
						'value' => 'etline',
					),
					'description' => esc_html__('Select icon from library.', 'lafka-plugin'),
				),
				array(
					'type' => 'iconpicker',
					'heading' => esc_html__('Icon', 'lafka-plugin'),
					'param_name' => 'icon_flaticon',
					'value' => '', // default value to backend editor admin_label
					'settings' => array(
						'emptyIcon' => true,
						'type' => 'flaticon',
						'iconsPerPage' => 100,
						// default 100, how many icons per/page to display, we use (big number) to display all icons in single page
					),
					'dependency' => array(
						'element' => 'type',
						'value' => 'flaticon',
					),
					'description' => esc_html__('Select icon from library.', 'lafka-plugin'),
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Pre-Title', 'lafka-plugin'),
					'param_name' => 'pre_title',
					'value' => '',
					'description' => esc_html__('Enter the banner pre-title.', 'lafka-plugin'),
				),
				array(
					'type' => 'checkbox',
					'heading' => esc_html__('Special Pre-Title Font', 'lafka-plugin'),
					'param_name' => 'pre_title_use_special_font',
					'description' => esc_html__('Recommended to be used only with short texts (5 to 6 characters).', 'lafka-plugin'),
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Main Title', 'lafka-plugin'),
					'param_name' => 'title',
					'value' => '',
					'description' => esc_html__('Enter the banner main title.', 'lafka-plugin'),
					'admin_label' => true
				),
				array(
					'type' => 'dropdown',
					'heading' => esc_html__('Main Title Font Size', 'lafka-plugin'),
					'param_name' => 'title_size',
					'value' => array(
						esc_html__('Default', 'lafka-plugin') => '',
						esc_html__('Big', 'lafka-plugin') => 'lafka_banner_big',
					),
					'description' => esc_html__('Choose predefined main title font size.', 'lafka-plugin'),
					'admin_label' => true
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Sub-Title', 'lafka-plugin'),
					'param_name' => 'subtitle',
					'value' => '',
					'description' => esc_html__('Enter Sub-Title.', 'lafka-plugin'),
				),
				array(
					'type' => 'attach_image',
					'heading' => esc_html__('Image', 'lafka-plugin'),
					'param_name' => 'image_id',
					'value' => '',
					'description' => esc_html__('Choose image for the banner. (Actual size will be used)', 'lafka-plugin')
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Link', 'lafka-plugin'),
					'param_name' => 'link',
					'value' => '',
					'description' => esc_html__('Enter the URL where the banner will lead to.', 'lafka-plugin'),
				),
				array(
					'type' => 'dropdown',
					'heading' => esc_html__('Open in', 'lafka-plugin'),
					'value' => array(
						esc_html__('New window', 'lafka-plugin') => '_blank',
						esc_html__('Same window', 'lafka-plugin') => '_self',
					),
					'param_name' => 'link_target',
					'description' => esc_html__('Open link in new window or current.', 'lafka-plugin'),
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Button Text', 'lafka-plugin'),
					'param_name' => 'button_text',
					'value' => '',
					'description' => esc_html__('Enter text for the button.', 'lafka-plugin'),
				),
				array(
					'type' => 'dropdown',
					'heading' => esc_html__('Color Scheme', 'lafka-plugin'),
					'param_name' => 'color_scheme',
					'value' => array(
						esc_html__('Light', 'lafka-plugin') => '',
						esc_html__('Dark', 'lafka-plugin') => 'lafka-banner-dark',
					),
					'description' => esc_html__('Choose the color scheme.', 'lafka-plugin')
				),
				array(
					'type' => 'dropdown',
					'heading' => esc_html__('Appear Animation', 'lafka-plugin'),
					'param_name' => 'appear_animation',
					'value' => array(
						esc_html__('none', 'lafka-plugin') => '',
						esc_html__('From Left', 'lafka-plugin') => 'lafka-from-left',
						esc_html__('From Right', 'lafka-plugin') => 'lafka-from-right',
						esc_html__('From Bottom', 'lafka-plugin') => 'lafka-from-bottom',
						esc_html__('Fade', 'lafka-plugin') => 'lafka-fade'
					),
					'description' => esc_html__('Choose how the element will appear.', 'lafka-plugin')
				),
				array(
					'type' => 'css_editor',
					'heading' => esc_html__('CSS box', 'lafka-plugin'),
					'param_name' => 'css',
					'group' => esc_html__('Design Options', 'lafka-plugin'),
				),
			),
			'js_view' => 'VcIconElementView_Backend',
		));

		// Map lafka_cloudzoom_gallery shortcode
		vc_map(array(
			'name' => esc_html__('CloudZoom gallery', 'lafka-plugin'),
			'base' => 'lafka_cloudzoom_gallery',
			'icon' => $althem_icon,
			'description' => esc_html__('Output CloudZoom gallery', 'lafka-plugin'),
			'category' => esc_html__('Lafka Shortcodes', 'lafka-plugin'),
			'params' => array(
				array(
					'type' => 'attach_images',
					'heading' => esc_html__('Images', 'lafka-plugin'),
					'param_name' => 'images',
					'value' => '',
					'description' => esc_html__('Choose images for the gallery.', 'lafka-plugin')
				)
			)
		));

		// Define filters to be able to use the Taxonomies search autocomplete field
		add_filter('vc_autocomplete_lafka_foodmenu_taxonomies_callback', 'lafka_foodmenu_category_field_search', 10, 1);
		add_filter('vc_autocomplete_lafka_foodmenu_taxonomies_render', 'vc_autocomplete_taxonomies_field_render', 10, 1);

		// Map lafka_foodmenu shortcode
		vc_map(array(
			'name' => esc_html__('Restaurant Menu', 'lafka-plugin'),
			'base' => 'lafka_foodmenu',
			'icon' => $althem_icon,
			'description' => esc_html__('Customisable Menu List', 'lafka-plugin'),
			'category' => esc_html__('Lafka Shortcodes', 'lafka-plugin'),
			'params' => array(
				array(
					'type' => 'dropdown',
					'heading' => esc_html__('Color Scheme', 'lafka-plugin'),
					'param_name' => 'color_scheme',
					'value' => array(
						esc_html__('Dark', 'lafka-plugin') => 'lafka-foodmenu-dark',
						esc_html__('Light', 'lafka-plugin') => 'lafka-foodmenu-light'
					),
					'description' => esc_html__('Choose color scheme.', 'lafka-plugin')
				),
				array(
					'type' => 'autocomplete',
					'heading' => esc_html__('Filter By Category', 'lafka-plugin'),
					'param_name' => 'taxonomies',
					'settings' => array(
						'multiple' => true,
						'min_length' => 1,
						'groups' => false,
						// In UI show results grouped by groups, default false
						'unique_values' => true,
						// In UI show results except selected. NB! You should manually check values in backend, default false
						'display_inline' => true,
						// In UI show results inline view, default false (each value in own line)
						'delay' => 500,
						// delay for search. default 500
						'auto_focus' => true,
						// auto focus input, default true
					),
					'param_holder_class' => 'vc_not-for-custom',
					'description' => esc_html__('Enter menu category names.', 'lafka-plugin'),
					'admin_label' => true
				),
				array(
					'type' => 'checkbox',
					'heading' => esc_html__('Lightbox', 'lafka-plugin'),
					'param_name' => 'show_lightbox',
					'value' => array(esc_html__('Show link that opens the featured image in lightbox', 'lafka-plugin') => 'yes'),
				),
				array(
					'type' => 'checkbox',
					'heading' => esc_html__('Sortable', 'lafka-plugin'),
					'param_name' => 'enable_sortable',
					'value' => array(esc_html__('Enable Sortable', 'lafka-plugin') => 'yes'),
					'description' => esc_html__('Show menu categories on top and filter entries from specific categories.', 'lafka-plugin'),
					'admin_label' => true,
				),
				array(
					'type' => 'checkbox',
					'heading' => esc_html__('Hide Images', 'lafka-plugin'),
					'param_name' => 'hide_foodmenu_images',
					'value' => array(esc_html__('Enable', 'lafka-plugin') => 'yes'),
					'description' => esc_html__('No images in the menu and menu entries.', 'lafka-plugin'),
				),
				array(
					'type' => 'checkbox',
					'heading' => esc_html__('Simple Menu List', 'lafka-plugin'),
					'param_name' => 'foodmenu_simple_menu',
					'value' => array(esc_html__('Enable', 'lafka-plugin') => 'yes'),
					'description' => esc_html__('No links to single menu entry page.', 'lafka-plugin'),
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Number of Menu Entries Listed', 'lafka-plugin'),
					'param_name' => 'limit',
					'value' => '',
					'description' => esc_html__('Enter the number of menu entries to be displayed.', 'lafka-plugin'),
					'admin_label' => true
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Offset', 'lafka-plugin'),
					'param_name' => 'offset',
					'value' => '',
					'description' => esc_html__('Set number of menu entries to be skipped. ( Number of Menu Entries must be filled )', 'lafka-plugin'),
				),
				array(
					'type' => 'dropdown',
					'heading' => esc_html__('Sorting direction', 'lafka-plugin'),
					'param_name' => 'date_sort',
					'value' => array(
						esc_html__('Descending', 'lafka-plugin') => 'DESC',
						esc_html__('Ascending', 'lafka-plugin') => 'ASC'
					),
					'description' => esc_html__('Choose the date sorting direction.', 'lafka-plugin'),
					'admin_label' => true
				),
				array(
					'type' => 'css_editor',
					'heading' => esc_html__('CSS box', 'lafka-plugin'),
					'param_name' => 'css',
					'group' => esc_html__('Design Options', 'lafka-plugin'),
				),
			)
		));

		// Map lafka_icon_teaser shortcode
		vc_map(array(
			'name' => esc_html__('Icon Teaser', 'lafka-plugin'),
			'base' => 'lafka_icon_teaser',
			'icon' => $althem_icon,
			'category' => esc_html__('Lafka Shortcodes', 'lafka-plugin'),
			'description' => esc_html__('Icon teaser', 'lafka-plugin'),
			'params' => array(
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Title', 'lafka-plugin'),
					'value' => '',
					'param_name' => 'title',
					'description' => esc_html__('Enter title', 'lafka-plugin'),
					'admin_label' => true
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Subtitle', 'lafka-plugin'),
					'value' => '',
					'param_name' => 'subtitle',
					'description' => esc_html__('Enter subtitle', 'lafka-plugin'),
				),
				array(
					'type' => 'colorpicker',
					'heading' => esc_html__('Title/Subtitle Color', 'lafka-plugin'),
					'param_name' => 'titles_color',
					'value' => '',
					'description' => esc_html__('Choose Title/Subtitle color.', 'lafka-plugin'),
				),
				array(
					'type' => 'dropdown',
					'heading' => esc_html__('Align', 'lafka-plugin'),
					'value' => array(
						'Left' => 'teaser-left',
						'Right' => 'teaser-right'
					),
					'param_name' => 'align',
					'description' => esc_html__('Choose alignment', 'lafka-plugin'),
				),
				array(
					'type' => 'dropdown',
					'heading' => esc_html__('Appear Animation', 'lafka-plugin'),
					'param_name' => 'appear_animation',
					'value' => array(
						esc_html__('none', 'lafka-plugin') => '',
						esc_html__('From Left', 'lafka-plugin') => 'lafka-from-left',
						esc_html__('From Right', 'lafka-plugin') => 'lafka-from-right',
						esc_html__('From Bottom', 'lafka-plugin') => 'lafka-from-bottom',
						esc_html__('Fade', 'lafka-plugin') => 'lafka-fade'
					),
					'description' => esc_html__('Choose how the element will appear.', 'lafka-plugin')
				),
				array(
					'type' => 'dropdown',
					'heading' => esc_html__('Icon library / Custom Image', 'lafka-plugin'),
					'value' => array(
						esc_html__('Font Awesome 5', 'lafka-plugin') => 'fontawesome',
						esc_html__('Elegant Icons Font', 'lafka-plugin') => 'etline',
						esc_html__('Fast Food Icons', 'lafka-plugin') => 'flaticon',
						esc_html__('Use Custom Image', 'lafka-plugin') => 'custom_image',
					),
					'param_name' => 'type',
					'admin_label' => true,
					'description' => esc_html__('Select icon library or select image.', 'lafka-plugin'),
				),
				array(
					'type' => 'iconpicker',
					'heading' => esc_html__('Icon', 'lafka-plugin'),
					'param_name' => 'icon_fontawesome',
					'value' => 'fa fa-adjust', // default value to backend editor admin_label
					'settings' => array(
						'emptyIcon' => false, // default true, display an "EMPTY" icon?
						'type' => 'fontawesome',
						'iconsPerPage' => 4000, // default 100, how many icons per/page to display, we use (big number) to display all icons in single page
					),
					'dependency' => array(
						'element' => 'type',
						'value' => 'fontawesome',
					),
					'description' => esc_html__('Select icon from library.', 'lafka-plugin'),
				),
				array(
					'type' => 'iconpicker',
					'heading' => esc_html__('Icon', 'lafka-plugin'),
					'param_name' => 'icon_etline',
					'value' => 'icon-mobile', // default value to backend editor admin_label
					'settings' => array(
						'emptyIcon' => false,
						'type' => 'etline',
						'iconsPerPage' => 100,
						// default 100, how many icons per/page to display, we use (big number) to display all icons in single page
					),
					'dependency' => array(
						'element' => 'type',
						'value' => 'etline',
					),
					'description' => esc_html__('Select icon from library.', 'lafka-plugin'),
				),
				array(
					'type' => 'iconpicker',
					'heading' => esc_html__('Icon', 'lafka-plugin'),
					'param_name' => 'icon_flaticon',
					'value' => 'flaticon-001-popcorn', // default value to backend editor admin_label
					'settings' => array(
						'emptyIcon' => false,
						'type' => 'flaticon',
						'iconsPerPage' => 100,
						// default 100, how many icons per/page to display, we use (big number) to display all icons in single page
					),
					'dependency' => array(
						'element' => 'type',
						'value' => 'flaticon',
					),
					'description' => esc_html__('Select icon from library.', 'lafka-plugin'),
				),
				array(
					'type' => 'attach_image',
					'heading' => esc_html__('Image', 'lafka-plugin'),
					'param_name' => 'icon_image_id',
					'value' => '',
					'description' => esc_html__('Choose image instead of icon. (60 x 60 size will be used)', 'lafka-plugin'),
					'dependency' => array(
						'element' => 'type',
						'value' => 'custom_image',
					),
				),
				array(
					'type' => 'colorpicker',
					'heading' => esc_html__('Icon color', 'lafka-plugin'),
					'param_name' => 'color',
					'description' => esc_html__('Select icon color.', 'lafka-plugin'),
				),
				array(
					'type' => 'textarea_html',
					'holder' => 'div',
					'class' => '',
					'heading' => esc_html__('Text', 'lafka-plugin'),
					'value' => '',
					'param_name' => 'content',
					'description' => esc_html__('Enter the text that will be used with the icon', 'lafka-plugin'),
				),
			),
			'js_view' => 'VcIconElementView_Backend',
		));

		// Map lafka_icon_box shortcode
		vc_map(array(
			'name' => esc_html__('Icon Box', 'lafka-plugin'),
			'base' => 'lafka_icon_box',
			'icon' => $althem_icon,
			'category' => esc_html__('Lafka Shortcodes', 'lafka-plugin'),
			'description' => esc_html__('Icon box', 'lafka-plugin'),
			'params' => array(
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Title', 'lafka-plugin'),
					'value' => '',
					'param_name' => 'title',
					'description' => esc_html__('Enter title', 'lafka-plugin'),
					'admin_label' => true
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Subtitle', 'lafka-plugin'),
					'value' => '',
					'param_name' => 'subtitle',
					'description' => esc_html__('Enter subtitle', 'lafka-plugin'),
				),
				array(
					'type' => 'colorpicker',
					'heading' => esc_html__('Title/Subtitle Color', 'lafka-plugin'),
					'param_name' => 'titles_color',
					'value' => '',
					'description' => esc_html__('Choose Title/Subtitle color.', 'lafka-plugin'),
				),
				array(
					'type' => 'dropdown',
					'heading' => esc_html__('Icon Library / Custom Image', 'lafka-plugin'),
					'value' => array(
						esc_html__('Font Awesome 5', 'lafka-plugin') => 'fontawesome',
						esc_html__('Elegant Icons Font', 'lafka-plugin') => 'etline',
						esc_html__('Fast Food Icons', 'lafka-plugin') => 'flaticon',
						esc_html__('Use Custom Image', 'lafka-plugin') => 'custom_image',
					),
					'param_name' => 'type',
					'admin_label' => true,
					'description' => esc_html__('Select icon library or select image.', 'lafka-plugin'),
				),
				array(
					'type' => 'iconpicker',
					'heading' => esc_html__('Icon', 'lafka-plugin'),
					'param_name' => 'icon_fontawesome',
					'value' => 'fa fa-adjust', // default value to backend editor admin_label
					'settings' => array(
						'emptyIcon' => false, // default true, display an "EMPTY" icon?
						'type' => 'fontawesome',
						'iconsPerPage' => 4000, // default 100, how many icons per/page to display, we use (big number) to display all icons in single page
					),
					'dependency' => array(
						'element' => 'type',
						'value' => 'fontawesome',
					),
					'description' => esc_html__('Select icon from library.', 'lafka-plugin'),
				),
				array(
					'type' => 'iconpicker',
					'heading' => esc_html__('Icon', 'lafka-plugin'),
					'param_name' => 'icon_etline',
					'value' => 'icon-mobile', // default value to backend editor admin_label
					'settings' => array(
						'emptyIcon' => false,
						'type' => 'etline',
						'iconsPerPage' => 100,
						// default 100, how many icons per/page to display, we use (big number) to display all icons in single page
					),
					'dependency' => array(
						'element' => 'type',
						'value' => 'etline',
					),
					'description' => esc_html__('Select icon from library.', 'lafka-plugin'),
				),
				array(
					'type' => 'iconpicker',
					'heading' => esc_html__('Icon', 'lafka-plugin'),
					'param_name' => 'icon_flaticon',
					'value' => 'flaticon-001-popcorn', // default value to backend editor admin_label
					'settings' => array(
						'emptyIcon' => false,
						'type' => 'flaticon',
						'iconsPerPage' => 100,
						// default 100, how many icons per/page to display, we use (big number) to display all icons in single page
					),
					'dependency' => array(
						'element' => 'type',
						'value' => 'flaticon',
					),
					'description' => esc_html__('Select icon from library.', 'lafka-plugin'),
				),
				array(
					'type' => 'attach_image',
					'heading' => esc_html__('Image', 'lafka-plugin'),
					'param_name' => 'icon_image_id',
					'value' => '',
					'description' => esc_html__('Choose image instead of icon. (60 x 60 size will be used)', 'lafka-plugin'),
					'dependency' => array(
						'element' => 'type',
						'value' => 'custom_image',
					),
				),
				array(
					'type' => 'colorpicker',
					'heading' => esc_html__('Icon color', 'lafka-plugin'),
					'param_name' => 'color',
					'description' => esc_html__('Select icon color.', 'lafka-plugin'),
				),
				array(
					'type' => 'dropdown',
					'heading' => esc_html__('Alignment', 'lafka-plugin'),
					'param_name' => 'alignment',
					'value' => array(
						esc_html__('Center', 'lafka-plugin') => '',
						esc_html__('Left', 'lafka-plugin') => 'lafka-icon-box-left',
						esc_html__('Right', 'lafka-plugin') => 'lafka-icon-box-right'
					),
					'description' => esc_html__('Choose icon alignment.', 'lafka-plugin')
				),
				array(
					'type' => 'dropdown',
					'heading' => esc_html__('Icon Style', 'lafka-plugin'),
					'param_name' => 'icon_style',
					'value' => array(
						esc_html__('Circle', 'lafka-plugin') => '',
						esc_html__('Square', 'lafka-plugin') => 'lafka-icon-box-square',
						esc_html__('Clean', 'lafka-plugin') => 'lafka-clean-icon'
					),
					'description' => esc_html__('Choose icon style.', 'lafka-plugin')
				),
				array(
					'type' => 'dropdown',
					'heading' => esc_html__('Appear Animation', 'lafka-plugin'),
					'param_name' => 'appear_animation',
					'value' => array(
						esc_html__('none', 'lafka-plugin') => '',
						esc_html__('From Left', 'lafka-plugin') => 'lafka-from-left',
						esc_html__('From Right', 'lafka-plugin') => 'lafka-from-right',
						esc_html__('From Bottom', 'lafka-plugin') => 'lafka-from-bottom',
						esc_html__('Fade', 'lafka-plugin') => 'lafka-fade'
					),
					'description' => esc_html__('Choose how the element will appear.', 'lafka-plugin')
				),
				array(
					'type' => 'textarea_html',
					'holder' => 'div',
					'class' => '',
					'heading' => esc_html__('Text', 'lafka-plugin'),
					'value' => '',
					'param_name' => 'content',
					'description' => esc_html__('Enter the text that will be used with the icon', 'lafka-plugin'),
				),
			),
			'js_view' => 'VcIconElementView_Backend',
		));

		// Map lafka_map shortcode
		vc_map(array(
			'name' => esc_html__('Map', 'lafka-plugin'),
			'base' => 'lafka_map',
			'icon' => $althem_icon,
			'description' => esc_html__('Map with location', 'lafka-plugin'),
			'category' => esc_html__('Lafka Shortcodes', 'lafka-plugin'),
			'params' => array(
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Location Title', 'lafka-plugin'),
					'param_name' => 'location_title',
					'value' => '',
					'description' => esc_html__('Will appear when hover over the location.', 'lafka-plugin'),
					'admin_label' => true
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Latitude', 'lafka-plugin'),
					'param_name' => 'map_latitude',
					'value' => '',
					'description' => esc_html__('Enter location latitude.', 'lafka-plugin'),
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Longitude', 'lafka-plugin'),
					'param_name' => 'map_longitude',
					'value' => '',
					'description' => esc_html__('Enter location longitude.', 'lafka-plugin') . '</br></br>' . sprintf(_x('Go to %s and get your location coordinates: </br>(e.g. Latitude: 40.588372 / Longitude: -74.240112)', 'theme-options', 'lafka-plugin'), '<a href="https://www.latlong.net/" target="_blank">www.latlong.net</a>'),
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Map height', 'lafka-plugin'),
					'param_name' => 'height',
					'value' => '400',
					'description' => esc_html__('Map height in px.', 'lafka-plugin'),
				),
			)
		));
		// Map lafka_pricing_table shortcode
		vc_map(array(
			'name' => esc_html__('Pricing Table', 'lafka-plugin'),
			'base' => 'lafka_pricing_table',
			'icon' => $althem_icon,
			'description' => esc_html__('Create pricing tables', 'lafka-plugin'),
			'category' => esc_html__('Lafka Shortcodes', 'lafka-plugin'),
			'params' => array(
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Title', 'lafka-plugin'),
					'param_name' => 'title',
					'value' => '',
					'description' => esc_html__('Enter the table title.', 'lafka-plugin'),
					'admin_label' => true
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Sub Title', 'lafka-plugin'),
					'param_name' => 'subtitle',
					'value' => '',
					'description' => esc_html__('Enter sub title.', 'lafka-plugin'),
				),
				array(
					'type' => 'checkbox',
					'heading' => esc_html__('Styled for Dark Background', 'lafka-plugin'),
					'param_name' => 'styled_for_dark',
					'value' => array(esc_html__('Yes', 'lafka-plugin') => 'yes')
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Price Bills', 'lafka-plugin'),
					'param_name' => 'price',
					'value' => '',
					'description' => esc_html__('Enter the bills price for this package. e.g. 157.', 'lafka-plugin'),
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Price Coins', 'lafka-plugin'),
					'param_name' => 'price_coins',
					'value' => '',
					'description' => esc_html__('Enter the coins for the price. e.g. 49 , for a price of 157.49', 'lafka-plugin'),
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Currency Symbol', 'lafka-plugin'),
					'param_name' => 'currency_symbol',
					'value' => '',
					'description' => esc_html__('Enter the currency symbol for the price. e.g. $.', 'lafka-plugin'),
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Period', 'lafka-plugin'),
					'param_name' => 'period',
					'value' => '',
					'description' => esc_html__('e.g. per month.', 'lafka-plugin'),
				),
				array(
					'type' => 'dropdown',
					'heading' => esc_html__('Appear Animation', 'lafka-plugin'),
					'param_name' => 'appear_animation',
					'value' => array(
						esc_html__('none', 'lafka-plugin') => '',
						esc_html__('From Left', 'lafka-plugin') => 'lafka-from-left',
						esc_html__('From Right', 'lafka-plugin') => 'lafka-from-right',
						esc_html__('From Bottom', 'lafka-plugin') => 'lafka-from-bottom',
						esc_html__('Fade', 'lafka-plugin') => 'lafka-fade'
					),
					'description' => esc_html__('Choose how the element will appear.', 'lafka-plugin')
				),
				array(
					'type' => 'textarea_html',
					'holder' => 'div',
					'class' => '',
					'heading' => esc_html__('Content', 'lafka-plugin'),
					'value' => '',
					'param_name' => 'content',
					'description' => esc_html__('Enter the pricing table content', 'lafka-plugin'),
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Button Text', 'lafka-plugin'),
					'param_name' => 'button_text',
					'value' => '',
					'description' => esc_html__('Enter text for the button.', 'lafka-plugin'),
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Link', 'lafka-plugin'),
					'param_name' => 'link',
					'value' => '',
					'description' => esc_html__('Enter the URL for the button.', 'lafka-plugin'),
				),
				array(
					'type' => 'colorpicker',
					'heading' => esc_html__('Accent Color', 'lafka-plugin'),
					'param_name' => 'accent_color',
					'value' => '',
					'description' => esc_html__('Choose accent color of the pricing table.', 'lafka-plugin'),
				),
				array(
					'type' => 'checkbox',
					'heading' => esc_html__('Featured', 'lafka-plugin'),
					'param_name' => 'featured',
					'value' => array(esc_html__('Mark as featured', 'lafka-plugin') => 'yes')
				)
			),
			'js_view' => 'VcIconElementView_Backend',
		));
		// Map lafka_contact_form shortcode
		vc_map(array(
			'name' => esc_html__('Contact Form', 'lafka-plugin'),
			'base' => 'lafka_contact_form',
			'icon' => $althem_icon,
			'description' => esc_html__('Configurable Contact Form', 'lafka-plugin'),
			'category' => esc_html__('Lafka Shortcodes', 'lafka-plugin'),
			'params' => array(
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Title', 'lafka-plugin'),
					'param_name' => 'title',
					'value' => '',
					'description' => esc_html__('Enter contact form title.', 'lafka-plugin'),
					'admin_label' => true
				),
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Receiving Email Address', 'lafka-plugin'),
					'param_name' => 'contact_mail_to',
					'value' => $current_user_email,
					'description' => esc_html__('Email address for receing the contact form email.', 'lafka-plugin'),
					'admin_label' => true
				),
				array(
					'type' => 'checkbox',
					'heading' => esc_html__('Use Captcha', 'lafka-plugin'),
					'param_name' => 'simple_captcha',
					'value' => array(esc_html__('Use simple captcha for the contact form', 'lafka-plugin') => true),
				),
				array(
					'type' => 'checkbox',
					'heading' => esc_html__('Select Fields for the Contact Form', 'lafka-plugin'),
					'param_name' => 'contact_form_fields',
					'value' => array(
						esc_html__('Name', 'lafka-plugin') => 'name',
						esc_html__('E-Mail Address', 'lafka-plugin') => 'email',
						esc_html__('Phone', 'lafka-plugin') => 'phone',
						esc_html__('Street Address', 'lafka-plugin') => 'address',
						esc_html__('Subject', 'lafka-plugin') => 'subject',
					),
					'description' => esc_html__('Choose which fields to be displayed on the contact form. Selcted fields will also be required fields. The message textarea will be always displayed.', 'lafka-plugin')
				)
			)
		));
		// Map lafka_countdown shortcode
		vc_map(array(
			'name' => esc_html__('Countdown', 'lafka-plugin'),
			'base' => 'lafka_countdown',
			'icon' => $althem_icon,
			'description' => esc_html__('Customized Countdown', 'lafka-plugin'),
			'category' => esc_html__('Lafka Shortcodes', 'lafka-plugin'),
			'params' => array(
				array(
					'type' => 'textfield',
					'heading' => esc_html__('Expire Date', 'lafka-plugin'),
					'param_name' => 'date',
					'value' => '',
					'description' => esc_html__('Enter the end date for the counter.', 'lafka-plugin') . '<br/>' . esc_html__('Use following format YYYY/MM/DD HH:MM:SS, e.g. 2020/04/25 17:45:00', 'lafka-plugin'),
					'admin_label' => true
				),
				array(
					'type' => 'dropdown',
					'param_name' => 'counter_size',
					'value' => array(
						esc_html__('Normal', 'lafka-plugin') => '',
						esc_html__('Big', 'lafka-plugin') => 'lafka-counter-big',
					),
					'std' => '',
					'heading' => esc_html__('Size', 'lafka-plugin'),
					'description' => esc_html__('Select counter size.', 'lafka-plugin'),
				),
				array(
					'type' => 'colorpicker',
					'heading' => esc_html__('Color', 'lafka-plugin'),
					'param_name' => 'color',
					'value' => '',
					'description' => esc_html__('Choose counter color.', 'lafka-plugin'),
				)
			)
		));
// If WooCommerce is active
		if (defined('LAFKA_PLUGIN_IS_WOOCOMMERCE') && LAFKA_PLUGIN_IS_WOOCOMMERCE) {
			$order_by_values = array(
				'',
				esc_html__( 'Date', 'lafka-plugin' ) => 'date',
				esc_html__( 'ID', 'lafka-plugin' ) => 'ID',
				esc_html__( 'Author', 'lafka-plugin' ) => 'author',
				esc_html__( 'Title', 'lafka-plugin' ) => 'title',
				esc_html__( 'Modified', 'lafka-plugin' ) => 'modified',
				esc_html__( 'Random', 'lafka-plugin' ) => 'rand',
				esc_html__( 'Comment count', 'lafka-plugin' ) => 'comment_count',
				esc_html__( 'Menu order', 'lafka-plugin' ) => 'menu_order',
				esc_html__( 'Menu order and title', 'lafka-plugin' ) => 'menu_order title',
				esc_html__( 'Include', 'lafka-plugin' ) => 'include',
			);

			$order_way_values = array(
				'',
				esc_html__('Descending', 'lafka-plugin') => 'DESC',
				esc_html__('Ascending', 'lafka-plugin') => 'ASC',
			);

			$columns_values = array(2, 3, 4, 5, 6);

			// Map lafka_woo_top_rated_carousel shortcode
			vc_map(array(
				'name' => esc_html__('Top Rated Products Carousel', 'lafka-plugin'),
				'base' => 'lafka_woo_top_rated_carousel',
				'icon' => $althem_icon,
				'category' => esc_html__('Lafka Shortcodes', 'lafka-plugin'),
				'description' => esc_html__('List all products on sale in carousel', 'lafka-plugin'),
				'params' => array(
					array(
						'type' => 'textfield',
						'heading' => esc_html__('Per page', 'lafka-plugin'),
						'value' => 12,
						'param_name' => 'per_page',
						'description' => esc_html__('How much items per page to show', 'lafka-plugin'),
					),
					array(
						'type' => 'dropdown',
						'heading' => esc_html__('Columns', 'lafka-plugin'),
						'value' => $columns_values,
						'param_name' => 'columns',
						'description' => esc_html__('How much columns grid', 'lafka-plugin'),
					),
					array(
						'type' => 'dropdown',
						'heading' => esc_html__('Order by', 'lafka-plugin'),
						'param_name' => 'orderby',
						'value' => $order_by_values,
						'description' => sprintf(esc_html__('Select how to sort retrieved products. More at %s.', 'lafka-plugin'), '<a href="http://codex.wordpress.org/Class_Lafkaence/WP_Query#Order_.26_Orderby_Parameters" target="_blank">WordPress codex page</a>')
					),
					array(
						'type' => 'dropdown',
						'heading' => esc_html__('Order way', 'lafka-plugin'),
						'param_name' => 'order',
						'value' => $order_way_values,
						'description' => sprintf(esc_html__('Designates the ascending or descending order. More at %s.', 'lafka-plugin'), '<a href="http://codex.wordpress.org/Class_Lafkaence/WP_Query#Order_.26_Orderby_Parameters" target="_blank">WordPress codex page</a>')
					),
				)
			));

			// Map lafka_woo_recent_carousel shortcode
			vc_map(array(
				'name' => esc_html__('Recent Products Carousel', 'lafka-plugin'),
				'base' => 'lafka_woo_recent_carousel',
				'icon' => $althem_icon,
				'category' => esc_html__('Lafka Shortcodes', 'lafka-plugin'),
				'description' => esc_html__('Lists recent products in carousel', 'lafka-plugin'),
				'params' => array(
					array(
						'type' => 'textfield',
						'heading' => esc_html__('Per page', 'lafka-plugin'),
						'value' => 12,
						'param_name' => 'per_page',
						'description' => esc_html__('The "per_page" shortcode determines how many products to show on the page', 'lafka-plugin'),
					),
					array(
						'type' => 'dropdown',
						'heading' => esc_html__('Columns', 'lafka-plugin'),
						'value' => $columns_values,
						'param_name' => 'columns',
						'description' => esc_html__('The columns attribute controls how many columns wide the products should be before wrapping.', 'lafka-plugin'),
					),
					array(
						'type' => 'dropdown',
						'heading' => esc_html__('Order by', 'lafka-plugin'),
						'param_name' => 'orderby',
						'value' => $order_by_values,
						'description' => sprintf(esc_html__('Select how to sort retrieved products. More at %s.', 'lafka-plugin'), '<a href="http://codex.wordpress.org/Class_Lafkaence/WP_Query#Order_.26_Orderby_Parameters" target="_blank">WordPress codex page</a>')
					),
					array(
						'type' => 'dropdown',
						'heading' => esc_html__('Order way', 'lafka-plugin'),
						'param_name' => 'order',
						'value' => $order_way_values,
						'description' => sprintf(esc_html__('Designates the ascending or descending order. More at %s.', 'lafka-plugin'), '<a href="http://codex.wordpress.org/Class_Lafkaence/WP_Query#Order_.26_Orderby_Parameters" target="_blank">WordPress codex page</a>')
					),
				)
			));

			// Map lafka_woo_featured_carousel shortcode
			vc_map(array(
				'name' => esc_html__('Featured Products Carousel', 'lafka-plugin'),
				'base' => 'lafka_woo_featured_carousel',
				'icon' => $althem_icon,
				'category' => esc_html__('Lafka Shortcodes', 'lafka-plugin'),
				'description' => esc_html__('Display products set as featured in carousel', 'lafka-plugin'),
				'params' => array(
					array(
						'type' => 'textfield',
						'heading' => esc_html__('Per page', 'lafka-plugin'),
						'value' => 12,
						'param_name' => 'per_page',
						'description' => esc_html__('The "per_page" shortcode determines how many products to show on the page', 'lafka-plugin'),
					),
					array(
						'type' => 'dropdown',
						'heading' => esc_html__('Columns', 'lafka-plugin'),
						'value' => $columns_values,
						'param_name' => 'columns',
						'description' => esc_html__('The columns attribute controls how many columns wide the products should be before wrapping.', 'lafka-plugin'),
					),
					array(
						'type' => 'dropdown',
						'heading' => esc_html__('Order by', 'lafka-plugin'),
						'param_name' => 'orderby',
						'value' => $order_by_values,
						'description' => sprintf(esc_html__('Select how to sort retrieved products. More at %s.', 'lafka-plugin'), '<a href="http://codex.wordpress.org/Class_Lafkaence/WP_Query#Order_.26_Orderby_Parameters" target="_blank">WordPress codex page</a>')
					),
					array(
						'type' => 'dropdown',
						'heading' => esc_html__('Order way', 'lafka-plugin'),
						'param_name' => 'order',
						'value' => $order_way_values,
						'description' => sprintf(esc_html__('Designates the ascending or descending order. More at %s.', 'lafka-plugin'), '<a href="http://codex.wordpress.org/Class_Lafkaence/WP_Query#Order_.26_Orderby_Parameters" target="_blank">WordPress codex page</a>')
					),
				)
			));

			// Map lafka_woo_sale_carousel shortcode
			vc_map(array(
				'name' => esc_html__('Sale Products Carousel', 'lafka-plugin'),
				'base' => 'lafka_woo_sale_carousel',
				'icon' => $althem_icon,
				'category' => esc_html__('Lafka Shortcodes', 'lafka-plugin'),
				'description' => esc_html__('List all products on sale in carousel', 'lafka-plugin'),
				'params' => array(
					array(
						'type' => 'textfield',
						'heading' => esc_html__('Per page', 'lafka-plugin'),
						'value' => 12,
						'param_name' => 'per_page',
						'description' => esc_html__('How much items per page to show', 'lafka-plugin'),
					),
					array(
						'type' => 'dropdown',
						'heading' => esc_html__('Columns', 'lafka-plugin'),
						'value' => $columns_values,
						'param_name' => 'columns',
						'description' => esc_html__('How much columns grid', 'lafka-plugin'),
					),
					array(
						'type' => 'dropdown',
						'heading' => esc_html__('Order by', 'lafka-plugin'),
						'param_name' => 'orderby',
						'value' => $order_by_values,
						'description' => sprintf(esc_html__('Select how to sort retrieved products. More at %s.', 'lafka-plugin'), '<a href="http://codex.wordpress.org/Class_Lafkaence/WP_Query#Order_.26_Orderby_Parameters" target="_blank">WordPress codex page</a>')
					),
					array(
						'type' => 'dropdown',
						'heading' => esc_html__('Order way', 'lafka-plugin'),
						'param_name' => 'order',
						'value' => $order_way_values,
						'description' => sprintf(esc_html__('Designates the ascending or descending order. More at %s.', 'lafka-plugin'), '<a href="http://codex.wordpress.org/Class_Lafkaence/WP_Query#Order_.26_Orderby_Parameters" target="_blank">WordPress codex page</a>')
					),
				)
			));

			// Map lafka_woo_best_selling_carousel shortcode
			vc_map(array(
				'name' => esc_html__('Best Selling Products Carousel', 'lafka-plugin'),
				'base' => 'lafka_woo_best_selling_carousel',
				'icon' => $althem_icon,
				'category' => esc_html__('Lafka Shortcodes', 'lafka-plugin'),
				'description' => esc_html__('List best selling products in carousel', 'lafka-plugin'),
				'params' => array(
					array(
						'type' => 'textfield',
						'heading' => esc_html__('Per page', 'lafka-plugin'),
						'value' => 12,
						'param_name' => 'per_page',
						'description' => esc_html__('How much items per page to show', 'lafka-plugin'),
					),
					array(
						'type' => 'dropdown',
						'heading' => esc_html__('Columns', 'lafka-plugin'),
						'value' => $columns_values,
						'param_name' => 'columns',
						'description' => esc_html__('How much columns grid', 'lafka-plugin'),
					),
				)
			));

			// Map lafka_woo_product_category_carousel shortcode
			vc_map(array(
				'name' => __( 'Product Category Carousel', 'lafka-plugin' ),
				'base' => 'lafka_woo_product_category_carousel',
				'icon' => $althem_icon,
				'category' => __( 'Lafka Shortcodes', 'lafka-plugin' ),
				'description' => __( 'Show products from category in carousel', 'lafka-plugin' ),
				'params' => array(
					array(
						'type' => 'textfield',
						'heading' => __( 'Per page', 'lafka-plugin' ),
						'value' => 12,
						'save_always' => true,
						'param_name' => 'per_page',
						'description' => __( 'How much items per page to show', 'lafka-plugin' ),
					),
					array(
						'type' => 'textfield',
						'heading' => __( 'Columns', 'lafka-plugin' ),
						'value' => 4,
						'save_always' => true,
						'param_name' => 'columns',
						'description' => __( 'How much columns grid', 'lafka-plugin' ),
					),
					array(
						'type' => 'dropdown',
						'heading' => __( 'Order by', 'lafka-plugin' ),
						'param_name' => 'orderby',
						'value' => $order_by_values,
						'std' => 'menu_order title',
						// Default WC value
						'save_always' => true,
						'description' => sprintf( __( 'Select how to sort retrieved products. More at %s.', 'lafka-plugin' ), '<a href="http://codex.wordpress.org/Class_Reference/WP_Query#Order_.26_Orderby_Parameters" target="_blank">WordPress codex page</a>' ),
					),
					array(
						'type' => 'dropdown',
						'heading' => __( 'Sort order', 'lafka-plugin' ),
						'param_name' => 'order',
						'value' => $order_way_values,
						'std' => 'ASC',
						// default WC value
						'save_always' => true,
						'description' => sprintf( __( 'Designates the ascending or descending order. More at %s.', 'lafka-plugin' ), '<a href="http://codex.wordpress.org/Class_Reference/WP_Query#Order_.26_Orderby_Parameters" target="_blank">WordPress codex page</a>' ),
					),
					array(
						'type' => 'autocomplete',
						'heading' => esc_html__('Category', 'lafka-plugin'),
						'param_name' => 'category',
						'save_always' => true,
						'settings' => array(
							'multiple' => false,
							'sortable' => false,
						),
						'description' => esc_html__('Product category lists', 'lafka-plugin'),
					),
				),
			));

			// Map lafka_woo_recent_viewed_products shortcode
			vc_map(array(
				'name' => esc_html__('Recently Viewed Products', 'lafka-plugin'),
				'base' => 'lafka_woo_recent_viewed_products',
				'icon' => $althem_icon,
				'category' => esc_html__('Lafka Shortcodes', 'lafka-plugin'),
				'description' => esc_html__('List recently viewed products', 'lafka-plugin'),
				'params' => array(
					array(
						'type' => 'textfield',
						'heading' => esc_html__('Title', 'lafka-plugin'),
						'value' => esc_html__('Recently viewed products', 'lafka-plugin'),
						'param_name' => 'title',
						'description' => esc_html__('Title for the shortcode.', 'lafka-plugin'),
						'admin_label' => true
					),
					array(
						'type' => 'dropdown',
						'heading' => esc_html__('Layout', 'lafka-plugin'),
						'value' => array(
							esc_html__('Grid', 'lafka-plugin') => 'grid',
							esc_html__('Carousel', 'lafka-plugin') => 'carousel',
						),
						'description' => esc_html__('Choose between grid and carousel layout.', 'lafka-plugin'),
						'param_name' => 'layout',
						'admin_label' => true
					),
					array(
						'type'        => 'dropdown',
						'heading'     => esc_html__( 'Columns', 'lafka-plugin' ),
						'value'       => $columns_values,
						'param_name'  => 'columns',
						'description' => esc_html__( 'How many products to be displayed on a row.', 'lafka-plugin' ),
						'admin_label' => true,
					),
					array(
						'type' => 'textfield',
						'heading' => esc_html__('Number of Products', 'lafka-plugin'),
						'value' => 12,
						'param_name' => 'num_of_products',
						'description' => esc_html__('Number of products to show.', 'lafka-plugin'),
						'admin_label' => true
					),
				)
			));

			// Map lafka_woo_product_categories_carousel shortcode
			vc_map(array(
				'name' => esc_html__('Product Categories Carousel', 'lafka-plugin'),
				'base' => 'lafka_woo_product_categories_carousel',
				'icon' => $althem_icon,
				'category' => esc_html__('Lafka Shortcodes', 'lafka-plugin'),
				'description' => esc_html__('Display categories in carousel', 'lafka-plugin'),
				'params' => array(
					array(
						'type' => 'textfield',
						'heading' => esc_html__('Number', 'lafka-plugin'),
						'param_name' => 'number',
						'description' => esc_html__('The `number` field is used to display the number of products.', 'lafka-plugin'),
					),
					array(
						'type' => 'dropdown',
						'heading' => esc_html__('Order by', 'lafka-plugin'),
						'param_name' => 'orderby',
						'value' => $order_by_values,
						'description' => sprintf(esc_html__('Select how to sort retrieved products. More at %s.', 'lafka-plugin'), '<a href="http://codex.wordpress.org/Class_Lafkaence/WP_Query#Order_.26_Orderby_Parameters" target="_blank">WordPress codex page</a>')
					),
					array(
						'type' => 'dropdown',
						'heading' => esc_html__('Order way', 'lafka-plugin'),
						'param_name' => 'order',
						'value' => $order_way_values,
						'description' => sprintf(esc_html__('Designates the ascending or descending order. More at %s.', 'lafka-plugin'), '<a href="http://codex.wordpress.org/Class_Lafkaence/WP_Query#Order_.26_Orderby_Parameters" target="_blank">WordPress codex page</a>')
					),
					array(
						'type' => 'textfield',
						'heading' => esc_html__('Columns', 'lafka-plugin'),
						'value' => 4,
						'param_name' => 'columns',
						'description' => esc_html__('How much columns grid', 'lafka-plugin'),
					),
					array(
						'type' => 'textfield',
						'heading' => esc_html__('Number', 'lafka-plugin'),
						'param_name' => 'hide_empty',
						'description' => esc_html__('Hide empty', 'lafka-plugin'),
					),
					array(
						'type' => 'autocomplete',
						'heading' => esc_html__('Categories', 'lafka-plugin'),
						'param_name' => 'ids',
						'settings' => array(
							'multiple' => true,
							'sortable' => true,
						),
						'description' => esc_html__('List of product categories', 'lafka-plugin'),
					),
				)
			));
			//Filters For autocomplete param:
			//For suggestion: vc_autocomplete_[shortcode_name]_[param_name]_callback

			add_filter('vc_autocomplete_lafka_woo_product_categories_carousel_ids_callback', 'lafka_productCategoryCategoryAutocompleteSuggester', 10, 1); // Get suggestion(find). Must return an array
			add_filter('vc_autocomplete_lafka_woo_product_categories_carousel_ids_render', 'lafka_productCategoryCategoryRenderByIdExact', 10, 1); // Render exact category by id. Must return an array (label,value)
			add_filter('vc_autocomplete_lafka_woo_product_category_carousel_category_callback', 'lafka_productCategoryCategoryAutocompleteSuggester', 10, 1); // Get suggestion(find). Must return an array
			add_filter('vc_autocomplete_lafka_woo_product_category_carousel_category_render', 'lafka_productCategoryCategoryRenderByIdExact', 10, 1); // Render exact category by id. Must return an array (label,value)
			// Map lafka_woo_products_slider shortcode
			vc_map(array(
				'name' => esc_html__('Products Slider', 'lafka-plugin'),
				'base' => 'lafka_woo_products_slider',
				'icon' => $althem_icon,
				'category' => esc_html__('Lafka Shortcodes', 'lafka-plugin'),
				'description' => esc_html__('Display Products in slider', 'lafka-plugin'),
				'params' => array(
					array(
						'type' => 'dropdown',
						'heading' => esc_html__('Order by', 'lafka-plugin'),
						'param_name' => 'orderby',
						'value' => $order_by_values,
						'std' => 'title',
						'description' => sprintf(__('Select how to sort retrieved products. More at %s. Default by Title', 'lafka-plugin'), '<a href="http://codex.wordpress.org/Class_Lafkaence/WP_Query#Order_.26_Orderby_Parameters" target="_blank">WordPress codex page</a>')
					),
					array(
						'type' => 'dropdown',
						'heading' => esc_html__('Order way', 'lafka-plugin'),
						'param_name' => 'order',
						'value' => $order_way_values,
						'description' => sprintf(__('Designates the ascending or descending order. More at %s. Default by ASC', 'lafka-plugin'), '<a href="http://codex.wordpress.org/Class_Lafkaence/WP_Query#Order_.26_Orderby_Parameters" target="_blank">WordPress codex page</a>')
					),
					array(
						'type' => 'autocomplete',
						'heading' => esc_html__('Products', 'lafka-plugin'),
						'param_name' => 'ids',
						'admin_label' => true,
						'settings' => array(
							'multiple' => true,
							'sortable' => true,
							'unique_values' => true,
							// In UI show results except selected. NB! You should manually check values in backend
						),
						'description' => esc_html__('Enter List of Products', 'lafka-plugin'),
					),
					array(
						'type' => 'hidden',
						'param_name' => 'skus',
					),
					array(
						'type' => 'checkbox',
						'heading' => esc_html__('Autoplay', 'lafka-plugin'),
						'value' => array(esc_html__('On', 'lafka-plugin') => 'yes'),
						'param_name' => 'autoplay',
						'std' => 'yes',
						'admin_label' => true
					),
					array(
						'type' => 'textfield',
						'heading' => esc_html__('Autoplay Timeout', 'lafka-plugin'),
						'param_name' => 'timeout',
						'value' => '5',
						'description' => esc_html__('Autoplay interval timeout in seconds.', 'lafka-plugin'),
					),
				)
			));

			// Add Elegant Icons Font
			$attributes_icon = array(
				'type' => 'iconpicker',
				'heading' => esc_html__('Icon', 'lafka-plugin'),
				'param_name' => 'icon_etline',
				'value' => 'icon-mobile', // default value to backend editor admin_label
				'settings' => array(
					'emptyIcon' => false,
					'type' => 'etline',
					'iconsPerPage' => 100,
					// default 100, how many icons per/page to display, we use (big number) to display all icons in single page
				),
				'dependency' => array(
					'element' => 'type',
					'value' => 'etline',
				),
				'description' => esc_html__('Select icon from library.', 'lafka-plugin'),
			);

			// Add Flaticon Icons Font
			$attributes_flaticon_icon = array(
				'type' => 'iconpicker',
				'heading' => esc_html__('Icon', 'lafka-plugin'),
				'param_name' => 'icon_flaticon',
				'value' => 'flaticon-001-popcorn', // default value to backend editor admin_label
				'settings' => array(
					'emptyIcon' => false,
					'type' => 'flaticon',
					'iconsPerPage' => 100,
					// default 100, how many icons per/page to display, we use (big number) to display all icons in single page
				),
				'dependency' => array(
					'element' => 'type',
					'value' => 'flaticon',
				),
				'description' => esc_html__('Select icon from library.', 'lafka-plugin'),
			);

			$attributes_rest = array(
				'type' => 'iconpicker',
				'heading' => esc_html__('Icon', 'lafka-plugin'),
				'param_name' => 'icon_etline',
				'value' => 'icon-mobile', // default value to backend editor admin_label
				'settings' => array(
					'emptyIcon' => false,
					'type' => 'etline',
					'iconsPerPage' => 100,
					// default 100, how many icons per/page to display, we use (big number) to display all icons in single page
				),
				'dependency' => array(
					'element' => 'icon_type',
					'value' => 'etline',
				),
				'description' => esc_html__('Select icon from library.', 'lafka-plugin'),
			);

			$attributes_flaticon_rest = array(
				'type' => 'iconpicker',
				'heading' => esc_html__('Icon', 'lafka-plugin'),
				'param_name' => 'icon_flaticon',
				'value' => 'flaticon-001-popcorn', // default value to backend editor admin_label
				'settings' => array(
					'emptyIcon' => false,
					'type' => 'flaticon',
					'iconsPerPage' => 100,
					// default 100, how many icons per/page to display, we use (big number) to display all icons in single page
				),
				'dependency' => array(
					'element' => 'icon_type',
					'value' => 'flaticon',
				),
				'description' => esc_html__('Select icon from library.', 'lafka-plugin'),
			);

			$attributes_i = array(
				'type' => 'iconpicker',
				'heading' => esc_html__('Icon', 'lafka-plugin'),
				'param_name' => 'i_icon_etline',
				'value' => 'icon-mobile', // default value to backend editor admin_label
				'settings' => array(
					'emptyIcon' => false,
					'type' => 'etline',
					'iconsPerPage' => 100,
					// default 100, how many icons per/page to display, we use (big number) to display all icons in single page
				),
				'dependency' => array(
					'element' => 'i_type',
					'value' => 'etline',
				),
				'description' => esc_html__('Select icon from library.', 'lafka-plugin'),
			);

			$attributes_i_flaticon = array(
				'type' => 'iconpicker',
				'heading' => esc_html__('Icon', 'lafka-plugin'),
				'param_name' => 'i_icon_flaticon',
				'value' => 'flaticon-001-popcorn', // default value to backend editor admin_label
				'settings' => array(
					'emptyIcon' => false,
					'type' => 'flaticon',
					'iconsPerPage' => 100,
					// default 100, how many icons per/page to display, we use (big number) to display all icons in single page
				),
				'dependency' => array(
					'element' => 'i_type',
					'value' => 'flaticon',
				),
				'description' => esc_html__('Select icon from library.', 'lafka-plugin'),
			);

			vc_add_param('vc_icon', $attributes_icon);
			vc_add_param('vc_icon', $attributes_flaticon_icon);
			vc_add_param('vc_message', $attributes_rest);
			vc_add_param('vc_message', $attributes_flaticon_rest);
			vc_add_param('lafka_counter', $attributes_i);
			vc_add_param('lafka_counter', $attributes_i_flaticon);

			// Add list view attribute to WC_Shortcode_Products shortcodes
			$list_view_products_attribute = array(
				'type' => 'checkbox',
				'heading' => esc_html__('List View', 'lafka-plugin'),
				'param_name' => 'class',
				'value' => array(esc_html__('Enable', 'lafka-plugin') => 'lafka-products-list-view lafka-is-shortcode'),
				'admin_label' => true,
				'description' => esc_html__('Display products in list view. NOTE: Columns attribute will be discarded.', 'lafka-plugin'),
				'weight' => 1
			);
			vc_add_param( 'products', $list_view_products_attribute );
			vc_add_param( 'recent_products', $list_view_products_attribute );
			vc_add_param( 'featured_products', $list_view_products_attribute );
			vc_add_param( 'product_category', $list_view_products_attribute );
			vc_add_param( 'best_selling_products', $list_view_products_attribute );
			vc_add_param( 'top_rated_products', $list_view_products_attribute );
			vc_add_param( 'product_attribute', $list_view_products_attribute );

			//Filters For autocomplete param:
			//For suggestion: vc_autocomplete_[shortcode_name]_[param_name]_callback
			$WCvendor = new Vc_Vendor_Woocommerce();

			add_filter('vc_autocomplete_lafka_woo_products_slider_ids_callback', array(&$WCvendor, 'productIdAutocompleteSuggester'), 10, 1); // Get suggestion(find). Must return an array
			add_filter('vc_autocomplete_lafka_woo_products_slider_ids_render', array(&$WCvendor, 'productIdAutocompleteRender'), 10, 1); // Render exact product. Must return an array (label,value)
			//For param: ID default value filter
			add_filter('vc_form_fields_render_field_lafka_woo_products_slider_ids_param_value', array(&$WCvendor, 'productsIdsDefaultValue'), 10, 4); // Defines default value for param if not provided. Takes from other param value.
		}

		// If WCMp is active
		if (defined('LAFKA_PLUGIN_IS_WC_MARKETPLACE') && LAFKA_PLUGIN_IS_WC_MARKETPLACE) {

			// Map lafka_woo_top_rated_carousel shortcode
			vc_map(array(
				'name' => esc_html__('WCMp Vendors List', 'lafka-plugin'),
				'base' => 'lafka_wcmp_vendorslist',
				'icon' => $althem_icon,
				'category' => esc_html__('Lafka Shortcodes', 'lafka-plugin'),
				'description' => esc_html__('Displays registered vendors', 'lafka-plugin'),
				'params' => array(
					array(
						'type' => 'dropdown',
						'heading' => esc_html__('Order By', 'lafka-plugin'),
						'value' => array(
							esc_html__('Date Registered', 'lafka-plugin') => 'registered',
							esc_html__('Vendor Name', 'lafka-plugin') => 'name',
							esc_html__('Product Category', 'lafka-plugin') => 'category'
						),
						'param_name' => 'orderby',
						'description' => esc_html__('Sort vendors by chosen order parameter.', 'lafka-plugin'),
						'admin_label' => true
					),
					array(
						'type' => 'dropdown',
						'heading' => esc_html__('Order Way', 'lafka-plugin'),
						'param_name' => 'order',
						'value' => array(
							esc_html__('Ascending', 'lafka-plugin') => 'ASC',
							esc_html__('Descending', 'lafka-plugin') => 'DESC',
						),
						'description' => esc_html__('Designates the ascending or descending order.', 'lafka-plugin'),
						'admin_label' => true
					),
					array(
						'type' => 'textfield',
						'heading' => esc_html__('Number of Vendors Listed', 'lafka-plugin'),
						'param_name' => 'limit',
						'value' => '',
						'description' => esc_html__('Enter the number of vendors to be displayed. NOTE: If leaved empty, all vendors will be listed.', 'lafka-plugin'),
						'admin_label' => true
					),
					array(
						'type' => 'checkbox',
						'heading' => esc_html__('Hide Sorting Options', 'lafka-plugin'),
						'param_name' => 'hide_order_by',
						'value' => array(esc_html__('Hide "Sort" dropdown', 'lafka-plugin') => 'yes'),
						'admin_label' => true
					),
				)
			));
		}

	}

}

add_action('vc_after_init', 'lafka_add_etline_type'); /* Note: here we are using vc_after_init because WPBMap::GetParam and mutateParame are available only when default content elements are "mapped" into the system */
if (!function_exists('lafka_add_etline_type')) {

	/**
	 * Add Elegant Icons Font option to the
	 * shortcode type parameters
	 */
	function lafka_add_etline_type() {

		//Get current values stored in the type param in "Call to Action" element
		$param = WPBMap::getParam('vc_icon', 'type');
		//Append new value to the 'value' array
		$param['value'][esc_html__('Elegant Icons Font', 'lafka-plugin')] = 'etline';
		$param['value'][esc_html__('Fast Food Icons', 'lafka-plugin')] = 'flaticon';
		//Finally "mutate" param with new values
		vc_update_shortcode_param('vc_icon', $param);

		$param = WPBMap::getParam('vc_message', 'icon_type');
		$param['value'][esc_html__('Elegant Icons Font', 'lafka-plugin')] = 'etline';
		$param['value'][esc_html__('Fast Food Icons', 'lafka-plugin')] = 'flaticon';
		vc_update_shortcode_param('vc_message', $param);

		$param = WPBMap::getParam('lafka_counter', 'i_type');
		$param['value'][esc_html__('Elegant Icons Font', 'lafka-plugin')] = 'etline';
		$param['value'][esc_html__('Fast Food Icons', 'lafka-plugin')] = 'flaticon';
		vc_update_shortcode_param('lafka_counter', $param);
	}

}

// Show columns attribute only if list view is not selected
add_action( 'vc_after_init', 'lafka_add_dependency_to_product_columns_param' );
if ( ! function_exists( 'lafka_add_dependency_to_product_columns_param' ) ) {
	function lafka_add_dependency_to_product_columns_param() {
		$shortcodes_to_update = array( 'recent_products', 'featured_products', 'product_category', 'best_selling_products', 'top_rated_products', 'product_attribute' );
		foreach ( $shortcodes_to_update as $shortcode ) {
			$param               = WPBMap::getParam( $shortcode, 'columns' );
			$param['dependency'] = array( 'element' => 'class', 'value_not_equal_to' => array( 'lafka-products-list-view lafka-is-shortcode' ) );
			vc_update_shortcode_param( $shortcode, $param );
		}
	}
}

// Add additional parameters on VC shortcodes
add_action('vc_before_init', 'lafka_add_atts_vc_shortcodes');
if (!function_exists('lafka_add_atts_vc_shortcodes')) {

	function lafka_add_atts_vc_shortcodes() {

		$video_opacity_values = array('1' => '1');
		for ($j = 9; $j >= 1; $j--) {
			$video_opacity_values['0.' . $j] = '0.' . $j;
		}

		$video_background_attributes = array(
			array(
				'type' => 'textfield',
				'heading' => esc_html__('YouTube video URL', 'lafka-plugin'),
				'param_name' => 'video_bckgr_url',
				'value' => '',
				'description' => esc_html__('Paste the YouTube URL.', 'lafka-plugin'),
				'group' => esc_html__('Lafka Video Background', 'lafka-plugin')
			),
			array(
				'type' => 'dropdown',
				'heading' => esc_html__('Video Opacity', 'lafka-plugin'),
				'param_name' => 'video_opacity',
				'value' => $video_opacity_values,
				'description' => esc_html__('Set opacity fot the video.', 'lafka-plugin'),
				'group' => esc_html__('Lafka Video Background', 'lafka-plugin')
			),
			array(
				'type' => 'checkbox',
				'heading' => esc_html__('Raster', 'lafka-plugin'),
				'param_name' => 'video_raster',
				'value' => array(esc_html__('Enable Raster effect', 'lafka-plugin') => 'yes'),
				'group' => esc_html__('Lafka Video Background', 'lafka-plugin')
			),
			array(
				'type' => 'textfield',
				'heading' => esc_html__('Start time', 'lafka-plugin'),
				'param_name' => 'video_bckgr_start',
				'value' => '',
				'description' => esc_html__('Set the seconds the video should start at.', 'lafka-plugin'),
				'group' => esc_html__('Lafka Video Background', 'lafka-plugin')
			),
			array(
				'type' => 'textfield',
				'heading' => esc_html__('End time', 'lafka-plugin'),
				'param_name' => 'video_bckgr_end',
				'value' => '',
				'description' => esc_html__('Set the seconds the video should stop at.', 'lafka-plugin'),
				'group' => esc_html__('Lafka Video Background', 'lafka-plugin')
			),
		);

		// Additional attributes for vc_row shortcode
		$attributes = array_merge($video_background_attributes, array(
			array(
				'type' => 'dropdown',
				'heading' => esc_html__('General row alignment', 'lafka-plugin'),
				'param_name' => 'general_row_align',
				'value' => array(
					esc_html__('Left', 'lafka-plugin') => '',
					esc_html__('Right', 'lafka-plugin') => 'lafka-align-right',
					esc_html__('Center', 'lafka-plugin') => 'lafka-align-center'
				),
				'group' => esc_html__('Design Options', 'lafka-plugin')
			),
			array(
				'type' => 'checkbox',
				'heading' => esc_html__('Allow content overflow', 'lafka-plugin'),
				'param_name' => 'allow_overflow',
				'value' => array(esc_html__('Yes', 'lafka-plugin') => 'yes'),
				'group' => esc_html__('Design Options', 'lafka-plugin')
			),
			array(
				'type' => 'checkbox',
				'heading' => esc_html__('Fixed Background', 'lafka-plugin'),
				'param_name' => 'fixed_background',
				'value' => array(esc_html__('Yes', 'lafka-plugin') => 'yes'),
				'group' => esc_html__('Design Options', 'lafka-plugin')
			),
		));
		// Add params to Row shortcode
		vc_add_params('vc_row', $attributes);

		// Add params to Inner Row shortcode
		vc_add_params('vc_row_inner', $video_background_attributes);

		// Additional attributes for vc_progress_bar shortcode
		$attributes = array(
			array(
				'type' => 'dropdown',
				'heading' => esc_html__('Dysplay Style', 'lafka-plugin'),
				'param_name' => 'display_style',
				'value' => array(
					esc_html__('Classic Style', 'lafka-plugin') => '',
					esc_html__('Lafka Style', 'lafka-plugin') => 'lafka-progress-bar'
				),
				'weight' => 1,
				'description' => esc_html__('Choose between the standard VC style and Lafka style.', 'lafka-plugin')
			)
		);
		vc_add_params('vc_progress_bar', $attributes);

		// Additional attributes for vc_custom_heading shortcode
		$attributes = array(
			array(
				'type' => 'checkbox',
				'heading' => esc_html__('Line Accent', 'lafka-plugin'),
				'param_name' => 'line_accent',
				'description' => esc_html__('Append line accent to the text.', 'lafka-plugin')
			),
			array(
				'type' => 'checkbox',
				'heading' => esc_html__('Special Font', 'lafka-plugin'),
				'param_name' => 'special_font',
				'description' => esc_html__('Use special font for the heading.', 'lafka-plugin')
			)
		);
		vc_add_params('vc_custom_heading', $attributes);
	}

}

// Hook to where classes are added in VC shortcodes (for all shortcodes)
if(defined('VC_SHORTCODE_CUSTOM_CSS_FILTER_TAG')) {
	add_filter( VC_SHORTCODE_CUSTOM_CSS_FILTER_TAG, 'lafka_add_custom_classes_to_vc_shortcodes', 10, 3 );
}
if (!function_exists('lafka_add_custom_classes_to_vc_shortcodes')) {

	function lafka_add_custom_classes_to_vc_shortcodes( $classes, $shortcode_name, $attributes ) {
		if(is_array($attributes)) {
			if ( $shortcode_name === 'vc_custom_heading' ) {
				if(array_key_exists( 'line_accent', $attributes ) && $attributes['line_accent']) {
					$classes .= ' lafka-line-accent-text';
				}
				if(array_key_exists( 'special_font', $attributes ) && $attributes['special_font']) {
					$classes .= ' special-font';
				}
			}
		}

		return $classes;
	}
}

// Autocomplete suggestor for lafka_woo_product_categories_carousel
if (!function_exists('lafka_productCategoryCategoryAutocompleteSuggester')) {

	function lafka_productCategoryCategoryAutocompleteSuggester($query, $slug = false) {
		global $wpdb;

		$cat_id = (int) $query;
		$query = trim($query);
		$like_query = '%' . $wpdb->esc_like( $query ) . '%';
		$post_meta_infos = $wpdb->get_results(
			$wpdb->prepare("SELECT a.term_id AS id, b.name as name, b.slug AS slug
						FROM {$wpdb->term_taxonomy} AS a
						INNER JOIN {$wpdb->terms} AS b ON b.term_id = a.term_id
						WHERE a.taxonomy = 'product_cat' AND (a.term_id = %d OR b.slug LIKE %s OR b.name LIKE %s )", $cat_id > 0 ? $cat_id : - 1, $like_query, $like_query), ARRAY_A);

		$result = array();
		if (is_array($post_meta_infos) && !empty($post_meta_infos)) {
			foreach ($post_meta_infos as $value) {
				$data = array();
				$data['value'] = $slug ? $value['slug'] : $value['id'];
				$data['label'] = esc_html__('Id', 'lafka-plugin') . ': ' .
				                 $value['id'] .
				                 ( ( ! empty($value['name']) ) ? ' - ' . esc_html__('Name', 'lafka-plugin') . ': ' .
				                                                    $value['name'] : '' ) .
				                 ( ( ! empty($value['slug']) ) ? ' - ' . esc_html__('Slug', 'lafka-plugin') . ': ' .
				                                                    $value['slug'] : '' );
				$result[] = $data;
			}
		}

		return $result;
	}

}

// Render by ID for lafka_woo_product_categories_carousel
if (!function_exists('lafka_productCategoryCategoryRenderByIdExact')) {

	function lafka_productCategoryCategoryRenderByIdExact($query) {
		$query = $query['value'];
		$cat_id = (int) $query;
		$term = get_term($cat_id, 'product_cat');

		if ( is_wp_error( $term ) || ! $term ) {
			return false;
		}

		$term_slug = $term->slug;
		$term_title = $term->name;
		$term_id = $term->term_id;

		$term_slug_display = '';
		if (!empty($term_slug)) {
			$term_slug_display = ' - ' . esc_html__('Slug', 'lafka-plugin') . ': ' . $term_slug;
		}

		$term_title_display = '';
		if (!empty($term_title)) {
			$term_title_display = ' - ' . esc_html__('Title', 'lafka-plugin') . ': ' . $term_title;
		}

		$term_id_display = esc_html__('Id', 'lafka-plugin') . ': ' . $term_id;

		$data = array();
		$data['value'] = $term_id;
		$data['label'] = $term_id_display . $term_title_display . $term_slug_display;

		return !empty($data) ? $data : false;
	}

}

if (!function_exists('lafka_icon_element_fonts_enqueue')) {

	/**
	 * Enqueue icon element font
	 * @param $font
	 */
	function lafka_icon_element_fonts_enqueue($font) {
		switch ($font) {
			case 'fontawesome':
				wp_enqueue_style('font_awesome_6');
				break;
			case 'openiconic':
				wp_enqueue_style('vc_openiconic');
				break;
			case 'typicons':
				wp_enqueue_style('vc_typicons');
				break;
			case 'entypo':
				wp_enqueue_style('vc_entypo');
				break;
			case 'linecons':
				wp_enqueue_style('vc_linecons');
				break;
			default:
				do_action('vc_enqueue_font_icon_element', $font); // hook to custom do enqueue style
		}
	}

}
