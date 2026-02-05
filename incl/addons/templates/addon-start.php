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
<div class="<?php echo implode( ' ', $classes ) ?>"
	<?php if ( ! empty( $addon['limit'] ) )	echo 'data-addon-group-limit="' . esc_attr( $addon['limit'] ) . '"' ?> >
	<?php do_action( 'wc_product_addon_start', $addon ); ?>

	<?php if ( $name ) : ?>
		<h3 class="addon-name"><?php echo wptexturize( $name ); ?> <?php if ( 1 == $required ) echo '<abbr class="required" title="' . esc_html__( 'Required field', 'lafka-plugin' ) . '">*</abbr>'; ?></h3>
	<?php endif; ?>

	<?php if ( $description ) : ?>
		<?php echo '<div class="addon-description">' . wpautop( wptexturize( $description ) ) . '</div>'; ?>
	<?php endif; ?>

	<?php do_action( 'wc_product_addon_options', $addon ); ?>
