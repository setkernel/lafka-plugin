<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'name'        => esc_html__( 'Lafka Shipping Areas', 'lafka-plugin' ),
	'base'        => 'lafka_shipping_areas',
	'icon'        => plugins_url( '../../assets/image/VC_logo_alth.png', dirname( __FILE__ ) ),
	'category'    => esc_html__( 'Lafka Shortcodes', 'lafka-plugin' ),
	'description' => esc_html__( 'Delivery map with defined areas', 'lafka-plugin' ),
	'params'      => array(
		array(
			'type'        => 'textfield',
			'heading'     => esc_html__( 'Title', 'lafka-plugin' ),
			'param_name'  => 'title',
			'description' => esc_html__( 'Enter shipping areas map title.', 'lafka-plugin' ),
			'admin_label' => true,
		),
		array(
			'type'        => 'param_group',
			'heading'     => esc_html__( 'Shipping Areas', 'lafka-plugin' ),
			'param_name'  => 'areas',
			'description' => esc_html__( 'Choose and setup lafka shipping areas to be shown on the map.', 'lafka-plugin' ),
			'value'       => '',
			'params'      => array(
				array(
					'type'        => 'autocomplete',
					'heading'     => esc_html__( 'Shipping Area', 'lafka-plugin' ),
					'param_name'  => 'area_id',
					'description' => esc_html__( 'Enter area title to pick a area.', 'lafka-plugin' ),
					'std'         => '',
				),
				array(
					'type'        => 'textfield',
					'heading'     => esc_html__( 'Label Text', 'lafka-plugin' ),
					'param_name'  => 'label_text',
					'description' => esc_html__( 'Enter text for area label.', 'lafka-plugin' ),
					'admin_label' => true,
					'std'         => '',
				),
				array(
					'type'        => 'dropdown',
					'heading'     => esc_html__( 'Label Position', 'lafka-plugin' ),
					'param_name'  => 'label_position',
					'value'       => array(
						esc_html__( 'Center', 'lafka-plugin' ) => 'center',
						esc_html__( 'Top', 'lafka-plugin' )    => 'top',
						esc_html__( 'Right', 'lafka-plugin' )  => 'right',
						esc_html__( 'Bottom', 'lafka-plugin' ) => 'bottom',
						esc_html__( 'Left', 'lafka-plugin' )   => 'left',
					),
					'description' => esc_html__( 'Select where in the area to position the label.', 'lafka-plugin' ),
				),
				array(
					'type'        => 'colorpicker',
					'heading'     => esc_html__( 'Color', 'lafka-plugin' ),
					'param_name'  => 'area_color',
					'description' => esc_html__( 'Select area color.', 'lafka-plugin' ),
					'std'         => '',
				),
			),
		),
		array(
			'type'        => 'textfield',
			'heading'     => esc_html__( 'Map Height', 'lafka-plugin' ),
			'param_name'  => 'map_height',
			'description' => esc_html__( 'Enter map height in pixels. It will fill the width of the container.', 'lafka-plugin' ),
			'std'         => '400',
		),
		array(
			'type'       => 'checkbox',
			'heading'    => esc_html__( 'Circular Area', 'lafka-plugin' ),
			'param_name' => 'circle_area',
			'value'      => array( esc_html__( 'Draw circular area around store location with specified radius, label and color.', 'lafka-plugin' ) => 'yes' ),
			'std'        => 'no'
		),
		array(
			'type'             => 'textfield',
			'heading'          => esc_html__( 'Area Radius', 'lafka-plugin' ),
			'param_name'       => 'circle_radius',
			'description'      => esc_html__( 'Enter area radius.', 'lafka-plugin' ),
			'edit_field_class' => 'vc_col-sm-6',
			'std'              => '',
			'dependency'       => array(
				'element' => 'circle_area',
				'value'   => 'yes'
			)
		),
		array(
			'type'             => 'dropdown',
			'heading'          => esc_html__( 'Radius Distance Unit', 'lafka-plugin' ),
			'param_name'       => 'circle_radius_unit',
			'value'            => array(
				esc_html__( 'Metric (km)', 'lafka-plugin' )      => 'metric',
				esc_html__( 'Imperial (miles)', 'lafka-plugin' ) => 'imperial',
			),
			'description'      => esc_html__( 'Select radius distance unit.', 'lafka-plugin' ),
			'edit_field_class' => 'vc_col-sm-6',
			'dependency'       => array(
				'element' => 'circle_area',
				'value'   => 'yes'
			)
		),
		array(
			'type'        => 'textfield',
			'heading'     => esc_html__( 'Label Text', 'lafka-plugin' ),
			'param_name'  => 'circle_label_text',
			'description' => esc_html__( 'Enter text for the circular area label.', 'lafka-plugin' ),
			'std'         => '',
			'dependency'  => array(
				'element' => 'circle_area',
				'value'   => 'yes'
			)
		),
		array(
			'type'        => 'colorpicker',
			'heading'     => esc_html__( 'Color', 'lafka-plugin' ),
			'param_name'  => 'circle_area_color',
			'description' => esc_html__( 'Select circular area color.', 'lafka-plugin' ),
			'std'         => '',
			'dependency'  => array(
				'element' => 'circle_area',
				'value'   => 'yes'
			)
		),
		array(
			'type'       => 'css_editor',
			'heading'    => esc_html__( 'CSS box', 'lafka-plugin' ),
			'param_name' => 'css',
			'group'      => esc_html__( 'Design Options', 'lafka-plugin' ),
		),
	),
);