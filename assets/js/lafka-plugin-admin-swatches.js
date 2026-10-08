jQuery( document ).ready( function ( $ ) {
	'use strict';
	const wp = window.wp,
		lafka_wcs = window.lafka_wcs || {},
		$body = $( 'body' );
	let frame;

	$( '#term-color' ).wpColorPicker();

	// Update attribute image
	$body.on( 'click', '.lafka-wcs-upload-image-button', function ( event ) {
		event.preventDefault();

		const $button = $( this );

		// If the media frame already exists, reopen it.
		if ( frame ) {
			frame.open();
			return;
		}

		// Create the media frame.
		frame = wp.media.frames.downloadable_file = wp.media( {
			title   : lafka_wcs.i18n.mediaTitle,
			button  : {
				text: lafka_wcs.i18n.mediaButton
			},
			multiple: false
		} );

		// When an image is selected, run a callback.
		frame.on( 'select', function () {
			const attachment = frame.state().get( 'selection' ).first().toJSON();

			$button.siblings( 'input.lafka-wcs-term-image' ).val( attachment.id );
			$button.siblings( '.lafka-wcs-remove-image-button' ).show();
			$button.parent().prev( '.lafka-wcs-term-image-thumbnail' ).find( 'img' ).attr( 'src', attachment.sizes.thumbnail.url );
		} );

		// Finally, open the modal.
		frame.open();

	} ).on( 'click', '.lafka-wcs-remove-image-button', function () {
		const $button = $( this );

		$button.siblings( 'input.lafka-wcs-term-image' ).val( '' );
		$button.siblings( '.lafka-wcs-remove-image-button' ).show();
		$button.parent().prev( '.lafka-wcs-term-image-thumbnail' ).find( 'img' ).attr( 'src', lafka_wcs.placeholder );

		return false;
	} );

	// Toggle add new attribute term modal
	const $modal = $( '#lafka-wcs-modal-container' ),
		$spinner = $modal.find( '.spinner' ),
		$msg = $modal.find( '.message' );
	let $metabox = null;

	$body.on( 'click', '.lafka-wcs_add_new_attribute', function ( e ) {
		e.preventDefault();
		const $button = $( this ),
			taxInputTemplate = wp.template( 'lafka-wcs-input-tax' ),
			data = {
				type: $button.data( 'type' ),
				tax : $button.closest( '.woocommerce_attribute' ).data( 'taxonomy' )
			};

		// Insert input — validate type to prevent selector injection
		const allowedTypes = ['color', 'image', 'label'];
		if ( allowedTypes.indexOf( data.type ) === -1 ) {
			return;
		}
		$modal.find( '.lafka-wcs-term-swatch' ).html( $( '#tmpl-lafka-wcs-input-' + data.type ).html() );
		$modal.find( '.lafka-wcs-term-tax' ).html( taxInputTemplate( data ) );

		if ( 'color' === data.type ) {
			$modal.find( 'input.lafka-wcs-input-color' ).wpColorPicker();
		}

		$metabox = $button.closest( '.woocommerce_attribute.wc-metabox' );
		$modal.show();
	} ).on( 'click', '.lafka-wcs-modal-close, .lafka-wcs-modal-backdrop', function ( e ) {
		e.preventDefault();

		closeModal();
	} );

	// Send ajax request to add new attribute term
	$body.on( 'click', '.lafka-wcs-new-attribute-submit', function ( e ) {
		e.preventDefault();

		let error = false;
		const data = {};

		// Validate
		$modal.find( '.lafka-wcs-input' ).each( function () {
			const $this = $( this );

			if ( $this.attr( 'name' ) !== 'slug' && !$this.val() ) {
				$this.addClass( 'error' );
				error = true;
			} else {
				$this.removeClass( 'error' );
			}

			data[$this.attr( 'name' )] = $this.val();
		} );

		if ( error ) {
			return;
		}

		// Send ajax request
		$spinner.addClass( 'is-active' );
		$msg.hide();
		wp.ajax.send( 'lafka-wcs_add_new_attribute', {
			data   : data,
			error  : function ( res ) {
				$spinner.removeClass( 'is-active' );
				$msg.addClass( 'error' ).text( res ).show();
			},
			success: function ( res ) {
				$spinner.removeClass( 'is-active' );
				$msg.addClass( 'success' ).text( res.msg ).show();

				$metabox.find( 'select.attribute_values' ).append( '<option value="' + res.id + '" selected="selected">' + res.name + '</option>' );
				$metabox.find( 'select.attribute_values' ).trigger( 'change' );

				closeModal();
			}
		} );
	} );

	/**
	 * Close modal
	 */
	function closeModal() {
		$modal.find( '.lafka-wcs-term-name input, .lafka-wcs-term-slug input' ).val( '' );
		$spinner.removeClass( 'is-active' );
		$msg.removeClass( 'error success' ).hide();
		$modal.hide();
	}
} );

