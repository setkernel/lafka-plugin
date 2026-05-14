<?php defined( 'ABSPATH' ) || exit; ?>
<?php
/**
 * @var array $addon
 * @var int $required
 * @var string $name
 * @var string $description
 * @var string $type
 * @var string $has_options_with_images
 */

$classes = array( 'product-addon', sanitize_html_class( 'product-addon-' . $name ) );
if ( 1 == $required ) {
	$classes[] = 'required-product-addon';
}
if ( isset( $addon['type'] ) ) {
	$classes[] = sanitize_html_class( $addon['type'] );
}
if ( ! empty( $addon['limit'] ) ) {
	$classes[] = 'lafka-limit';
}
if ( $has_options_with_images ) {
	$classes[] = 'lafka-addon-with-images';
}
?>
<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
	<?php
	if ( ! empty( $addon['limit'] ) ) {
		echo 'data-addon-group-limit="' . esc_attr( $addon['limit'] ) . '"';}
	?>
	>
	<?php do_action( 'wc_product_addon_start', $addon ); ?>

	<?php if ( $name ) : ?>
		<h3 class="addon-name"><?php echo esc_html( wptexturize( $name ) ); ?>
		<?php
		if ( 1 == $required ) {
			echo '<abbr class="required" title="' . esc_html__( 'Required field', 'lafka-plugin' ) . '">*</abbr>';}
		?>
		</h3>
	<?php endif; ?>

	<?php if ( $description ) : ?>
		<?php
		// wp_kses_post() lets wpautop's <p> tags through while stripping any dangerous
		// markup that may have leaked in via the operator-defined description.
		echo '<div class="addon-description">' . wp_kses_post( wpautop( wptexturize( $description ) ) ) . '</div>';
		?>
	<?php endif; ?>

	<?php do_action( 'wc_product_addon_options', $addon ); ?>
