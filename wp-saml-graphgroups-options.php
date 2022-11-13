<?php
class WP_SAML_Auth_GraphGroups_Options {
	private static $instance;
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new WP_SAML_Auth_GraphGroups_Options;
			add_action( 'init', array( self::$instance, 'action_init_early' ), 9 );
		}
		return self::$instance;
	}
	public static function action_init_early() {
		if ( self::has_settings_filter() ) {
			return;
		}
		if ( self::do_required_settings_have_values() ) {
			add_filter(
				'wp_saml_auth_option',
				array( self::$instance, 'filter_option' ),
				9,
				2
			);
		}
	}
	public static function get_option_name() {
		return 'wp_saml_auth_settings';
	}
	public static function has_settings_filter() {
		$filter1    = remove_filter( 'wp_saml_auth_option', 'wpsa_filter_option', 0 );
		$filter2    = remove_filter( 'wp_saml_auth_option', array( self::$instance, 'filter_option' ), 9 );
		$has_filter = has_filter( 'wp_saml_auth_option' );
		if ( $filter1 ) {
			add_filter( 'wp_saml_auth_option', 'wpsa_filter_option', 0, 2 );
		}
		if ( $filter2 ) {
			add_filter(
				'wp_saml_auth_option',
				array( self::$instance, 'filter_option' ),
				9,
				2
			);
		}
		return $has_filter;
	}
	public static function do_required_settings_have_values() {
		$options = get_option( self::get_option_name() );
		$retval  = null;
		foreach ( WP_SAML_Auth_GraphGroups_Options::get_fields() as $field ) {
			if ( empty( $field['required'] ) ) {
				continue;
			}
			// Required option is empty.
			if ( empty( $options[ $field['uid'] ] ) ) {
				$retval = false;
				continue;
			}
			// Required option is present and return value hasn't been set.
			if ( is_null( $retval ) ) {
				$retval = true;
			}
		}
		return ! is_null( $retval ) ? $retval : false;
	}
	public static function filter_option( $value, $option_name ) {
		$options  = get_option( self::get_option_name() );
		$settings = array(
			'connection_type' => 'internal',
			'internal_config' => array(
				'strict'  => true,
				'debug'   => defined( 'WP_DEBUG' ) && WP_DEBUG ? true : false,
				'baseurl' => $options['baseurl'],
				'idp'     => array(
					'entityId'                 => $options['idp_entityId'],
					'singleSignOnService'      => array(
						'url'     => $options['idp_singleSignOnService_url'],
						'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
					),
					'singleLogoutService'      => array(
						'url'     => $options['idp_singleLogoutService_url'],
						'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
					),
				),
			),
		);

		$remaining_settings = array();
		foreach ( $remaining_settings as $setting ) {
			$settings[ $setting ] = $options[ $setting ];
		}
		$value = isset( $settings[ $option_name ] ) ? $settings[ $option_name ] : $value;
		return $value;
	}
}