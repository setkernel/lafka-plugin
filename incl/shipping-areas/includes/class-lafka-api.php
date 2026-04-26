<?php
/**
 * The file that defines the api request class
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The API request class.
 */
abstract class Lafka_API {

	/**
	 * URL of Google Maps Distance Matrix API
	 *
	 * @var string
	 */
	private static $api_url = 'https://maps.googleapis.com/maps/api/distancematrix/json';

	/**
	 * Making HTTP request to Google Maps Distance Matrix API
	 *
	 * @param array $args Custom arguments for $settings and $package data.
	 *
	 * @return array|WP_Error (array[]|WP_Error) WP_Error on failure.
	 */
	public static function calculate_distance( $args = array() ) {
		$request_data = wp_parse_args(
			$args,
			array(
				'origins'      => '',
				'destinations' => '',
				'key'          => '',
				'avoid'        => '',
				'language'     => get_locale(),
				'units'        => 'metric',
				'mode'         => 'driving',
			)
		);

		foreach ( $request_data as $key => $value ) {
			if ( is_array( $value ) ) {
				$request_data[ $key ] = implode( ',', array_map( 'rawurlencode', $value ) );
			} else {
				$request_data[ $key ] = rawurlencode( $value );
			}
		}

		$request_data_masked = array_merge(
			$request_data,
			array(
				'key' => '***',
			)
		);

		$api_response = wp_remote_get( add_query_arg( $request_data, self::$api_url ) );

		// Check if HTTP request is error.
		if ( is_wp_error( $api_response ) ) {
			$api_response->add_data(
				array(
					'request_data' => $request_data_masked,
				)
			);

			return $api_response;
		}

		if ( empty( $api_response['body'] ) ) {
			return new WP_Error(
				'api_response_body_empty',
				esc_html__( 'API response is empty.', 'lafka-plugin' ),
				array(
					'request_data' => $request_data_masked,
					'api_response' => $api_response,
				)
			);
		}

		// Decode API response body.
		$response_data = json_decode( $api_response['body'], true );

		if ( is_null( $response_data ) ) {
			return new WP_Error(
				'json_last_error',
				json_last_error_msg(),
				array(
					'request_data' => $request_data_masked,
					'api_response' => $api_response,
				)
			);
		}

		// Check API response is OK.
		$status = isset( $response_data['status'] ) ? $response_data['status'] : 'UNKNOWN_ERROR';

		if ( 'OK' !== $status ) {
			if ( isset( $response_data['error_message'] ) ) {
				return new WP_Error(
					$status,
					$response_data['error_message'],
					array(
						'request_data'  => $request_data_masked,
						'response_data' => $response_data,
					)
				);
			}

			return new WP_Error(
				$status,
				$status,
				array(
					'request_data'  => $request_data_masked,
					'response_data' => $response_data,
				)
			);
		}

		$element_errors = array(
			'NOT_FOUND'                 => esc_html__( 'Origin and/or destination of this pairing could not be geocoded.', 'lafka-plugin' ),
			'ZERO_RESULTS'              => esc_html__( 'No route could be found between the origin and destination.', 'lafka-plugin' ),
			'MAX_ROUTE_LENGTH_EXCEEDED' => esc_html__( 'Requested route is too long and cannot be processed.', 'lafka-plugin' ),
			'UNKNOWN'                   => esc_html__( 'Unknown error.', 'lafka-plugin' ),
		);

		$errors  = new WP_Error();
		$results = array();

		// Get the shipping distance.
		foreach ( $response_data['rows'] as $row ) {
			foreach ( $row['elements'] as $element ) {
				$element_status = $element['status'] ?? 'UNKNOWN';

				if ( 'OK' === $element_status ) {
					$results[] = array(
						'distance' => $element['distance']['value'], // Distance in meters unit.
						'duration' => $element['duration']['value'], // Duration in seconds unit.
					);

					continue;
				}

				if ( ! isset( $element_errors[ $element_status ] ) ) {
					$element_status = 'UNKNOWN';
				}

				$errors->add(
					$element_status,
					$element_errors[ $element_status ],
					array(
						'request_data'  => $request_data_masked,
						'response_data' => $response_data,
					)
				);
			}
		}

		if ( ! empty( $results ) ) {
			return $results;
		}

		return $errors;
	}
}
