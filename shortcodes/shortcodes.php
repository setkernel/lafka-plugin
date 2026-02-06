<?php
defined( 'ABSPATH' ) || exit;

// Include shortcodes classes
// If WCMp is active
if (defined('LAFKA_PLUGIN_IS_WC_MARKETPLACE') && LAFKA_PLUGIN_IS_WC_MARKETPLACE) {
    require_once( plugin_dir_path(__FILE__) . 'incl/LafkaShortcodeVendorList.php' );
    add_shortcode('lafka_wcmp_vendorslist', array('LafkaShortcodeVendorList', 'output'));
}

if (defined('WPB_VC_VERSION')) {
	VcShortcodeAutoloader::getInstance()->includeClass('WPBakeryShortCode_VC_Tta_Tabs');

	class WPBakeryShortCode_Lafka_Content_Slider extends WPBakeryShortCode_VC_Tta_Tabs {

		public $layout = 'tabs';

		public function getTtaContainerClasses() {
			$classes = parent::getTtaContainerClasses();

			$classes .= ' vc_tta-o-non-responsive';

			return $classes;
		}

		public function getTtaGeneralClasses() {
			$classes = parent::getTtaGeneralClasses();

			$classes .= ' vc_tta-pageable';

			// tabs have pagination on opposite side of tabs. pageable should behave normally
			if (false !== strpos($classes, 'vc_tta-tabs-position-top')) {
				$classes = str_replace('vc_tta-tabs-position-top', 'vc_tta-tabs-position-bottom', $classes);
			} else {
				$classes = str_replace('vc_tta-tabs-position-bottom', 'vc_tta-tabs-position-top', $classes);
			}

			return $classes;
		}

		/**
		 * Disable all tabs
		 *
		 * @param $atts
		 * @param $content
		 *
		 * @return string
		 */
		public function getParamTabsList($atts, $content) {
			return '';
		}

		public function getFileName() {
			return 'vc_lafka_content_slider';
		}

	}

}


/**
 * Define lafka_counter shortcode
 */
if (!function_exists('lafka_counter_shortcode')) {

	function lafka_counter_shortcode($atts) {

		// Attributes
		extract(shortcode_atts(
										array(
				'txt_before_counter' => '',
				'count_number' => '10',
				'txt_after_counter' => '',
				'add_icon' => 'false',
				'counter_style' => 'h4',
				'counter_alignment' => 'lafka-counter-left',
				'text_color' => '',
				'i_type' => 'fontawesome',
				'i_icon_fontawesome' => 'fas fa-adjust',
				'i_icon_openiconic' => 'vc-oi vc-oi-dial',
				'i_icon_typicons' => 'typcn typcn-adjust-brightness',
				'i_icon_entypo' => 'entypo-icon entypo-icon-note',
				'i_icon_linecons' => 'vc_li vc_li-heart',
				'i_icon_monosocial' => 'vc-mono vc-mono-fivehundredpx',
				'i_icon_material' => 'vc-material vc-material-cake',
				'i_icon_etline' => 'icon-mobile',
				'i_icon_flaticon' => 'flaticon-001-popcorn',
				'i_custom_color' => '',
										), $atts)
		);

		$iconClass = '';

		if (!empty($add_icon) && 'true' === $add_icon) {
			if (isset(${'i_icon_' . $i_type})) {
				$iconClass = ${'i_icon_' . $i_type};
			}
			vc_icon_element_fonts_enqueue($i_type);
		}

		$icon_color = '';
		if ($i_custom_color) {
			$icon_color = $i_custom_color;
		}

		ob_start();
		?>
		<div class="lafka-counter-shortcode">
			<div class="lafka-counter-content  lafka-counter-<?php echo esc_attr($counter_style) ?> <?php echo sanitize_html_class($counter_alignment) ?>" <?php if ($text_color): ?> style="color:<?php echo esc_attr($text_color) ?>" <?php endif; ?> >
				<?php echo esc_html($txt_before_counter) ?>
				<?php if ($iconClass): ?>
					<i class="<?php echo esc_attr($iconClass) ?>" <?php if ($icon_color && $icon_color !== 'custom'): ?> style="color:<?php echo esc_attr($icon_color) ?>" <?php endif; ?>></i>
				<?php endif; ?>
				<?php if (is_numeric($count_number)): ?>
					<span class="lafka-counter"><?php echo esc_html($count_number) ?></span>
				<?php endif; ?>
				<?php echo esc_html($txt_after_counter) ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

}
add_shortcode('lafka_counter', 'lafka_counter_shortcode');

/**
 * Define lafka_typed shortcode
 */
if (!function_exists('lafka_typed_shortcode')) {

	function lafka_typed_shortcode($atts) {

		// Attributes
		extract(shortcode_atts(
										array(
				'txt_before_typed' => '',
				'rotating_strings' => 'One,Two,Tree',
				'txt_after_typed' => '',
				'typed_style' => 'h4',
				'typed_alignment' => 'lafka-typed-left',
				'static_text_color' => '',
				'typed_text_color' => '',
				'loop' => 'yes',
				'el_class' => '',
				'css' => '',
										), $atts)
		);

		$unique_id = uniqid('lafka_typed');

		// css from Design options
		$css_design_class = '';
		if(defined('VC_SHORTCODE_CUSTOM_CSS_FILTER_TAG')) {
			$css_design_class = apply_filters(VC_SHORTCODE_CUSTOM_CSS_FILTER_TAG, vc_shortcode_custom_css_class($css, ' '), 'lafka_typed', $atts);
		}

		$rotating_strings_arr = explode(',', $rotating_strings);
		ob_start();
		?>
		<div class="lafka-typed-shortcode">
		<div class="lafka-typed-content lafka-typed-<?php echo esc_attr($typed_style) ?> <?php echo sanitize_html_class($typed_alignment) ?><?php echo ($css_design_class ? ' ' . esc_attr($css_design_class) : '') ?>" <?php if ($static_text_color): ?> style="color:<?php echo esc_html($static_text_color) ?>" <?php endif; ?> >
			<?php echo esc_html($txt_before_typed) ?>
			<span id="<?php echo esc_attr($unique_id) ?>" class="lafka-typed"  <?php if ($typed_text_color): ?> style="color:<?php echo esc_html($typed_text_color) ?>" <?php endif; ?>></span>
			<?php echo esc_html($txt_after_typed) ?>
		</div>

		</div>
		<?php if (is_array($rotating_strings_arr) && count($rotating_strings_arr) > 1 && $rotating_strings_arr[0] != ''): ?>
			<script>
				//<![CDATA[
				(function () {
					"use strict";
					document.addEventListener("DOMContentLoaded", function () {
						new Typed("#<?php echo esc_js($unique_id) ?>", {
							strings: [<?php
			foreach ($rotating_strings_arr as $str):
				echo '"' . esc_js($str) . '",';
			endforeach;
			?>],
							typeSpeed: 60,
							// time before typing starts
							startDelay: 1000,
							// backspacing speed
							backSpeed: 20,
							// delay before deleting last string
							backDelay: 1800,
							// MUST BE OPTIONAL TRUE/FALSE
							loop: <?php echo esc_js($loop == 'yes' ? 'true' : 'false') ?>,
							showCursor: true
						});
					});
				})();
				//]]>
			</script>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

}
add_shortcode('lafka_typed', 'lafka_typed_shortcode');


/**
 * Define lafkablogposts shortcode
 */
if (!function_exists('lafka_blogposts_shortcode')) {

	function lafka_blogposts_shortcode($atts) {

		// Attributes
		extract(shortcode_atts(
										array(
				'blog_style' => '',
				'date_sort' => 'default',
				'number_of_posts' => '',
				'offset' => ''
										), $atts), EXTR_PREFIX_ALL, 'lafka_blogposts_param'
		);

		if (is_front_page()) {
			$paged = (get_query_var('page')) ? get_query_var('page') : 1;
		} else {
			$paged = (get_query_var('paged')) ? get_query_var('paged') : 1;
		}
		$query_args = array(
				'paged' => $paged,
				'post_type' => 'post'
		);

		// If defined sort order
		if ($lafka_blogposts_param_date_sort != 'default') {
			$query_args['order'] = $lafka_blogposts_param_date_sort;
		}
		// Posts per page
		if ($lafka_blogposts_param_number_of_posts != '') {
			$query_args['posts_per_page'] = $lafka_blogposts_param_number_of_posts;
		}
		// Offset
		if ($lafka_blogposts_param_offset != '') {
			$query_args['offset'] = $lafka_blogposts_param_offset;
		}

		// The query - use WP_Query and temporarily replace global for pagination support
		global $wp_query;
		$original_query = $wp_query;
		$wp_query = new WP_Query($query_args);

		switch ($lafka_blogposts_param_blog_style) {
			 case 'lafka_blog_masonry':
				// load Isotope
				wp_enqueue_script('isotope');
				// Isotope settings
				ob_start();
				?>
				<script>
					//<![CDATA[
					(function ($) {
						"use strict";
                        $(window).on("load", function () {
							$('.lafka_blog_masonry', '#main').isotope({
								itemSelector: '#main div.blog-post'
							});
						});
					})(window.jQuery);
					//]]>
				</script>
				<?php
				echo ob_get_clean();
				break;
		}

		$output = '<div class="lafka_shortcode_blog ' . esc_attr($lafka_blogposts_param_blog_style) . '">';

		if (have_posts()) {
			while (have_posts()) {
				the_post();
				// Capture each post
				ob_start();

				$located_template = locate_template('content.php');
                if($located_template) {
                    include($located_template);
                }

				$output .= ob_get_clean();
			}
		}

		$output .= '</div>';

		// Capture the pagination
		ob_start();
		?>

		<!-- PAGINATION -->
		<div class="box box-common">
			<?php
			if (function_exists('lafka_pagination')) :
				lafka_pagination();
			else :
				?>
				<div class="navigation group">
					<div class="alignleft"><?php next_posts_link(esc_html__('Next &raquo;', 'lafka-plugin')) ?></div>
					<div class="alignright"><?php previous_posts_link(esc_html__('&laquo; Back', 'lafka-plugin')) ?></div>
				</div>

			<?php endif; ?>
		</div>
		<!-- END OF PAGINATION -->

		<?php
		$output .= ob_get_clean();

		$wp_query = $original_query;
		wp_reset_postdata();

		return $output;
	}

}
add_shortcode('lafkablogposts', 'lafka_blogposts_shortcode');

/**
 * Define lafka_foodmenu shortcode
 */
if (!function_exists('lafka_foodmenu_shortcode')) {


	function lafka_foodmenu_shortcode($atts) {
		global $wp;
		// Attributes
		extract( shortcode_atts(
			array(
				'color_scheme'         => 'lafka-foodmenu-dark',
				'taxonomies'           => '',
				'show_lightbox'        => 'no',
				'hide_foodmenu_images' => 'no',
				'foodmenu_simple_menu' => 'no',
				'enable_sortable'      => 'no',
				'limit'                => '',
				'offset'               => '',
				'date_sort'            => 'DESC',
				'css'                  => ''
			), $atts ) );

		// css from Design options
		$css_design_class = '';
		if(defined('VC_SHORTCODE_CUSTOM_CSS_FILTER_TAG')) {
			$css_design_class = apply_filters(VC_SHORTCODE_CUSTOM_CSS_FILTER_TAG, vc_shortcode_custom_css_class($css, ' '), 'lafka_foodmenu', $atts);
		}

		$get_foodmenu_args = array(
				'post_type' => 'lafka-foodmenu',
				'post_status' => 'publish',
			    'order' => $date_sort
		);

		// Number of menu entries
		if ($limit) {
			$get_foodmenu_args['posts_per_page'] = $limit;
		} else {
			$get_foodmenu_args['nopaging'] = true;
		}

		// Offset
		if ($offset) {
			$get_foodmenu_args['offset'] = $offset;
		}

		// If defined sort order
		if ($date_sort != 'DESC') {
			$get_foodmenu_args['order'] = $date_sort;
		}

		// Filter by category
		if ($taxonomies) {
			$get_foodmenu_args['tax_query'] = array(
					array(
							'taxonomy' => 'lafka_foodmenu_category',
							'field' => 'term_id',
							'terms' => explode(',', $taxonomies),
					),
			);
		}

		$projects = new WP_Query($get_foodmenu_args);

		$unique_id = uniqid('latest_projects');
		$thumb_size = 'lafka-general-small-size-nocrop';

		wp_enqueue_script('isotope');
		ob_start();
		?>
        <script>
            //<![CDATA[
            (function ($) {
                "use strict";
                $(document).ready(function () {
                    var $container = $('#<?php echo esc_attr($unique_id) ?> div.lafka-foodmenu-shortcode-container');

	                <?php if ($enable_sortable == 'yes'): ?>
                        var $isotopedGrid = $container.isotope({
                            itemSelector: 'div.foodmenu-unit',
                            layoutMode: 'masonry',
                            transitionDuration: '0.5s'
                        });

                        // layout Isotope after each image loads
                        $isotopedGrid.imagesLoaded().progress(function () {
                            $isotopedGrid.isotope('layout');
                        });

                        // bind filter button click
                        $container.prev('div.lafka-foodmenu-categories').on('click', 'a', function () {
                            var filterValue = $(this).attr('data-filter');
                            // use filterFn if matches value
                            $container.isotope({filter: filterValue});
                        });

                        // change is-checked class on buttons
                        $container.prev('div.lafka-foodmenu-categories').each(function (i, buttonGroup) {
                            var $buttonGroup = $(buttonGroup);
                            $buttonGroup.on('click', 'a', function () {
                                $buttonGroup.find('.is-checked').removeClass('is-checked');
                                $(this).addClass('is-checked');
                            });
                        });

                        // Add magnific function
                        $isotopedGrid.isotope('on', 'layoutComplete',
                            function (isoInstance, laidOutItems) {
                                $isotopedGrid.find('a.foodmenu-lightbox-link').magnificPopup({
                                    mainClass: 'mfp-fade',
                                    type: 'image'
                                });
                            }
                        );
	                <?php else: ?>
                        $container.find('a.foodmenu-lightbox-link').magnificPopup({
                            mainClass: 'mfp-fade',
                            type: 'image'
                        });
	                <?php endif; ?>
                });
            })(window.jQuery);
            //]]>
        </script>

		<?php if ($projects->have_posts()): ?>
		    <?php
                $classes = array('lafka-foodmenu-shortcode');

                if($css_design_class) {
                    $classes[] = $css_design_class;
                }
                $classes[] = $color_scheme;
            ?>
			<div id="<?php echo esc_attr($unique_id) ?>" class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
				<?php
				if ($enable_sortable == 'yes'):
					$foodmenu_categories = array();
					if ($taxonomies) {
						$taxonomies_arr = explode(',', $taxonomies);
						if (is_array($taxonomies_arr) && !empty($taxonomies_arr)) {
							$foodmenu_categories = get_terms(array('taxonomy'=>'lafka_foodmenu_category', 'term_taxonomy_id'=>$taxonomies_arr));
						}
					} else {
						$foodmenu_categories = get_terms('lafka_foodmenu_category');
					}
					?>
					<div class="lafka-foodmenu-categories">
						<ul>
							<li><a class="is-checked" data-filter="*" href="#"><?php esc_html_e('show all', 'lafka-plugin') ?></a></li>
							<?php foreach ($foodmenu_categories as $category): ?>
								<li><a data-filter=".<?php echo esc_attr($category->slug) ?>" href="#"><?php echo esc_html($category->name) ?></a></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
				<div class="lafka-foodmenu-shortcode-container">
				<?php endif; ?>

				<?php while ($projects->have_posts()): ?>
					<?php $projects->the_post(); ?>
					<?php
                    global $post;
					$post_id = get_the_ID();
					$current_terms = get_the_terms($post_id, 'lafka_foodmenu_category');
					$current_terms_as_simple_array = array();
					$current_terms_as_classes = array();

					if ($current_terms) {
						foreach ($current_terms as $term) {
							$current_terms_as_simple_array[] = $term->name;
							$current_terms_as_classes[] = $term->slug;
						}
					}

					$featured_image_attr = wp_get_attachment_image_src(get_post_thumbnail_id(get_the_ID()), 'full');
					$featured_image_src = '';
					if ($featured_image_attr) {
						$featured_image_src = $featured_image_attr[0];
					}
					?>
					<div class="foodmenu-unit lafka-none-overlay <?php echo esc_attr(implode(' ', $current_terms_as_classes)) ?>">
						<div class="foodmenu-unit-holder">
							<?php if ( $hide_foodmenu_images !== 'yes' ): ?>
								<?php if ( has_post_thumbnail() ): ?>
									<?php if ( $foodmenu_simple_menu !== 'yes' ): ?>
                                        <a title="<?php esc_html_e( 'View more', 'lafka-plugin' ) ?>" href="<?php the_permalink(); ?>" class="lafka-foodmenu-image-link">
									<?php endif; ?>
									<?php the_post_thumbnail( $thumb_size ); ?>
									<?php if ( $foodmenu_simple_menu !== 'yes' ): ?>
                                        </a>
									<?php endif; ?>
								<?php else: ?>
                                    <img src="<?php echo esc_url( LAFKA_PLUGIN_IMAGES_PATH . 'cat_not_found-small.png' ) ?>"
                                         alt="<?php esc_html_e( 'No image available', 'lafka-plugin' ) ?>"/>
								<?php endif; ?>
							<?php endif; ?>
                            <div class="foodmenu-unit-info">
                                <a <?php if ( $foodmenu_simple_menu !== 'yes' ): ?> title="<?php esc_html_e( 'View more', 'lafka-plugin' ) ?>" <?php endif; ?>
		                            <?php if ( $foodmenu_simple_menu !== 'yes' ): ?>
                                        href="<?php the_permalink(); ?>"
		                            <?php else: ?>
                                        href="#"
		                            <?php endif; ?>
                                   class="foodmenu-link">
                                    <h4>
                                        <?php the_title(); ?>
	                                    <?php
                                        $item_weight      = get_post_meta( $post_id, 'lafka_item_weight', true );
                                        $item_weight_unit = get_post_meta( $post_id, 'lafka_item_weight_unit', true );
                                        $item_price       = get_post_meta( $post_id, 'lafka_item_single_price', true );
                                        $item_ingredients = get_post_meta( $post_id, 'lafka_ingredients', true );
                                        ?>
	                                    <?php if( $item_weight && $item_weight_unit ): ?>
                                            <span class="lafka-item-weight-list"><?php echo esc_html( $item_weight . ' ' . $item_weight_unit ) ?></span>
	                                    <?php endif; ?>
	                                    <?php if( $item_price && function_exists('lafka_get_formatted_price') ): ?>
                                            <span><?php echo wp_kses_post( lafka_get_formatted_price( $item_price ) ) ?></span>
	                                    <?php endif; ?>
                                    </h4>
	                                <?php if ( $item_ingredients ): ?>
                                        <h6><?php echo esc_html( $item_ingredients ); ?></h6>
	                                <?php endif; ?>
	                                <?php if (  function_exists('lafka_has_foodmenu_options') && lafka_has_foodmenu_options( $post ) ): ?>
		                                <?php $lafka_foodmenu_options_array = lafka_get_foodmenu_options( $post ); ?>
                                        <ul>
			                                <?php foreach ( $lafka_foodmenu_options_array as $option => $price ): ?>
                                                <li>
					                                <?php if ( $option ): ?>
                                                        <span class="lafka-foodmenu-option"><?php echo esc_html( $option ) ?></span>
					                                <?php endif; ?>
					                                <?php if ( $price ): ?>
                                                        <span class="lafka-foodmenu-price"><?php echo wp_kses_post( $price ) ?></span>
					                                <?php endif; ?>
                                                </li>
			                                <?php endforeach; ?>
                                        </ul>
	                                <?php endif; ?>
                                </a>
                                <?php if ($hide_foodmenu_images !== 'yes' && $featured_image_src && $show_lightbox === 'yes'): ?>
                                    <a class="foodmenu-lightbox-link" href="<?php echo esc_url($featured_image_src) ?>"><span></span></a>
                                <?php endif; ?>
                            </div>
						</div>
					</div>
				<?php endwhile; ?>

				<?php wp_reset_postdata(); ?>

            <?php if ($projects->have_posts()): ?>
				</div>
				<div class="clear"></div>

			</div>
			<?php endif; ?>
		<?php

		$output = ob_get_clean();

		return $output;
	}

}
add_shortcode('lafka_foodmenu', 'lafka_foodmenu_shortcode');

/**
 * Define lafka_latest_posts shortcode
 */
if (!function_exists('lafka_latest_posts_shortcode')) {

	function lafka_latest_posts_shortcode($atts) {


		// Attributes
		extract(shortcode_atts(
			array(
				'columns' => '4',
				'taxonomies' => '',
				'layout' => 'grid',
				'number_of_posts' => '4',
				'offset' => '',
				'date_sort' => 'default',
				'css' => ''
			), $atts), EXTR_PREFIX_ALL, 'lafka_blogposts_param'
		);

		// css from Design options
		$css_design_class = '';
		if(defined('VC_SHORTCODE_CUSTOM_CSS_FILTER_TAG')) {
			$css_design_class = apply_filters(VC_SHORTCODE_CUSTOM_CSS_FILTER_TAG, vc_shortcode_custom_css_class($lafka_blogposts_param_css, ' '), 'lafka_latest_posts', $atts);
		}

		$query_args = array(
				'post_type' => 'post',
				'ignore_sticky_posts' => 1
		);

		// Filter by category
		if ($lafka_blogposts_param_taxonomies) {
			$query_args['tax_query'] = array(
					array(
							'taxonomy' => 'category',
							'field' => 'term_id',
							'terms' => explode(',', $lafka_blogposts_param_taxonomies),
					),
			);
		}

		// If defined sort order
		if ($lafka_blogposts_param_date_sort != 'default') {
			$query_args['order'] = $lafka_blogposts_param_date_sort;
		}
		// Posts per page
		if ($lafka_blogposts_param_number_of_posts != '') {
			$query_args['posts_per_page'] = $lafka_blogposts_param_number_of_posts;
		}
		// Offset
		if ($lafka_blogposts_param_offset != '') {
			$query_args['offset'] = $lafka_blogposts_param_offset;
		}

		$lafka_is_latest_posts = true;

		// The query
		$lafka_latest_query = new WP_Query($query_args);

		$js_config_output = '';

		$unique_id = uniqid('latest_posts');

		$layout_class = '';
		switch ($lafka_blogposts_param_layout) {
			case 'grid':
				$layout_class = 'lafka-latest-grid';
				break;
			case 'carousel':
				$layout_class = 'owl-carousel lafka-owl-carousel';
				break;
		}

		if ($lafka_blogposts_param_layout === 'carousel') {
			ob_start();
			?>
				(function ($) {
					"use strict";
                    $(window).on("load", function () {
						jQuery("#<?php echo esc_js($unique_id) ?>").owlCarousel({
							rtl: <?php echo is_rtl() ? 'true' : 'false'; ?>,
							responsiveClass: true,
							responsive: {
								0: {
									items: 1,
								},
								600: {
									items: 2,
								},
								768: {
                                    items: <?php echo ($lafka_blogposts_param_columns < 3 ? esc_js($lafka_blogposts_param_columns) : 3) ?>,
                                },
                                1024: {
                                    items: <?php echo ($lafka_blogposts_param_columns < 4 ? esc_js($lafka_blogposts_param_columns) : 4) ?>,
                                },
								1280: {
									items: <?php echo esc_js($lafka_blogposts_param_columns) ?>,
								}
							},
							dots: false,
							nav: true,
							navText: [
								"<i class='fas fa-angle-left'></i>",
								"<i class='fas fa-angle-right'></i>"
							],
						});
					});
				})(window.jQuery);
			<?php
			$js_config_output = ob_get_clean();
			wp_add_inline_script('owl-carousel', $js_config_output);
		}

		// Classes
		$shortcode_classes = array('lafka_shortcode_latest_posts', 'lafka_blog_masonry', 'lafka-latest-blog-col-' . $lafka_blogposts_param_columns, $layout_class, $css_design_class);

		$output = '<div id="' . esc_attr($unique_id) . '" class="' . esc_attr(implode(' ', $shortcode_classes)) . '">';

		if ($lafka_latest_query->have_posts()) {
			while ($lafka_latest_query->have_posts()) {
				$lafka_latest_query->the_post();
				// Capture each post
				ob_start();

				include(locate_template('content.php'));

				$output .= ob_get_clean();
			}
		}

		$output .= '</div>';

		wp_reset_postdata();

		return $output;
	}

}
add_shortcode('lafka_latest_posts', 'lafka_latest_posts_shortcode');

/**
 * Define lafka_banner shortcode
 */
if (!function_exists('lafka_banner_shortcode')) {

	function lafka_banner_shortcode($atts) {

		// Attributes
		extract(shortcode_atts(
										array(
				'type' => 'fontawesome',
				'icon_fontawesome' => '',
				'icon_openiconic' => '',
				'icon_typicons' => '',
				'icon_linecons' => '',
				'icon_entypo' => '',
				'icon_etline' => '',
				'icon_flaticon' => '',
				'alignment' => 'banner-center-center',
				'image_id' => '',
				'pre_title' => '',
				'pre_title_use_special_font' => '',
				'title' => '',
				'title_size' => '',
				'subtitle' => '',
				'link' => '',
				'link_target' => '_blank',
				'button_text' => '',
				'color_scheme' => '',
				'appear_animation' => '',
				'css' => ''
										), $atts)
		);

		// css from Design options
		$css_design_class = '';
		if(defined('VC_SHORTCODE_CUSTOM_CSS_FILTER_TAG')) {
			$css_design_class = apply_filters(VC_SHORTCODE_CUSTOM_CSS_FILTER_TAG, vc_shortcode_custom_css_class($css, ' '), 'lafka_banner', $atts);
		}

		// Enqueue needed icon font.
		lafka_icon_element_fonts_enqueue($type);

		$iconClass = isset(${"icon_" . $type}) ? esc_attr(${"icon_" . $type}) : 'fas fa-adjust';

		ob_start();
		?>
		<div class="wpb_lafka_banner wpb_content_element <?php echo esc_attr($alignment) ?> <?php echo esc_attr($title_size) ?><?php if ($appear_animation !== '') echo ' ' . sanitize_html_class($appear_animation); ?><?php if ($color_scheme !== '') echo ' ' . sanitize_html_class($color_scheme); ?> <?php echo ($css_design_class ? ' ' . esc_attr($css_design_class) : '') ?>">
			<div class="wpb_wrapper">
				<div class="lafka_whole_banner_wrapper">
					<a href="<?php echo esc_url($link) ? esc_url($link) : '#'; ?>" target="<?php echo esc_attr($link_target) ?>" <?php echo esc_attr($title) ? 'title="' . esc_attr($title) . '"' : ''; ?>>
						<?php if ($image_id): ?>
							<div class="lafka_banner_image">
								<?php echo wp_get_attachment_image( $image_id, 'full', false, array( 'class' => 'lafka_banner_bg', 'alt' => ($title ? esc_attr($title) : 'banner' )) );?>
							</div>
						<?php endif; ?>
						<div class="lafka_banner_text">
							<div class="lafka_banner_centering">
								<div class="lafka_banner_centered">
									<?php if($iconClass): ?>
										<span class="lafka_banner-icon <?php echo esc_attr($iconClass); ?>" ></span>
									<?php endif; ?>
									<?php if ($pre_title): ?>
                                        <h5 <?php if($pre_title_use_special_font): ?>class="lafka-special-pre-title"<?php endif; ?> ><?php echo esc_html($pre_title) ?></h5>
									<?php endif; ?>
									<?php if ($title): ?>
										<h4><span><?php echo esc_html($title) ?></span></h4>
									<?php endif; ?>
									<?php if ($subtitle): ?>
										<h6><?php echo esc_html($subtitle) ?></h6>
									<?php endif; ?>
									<?php if ($button_text): ?>
										<span class="lafka_banner_buton"><?php echo esc_html($button_text) ?></span>
									<?php endif; ?>
								</div>
							</div>
						</div>
					</a>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

}
add_shortcode('lafka_banner', 'lafka_banner_shortcode');

/**
 * Define lafka_cloudzoom_gallery shortcode
 */
if (!function_exists('lafka_cloudzoom_gallery_shortcode')) {

	function lafka_cloudzoom_gallery_shortcode($atts) {

		// Attributes
		extract(shortcode_atts(
										array(
				'images' => ''
										), $atts)
		);

		$img_size = 'lafka-640x640';

		if ($images) {
			$images = explode(',', $images);
			$unique_id = uniqid('lafka_cloudzoom_gallery_');

			if (is_array($images) && !empty($images)) {
				ob_start();
				?>
				<div class="lafka-cloudzoom-gallery wpb_content_element">
					<div class="wpb_wrapper">
						<?php
						$first_image_attach_id = $images[0];
						$first_image = wp_get_attachment_image($first_image_attach_id, $img_size);
						$first_image_attach_url = wp_get_attachment_url($first_image_attach_id);
						echo sprintf('<a id="%s" href="%s" itemprop="image" class="cloud-zoom" rel="position: \'inside\' , showTitle: false, adjustX:-4, adjustY:-4">%s</a>', esc_attr($unique_id), esc_url($first_image_attach_url), $first_image);
						?>

						<ul class="additional-images">
							<?php foreach ($images as $attach_id): ?>
								<?php
								$thumb_image = wp_get_attachment_image($attach_id, 'lafka-widgets-thumb');
								$small_image_params = wp_get_attachment_image_src($attach_id, $img_size);

								$image_attach_url = wp_get_attachment_url($attach_id);
								?>
								<li>
									<?php echo sprintf('<a rel="useZoom: \'%s\', smallImage: \'%s\'" class="cloud-zoom-gallery" href="%s">%s</a>', esc_attr($unique_id), esc_url($small_image_params[0]), esc_url($image_attach_url), $thumb_image); ?>
								</li>
							<?php endforeach; ?>
						</ul>

					</div>
				</div>
				<script>
					//<![CDATA[
					(function ($) {
						"use strict";
						$(document).ready(function () {
							jQuery('#<?php echo esc_attr($unique_id) ?>').CloudZoom();
						});
					})(window.jQuery);
					//]]>
				</script>
				<?php
				return ob_get_clean();
			}
		}
	}

}
add_shortcode('lafka_cloudzoom_gallery', 'lafka_cloudzoom_gallery_shortcode');

/**
 * Define lafka_icon_teaser shortcode
 */
if (!function_exists('lafka_icon_teaser_shortcode')) {

    function lafka_icon_teaser_shortcode($atts, $content = '') {
        // Attributes
        extract(shortcode_atts(
                                        array(
                'title' => '',
                'subtitle' => '',
                'type' => 'fontawesome',
                'icon_fontawesome' => 'fas fa-adjust',
                'icon_etline' => 'icon-mobile',
                'icon_flaticon' => 'flaticon-001-popcorn',
                'icon_image_id' => '',
                'color' => '',
                'align' => 'teaser-left',
                'appear_animation' => '',
                'titles_color' => ''
                                        ), $atts)
        );

        // Enqueue font-awesome.
        wp_enqueue_style('font_awesome_6');

        $unique_id = uniqid('lafka_icon_teaser_');

        $classes = array('icon_link_item', $align);
	    if($type === 'custom_image') {
		    $classes[] = 'lafka-image-icon';
	    }

        ob_start();
        ?>
        <div class="lafka_icon_teaser wpb_content_element<?php if ($appear_animation !== '') echo ' ' . sanitize_html_class($appear_animation); ?>">
            <div class="<?php echo esc_attr(implode( ' ', $classes)) ?>">
                <a href="#<?php echo esc_attr($unique_id) ?>" class="lafka-icon-teaser-popup-link">
                    <div  class="icon_holder">
                        <?php if ($type === 'fontawesome'): ?>
                            <i <?php echo( $color ? ' style="color:' . esc_attr($color) . ';"' : '' ); ?> class="<?php echo esc_attr($icon_fontawesome); ?>"></i>
                        <?php elseif ($type === 'etline'): ?>
                            <i <?php echo( $color ? ' style="color:' . esc_attr($color) . ';"' : '' ); ?> class="<?php echo esc_attr($icon_etline); ?>"></i>
                        <?php elseif ($type === 'flaticon'): ?>
                            <i <?php echo( $color ? ' style="color:' . esc_attr($color) . ';"' : '' ); ?> class="<?php echo esc_attr($icon_flaticon); ?>"></i>
                        <?php elseif($type === 'custom_image'): ?>
	                        <?php echo wp_get_attachment_image( $icon_image_id, 'lafka-widgets-thumb', false, array( 'alt' => $title ));?>
                        <?php endif; ?>
                    </div>
                    <?php if ($title): ?>
                        <h5<?php echo( $titles_color ? ' style="color:' . esc_attr($titles_color) . ';"' : '' ); ?>><?php echo esc_html($title) ?></h5>
                    <?php endif; ?>
                    <?php if ($subtitle): ?>
                        <small<?php echo( $titles_color ? ' style="color:' . esc_attr($titles_color) . ';"' : '' ); ?>><?php echo esc_html($subtitle) ?></small>
                    <?php endif; ?>
                </a>
            </div>
        </div>
        <!-- The popup -->
        <div id="<?php echo esc_attr($unique_id) ?>" class="icon_teaser mfp-hide">
            <?php if ($title): ?>
                <h3><?php echo esc_html($title) ?></h3>
            <?php endif; ?>
            <?php if ($subtitle): ?>
                <h6><?php echo esc_html($subtitle) ?></h6>
            <?php endif; ?>
            <p><?php echo wp_kses_post( do_shortcode($content) ) ?></p>
        </div>
        <!-- End The popup -->
        <script>
            //<![CDATA[
            (function ($) {
                "use strict";
                $(document).ready(function () {
                    $('.lafka-icon-teaser-popup-link').magnificPopup({
                        mainClass: 'lafka-icon-teaser-lightbox mfp-fade',
                        type: 'inline',
                        midClick: true // Allow opening popup on middle mouse click. Always set it to true if you don't provide alternative source in href.
                    });
                });
            })(window.jQuery);
            //]]>
        </script>
        <?php
        $output = ob_get_clean();

        return $output;
    }

}
add_shortcode('lafka_icon_teaser', 'lafka_icon_teaser_shortcode');


/**
 * Define lafka_icon_box shortcode
 */
if (!function_exists('lafka_icon_box_shortcode')) {

    function lafka_icon_box_shortcode($atts, $content = '') {
        // Attributes
        extract(shortcode_atts(
                                        array(
                'title' => '',
                'subtitle' => '',
                'type' => 'fontawesome',
                'icon_fontawesome' => 'fas fa-adjust',
                'icon_etline' => 'icon-mobile',
                'icon_flaticon' => 'flaticon-001-popcorn',
                'icon_image_id' => '',
                'color' => '',
                'alignment' => '',
                'icon_style' => '',
                'appear_animation' => '',
                'titles_color' => ''
                                        ), $atts)
        );

        // Enqueue font-awesome.
        wp_enqueue_style('font_awesome_6');

        $iconbox_styling_classes = array('lafka-iconbox', $alignment, $icon_style);
        if($type === 'custom_image') {
            $iconbox_styling_classes[] = 'lafka-image-icon';
        }

        $icon_style_inline = 'background-color';
        if ($icon_style == 'lafka-clean-icon') {
            $icon_style_inline = 'color';
        }

        ob_start();
        ?>
        <div class="wpb_content_element<?php if ($appear_animation !== '') echo ' ' . sanitize_html_class($appear_animation); ?>">
            <div class="<?php echo esc_attr(implode( ' ', $iconbox_styling_classes)) ?>">
                <div class="icon_wrapper">
                    <span class="icon_inner"<?php echo( $color ? ' style="' . esc_attr($icon_style_inline) . ':' . esc_attr($color) . ';"' : '' ); ?>>
                        <?php if ($type === 'fontawesome'): ?>
                            <i class="<?php echo esc_attr($icon_fontawesome); ?>"></i>
                        <?php elseif ($type === 'etline'): ?>
                            <i class="<?php echo esc_attr($icon_etline); ?>"></i>
                        <?php elseif ($type === 'flaticon'): ?>
                            <i class="<?php echo esc_attr($icon_flaticon); ?>"></i>
                        <?php elseif($type === 'custom_image'): ?>
							<?php echo wp_get_attachment_image( $icon_image_id, 'lafka-widgets-thumb', false, array( 'alt' => $title ));?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="iconbox_content">
                    <?php if ($title): ?>
                        <h5<?php echo( $titles_color ? ' style="color:' . esc_attr($titles_color) . ';"' : '' ); ?>><?php echo esc_html($title) ?></h5>
                    <?php endif; ?>
                    <?php if ($subtitle): ?>
                        <small<?php echo( $titles_color ? ' style="color:' . esc_attr($titles_color) . ';"' : '' ); ?>><?php echo esc_html($subtitle) ?></small>
                    <?php endif; ?>
                    <div class="iconbox_text_content">
                        <?php echo wp_kses_post( do_shortcode($content) ) ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
        $output = ob_get_clean();

        return $output;
    }

}
add_shortcode('lafka_icon_box', 'lafka_icon_box_shortcode');


/**
 * Define lafka_countdown shortcode
 */
if (!function_exists('lafka_countdown_shortcode')) {

    function lafka_countdown_shortcode($atts) {

        // Attributes
        extract(shortcode_atts(
                                        array(
                'date' => '',
                'counter_size' => '',
                'color' => '',
                                        ), $atts)
        );

        $output = '';

        if ($date) {
            $unique_id = uniqid('lafka_count_');

            ob_start();
            ?>
            <div id="<?php echo esc_attr($unique_id) ?>" class="lafka_shortcode_count_holder<?php if($counter_size !== '') echo ' ' . sanitize_html_class($counter_size); ?>" <?php if ($color) echo 'style="color: ' . esc_attr($color) . ';"'; ?>></div>
            <script>
                //<![CDATA[
                jQuery(function () {
                    jQuery('#<?php echo esc_js($unique_id) ?>').countdown({until: new Date("<?php echo esc_js($date) ?>"), compact: false});
                });
                //]]>
            </script>
            <?php
            $output = ob_get_clean();
        }

        return $output;
    }

}

add_shortcode('lafka_countdown', 'lafka_countdown_shortcode');

/**
 * Define lafka_map shortcode
 */
if (!function_exists('lafka_map_shortcode')) {

    function lafka_map_shortcode($atts) {
        // Attributes
        extract(shortcode_atts(
                                        array(
                'location_title' => '',
                'map_latitude' => '',
                'map_longitude' => '',
                'height' => '400'
                                        ), $atts)
        );

        $output = '';

        if ($map_latitude && $map_longitude && !is_search()) {

            $map_canvas_unique_id = uniqid('map_canvas');
            $routeStart_unique_id = uniqid('routeStart');

	        // Enqueue google maps script
	        wp_enqueue_script( 'lafka-google-maps' );
	        // Map config
	        wp_enqueue_script( 'lafka-plugin-map-config-' . $map_canvas_unique_id, plugins_url( "assets/js/lafka-plugin-map-config.min.js", dirname( __FILE__ ) ), array( 'lafka-google-maps' ), false, true );
	        wp_add_inline_script( 'lafka-plugin-map-config-' . $map_canvas_unique_id, "
                window.addEventListener('DOMContentLoaded', () => {
                    google.maps.event.addDomListener(window, 'load', initialize('" . esc_js( $location_title ? $location_title : esc_html__( 'Our Location', 'lafka-plugin' ) ) . "', '" . esc_js( $map_latitude ) . "', '" . esc_js( $map_longitude ) . "', '" . esc_url( plugins_url( 'assets/image/google_maps/', dirname( __FILE__ ) ) ) . "', '" . esc_js( $map_canvas_unique_id ) . "'));
                });
	        " );

            ob_start();
            ?>
            <div class="lafka-google-maps lafka-map-shortcode">
                <div id="<?php echo esc_attr($map_canvas_unique_id) ?>" class="map_canvas" style="width:100%; height:<?php echo esc_attr($height) ?>px"></div>
                <div class="directions_holder">
                    <h4><i class="fa fa-map-marker"></i> <?php esc_html_e('Get Directions', 'lafka-plugin') ?></h4>
                    <p><?php esc_html_e('Fill in your address or zipcode to calculate the route', 'lafka-plugin') ?></p>
                    <form action="" align="right" class="lafka-directions-form" data-route-start="<?php echo esc_attr($routeStart_unique_id) ?>" data-lat="<?php echo esc_attr($map_latitude) ?>" data-lng="<?php echo esc_attr($map_longitude) ?>" data-canvas="<?php echo esc_attr($map_canvas_unique_id) ?>">
                        <input type="text" id="<?php echo esc_attr($routeStart_unique_id) ?>" value="" placeholder="<?php esc_html_e('Address or postcode', 'lafka-plugin') ?>" style="margin-top:3px"><br /><br />
                        <input type="submit" value="<?php esc_html_e('Calculate route', 'lafka-plugin') ?>" class="button" />
                    </form>
                    <script>
                    (function(){
                        var form = document.querySelector('[data-canvas="<?php echo esc_js($map_canvas_unique_id) ?>"]');
                        if (form) {
                            form.addEventListener('submit', function(e) {
                                e.preventDefault();
                                calcRoute(form.getAttribute('data-route-start'), form.getAttribute('data-lat'), form.getAttribute('data-lng'), form.getAttribute('data-canvas'));
                            });
                        }
                    })();
                    </script>
                </div>
            </div>

            <?php
            $output = ob_get_clean();
        }
        return $output;
    }

}
add_shortcode('lafka_map', 'lafka_map_shortcode');

/**
 * Define lafka_pricing_table shortcode
 */
if (!function_exists('lafka_pricing_table_shortcode')) {

    function lafka_pricing_table_shortcode($atts, $content = '') {

        // Attributes
        extract(shortcode_atts(
                                        array(
                'title' => '',
                'subtitle' => '',
                'styled_for_dark' => '',
                'price' => '',
                'price_coins' => '',
                'currency_symbol' => '',
                'period' => '',
                'appear_animation' => '',
                'button_text' => '',
                'link' => '',
                'accent_color' => '',
                'featured' => 'no'
                                        ), $atts)
        );

        ob_start();
        ?>

        <div class="lafka-pricing-table-shortcode<?php echo ($styled_for_dark === 'yes' ? ' pricing-table-light-titles' : '') ?><?php echo ($featured === 'yes' ? ' lafka-pricing-is-featured' : '') ?><?php if ($appear_animation !== '') echo ' ' . sanitize_html_class($appear_animation); ?>">
            <?php if ($price && $period): ?>
                <div class="lafka-pricing-table-price">
                    <?php if ($price): ?>
                        <?php if($currency_symbol): ?><sub><?php echo esc_html($currency_symbol) ?></sub><?php endif; ?><?php echo esc_html($price); ?><?php if($price_coins): ?><?php echo "<sup>.".esc_html($price_coins)."</sup>" ?><?php endif; ?>
                    <?php endif; ?>
                    <?php if ($period): ?>
                        <span class="lafka-pricing-table-period"><?php echo esc_html($period) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="lafka-pricing-heading" <?php echo( $accent_color ? ' style="background-color:' . esc_attr($accent_color) . ';"' : '' ); ?> >
                <?php if ($title): ?>
                    <h5><?php echo esc_html($title) ?></h5>
                <?php endif; ?>
                <?php if ($subtitle): ?>
                    <small><?php echo esc_html($subtitle) ?></small>
                <?php endif; ?>
            </div>
            <?php if ($content): ?>
                <div class="lafka-pricing-table-content"><?php echo wp_kses_post( do_shortcode($content) ) ?></div>
            <?php endif; ?>
            <?php if ($link): ?>
                <div class="lafka-pricing-table-button">
                    <a class="lafka_pricing_table-button" href="<?php echo esc_url($link); ?>" <?php echo( $accent_color ? ' style="background-color:' . esc_attr($accent_color) . ';"' : '' ); ?>>
                        <?php echo esc_html($button_text) ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <?php
        return ob_get_clean();
    }

}
add_shortcode('lafka_pricing_table', 'lafka_pricing_table_shortcode');

/**
 * Define lafka_contact_form shortcode
 */
if (!function_exists('lafka_contact_form_shortcode')) {

    function lafka_contact_form_shortcode($atts, $content = '') {

        $current_user_email = '';
        if (is_user_logged_in()) {
            $the_logged_user = wp_get_current_user();
            if ($the_logged_user instanceof WP_User) {
                $current_user_email = $the_logged_user->user_email;
            }
        }

        wp_enqueue_script('jquery-form');

        // Attributes
        $combined_atts = shortcode_atts(
                        array(
                'title' => '',
                'contact_mail_to' => $current_user_email,
                'simple_captcha' => false,
                'contact_form_fields' => array()
                        ), $atts);

        //append lafka_ to each field
        foreach ($combined_atts as $key => $val) {
            $combined_atts['lafka_' . $key] = $val;
            unset($combined_atts[$key]);
        }
        extract($combined_atts);

        $unique_id = uniqid('lafka_contactform');
        $nonce = wp_create_nonce('lafka_contactform');

        $lafka_shortcode_params_for_tpl = json_encode($combined_atts);

        ob_start();
        ?>
        <div id="holder_<?php echo esc_attr($unique_id) ?>" class="lafka-contacts-holder lafka-contacts-shortcode" >
            <?php
            $inline_js = '"use strict";
            jQuery(document).ready(function () {
                var submitButton = jQuery(\'#holder_' . esc_js($unique_id) . ' input:submit\');
                var loader = jQuery(\'<img id="' . esc_js($unique_id) . '_loading_gif" class="lafka-contacts-loading" src="' . esc_url(plugin_dir_url(__FILE__)) . '../assets/image/contacts_ajax_loading.png" />\').prependTo(\'#holder_' . esc_attr($unique_id) . ' div.buttons div.left\').hide();

                jQuery(\'#holder_' . esc_js($unique_id) . ' form\').ajaxForm({
                    target: \'#holder_' . esc_js($unique_id) . '\',
                    data: {
                        // additional data to be included along with the form fields
                        unique_id: \'' . esc_js($unique_id) . '\',
                        action: \'lafka_submit_contact\',
                        _ajax_nonce: \'' . esc_js($nonce) . '\'
                    },
                    beforeSubmit: function (formData, jqForm, options) {
                        // optionally process data before submitting the form via AJAX
                        submitButton.hide();
                        loader.show();
                    },
                    success: function (responseText, statusText, xhr, $form) {
                        // code that\'s executed when the request is processed successfully
                        loader.remove();
                        submitButton.show();
                    }
                });
            });';

            wp_add_inline_script('flexslider', $inline_js);
            ?>
            <?php require(plugin_dir_path( __FILE__ ) . 'partials/contact-form.php'); ?>
        </div>
        <?php
        return ob_get_clean();
    }

}
add_shortcode('lafka_contact_form', 'lafka_contact_form_shortcode');



/**
 * Define lafka_woo_top_rated_carousel shortcode
 */
if (!function_exists('lafka_woo_top_rated_carousel_shortcode')) {

    function lafka_woo_top_rated_carousel_shortcode($atts) {

        $atts = shortcode_atts(array(
                'per_page' => '12',
                'columns' => '4',
                'orderby' => 'title',
                'order' => 'asc'
                        ), $atts);

        $woocommerce_shortcode_args = array(
                'limit' => $atts['per_page'],
                'columns' => $atts['columns'],
                'orderby' => $atts['orderby'],
                'order' => $atts['order'],
                'category' => '',
                'cat_operator' => 'IN',
                'class' => 'owl-carousel'
        );
        $shortcode = new WC_Shortcode_Products( $woocommerce_shortcode_args, 'top_rated_products' );

        $unique_id = uniqid('woo_top_rated');

        ob_start();
        ?>
            (function ($) {
                "use strict";
                    $(window).on("load", function () {
                    jQuery("#<?php echo esc_js($unique_id) ?> div.woocommerce").owlCarousel({
                        rtl: <?php echo is_rtl() ? 'true' : 'false'; ?>,
                        responsiveClass: true,
                        responsive: {
                            0: {
                                items: 1,
                            },
                            600: {
                                items: 2,
                            },
                            768: {
                                items: <?php echo ($atts['columns'] < 3 ? esc_js($atts['columns']) : 3) ?>,
                            },
                            1024: {
                                items: <?php echo ($atts['columns'] < 4 ? esc_js($atts['columns']) : 4) ?>,
                            },
                            1280: {
                                items: <?php echo esc_js($atts['columns']) ?>,
                            }
                        },
                        dots: false,
                        nav: true,
                        navText: [
                            "<i class='fas fa-angle-left'></i>",
                            "<i class='fas fa-angle-right'></i>"
                        ],
                    });
                });
            })(window.jQuery);
        <?php
        $js_config_output = ob_get_clean();
	    wp_add_inline_script('owl-carousel', $js_config_output);

        return '<div id="' . esc_attr($unique_id) . '">' . $shortcode->get_content() . '</div>';
    }

}

/**
 * Define lafka_woo_recent_carousel shortcode
 */
if (!function_exists('lafka_woo_recent_carousel_shortcode')) {

    function lafka_woo_recent_carousel_shortcode($atts) {

        $atts = shortcode_atts(array(
                'per_page' => '12',
                'columns' => '4',
                'orderby' => 'date',
                'order' => 'desc'
        ), $atts);

        $woocommerce_shortcode_args = array(
                'limit' => $atts['per_page'],
                'columns' => $atts['columns'],
                'orderby' => $atts['orderby'],
                'order' => $atts['order'],
                'category' => '',
                'cat_operator' => 'IN',
                'class' => 'owl-carousel'
        );
        $shortcode = new WC_Shortcode_Products( $woocommerce_shortcode_args, 'recent_products' );

        $unique_id = uniqid('woo_recent_carousel');

        ob_start();
        ?>
            (function ($) {
                "use strict";
                    $(window).on("load", function () {
                    jQuery("#<?php echo esc_js($unique_id) ?> div.woocommerce").owlCarousel({
                        rtl: <?php echo is_rtl() ? 'true' : 'false'; ?>,
                        responsiveClass: true,
                        responsive: {
                            0: {
                                items: 1,
                            },
                            600: {
                                items: 2,
                            },
                            768: {
                                items: <?php echo ($atts['columns'] < 3 ? esc_js($atts['columns']) : 3) ?>,
                            },
                            1024: {
                                items: <?php echo ($atts['columns'] < 4 ? esc_js($atts['columns']) : 4) ?>,
                            },
                            1280: {
                                items: <?php echo esc_js($atts['columns']) ?>,
                            }
                        },
                        dots: false,
                        nav: true,
                        navText: [
                            "<i class='fas fa-angle-left'></i>",
                            "<i class='fas fa-angle-right'></i>"
                        ],
                    });
                });
            })(window.jQuery);
        <?php
        $js_config_output = ob_get_clean();
	    wp_add_inline_script('owl-carousel', $js_config_output);

        return '<div id="' . esc_attr($unique_id) . '">' . $shortcode->get_content() . '</div>';
    }

}

/**
 * Define lafka_woo_featured_carousel shortcode
 */
if (!function_exists('lafka_woo_featured_carousel_shortcode')) {

    function lafka_woo_featured_carousel_shortcode($atts) {

        $atts = shortcode_atts(array(
                'per_page' => '12',
                'columns' => '4',
                'orderby' => 'date',
                'order' => 'desc'
                        ), $atts);

        $woocommerce_shortcode_args = array(
            'limit' => $atts['per_page'],
            'columns' => $atts['columns'],
            'orderby' => $atts['orderby'],
            'order' => $atts['order'],
            'category' => '',
            'cat_operator' => 'IN',
            'class' => 'owl-carousel',
            'visibility' => 'featured'
        );
        $shortcode = new WC_Shortcode_Products( $woocommerce_shortcode_args, 'featured_products' );

        $unique_id = uniqid('woo_featured_carousel');

        ob_start();
        ?>
            (function ($) {
                "use strict";
                    $(window).on("load", function () {
                    jQuery("#<?php echo esc_js($unique_id) ?> div.woocommerce").owlCarousel({
                        rtl: <?php echo is_rtl() ? 'true' : 'false'; ?>,
                        responsiveClass: true,
                        responsive: {
                            0: {
                                items: 1,
                            },
                            600: {
                                items: 2,
                            },
                            768: {
                                items: <?php echo ($atts['columns'] < 3 ? esc_js($atts['columns']) : 3) ?>,
                            },
                            1024: {
                                items: <?php echo ($atts['columns'] < 4 ? esc_js($atts['columns']) : 4) ?>,
                            },
                            1280: {
                                items: <?php echo esc_js($atts['columns']) ?>,
                            }
                        },
                        dots: false,
                        nav: true,
                        navText: [
                            "<i class='fas fa-angle-left'></i>",
                            "<i class='fas fa-angle-right'></i>"
                        ],
                    });
                });
            })(window.jQuery);
        <?php
        $js_config_output = ob_get_clean();
	    wp_add_inline_script('owl-carousel', $js_config_output);

        return '<div id="' . esc_attr($unique_id) . '">' . $shortcode->get_content() . '</div>';
    }

}

/**
 * Define lafka_woo_sale_carousel shortcode
 */
if (!function_exists('lafka_woo_sale_carousel_shortcode')) {

    function lafka_woo_sale_carousel_shortcode($atts) {

        $atts = shortcode_atts(array(
                'per_page' => '12',
                'columns' => '4',
                'orderby' => 'title',
                'order' => 'asc'
                        ), $atts);

        $woocommerce_shortcode_args = array(
            'limit'        => $atts['per_page'],
            'columns'      => $atts['columns'],
            'orderby'      => $atts['orderby'],
            'order'        => $atts['order'],
            'category'     => '',
            'cat_operator' => 'IN',
            'class' => 'owl-carousel'
        );
        $shortcode = new WC_Shortcode_Products( $woocommerce_shortcode_args, 'sale_products' );

        $unique_id = uniqid('woo_sale_carousel');

        ob_start();
        ?>
            (function ($) {
                "use strict";
                    $(window).on("load", function () {
                    jQuery("#<?php echo esc_js($unique_id) ?> div.woocommerce").owlCarousel({
                        rtl: <?php echo is_rtl() ? 'true' : 'false'; ?>,
                        responsiveClass: true,
                        responsive: {
                            0: {
                                items: 1,
                            },
                            600: {
                                items: 2,
                            },
                            768: {
                                items: <?php echo ($atts['columns'] < 3 ? esc_js($atts['columns']) : 3) ?>,
                            },
                            1024: {
                                items: <?php echo ($atts['columns'] < 4 ? esc_js($atts['columns']) : 4) ?>,
                            },
                            1280: {
                                items: <?php echo esc_js($atts['columns']) ?>,
                            }
                        },
                        dots: false,
                        nav: true,
                        navText: [
                            "<i class='fas fa-angle-left'></i>",
                            "<i class='fas fa-angle-right'></i>"
                        ],
                    });
                });
            })(window.jQuery);
        <?php
        $js_config_output = ob_get_clean();
	    wp_add_inline_script('owl-carousel', $js_config_output);

        return '<div id="' . esc_attr($unique_id) . '">' . $shortcode->get_content() . '</div>';
    }

}

/**
 * Define lafka_woo_best_selling_carousel shortcode
 */
if (!function_exists('lafka_woo_best_selling_carousel_shortcode')) {

    function lafka_woo_best_selling_carousel_shortcode($atts) {

        $atts = shortcode_atts(array(
                'per_page' => '12',
                'columns' => '4'
                        ), $atts);

        $woocommerce_shortcode_args = array(
            'limit'        => $atts['per_page'],
            'columns'      => $atts['columns'],
            'category'     => '',
            'cat_operator' => 'IN',
            'class' => 'owl-carousel'
        );
        $shortcode = new WC_Shortcode_Products( $woocommerce_shortcode_args, 'best_selling_products' );

        $unique_id = uniqid('woo_best_selling');

        ob_start();
        ?>
            (function ($) {
                "use strict";
                    $(window).on("load", function () {
                    jQuery("#<?php echo esc_js($unique_id) ?> div.woocommerce").owlCarousel({
                        rtl: <?php echo is_rtl() ? 'true' : 'false'; ?>,
                        responsiveClass: true,
                        responsive: {
                            0: {
                                items: 1,
                            },
                            600: {
                                items: 2,
                            },
                            768: {
                                items: <?php echo ($atts['columns'] < 3 ? esc_js($atts['columns']) : 3) ?>,
                            },
                            1024: {
                                items: <?php echo ($atts['columns'] < 4 ? esc_js($atts['columns']) : 4) ?>,
                            },
                            1280: {
                                items: <?php echo esc_js($atts['columns']) ?>,
                            }
                        },
                        dots: false,
                        nav: true,
                        navText: [
                            "<i class='fas fa-angle-left'></i>",
                            "<i class='fas fa-angle-right'></i>"
                        ],
                    });
                });
            })(window.jQuery);
        <?php
        $js_config_output = ob_get_clean();
	    wp_add_inline_script('owl-carousel', $js_config_output);

        return '<div id="' . esc_attr($unique_id) . '">' . $shortcode->get_content() . '</div>';
    }

}

/**
 * Define lafka_woo_product_category_carousel shortcode
 */
if (!function_exists('lafka_woo_product_category_carousel_shortcode')) {

	function lafka_woo_product_category_carousel_shortcode($atts) {

		if ( empty( $atts['category'] ) ) {
			return '';
		}

		$atts = array_merge( array(
			'limit'        => '12',
			'columns'      => '4',
			'orderby'      => 'menu_order title',
			'order'        => 'ASC',
			'category'     => '',
			'cat_operator' => 'IN',
			'class' => 'owl-carousel'
		), (array) $atts );

		$shortcode = new WC_Shortcode_Products( $atts, 'product_category' );

		$unique_id = uniqid('woo_product_category_carousel_');

		ob_start();
		?>
            (function ($) {
                "use strict";
                    $(window).on("load", function () {
                    jQuery("#<?php echo esc_js($unique_id) ?> div.woocommerce").owlCarousel({
                        rtl: <?php echo is_rtl() ? 'true' : 'false'; ?>,
                        responsiveClass: true,
                        responsive: {
                            0: {
                                items: 1,
                            },
                            600: {
                                items: 2,
                            },
                            768: {
                                items: <?php echo ($atts['columns'] < 3 ? esc_js($atts['columns']) : 3) ?>,
                            },
                            1024: {
                                items: <?php echo ($atts['columns'] < 4 ? esc_js($atts['columns']) : 4) ?>,
                            },
                            1280: {
                                items: <?php echo esc_js($atts['columns']) ?>,
                            }
                        },
                        dots: false,
                        nav: true,
                        navText: [
                            "<i class='fas fa-angle-left'></i>",
                            "<i class='fas fa-angle-right'></i>"
                        ],
                    });
                });
            })(window.jQuery);
		<?php
		$js_config_output = ob_get_clean();
		wp_add_inline_script('owl-carousel', $js_config_output);

		return '<div id="' . esc_attr($unique_id) . '">' . $shortcode->get_content() . '</div>';
	}

}

/**
 * Define lafka_woo_recent_viewed_products shortcode
 */
if (!function_exists('lafka_woo_recent_viewed_products_shortcode')) {

    function lafka_woo_recent_viewed_products_shortcode($atts) {
        global $woocommerce_loop;

        $atts = shortcode_atts(array(
                'title' => esc_html__('Recently viewed products', 'lafka-plugin'),
                'layout' => 'grid',
                'columns' => '4',
                'num_of_products' => '12',
                        ), $atts);

        $viewed_products = ! empty( $_COOKIE['woocommerce_recently_viewed'] ) ? (array) explode( '|', wp_unslash( $_COOKIE['woocommerce_recently_viewed'] ) ) : array(); // @codingStandardsIgnoreLine
        $viewed_products = array_reverse( array_filter( array_map( 'absint', $viewed_products ) ) );

        if ( empty( $viewed_products ) ) {
            return;
        }

        $query_args = array(
            'posts_per_page' => $atts['num_of_products'],
            'no_found_rows'  => 1,
            'post_status'    => 'publish',
            'post_type'      => 'product',
            'post__in'       => $viewed_products,
            'orderby'        => 'post__in',
        );

        if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
            $query_args['tax_query'] = array(
                array(
                    'taxonomy' => 'product_visibility',
                    'field'    => 'name',
                    'terms'    => 'outofstock',
                    'operator' => 'NOT IN',
                ),
            );
        }

        $unique_id = uniqid('lafka_woo_recent_viewed');

        $js_config_output = '';
        $carousel_class = '';
        if($atts['layout'] === 'carousel') {
            $carousel_class = 'owl-carousel ';
            ob_start();
            ?>
                (function ($) {
                    "use strict";
                        $(window).on("load", function () {
                        jQuery("#<?php echo esc_js($unique_id) ?> div.owl-carousel").owlCarousel({
                            rtl: <?php echo is_rtl() ? 'true' : 'false'; ?>,
                            responsiveClass: true,
                            responsive: {
                                0: {
                                    items: 1,
                                },
                                600: {
                                    items: 2,
                                },
                                768: {
                                    items: <?php echo ($atts['columns'] < 3 ? esc_js($atts['columns']) : 3) ?>,
                                },
                                1024: {
                                    items: <?php echo ($atts['columns'] < 4 ? esc_js($atts['columns']) : 4) ?>,
                                },
                                1280: {
                                    items: <?php echo esc_js($atts['columns']) ?>,
                                }
                            },
                            dots: false,
                            nav: true,
                            navText: [
                                "<i class='fas fa-angle-left'></i>",
                                "<i class='fas fa-angle-right'></i>"
                            ],
                        });
                    });
                })(window.jQuery);
            <?php
            $js_config_output = ob_get_clean();
	        wp_add_inline_script('owl-carousel', $js_config_output);
        }

        ob_start();

        $products = new WP_Query( $query_args );
        $woocommerce_loop['columns'] = $atts['columns'];

        ?>
            <div id="<?php echo esc_attr($unique_id) ?>" class="lafka_woo_recent_viewed">
                <?php if($atts['title'] !== ''): ?>
                    <h4><?php echo esc_html($atts['title']) ?></h4>
                <?php endif; ?>
                <div class="<?php echo esc_attr($carousel_class) ?>woocommerce columns-<?php echo esc_attr($atts['columns']) ?>">
                    <?php if ( $products->have_posts() ): ?>

                        <?php while ($products->have_posts()) : $products->the_post(); ?>
                            <?php wc_get_template_part('content', 'product'); ?>
                        <?php endwhile; // end of the loop.                      ?>

                        <?php woocommerce_product_loop_end(); ?>

                    <?php endif; ?>
                </div>
            </div>
        <?php
        wp_reset_postdata();

        $products_output = ob_get_clean();

        return $products_output;
    }

}

/**
 * Define lafka_woo_product_categories_carousel shortcode
 * List all (or limited) product categories
 */
if (!function_exists('lafka_woo_product_categories_carousel_shortcode')) {

    function lafka_woo_product_categories_carousel_shortcode($atts) {
        global $woocommerce_loop;

        $atts = shortcode_atts(array(
                'number' => null,
                'orderby' => 'name',
                'order' => 'ASC',
                'columns' => '4',
                'hide_empty' => 1,
                'parent' => '',
                'ids' => ''
                        ), $atts);

        if (isset($atts['ids'])) {
            $ids = explode(',', $atts['ids']);
            $ids = array_map('trim', $ids);
        } else {
            $ids = array();
        }

        $hide_empty = ( $atts['hide_empty'] == true || $atts['hide_empty'] == 1 ) ? 1 : 0;

// get terms and workaround WP bug with parents/pad counts
        $args = array(
                'orderby' => $atts['orderby'],
                'order' => $atts['order'],
                'hide_empty' => $hide_empty,
                'include' => $ids,
                'pad_counts' => true,
                'child_of' => $atts['parent']
        );

        $product_categories = get_terms('product_cat', $args);

        if ('' !== $atts['parent']) {
            $product_categories = wp_list_filter($product_categories, array('parent' => $atts['parent']));
        }

        if ($hide_empty) {
            foreach ($product_categories as $key => $category) {
                if ($category->count == 0) {
                    unset($product_categories[$key]);
                }
            }
        }

        if ($atts['number']) {
            $product_categories = array_slice($product_categories, 0, $atts['number']);
        }

        $woocommerce_loop['columns'] = $atts['columns'];
        $unique_id = uniqid('woo_product_categories');

        ob_start();
        ?>
            (function ($) {
                "use strict";
                    $(window).on("load", function () {
                    jQuery("#<?php echo esc_js($unique_id) ?>").owlCarousel({
                        rtl: <?php echo is_rtl() ? 'true' : 'false'; ?>,
                        responsiveClass: true,
                        responsive: {
                            0: {
                                items: 1,
                            },
                            600: {
                                items: 2,
                            },
                            768: {
                                items: <?php echo ($atts['columns'] < 3 ? esc_js($atts['columns']) : 3) ?>,
                            },
                            1024: {
                                items: <?php echo ($atts['columns'] < 4 ? esc_js($atts['columns']) : 4) ?>,
                            },
                            1280: {
                                items: <?php echo esc_js($atts['columns']) ?>,
                            }
                        },
                        dots: false,
                        nav: true,
                        navText: [
                            "<i class='fas fa-angle-left'></i>",
                            "<i class='fas fa-angle-right'></i>"
                        ],
                    });
                });
            })(window.jQuery);
        <?php
        $js_config_output = ob_get_clean();
	    wp_add_inline_script('owl-carousel', $js_config_output);
        ob_start();

// Reset loop/columns globals when starting a new loop
        $woocommerce_loop['loop'] = $woocommerce_loop['column'] = '';

        if ($product_categories) {

            woocommerce_product_loop_start();

            foreach ($product_categories as $category) {

                wc_get_template('content-product_cat.php', array(
                        'category' => $category
                ));
            }

            woocommerce_product_loop_end();
        }

	    wc_reset_loop();

        return '<div id="' . esc_attr($unique_id) . '" class="owl-carousel woocommerce columns-' . $atts['columns'] . '">' . ob_get_clean() . '</div>';
    }

}

/**
 * Define lafka_woo_products_slider shortcode
 */
if (!function_exists('lafka_woo_products_slider_shortcode')) {

    function lafka_woo_products_slider_shortcode($atts) {
        global $post;

        if (empty($atts)) {
            return '';
        }

        $atts = shortcode_atts(array(
                'orderby' => 'title',
                'order' => 'asc',
                'ids' => '',
                'skus' => '',
                'autoplay' => true,
                'timeout' => 5
                        ), $atts);

        $args = array(
                'post_type' => 'product',
                'post_status' => 'publish',
                'ignore_sticky_posts' => 1,
                'orderby' => $atts['orderby'],
                'order' => $atts['order'],
                'posts_per_page' => 50,
        );

        if (!empty($atts['skus'])) {
            $skus = explode(',', $atts['skus']);
            $skus = array_map('trim', $skus);
            $args['meta_query'][] = array(
                    'key' => '_sku',
                    'value' => $skus,
                    'compare' => 'IN'
            );
        }

        if (!empty($atts['ids'])) {
            $ids = explode(',', $atts['ids']);
            $ids = array_map('trim', $ids);
            $args['post__in'] = $ids;
        }

        $unique_id = uniqid('lafka_woo_products_slider');

        ob_start();
        ?>
            (function ($) {
                "use strict";
                    $(window).on("load", function () {
                    jQuery("#<?php echo esc_js($unique_id) ?>").owlCarousel({
                        rtl: <?php echo is_rtl() ? 'true' : 'false'; ?>,
                        responsiveClass: true,
                        items: 1,
                        dots: false,
                        nav: true,
                        navText: [
                            "<i class='fas fa-angle-left'></i>",
                            "<i class='fas fa-angle-right'></i>"
                        ],
                        autoplay: <?php echo $atts['autoplay'] ? 'true' : 'false'; ?>,
	                    <?php if (is_numeric($atts['timeout'])): ?>
                        autoplayTimeout: <?php echo $atts['timeout'] * 1000; ?>,
	                    <?php endif; ?>
                        loop: true,
                        autoplayHoverPause: true
                    });
                });
            })(window.jQuery);
        <?php
        $js_config_output = ob_get_clean();
	    wp_add_inline_script('owl-carousel', $js_config_output);

        ob_start();

        $products = new WP_Query(apply_filters('woocommerce_shortcode_products_query', $args, $atts, $args['post_type']));

        if ($products->have_posts()) :
            ?>

            <?php while ($products->have_posts()) : $products->the_post(); ?>
                <?php $product = wc_get_product(get_the_ID()); ?>

                <div class="lafka-product-slide-holder">
                    <div class="lafka-product-slide-image">
                        <a href="<?php the_permalink(); ?>" title="<?php the_title_attribute(); ?>" >
                            <?php if (has_post_thumbnail()) : ?>
                                <?php echo get_the_post_thumbnail(get_the_ID(), apply_filters('shop_single_image_size', 'shop_single'), array('title' => the_title_attribute('echo=0'))); ?>
                            <?php else: ?>
                                <?php echo sprintf('<img src="%s" alt="%s" />', wc_placeholder_img_src(), esc_html__('Placeholder', 'lafka-plugin')); ?>
                            <?php endif; ?>
                        </a>
                    </div>
                    <div class="lafka-product-slide-details">
                        <a href="<?php the_permalink(); ?>" title="<?php the_title_attribute(); ?>" ><h4><?php the_title(); ?></h4></a>
                        <span class="lafka-product-slide-description">
                            <?php if ($post->post_excerpt): ?>
                                <?php echo wp_kses_post( wp_trim_words(apply_filters('woocommerce_short_description', $post->post_excerpt), 23, '...') ); ?>
                            <?php else: ?>
                                <?php echo wp_trim_words(get_the_content(), 23, '...'); ?>
                            <?php endif; ?>
                        </span>
                        <div class="lafka-product-slide-countdown"><?php lafka_product_sale_countdown(); ?></div>
                        <span class="lafka-product-slide-price"><?php echo wp_kses_post($product->get_price_html()); ?></span>
                        <span class="lafka-product-slide-cart">
                            <?php echo apply_filters('woocommerce_loop_add_to_cart_link', sprintf('<a href="%s" rel="nofollow" data-product_id="%s" data-product_sku="%s" data-quantity="%s" class="button %s product_type_%s %s">%s</a>', esc_url($product->add_to_cart_url()), esc_attr($product->get_id()), esc_attr($product->get_sku()), esc_attr(isset($quantity) ? $quantity : 1 ), $product->is_purchasable() && $product->is_in_stock() ? 'add_to_cart_button' : '', esc_attr($product->get_type()), (('yes' === get_option( 'woocommerce_enable_ajax_add_to_cart') && $product->get_type() === 'simple') ? 'ajax_add_to_cart' : ''), esc_html($product->add_to_cart_text())), $product); ?>
                        </span>
                    </div>
                </div>

            <?php endwhile; // end of the loop.           ?>

            <?php
        endif;

        wp_reset_postdata();

        return '<div id="' . esc_attr($unique_id) . '" class="lafka-product-slider owl-carousel">' . ob_get_clean() . '</div>';
    }

}

// If WooCommerce is active
if (LAFKA_PLUGIN_IS_WOOCOMMERCE) {
    add_shortcode('lafka_woo_top_rated_carousel', 'lafka_woo_top_rated_carousel_shortcode');
    add_shortcode('lafka_woo_recent_carousel', 'lafka_woo_recent_carousel_shortcode');
    add_shortcode('lafka_woo_featured_carousel', 'lafka_woo_featured_carousel_shortcode');
    add_shortcode('lafka_woo_sale_carousel', 'lafka_woo_sale_carousel_shortcode');
    add_shortcode('lafka_woo_best_selling_carousel', 'lafka_woo_best_selling_carousel_shortcode');
	add_shortcode('lafka_woo_product_category_carousel', 'lafka_woo_product_category_carousel_shortcode');
    add_shortcode('lafka_woo_recent_viewed_products', 'lafka_woo_recent_viewed_products_shortcode');
    add_shortcode('lafka_woo_product_categories_carousel', 'lafka_woo_product_categories_carousel_shortcode');
    add_shortcode('lafka_woo_products_slider', 'lafka_woo_products_slider_shortcode');
}