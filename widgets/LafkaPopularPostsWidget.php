<?php
defined( 'ABSPATH' ) || exit;

/**
 * Lafka popular posts widget class
 *
 */
class LafkaPopularPostsWidget extends WP_Widget {

	function __construct() {
		$widget_ops = array(
			'classname'   => 'widget_recent_entries lafka-popular-posts',
			'description' => esc_html__( 'The most popular posts on your site', 'lafka-plugin' ),
		);
		parent::__construct( 'lafka-popular-posts', esc_html__( 'Lafka Popular Posts', 'lafka-plugin' ), $widget_ops );
		$this->alt_option_name = 'widget_popular_entries';
	}

	function widget( $args, $instance ) {
		if ( ! isset( $args['widget_id'] ) ) {
			$args['widget_id'] = $this->id;
		}

		$title = ( ! empty( $instance['title'] ) ) ? $instance['title'] : esc_html__( 'Popular Posts', 'lafka-plugin' );

		/** This filter is documented in wp-includes/widgets/class-wp-widget-pages.php */
		$title = apply_filters( 'widget_title', $title, $instance, $this->id_base );

		$number = ( ! empty( $instance['number'] ) ) ? absint( $instance['number'] ) : 5;
		if ( ! $number ) {
			$number = 5;
		}

		// PERF-9: cache the popular-posts ID list. Without this, every page that
		// renders the widget runs a `SELECT … ORDER BY comment_count DESC LIMIT N`
		// — a filesort that doesn't get query-cached because the LIMIT depends on
		// the widget instance. Mirror the LafkaLatestMenuEntriesWidget pattern:
		// `wp_cache_get/set` keyed by widget id + number, busted on save_post /
		// deleted_post via the lafka_popular_posts_widget_flush() helper below.
		$cache_ver = (int) wp_cache_get( 'lafka_popular_widget_ver', 'widget' );
		$cache_key = 'lafka_popular_widget_' . $args['widget_id'] . '_' . $number . '_v' . $cache_ver;
		$ids       = wp_cache_get( $cache_key, 'widget' );
		if ( false === $ids ) {
			$query = new WP_Query(
				apply_filters(
					'widget_posts_args',
					array(
						'posts_per_page'         => $number,
						'no_found_rows'          => true,
						'post_status'            => 'publish',
						'ignore_sticky_posts'    => true,
						'orderby'                => 'comment_count',
						'fields'                 => 'ids',
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					),
					$instance
				)
			);
			$ids = $query->posts;
			wp_cache_set( $cache_key, $ids, 'widget', HOUR_IN_SECONDS );
		}
		if ( empty( $ids ) ) {
			return;
		}
		// Prime the post cache in one shot so `get_the_title($id)` /
		// `has_post_thumbnail($id)` calls below are memory hits.
		_prime_post_caches( $ids, true, true );
		$popular_posts = array_map( 'get_post', $ids );
		// Build a tiny "have_posts"-equivalent for the existing template loop.
		$r          = new stdClass();
		$r->posts   = $popular_posts;

		echo wp_kses_post( $args['before_widget'] );
		if ( $title ) {
			echo wp_kses_post( $args['before_title'] . $title . $args['after_title'] );
		}
		?>
		<ul class="post-list fixed">
			<?php foreach ( $r->posts as $popular_post ) : ?>
				<?php
				$is_current = get_queried_object_id() === $popular_post->ID;
				?>
				<li>
					<a href="<?php echo esc_url( get_permalink( $popular_post->ID ) ); ?>"<?php echo $is_current ? ' aria-current="page"' : ''; ?> title="<?php echo esc_attr( get_the_title( $popular_post->ID ) ? get_the_title( $popular_post->ID ) : (string) $popular_post->ID ); ?>">
						<?php if ( has_post_thumbnail( $popular_post->ID ) ) : ?>
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_the_post_thumbnail() returns trusted WP-core HTML with attributes pre-escaped.
							echo get_the_post_thumbnail( $popular_post->ID, 'lafka-widgets-thumb', '' );
							?>
						<?php endif; ?>
						<?php
						$lafka_pp_title = get_the_title( $popular_post->ID );
						echo esc_html( '' !== $lafka_pp_title ? $lafka_pp_title : (string) $popular_post->ID );
						?>
						<br>
						<span class="post-date"><?php echo esc_html( get_the_date( '', $popular_post->ID ) ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php echo wp_kses_post( $args['after_widget'] ); ?>
		<?php
	}

	function update( $new_instance, $old_instance ) {
		$instance           = $old_instance;
		$instance['title']  = sanitize_text_field( $new_instance['title'] );
		$instance['number'] = (int) $new_instance['number'];

		return $instance;
	}

	function form( $instance ) {
		$title  = isset( $instance['title'] ) ? esc_attr( $instance['title'] ) : '';
		$number = isset( $instance['number'] ) ? absint( $instance['number'] ) : 5;
		?>
		<p><label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'lafka-plugin' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text"
					value="<?php echo esc_attr( $title ); ?>"/></p>

		<p><label for="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>"><?php esc_html_e( 'Number of posts to show:', 'lafka-plugin' ); ?></label>
			<input id="<?php echo esc_attr( $this->get_field_id( 'number' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'number' ) ); ?>" type="text"
					value="<?php echo esc_attr( $number ); ?>" size="3"/></p>

		<?php
	}
}

add_action( 'widgets_init', 'lafka_register_lafka_popular_widget' );
if ( ! function_exists( 'lafka_register_lafka_popular_widget' ) ) {

	function lafka_register_lafka_popular_widget() {
		register_widget( 'LafkaPopularPostsWidget' );
	}

}

/**
 * Bust the popular-posts widget cache whenever post comment counts can change.
 * The widget caches a list of post IDs ordered by `comment_count`, which
 * shifts on `save_post`, `deleted_post`, `wp_set_comment_status`, and
 * `comment_post`. We don't have a per-instance cache key, so flush the whole
 * `widget` cache group prefix here — the impact is one extra cache miss for
 * any other widget consuming that group, which is negligible.
 */
if ( ! function_exists( 'lafka_popular_posts_widget_flush' ) ) {
	function lafka_popular_posts_widget_flush() {
		// Bump a single integer "version" so cached entries become unreachable.
		$ver = (int) wp_cache_get( 'lafka_popular_widget_ver', 'widget' );
		wp_cache_set( 'lafka_popular_widget_ver', $ver + 1, 'widget' );
	}
	add_action( 'save_post', 'lafka_popular_posts_widget_flush' );
	add_action( 'deleted_post', 'lafka_popular_posts_widget_flush' );
	add_action( 'wp_set_comment_status', 'lafka_popular_posts_widget_flush' );
	add_action( 'comment_post', 'lafka_popular_posts_widget_flush' );
}