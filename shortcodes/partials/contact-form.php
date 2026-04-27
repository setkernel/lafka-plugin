<?php defined( 'ABSPATH' ) || exit; ?>
<?php
wp_enqueue_script( 'jquery-form' );

//fields translatable strings
$lafka_fields_strings            = array();
$lafka_fields_strings['name']    = esc_html__( 'Name', 'lafka-plugin' );
$lafka_fields_strings['email']   = esc_html__( 'E-Mail Address', 'lafka-plugin' );
$lafka_fields_strings['phone']   = esc_html__( 'Phone', 'lafka-plugin' );
$lafka_fields_strings['address'] = esc_html__( 'Street Address', 'lafka-plugin' );
$lafka_fields_strings['subject'] = esc_html__( 'Subject', 'lafka-plugin' );

//response messages
$lafka_missing_content = esc_html__( 'Please enter %s.', 'lafka-plugin' );
$lafka_missing_message = esc_html__( 'Please enter a message.', 'lafka-plugin' );
$lafka_captcha_message = esc_html__( 'Calculation result was not correct.', 'lafka-plugin' );
$lafka_email_invalid   = esc_html__( 'Email Address Invalid.', 'lafka-plugin' );
$lafka_message_unsent  = esc_html__( 'Message was not sent. Try Again.', 'lafka-plugin' );
$lafka_message_sent    = esc_html__( 'Thanks! Your message has been sent.', 'lafka-plugin' );

//user posted variables
$lafka_subject        = array_key_exists( 'lafka_subject', $_POST ) ? sanitize_text_field( wp_unslash( $_POST['lafka_subject'] ) ) : '';
$lafka_email          = array_key_exists( 'lafka_email', $_POST ) ? sanitize_email( wp_unslash( $_POST['lafka_email'] ) ) : '';
$lafka_name           = array_key_exists( 'lafka_name', $_POST ) ? sanitize_text_field( wp_unslash( $_POST['lafka_name'] ) ) : '';
$lafka_phone          = array_key_exists( 'lafka_phone', $_POST ) ? sanitize_text_field( wp_unslash( $_POST['lafka_phone'] ) ) : '';
$lafka_address        = array_key_exists( 'lafka_address', $_POST ) ? sanitize_text_field( wp_unslash( $_POST['lafka_address'] ) ) : '';
$lafka_message        = array_key_exists( 'lafka_enquiry', $_POST ) ? sanitize_textarea_field( wp_unslash( $_POST['lafka_enquiry'] ) ) : '';
$lafka_captcha_rand   = array_key_exists( 'lafka_contact_submitted', $_POST ) ? sanitize_text_field( $_POST['lafka_contact_submitted'] ) : '';
$lafka_captcha_answer = array_key_exists( 'lafka_captcha_answer', $_POST ) ? sanitize_text_field( $_POST['lafka_captcha_answer'] ) : '';
/*
 * SECURITY: do NOT honour POST-supplied `shortcode_params_for_tpl` for the
 * recipient. Earlier versions used a variable-variable assignment that let an
 * attacker forge `lafka_contact_mail_to` via JSON in the POST body, turning
 * the site's wp_mail() into an unauthenticated mail relay (CVE-class issue).
 *
 * In the shortcode-render path (PHP include from `shortcodes.php`), the
 * caller pre-sets these PHP variables from trusted shortcode attributes —
 * the `isset()` guards below detect that case and pass through untouched.
 *
 * In the AJAX handler context (lafka_submit_contact, _nopriv_), none of
 * these variables are set on include, so the secure defaults below apply:
 * - recipient defaults to admin email (filterable by `lafka_contact_form_recipient`)
 * - captcha is always on
 * - the form-fields allowlist is the conservative `name + email + message`
 *
 * Per-shortcode recipient customisation is no longer wire-controlled. To
 * customise per-page, hook the `lafka_contact_form_recipient` filter
 * server-side (return an email; the input is the default admin email).
 */
if ( ! isset( $lafka_contact_mail_to ) ) {
	$lafka_contact_mail_to = sanitize_email(
		apply_filters( 'lafka_contact_form_recipient', get_option( 'admin_email' ) )
	);
}
if ( ! isset( $lafka_simple_captcha ) ) {
	$lafka_simple_captcha = true;
}
if ( ! isset( $lafka_contact_form_fields ) ) {
	$lafka_contact_form_fields = array(
		'name'  => true,
		'email' => true,
	);
}
if ( ! isset( $lafka_title ) ) {
	$lafka_title = '';
}

$lafka_headers              = '';
$lafka_contactform_response = '';
$lafka_rand_captcha         = '';

/* Get choosen fields from Options */
$lafka_contacts_fields = array();

/* if is from shortcode */
if ( isset( $lafka_contact_form_fields ) ) {

	if ( is_string( $lafka_contact_form_fields ) ) {
		$lafka_contact_form_fields_arr = explode( ',', $lafka_contact_form_fields );
	} elseif ( is_array( $lafka_contact_form_fields ) ) {
		$lafka_contact_form_fields_arr = $lafka_contact_form_fields;
	} else {
		$lafka_contact_form_fields_arr = array();
	}

	foreach ( $lafka_contact_form_fields_arr as $lafka_field ) {
		$lafka_contacts_fields[ $lafka_field ] = true;
	}
}

$lafka_has_error  = false;
$lafka_name_error = $lafka_email_error = $lafka_phone_error = $lafka_address_error = $lafka_subject_error = $lafka_message_error = $lafka_captcha_error = false;

if ( isset( $_POST['lafka_contact_submitted'] ) ) {

	/* Validate Email address */
	if ( $lafka_email && $lafka_contacts_fields['email'] && ! filter_var( $lafka_email, FILTER_VALIDATE_EMAIL ) ) {
		$lafka_has_error   = true;
		$lafka_email_error = lafka_contact_form_generate_response( 'error', $lafka_email_invalid );
	} else {
		// SECURITY: From: is hard-set to the site admin email so SPF/DKIM
		// alignment doesn't get weaponised for spoofing. The user's address
		// goes into Reply-To: only — that's where the recipient hits "Reply"
		// to actually contact them.
		$lafka_from_email = sanitize_email( get_option( 'admin_email' ) );
		$lafka_headers    = 'From: ' . $lafka_from_email . "\r\n" .
			( $lafka_email ? 'Reply-To: ' . sanitize_email( $lafka_email ) . "\r\n" : '' );
	}

	/* Check if all fields are filled */
	foreach ( $lafka_contacts_fields as $lafka_fieldname => $lafka_is_enabled ) {
		if ( $lafka_is_enabled && ! ${'lafka_' . $lafka_fieldname} ) {
			$lafka_has_error                          = true;
			${'lafka_' . $lafka_fieldname . '_error'} = lafka_contact_form_generate_response( 'error', sprintf( $lafka_missing_content, $lafka_fields_strings[ $lafka_fieldname ] ) );
		}
	}

	/* Check for a message */
	if ( ! trim( $lafka_message ) ) {
		$lafka_has_error     = true;
		$lafka_message_error = lafka_contact_form_generate_response( 'error', $lafka_missing_message );
	}

	/* captcha validation */
	if ( $lafka_simple_captcha ) {
		if ( (int) $lafka_captcha_rand + 1 !== (int) $lafka_captcha_answer ) {
			$lafka_has_error     = true;
			$lafka_captcha_error = lafka_contact_form_generate_response( 'error', $lafka_captcha_message );
		}
	}

	// SECURITY: per-IP rate limit. 5 successful submissions per hour.
	// Mirrors KDS auth pattern — pluggable via `lafka_contact_form_client_ip` filter
	// for sites behind a reverse proxy (Cloudflare CF-Connecting-IP, etc.).
	$lafka_client_ip = isset( $_SERVER['REMOTE_ADDR'] )
		? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
		: '0.0.0.0';
	$lafka_client_ip   = apply_filters( 'lafka_contact_form_client_ip', $lafka_client_ip );
	$lafka_rate_key    = 'lafka_cf_rate_' . md5( $lafka_client_ip );
	$lafka_rate_count  = (int) get_transient( $lafka_rate_key );
	$lafka_rate_max    = (int) apply_filters( 'lafka_contact_form_rate_max', 5 );
	if ( $lafka_rate_count >= $lafka_rate_max ) {
		$lafka_has_error            = true;
		$lafka_contactform_response = lafka_contact_form_generate_response(
			'error',
			esc_html__( 'You are sending too many messages. Please try again later.', 'lafka-plugin' )
		);
	}

	if ( ! $lafka_has_error ) {
		$lafka_sent = wp_mail( sanitize_email( $lafka_contact_mail_to ), ( $lafka_subject ? sanitize_text_field( $lafka_subject ) : sprintf( esc_html__( 'Someone sent a message from %s', 'lafka-plugin' ), sanitize_text_field( get_bloginfo( 'name' ) ) ) ), ( $lafka_name ? 'Name: ' . sanitize_text_field( $lafka_name ) : '' ) . "\r\n" . ( $lafka_email ? 'E-Mail Address: ' . sanitize_text_field( $lafka_email ) . "\r\n" : '' ) . ( $lafka_phone ? 'Phone: ' . sanitize_text_field( $lafka_phone ) . "\r\n" : '' ) . ( $lafka_address ? 'Street Address: ' . sanitize_text_field( $lafka_address ) . "\r\n" : '' ) . "\r\n" . wp_kses_post( $lafka_message ), $lafka_headers );
		if ( $lafka_sent ) {
			$lafka_contactform_response = lafka_contact_form_generate_response( 'success', $lafka_message_sent ); //message sent!
			// Bump the per-IP rate counter only on successful sends so attackers spamming garbage don't lock out legit users.
			set_transient( $lafka_rate_key, $lafka_rate_count + 1, HOUR_IN_SECONDS );
			//clear values
			$lafka_subject = $lafka_email = $lafka_name = $lafka_phone = $lafka_address = $lafka_message = '';
		} else {
			$lafka_contactform_response = lafka_contact_form_generate_response( 'error', $lafka_message_unsent ); //message wasn't sent
		}
	}
}

$lafka_contact_title = isset( $lafka_title ) ? $lafka_title : esc_html__( 'Send us a message', 'lafka-plugin' );
?>
<?php if ( $lafka_contact_title ) : ?>
	<h2 class="contact-form-title"><?php echo esc_html( $lafka_contact_title ); ?></h2>
<?php endif; ?>
<form action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" method="post" class="contact-form">
	<?php if ( isset( $lafka_contacts_fields['name'] ) ) : ?>
		<div class="content lafka_name"> <span><?php esc_html_e( 'Your Name', 'lafka-plugin' ); ?>:</span>
			<input type="text" value="<?php echo esc_attr( $lafka_name ); ?>" name="lafka_name" />
			<?php
			if ( $lafka_name_error ) {
				echo wp_kses_post( $lafka_name_error );}
			?>
		</div>

	<?php endif; ?>
	<?php if ( isset( $lafka_contacts_fields['email'] ) ) : ?>
		<div class="content lafka_email"> <span><?php esc_html_e( 'E-Mail Address', 'lafka-plugin' ); ?>:</span>
			<input type="text" value="<?php echo esc_attr( $lafka_email ); ?>" name="lafka_email" />
			<?php
			if ( $lafka_email_error ) {
				echo wp_kses_post( $lafka_email_error );}
			?>
		</div>
	<?php endif; ?>
	<?php if ( isset( $lafka_contacts_fields['phone'] ) ) : ?>
		<div class="content lafka_phone"> <span><?php esc_html_e( 'Phone', 'lafka-plugin' ); ?>:</span>
			<input type="text" value="<?php echo esc_attr( $lafka_phone ); ?>" name="lafka_phone" />
			<?php
			if ( $lafka_phone_error ) {
				echo wp_kses_post( $lafka_phone_error );}
			?>
		</div>
	<?php endif; ?>
	<?php if ( isset( $lafka_contacts_fields['address'] ) ) : ?>
		<div class="content lafka_address"> <span><?php esc_html_e( 'Street Address', 'lafka-plugin' ); ?>:</span>
			<input type="text" value="<?php echo esc_attr( $lafka_address ); ?>" name="lafka_address" />
			<?php
			if ( $lafka_address_error ) {
				echo wp_kses_post( $lafka_address_error );}
			?>
		</div>
	<?php endif; ?>
	<?php if ( isset( $lafka_contacts_fields['subject'] ) ) : ?>
		<div class="content lafka_subject"> <span><?php esc_html_e( 'Subject', 'lafka-plugin' ); ?>:</span>
			<input type="text" value="<?php echo esc_attr( $lafka_subject ); ?>" name="lafka_subject" />
			<?php
			if ( $lafka_subject_error ) {
				echo wp_kses_post( $lafka_subject_error );}
			?>
		</div>
	<?php endif; ?>
	<div class="content lafka_enquiry"> <span><?php esc_html_e( 'Message Text', 'lafka-plugin' ); ?>:</span>
		<textarea style="width: 99%;" rows="10" cols="40" name="lafka_enquiry"><?php echo esc_textarea( $lafka_message ); ?></textarea>
		<?php
		if ( $lafka_message_error ) {
			echo wp_kses_post( $lafka_message_error );}
		?>
	</div>
	<?php if ( $lafka_simple_captcha ) : ?>
		<?php
		// `wp_rand` is the cryptographically-stronger sibling of `mt_rand`
		// (uses random_int internally on PHP 7+). The 1/9 success rate of an
		// integer captcha is still trivially brute-forceable by automation —
		// for real bot protection use hCaptcha/Turnstile via a filter on the
		// pre-mail validation. Tracked as M6 in Session 4 audit.
		$lafka_rand_captcha = wp_rand( 0, 8 );
		?>
		<div class="content lafka_form_test">
			<?php echo esc_html__( "Prove you're a human", 'lafka-plugin' ); ?>: <span class=constant>1</span> + <span class=random><?php echo esc_html( $lafka_rand_captcha ); ?></span> = ? <input type="text" value="" name="lafka_captcha_answer" />
		</div>
		<?php
		if ( $lafka_captcha_error ) {
			echo wp_kses_post( $lafka_captcha_error );}
		?>
	<?php endif; ?>
	<?php echo wp_kses_post( $lafka_contactform_response ); ?>
	<div class="buttons">
		<input type="hidden" name="lafka_contact_submitted" value="<?php echo esc_attr( $lafka_rand_captcha ); ?>">
		<input type="hidden" name="shortcode_params_for_tpl" value="<?php echo esc_attr( $lafka_shortcode_params_for_tpl ); ?>">
		<div class="left"><input class="button button-orange" value="<?php esc_html_e( 'Send message', 'lafka-plugin' ); ?>" type="submit"></div>
	</div>
</form>
