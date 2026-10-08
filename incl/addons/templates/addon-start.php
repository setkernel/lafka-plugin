<?php defined( 'ABSPATH' ) || exit; ?>
<?php
/**
 * @var array $addon
 * @var int $required
 * @var string $name
 * @var string $description
 * @var string $type
 * @var string $has_options_with_images
 * @var bool   $toggle  (T-19) Render the heading as a disclosure button.
 * @var string $body_id (T-19) Id of the collapsible body the button controls.
 */

// $toggle and $body_id arrive from wc_get_template()'s args (extracted by
// name), so they must keep those names.
$lafka_toggle  = ! empty( $toggle ) && $name && ! empty( $body_id );
$lafka_body_id = $lafka_toggle ? (string) $body_id : '';

$lafka_classes = array( 'product-addon', sanitize_html_class( 'product-addon-' . $name ) );
if ( 1 === (int) $required ) {
	$lafka_classes[] = 'required-product-addon';
}
if ( isset( $addon['type'] ) ) {
	$lafka_classes[] = sanitize_html_class( $addon['type'] );
}
if ( ! empty( $addon['limit'] ) ) {
	$lafka_classes[] = 'lafka-limit';
}
if ( $has_options_with_images ) {
	$lafka_classes[] = 'lafka-addon-with-images';
}
?>
<div class="<?php echo esc_attr( implode( ' ', $lafka_classes ) ); ?>"
	<?php
	if ( ! empty( $addon['limit'] ) ) {
		echo 'data-addon-group-limit="' . esc_attr( $addon['limit'] ) . '"';}
	?>
	>
	<?php
	do_action( 'lafka_product_addon_start', $addon );
	do_action_deprecated( 'wc_product_addon_start', array( $addon ), '10.4.0', 'lafka_product_addon_start' );
	?>

	<?php if ( $lafka_toggle ) : ?>
		<h3 class="addon-name"><button type="button" class="lafka-addon-toggle" aria-expanded="true" aria-controls="<?php echo esc_attr( $lafka_body_id ); ?>"><?php echo esc_html( wptexturize( $name ) ); ?>
		<?php
		if ( 1 === (int) $required ) {
			echo '<abbr class="required" title="' . esc_html__( 'Required field', 'lafka-plugin' ) . '">*</abbr>';}
		?>
		</button></h3>
		<div class="lafka-addon-body" id="<?php echo esc_attr( $lafka_body_id ); ?>">
	<?php elseif ( $name ) : ?>
		<h3 class="addon-name"><?php echo esc_html( wptexturize( $name ) ); ?>
		<?php
		if ( 1 === (int) $required ) {
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

	<?php
	do_action( 'lafka_product_addon_options', $addon );
	do_action_deprecated( 'wc_product_addon_options', array( $addon ), '10.4.0', 'lafka_product_addon_options' );
	?>
