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

		$r = new WP_Query(
			apply_filters(
				'widget_posts_args',
				array(
					'posts_per_page'      => $number,
					'no_found_rows'       => true,
					'post_status'         => 'publish',
					'ignore_sticky_posts' => true,
					'orderby'             => 'comment_count',
				),
				$instance
			)
		);

		if ( ! $r->have_posts() ) {
			return;
		}

		echo wp_kses_post( $args['before_widget'] );
		if ( $title ) {
			echo wp_kses_post( $args['before_title'] . $title . $args['after_title'] );
		}
		?>
		<ul class="post-list fixed">
			<?php foreach ( $r->posts as $popular_post ) : ?>
				<?php
				$aria_current = '';
				if ( get_queried_object_id() === $popular_post->ID ) {
					$aria_current = ' aria-current="page"';
				}
				?>
				<li>
					<a href="<?php the_permalink( $popular_post->ID ); ?>" <?php echo $aria_current; ?>title="<?php echo esc_attr( get_the_title() ? get_the_title() : get_the_ID() ); ?>">
						<?php if ( has_post_thumbnail( $popular_post->ID ) ) : ?>
							<?php echo get_the_post_thumbnail( $popular_post->ID, 'lafka-widgets-thumb', '' ); ?>
						<?php endif; ?>
						<?php
						if ( get_the_title( $popular_post->ID ) ) {
							echo get_the_title( $popular_post->ID );
						} else {
							echo esc_html( $popular_post->ID );
						}
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