<?php
/**
 * The offline page (precached by the service worker): the restaurant's name,
 * a tap-to-call phone, the weekly hours and, while it is still true, whether
 * the restaurant is open now. Self-contained: inline styles, no other request.
 *
 * Expects $lafka_offline from Lafka_Pwa::render_offline().
 *
 * @package Lafka\Plugin\Pwa
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

$lafka_status = is_array( $lafka_offline['status'] ) ? $lafka_offline['status'] : null;
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $lafka_offline['lang'] ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="theme-color" content="<?php echo esc_attr( $lafka_offline['colors']['theme_color'] ); ?>">
<title><?php echo esc_html( sprintf( /* translators: %s: restaurant name. */ __( '%s - offline', 'lafka-plugin' ), $lafka_offline['name'] ) ); ?></title>
<style>
	:root { --fg: <?php echo esc_attr( $lafka_offline['colors']['text_color'] ); ?>; --bg: <?php echo esc_attr( $lafka_offline['colors']['background_color'] ); ?>; --accent: <?php echo esc_attr( $lafka_offline['colors']['theme_color'] ); ?>; }
	* { box-sizing: border-box; }
	body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: var(--bg); color: var(--fg); font: 18px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
	main { width: 100%; max-width: 30rem; }
	h1 { margin: 0 0 4px; font-size: 1.75rem; line-height: 1.2; }
	h2 { margin: 24px 0 8px; font-size: 1rem; text-transform: uppercase; letter-spacing: .06em; }
	p { margin: 8px 0; }
	.status { font-weight: 700; }
	.call, .retry { display: flex; align-items: center; justify-content: center; min-height: 48px; margin-top: 16px; padding: 0 20px; border: 2px solid var(--fg); border-radius: 999px; background: transparent; color: var(--fg); font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
	.call { background: var(--fg); color: var(--bg); }
	dl { display: grid; grid-template-columns: auto 1fr; gap: 4px 16px; margin: 0; }
	dt { font-weight: 700; }
	dd { margin: 0; }
	a:focus-visible, button:focus-visible { outline: 3px solid var(--fg); outline-offset: 3px; }
</style>
</head>
<body>
<main>
	<h1><?php echo esc_html( $lafka_offline['name'] ); ?></h1>
	<p><?php esc_html_e( 'You are offline. Ordering needs a connection, so please check it and try again, or call us.', 'lafka-plugin' ); ?></p>
	<?php if ( $lafka_status && '' !== $lafka_status['label'] ) : ?>
		<p class="status" id="lafka-offline-status" data-until="<?php echo esc_attr( (string) $lafka_status['until'] ); ?>"><?php echo esc_html( $lafka_status['label'] ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $lafka_offline['tel'] ) : ?>
		<a class="call" href="tel:<?php echo esc_attr( $lafka_offline['tel'] ); ?>"><?php echo esc_html( sprintf( /* translators: %s: phone number. */ __( 'Call %s', 'lafka-plugin' ), '' !== $lafka_offline['phone'] ? $lafka_offline['phone'] : $lafka_offline['tel'] ) ); ?></a>
	<?php endif; ?>
	<button type="button" class="retry" id="lafka-offline-retry"><?php esc_html_e( 'Try again', 'lafka-plugin' ); ?></button>
	<?php if ( array() !== $lafka_offline['hours'] ) : ?>
		<h2><?php esc_html_e( 'Hours', 'lafka-plugin' ); ?></h2>
		<dl>
			<?php foreach ( $lafka_offline['hours'] as $lafka_day => $lafka_range ) : ?>
				<dt><?php echo esc_html( (string) $lafka_day ); ?></dt>
				<dd><?php echo esc_html( (string) $lafka_range ); ?></dd>
			<?php endforeach; ?>
		</dl>
	<?php endif; ?>
</main>
<script>
	document.getElementById('lafka-offline-retry').addEventListener('click', function () { window.location.href = <?php echo wp_json_encode( esc_url_raw( $lafka_offline['menu'] ) ); ?>; });
	// The "open now" line is only as true as the moment the page was saved: show it until the time it next changes.
	var lafkaStatus = document.getElementById('lafka-offline-status');
	if (lafkaStatus && (!Number(lafkaStatus.dataset.until) || Date.now() / 1000 > Number(lafkaStatus.dataset.until))) { lafkaStatus.hidden = true; }
</script>
</body>
</html>
